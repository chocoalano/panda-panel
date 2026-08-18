# Query Performance

What the panel does to keep a list fast, what it cannot do for you, and where the limits are. You reach for this page when a table is slower than the number of rows on it suggests it should be, or before putting a wide table in front of a large database.

Three things decide the cost of a list page: how many queries it runs, how much each one returns, and how much work happens per row. The first is mostly handled, the second is not narrowed at all, and the third is now proportional to what is actually shown.

## What a list page actually queries

A resource index with twenty-five rows, one aggregate column, one eager-loaded relation and two summaries:

```text
1. select count(*) from users                       -- the paginator's total
2. select "users".*, (subselect) as passkeys_count  -- the page
3. select * from passkeys where user_id in (…)      -- the eager load
4. sum(passkeys_count) …                            -- a summary
5. count(passkeys_count) …                          -- a summary
6. min(created_at) …                                -- a summary
7. max(created_at) …                                -- a summary
8. count(*) from notifications …                    -- the shell's bell
```

Eight, and **eight for thirty-five rows as well**. That is the property worth protecting, and the [test that protects it](../testing/tables.md) counts queries for a small page and a large one and asserts they are equal.

Summaries are the part that scales with the *schema* rather than the data: one query per summarizer that is not per-page, plus one per band on screen when the table is grouped. Four summarizers is four queries whatever the row count.

## N+1: what is handled

**Aggregates are computed in the select.** `counts()`, `sum()`, `exists()` and the rest become a subselect on the page query, so a column showing "12 posts" costs nothing per row.

```php
NumberColumn::make('posts_count')->counts('posts'),
```

**Relations named by a column are eager loaded, derived.** A column called `author.name` reads its value with `data_get()`, and `data_get()` on an unloaded relation loads it — once per record. That relation is now loaded for the page:

```php
TextColumn::make('author.name'),   // the table loads `author` for the page
```

The derivation walks the dotted name, verifies each segment really is a relation on the model, and drops anything it cannot verify. A JSON column addressed as `meta.total` is not a relation and is left alone. It is best effort and never fatal: adding an eager load can only reduce queries, but getting one wrong must not be able to break a page that works.

**Exports derive theirs too**, from the columns actually being written. An export walks the whole result set with no page size to bound it, so this is the one N+1 that used to have no ceiling.

**Sorting and searching a relation never load it.** A relation sort is a correlated subquery; a relation search is `whereHas`. Neither joins, so neither can multiply rows underneath the paginator.

## N+1: what is still yours

Derivation only sees names. Everything that reads a relation without naming one is invisible to it, and that is what `$with` is still for:

```php
protected static array $with = ['author', 'tags'];
```

Reach for it when a relation is read by:

- **a closure** — `formatUsing()`, `urlUsing()`, `tooltip()`, `extraAttributes()`, a `Group` title callback
- **`recordTitle()`**, which the breadcrumb, the global search result and the delete confirmation all call
- **a policy**, asked once per record per action while the row is serialized
- **an infolist entry or form field** on a record page — one record, so it is one query rather than N, but still a query

A policy is the one worth watching. Every row asks every record action whether the user may run it, so a policy that queries is a query per record per action and nothing in the framework can see it coming. Load what it needs in `$with`, or answer from attributes the record already has.

### Make a forgotten one loud

Nothing here can detect a lazy load, because a lazy load is indistinguishable from an intentional one. Laravel can:

```php
// app/Providers/AppServiceProvider.php
public function boot(): void
{
    Model::preventLazyLoading(! app()->isProduction());
}
```

In development that turns the slow page into an exception naming the relation and the model. In production it stays off, so a missed one degrades rather than 500s. This is the single most useful thing you can add on top of the panel, and it belongs in your application rather than in this package — it is a decision about your whole codebase, not about your panels.

## Column selection: a real limit

**Every read is `select *`.** There is no mechanism to narrow it, on a table, an export, or global search. A table showing three of forty columns still transfers forty, including any `TEXT`, `JSON` or `BLOB` among them.

This is a limitation rather than an oversight, and the reason is worth knowing before you reach for a workaround. A narrowed select would have to be derived from the declared columns, and the declared columns are not the whole story:

- `formatUsing()`, `urlUsing()` and `tooltip()` closures receive the **record** and may read any attribute on it
- `recordTitle()` reads whatever it likes
- a policy reads whatever it needs to decide
- an aggregate adds its own select, and a summary reads the alias

