# Search

A table search box matches a term against the columns that declared themselves searchable. Reach for it whenever a list is long enough that scrolling is not how somebody finds a row. Sorting and filtering answer "which of these, in what order"; search answers "where is this one".

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Users\Tables;

use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

final class UsersTable
{
    public static function configure(TableSchema $table): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('email')->searchable(),
            ])
            ->searchPlaceholder('Search by name or email...');
    }
}
```

The box appears because at least one column is searchable. Typing writes `?search=` into the URL, and the server matches the term against `name` and `email`.

## Declaring a searchable column

```php
use PandaPanel\Tables\Columns\Column;

Column::searchable(bool $searchable = true, ?array $columns = null, bool $individually = false): static
Column::isSearchable(): bool
Column::isIndividuallySearchable(): bool
Column::getSearchColumns(): array          // list<string>; [$name] unless $columns was given
```

| Argument | Type | Default | Meaning |
| --- | --- | --- | --- |
| `$searchable` | `bool` | `true` | whether the term is matched against this column at all |
| `$columns` | `list<string>\|null` | `null` | the database columns to match, when they differ from the attribute name |
| `$individually` | `bool` | `false` | also give this column its own box in a second header row |

```php
use PandaPanel\Tables\Columns\TextColumn;

// The displayed value comes from `name`; the term is matched against two columns.
TextColumn::make('name')->searchable(columns: ['first_name', 'last_name']),

// Searchable and given its own box.
TextColumn::make('reference')->searchable(individually: true),

// Explicitly not searchable, for a column a shared configuration made searchable.
TextColumn::make('notes')->searchable(false),
```

`searchable()` is a declaration, not behaviour. The query layer reads it as a whitelist, which is what keeps `?search=` from reaching a column the table never offered.

## What the table does with the term

```php
use PandaPanel\Tables\TableSchema;

TableSchema::isSearchable(): bool           // true when any column declared itself searchable
TableSchema::getSearchColumns(): array      // the local names, deduplicated
TableSchema::getSearchRelations(): array    // the dotted ones
```

The term is trimmed, truncated to 255 characters, and treated as absent when it is empty. Then, for each word, one `where(...)` group is added:

```sql
where (name like ? or email like ?)
```

Always inside a group, so an `orWhere` can never widen a constraint a filter already applied. The LIKE wildcards `\`, `%`, and `_` are escaped in the term, so `?search=%` matches a literal percent sign instead of scanning the table.

## Term splitting

```php
use PandaPanel\Tables\TableSchema;

TableSchema::splitSearchTerms(bool $split = true): self     // on by default
TableSchema::shouldSplitSearchTerms(): bool
```

On by default: each word of a multi-word term must match somewhere, so "ada lovelace" finds the record whose first name is in one column and surname in another. Every word gets its own group and the groups are ANDed, which means two words narrow rather than widen.

```php
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

// Split (default): "Apollo Lunar" matches the project named Apollo with a task named Lunar.
TableSchema::make()->columns([
    TextColumn::make('name')->searchable(),
    TextColumn::make('tasks.name')->searchable(),
]);

// Off: the phrase is the value. "Lovelace Ada" then finds nothing.
TableSchema::make()
    ->columns([TextColumn::make('reference')->searchable()])
    ->splitSearchTerms(false);
```

Turn it off where the phrase *is* the value — a reference, a serial, an address.

## Individual column search

A column that declared `individually: true` gets its own box in a second header row, ANDed with everything else: it answers "of these rows, which have this in that column".

```php
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

TableSchema::make()->columns([
    TextColumn::make('name')->searchable(individually: true),
    TextColumn::make('email')->searchable(),
]);
```

The state travels as its own parameter:

```text
?search=Apollo&columnSearch[name]=Two
```

Only a column that declared its own box can be searched this way. A request naming any other column searches nothing, and the echoed state omits it:

```php
use PandaPanel\Tables\TableSchema;

TableSchema::getIndividuallySearchableColumns(): array          // list<Column>
TableSchema::getIndividuallySearchableColumn(string $name): ?Column
```

```php
use PandaPanel\Tables\TableQuery;

$state = (new TableQuery($schema, $request))->state();

