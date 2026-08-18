# Persisted Table State

Table state lives in the URL: page, page size, search, sort, filters, column arrangement, and the active group. That is what makes back, forward, refresh, bookmark, and "send me that link" all behave. On top of that, a table can opt into remembering some of it in the session, so returning to the list finds it the way it was left.

You reach for persistence on a table somebody *works in* — a queue, a moderation list, a ledger. Leave it off for a table people pass through: coming back and finding it filtered by something typed yesterday is surprising.

## A minimal persisted table

```php
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Enums\SortDirection;
use PandaPanel\Tables\TableSchema;

return $table
    ->columns([
        TextColumn::make('reference')->searchable()->sortable(),
        TextColumn::make('status')->sortable(),
    ])
    ->defaultSort('created_at', SortDirection::Descending)
    ->persistSearchInSession()
    ->persistSortInSession()
    ->persistFiltersInSession()
    ->persistColumnsInSession();
```

All four are off by default and independent of one another.

## What each method covers

| Method | Remembers | Read-side |
| --- | --- | --- |
| `persistSearchInSession(bool $persist = true)` | `search` | `persistsSearchInSession(): bool` |
| `persistSortInSession(bool $persist = true)` | `sort`, `direction`, `group` | `persistsSortInSession(): bool` |
| `persistFiltersInSession(bool $persist = true)` | the whole `filters` map | `persistsFiltersInSession(): bool` |
| `persistColumnsInSession(bool $persist = true)` | `columns.visible`, `columns.order`, and `layout` | `persistsColumnsInSession(): bool` |

`group` rides with sort because the two are one decision about how rows are arranged.

**Nothing else is remembered.** `page`, `perPage`, and the per-column search boxes are read from the request every time. A remembered page number would mean opening a list on page seven of a result that has since changed, and a remembered page size is a preference the size control already expresses.

## The whole state map

| Query parameter | Persisted by | Validated against |
| --- | --- | --- |
| `page` | — | `max(1, …)` |
| `perPage` | — | `perPageOptions()` |
| `search` | `persistSearchInSession()` | trimmed, truncated to 255 characters |
| `columnSearch[{column}]` | — | columns declaring `searchable(individually: true)` |
| `sort` | `persistSortInSession()` | columns declaring `sortable()` |
| `direction` | `persistSortInSession()` | `SortDirection`, unknown falls back to `asc` |
| `group` | `persistSortInSession()` | declared groups |
| `filters[{name}]` | `persistFiltersInSession()` | each filter's own `sanitize()` |
| `columns[visible][]`, `columns[order][]` | `persistColumnsInSession()` | declared and toggleable column names |
| `layout` | `persistColumnsInSession()` | the layouts the table offers |

## The two rules that make restoring safe

**The request wins whenever it says anything at all — including that a value is now empty.** Absence is the only case that falls back to what was stored. Without that rule, clearing a search would be undone by the thing that remembered it.

```
?search=orders     store "orders", apply "orders"
?search=           store "", apply nothing
(no search key)    apply what was stored
```

A query string cannot hold an empty array, so `?filters=` and `?columns=` are how a URL says "filters, and there are none" and "the declared arrangement". The frontend writes those sentinels after any mutation of the respective map.

**A remembered value goes through the same validation a fresh one does.** A session naming a sort column the table no longer has is ignored exactly as a hand-typed one would be, so narrowing a schema can never resurrect a column through somebody's session.

## The session key

Built by the page, never from anything in the request:

| Table | Key |
| --- | --- |
| resource index | `panel.{panel id}.table.{resource slug}` |
| relation manager | `panel.{panel id}.table.{resource slug}.{manager key}` |

Each value is stored one level below it — `panel.admin.table.users.search`, `panel.admin.table.users.filters`, and so on.

A key a caller could influence would let one table read another's remembered state, which is why the request is not part of it. Two tables therefore never share remembered state, and the same table in two panels keeps two sets.

```php
use PandaPanel\Tables\TableQuery;

$tableQuery = new TableQuery(
    $schema,
    $request,
    namespace: null,
    sessionKey: sprintf('panel.%s.table.%s', $panel->getId(), UserResource::slug()),
);
```

Passing `sessionKey: null` turns persistence off for that instance whatever the schema declares. `TableWidget` does exactly that: a dashboard table takes a namespace but no session key.

## No session, no problem

A table can be rendered outside the web stack — a console command, an export job rebuilding the list query, a test that never touched the session. `TableQuery` checks `Request::hasSession()` before reading or writing, so such a table remembers nothing rather than failing.

## Namespacing versus persistence

They answer different questions and combine freely.

- **Namespace** — *where in the query string this table's state lives*, so several tables on one page do not fight over `page`. `relations.tasks`, `widgets.recent-orders`.
- **Session key** — *where remembered state is stored*, per user.

```php
new TableQuery($schema, $request, 'relations.tasks', 'panel.admin.table.projects.tasks');
// reads ?relations[tasks][sort]=title, remembers under panel.admin.table.projects.tasks.sort
```

## Testing

The session is the thing under test, so share one store across requests and build a fresh `Request` each time — `request()` is a singleton, and mutating its query bag would leak one call's parameters into the next.

```php
use Illuminate\Http\Request;
use PandaPanel\Tables\TableQuery;

function state(TableSchema $schema, array $query, string $key): array
{
    $request = Request::create('/', 'GET', $query);

    $request->setLaravelSession(app('session.store'));

    return (new TableQuery($schema, $request, null, $key))->state();
}

$schema = $schema->persistSearchInSession();

state($schema, ['search' => 'Apollo'], 'table.projects');

// A second visit carrying nothing gets what the first one asked for.
expect(state($schema, [], 'table.projects')['search'])->toBe('Apollo');

// And an explicit empty value clears it.
expect(state($schema, ['search' => ''], 'table.projects')['search'])->toBeNull();
```

For the sentinel cases, build the request from a real query *string*: `['filters' => []]` is a thing a test can write and a URL cannot.

```php
$request = Request::create('/?filters=', 'GET');
```

## Gotchas

- **Persistence is per user, not per session cookie value you control.** It is ordinary session storage; clearing the session clears it.
- **A default filter and a remembered filter interact.** A default only fills genuine silence. Once the session records that the user has been here and chosen — including choosing to clear everything — the default no longer applies. See [Filters](filters.md).
- **`persistSortInSession()` also persists the group.** There is no separate switch, because ungrouping and re-sorting are the same kind of decision.
- **`persistColumnsInSession()` also persists the [card layout](card-layout.md).** Same reasoning in the other direction: how a list is drawn and which columns are drawn are one decision, and both are presentation rather than a question about the data.
- **Per-column search boxes are never remembered.** They narrow an already-narrowed view, and restoring one silently would make an empty table hard to explain.
- **A stale session is ignored, not repaired.** Narrowing a schema does not need a migration; the values that no longer validate stop applying.
- **The URL still wins over everything.** Anything the request states is applied and stored; persistence only fills silence.

## See also

- [TableSchema basics](overview.md)
- [Filters](filters.md)
- [Search](search.md) and [Sorting](sorting.md)
- [Column manager](column-manager.md)
- [Pagination](pagination.md)
- [Grouping](grouping.md)
- [Relation tables](../relations/relation-tables.md)
- [Table API reference](api.md)