A derived select would break all four, and it would break them **silently** — the symptom is an attribute that is suddenly `null`, in a closure nobody thought about, with nothing to say why. That is a worse failure than the cost it saves.

If a specific table needs it, narrow the query yourself and accept the responsibility:

```php
public static function query(): Builder
{
    // Everything the columns, the closures, the title and the policy read —
    // plus the key of every relation being loaded.
    return parent::query()->select(['id', 'name', 'email', 'author_id', 'created_at']);
}
```

Call `parent::query()` — it carries the panel's narrowing, the tenant scope and `$with` — and re-check the list whenever a column, a closure or a policy changes. A `TEXT` column left out of a table nobody reads it in is usually where the win is.

**Include the foreign keys, and mind that eager loading is now derived.** A `belongsTo` is matched on the parent's key column: `with('author')` needs `author_id` in the select or the relation comes back empty for every row. That used to be visible — you wrote the `with()` yourself. Now a column called `author.name` adds it for you, so a narrowed select can break a relation nothing in the file mentions. If you narrow the select, list the keys of every relation any column names, and of everything in `$with`.

The failure is quiet in the usual way: the relation resolves to `null`, the column renders its placeholder, and nothing says why. If a narrowed table suddenly shows dashes where related values were, this is the first thing to check.

## Per-row work is now proportional

A hidden column costs nothing. `toRow()` serializes the columns the current arrangement shows, so a column turned off in the [column manager](../tables/column-manager.md) is not read from the record, not passed through its closures, and not sent to the frontend.

**Its summaries go with it.** A figure under a column nobody is looking at is an aggregate *query* whose result the frontend discards — more expensive than the cell that was already being skipped. Hiding a column with three summarizers saves three queries per page, and three more per band when the table is grouped.

Two exceptions, both deliberate: a [card layout](../tables/card-layout.md) always serializes its image and title columns, because a card draws them whatever the arrangement says; and a caller with no arrangement to read — a testing helper, a hand-built widget — still gets every column.

## Writes

Every write runs in a transaction by default, resolved from the action or page, then the panel, then on. A bulk action is **one** transaction for the whole selection. See [Transactions](../actions/transactions.md).

That has a cost worth knowing: the relation endpoint accepts up to 500 records, and a bulk action over 500 rows now holds its locks for the whole run rather than releasing them per record. On a busy table that is contention other requests will feel, and on MySQL a long transaction also grows the undo log. It is the right default — a half-applied bulk operation is worse than a slow one — but for work where each record is genuinely independent, `->databaseTransaction(false)` releases each write as it lands:

```php
Action::make('notify')
    ->databaseTransaction(false)
    ->bulkAction(static fn (Collection $records) => $records->each->notify());
```

The two shapes worth knowing:

- **An import is one transaction per row**, on purpose. A thousand-row file with a bad date in row four hundred imports the other nine hundred and ninety-nine and writes the rest to a failure report.
- **Row reordering is unconditional.** A list that half-reordered would be worse than one that did not move.

## Gotchas

- **A resource that overrides `query()` and forgets `parent::query()`** loses the panel's narrowing, the tenant scope, the nested parent scope *and* `$with` — silently, and the page still works. See [Resource queries](queries.md).
- **`$with` is not a substitute for an index.** Ordering or filtering by an unindexed column is the usual cause of a slow list, and no amount of eager loading touches it.
- **Group summaries cost one query per band on screen.** Group by something with few values. See [Summaries](../tables/summaries.md).
- **A table widget has no `$with`.** Its `query()` is the only place to put one.
- **Deriving an eager load is not free of memory.** A `hasMany` loaded for twenty-five rows holds every related record. It was going to be loaded anyway, one row at a time — this holds them at once. For a relation with thousands of children per record, show an aggregate rather than the relation.

## See also

- [Resource queries](queries.md) — `query()`, `$with`, and what overriding costs
- [Relationship columns](../tables/relationships.md) — aggregates, relation sorting, relation search
- [Summaries](../tables/summaries.md) — what each figure costs
- [Column manager](../tables/column-manager.md) — what hiding a column now saves
- [Transactions](../actions/transactions.md) — which writes are atomic
- [Testing tables](../testing/tables.md) — the query-count test that keeps this honest