$state['columnSearches'];   // ['name' => 'Apo'] — the invented and blank ones are gone
```

Each per-column term is trimmed, truncated to 255 characters, and split by the same rule the table-wide term is.

## Searching a relation

A searchable name containing a dot is matched with `orWhereHas` rather than a `LIKE` — it is not a column of this table, and matching it as one would be a SQL error rather than an empty result.

```php
use PandaPanel\Tables\Columns\TextColumn;

TextColumn::make('author.name')->label('Author')->searchable(),
```

The last dot separates the relation path from the column, so `author.profile.city` searches `author.profile` for `city`. A table whose only searchable columns are relations still reports `searchable: true`. See [Relationship columns](relationships.md).

## When the request is sent

How expensive a search is, is the server's knowledge, so when to ask is the server's decision.

```php
use PandaPanel\Tables\TableSchema;

TableSchema::searchDebounce(int $milliseconds): self    // default 300, floored at 0
TableSchema::searchOnBlur(bool $onBlur = true): self    // default false
TableSchema::searchPlaceholder(string $placeholder): self
```

```php
$table
    ->searchDebounce(750)   // a table over a large join wants the user to finish typing
    ->searchOnBlur()        // ask when the field loses focus, not while typing
    ->searchPlaceholder('Search by name, email, or reference...');
```

A negative debounce is clamped to `0`, which means ask immediately — only sensible for a table small enough that it does not matter. The placeholder defaults to `Search...`.

These three, plus `searchable` and `individualSearchColumns`, are what the definition carries:

```php
$schema->toArray();
// [
//     'searchable' => true,
//     'searchPlaceholder' => 'Search...',
//     'searchDebounce' => 300,
//     'searchOnBlur' => false,
//     'individualSearchColumns' => ['name'],
//     ...
// ]
```

## Remembering the term

```php
use PandaPanel\Tables\TableSchema;

TableSchema::persistSearchInSession(bool $persist = true): self   // off by default
TableSchema::persistsSearchInSession(): bool
```

Off by default: returning to a table and finding it filtered by something typed yesterday is surprising unless it is a table somebody works in rather than passes through.

Two rules make it safe to restore. The request wins whenever it says anything at all, *including* that the value is now empty — otherwise clearing a search would be undone by the thing that remembered it. And a remembered value goes through the same validation a fresh one does. The session key is built from the panel id and the resource slug, never from anything in the request, and a table rendered without session middleware remembers nothing rather than failing. See [Persisted state](persisted-state.md).

## Searching an array table

`PandaPanel\Tables\ArrayTableData` applies the same whitelist over records that are not in the database, in PHP:

```php
use Illuminate\Http\Request;
use PandaPanel\Tables\ArrayTableData;

$data = ArrayTableData::make($schema, $records, $request);
$page = $data->paginate();

return ['rows' => $data->rows($page), 'state' => $data->state()];
```

Matching is a case-insensitive substring test against `TableSchema::getSearchColumns()`. Three differences from the query layer, all of them deliberate: relations are not searched, the term is not split, and there is no per-column search. See [Array data tables](array-data.md).

## Notes

- **The schema is the whitelist.** `?search=` reaches only the declared columns, and `?columnSearch[password]=` reaches nothing. `state` echoes what the server *applied*, not what was requested.
- **A per-column term narrows the table-wide one**, never widens it. Searching "Apollo" and then "Two" in the name column leaves only rows matching both.
- **Search never narrows a record lookup.** It lives in `TableQuery::paginate()`, not in `Resource::query()`, so a record the search hides is still openable by URL.
- **`LIKE` is not full-text search.** Every term becomes `%term%`, which cannot use a plain B-tree index. On a large table, reach for a database index built for it or a search service rather than raising the debounce.
- **A relation search costs a subquery per searchable relation per word.** Splitting a three-word term across two relation columns is six `whereHas` clauses; that is the cost of the convenience, and it is worth measuring before making a wide table searchable everywhere.
- **`searchable()` on a column whose value is computed does nothing useful.** The term is matched against a database column of that name; a column whose value only exists after `formatUsing()` has nothing to match. Point it at real columns with the `columns:` argument.

## See also

- [Sorting](sorting.md)
- [Filters](filters.md)
- [Query builder](query-builder.md)
- [Relationship columns](relationships.md)
- [Persisted state](persisted-state.md)
- [Array data tables](array-data.md)
- [Columns](columns.md)
- [Global search](../resources/global-search.md)
- [Tables overview](overview.md)
