# Sorting

A column becomes sortable by saying so, and the header then toggles the order. Sorting is server-side and URL-driven: the query string holds the column and the direction, and the schema decides which columns those may name. Reach for the sections below when the ordering is not a plain column — a `CASE` over a status, a value on a related record, a default the table should start in.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Posts\Tables;

use PandaPanel\Tables\Columns\DateTimeColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Enums\SortDirection;
use PandaPanel\Tables\TableSchema;

final class PostsTable
{
    public static function configure(TableSchema $table): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('title')->sortable(),
                DateTimeColumn::make('created_at')->label('Published')->sortable(),
            ])
            ->defaultSort('created_at', SortDirection::Descending);
    }
}
```

Both headers are now clickable, and the table opens newest first.

## Declaring a sortable column

```php
use PandaPanel\Tables\Columns\Column;

Column::sortable(bool $sortable = true, ?string $column = null): static
Column::isSortable(): bool
Column::getSortColumn(): string          // $column ?? $name
```

| Argument | Type | Default | Meaning |
| --- | --- | --- | --- |
| `$sortable` | `bool` | `true` | whether the header offers sorting |
| `$column` | `string\|null` | `null` | the database column to order by, when it differs from the attribute name |

```php
use PandaPanel\Tables\Columns\TextColumn;

TextColumn::make('author')->sortable(column: 'author_last_name'),

// Explicitly not sortable, for a column a shared configuration made sortable.
TextColumn::make('summary')->sortable(false),
```

`sortable()` is a declaration, not behaviour: `TableQuery` reads it as a whitelist. `?sort=password` on a column that is not sortable — or is not a column at all — is ignored, and the echoed `state.sort` is `null`, so the header never renders as sorted by something the query did not apply.

## Direction

```php
use PandaPanel\Tables\Enums\SortDirection;

SortDirection::Ascending;                        // 'asc'
SortDirection::Descending;                       // 'desc'
SortDirection::fromRequest(mixed $value): self;  // anything unrecognised → Ascending
SortDirection::opposite(): self;
```

`fromRequest()` lowercases a string and falls back to `Ascending` for anything else, so `?direction=DESC` works and `?direction=nonsense` does not reach the query builder.

## The default order

```php
use PandaPanel\Tables\Enums\SortDirection;
use PandaPanel\Tables\TableSchema;

TableSchema::defaultSort(string $column, SortDirection $direction = SortDirection::Descending): self
TableSchema::defaultSortOptionLabel(string $label): self
TableSchema::getDefaultSortColumn(): ?string
TableSchema::getDefaultSortDirection(): SortDirection
```

```php
$table
    ->defaultSort('created_at', SortDirection::Descending)
    ->defaultSortOptionLabel('Newest first');
```

`defaultSortOptionLabel()` names that ordering for a UI that lists sort options; it is sent as `defaultSort.label` and is `null` when unset.

When the request names no sortable column, the table orders by the default. When no default was declared either, it orders by the model's key with the schema's default direction — which is `SortDirection::Descending` unless `defaultSort()` changed it. An explicit default beats implicit insertion order, which is not stable across databases.

```php
$schema->toArray()['defaultSort'];
// ['column' => 'created_at', 'direction' => 'desc', 'label' => 'Newest first']
// null when no default sort was declared
```

`defaultSort()` is checked against the columns that exist, but at serialization rather than at the setter — `defaultSort()` can legitimately be called before `columns()`, and would then have nothing to check against. A default naming a column the schema does not declare throws `PanelSchemaException::unknownDefaultSort()` listing the columns it does have, because the alternative is a table that quietly falls back to its natural order while the declaration reads as applied.

## Custom ordering

```php
use PandaPanel\Tables\Columns\Column;

Column::sortUsing(Closure(Builder, SortDirection): void $callback): static
Column::hasCustomSort(): bool
Column::applyCustomSort(Builder $query, SortDirection $direction): void
```

For an ordering the schema cannot express as a column name — a `CASE` over a status, a JSON path, a computed distance:

```php
use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Enums\SortDirection;

TextColumn::make('status')->sortUsing(
    static fn (Builder $query, SortDirection $direction) => $query
        ->orderByRaw("field(status, 'urgent', 'open', 'closed') {$direction->value}"),
),

TextColumn::make('attention')
    ->label('Needs attention')
    ->sortUsing(static function (Builder $query, SortDirection $direction): void {
        $query
            ->orderByRaw('email_verified_at is null '.$direction->value)
            ->orderBy('created_at');
    }),
```

`sortUsing()` sets `sortable` to true itself, so there is no need to also call `sortable()`. The closure then takes over ordering entirely: when a column has a custom sort, nothing else is applied for it — not `getSortColumn()`, not a sort relation. The direction it receives has already been validated against the enum, so interpolating `$direction->value` is safe; the rest of the string is yours to keep safe.

## Sorting by a related column

```php
use PandaPanel\Tables\Columns\Column;

Column::sortableByRelation(string $relation, string $column): static
Column::getSortRelation(): ?string
```

```php
use PandaPanel\Tables\Columns\TextColumn;

TextColumn::make('author_name')->label('Author')->sortableByRelation('author', 'name'),
```

This makes the column sortable and orders by a correlated subquery rather than a join: a join against a to-many relation multiplies rows and quietly breaks both the page size and the total. An aggregate column needs none of this — `posts_count` is a real column of the result set, so ordering it is an ordinary `ORDER BY`. See [Relationship columns](relationships.md).

## How the order is assembled

`TableQuery::applySort()` runs in a fixed order, and only one strategy applies to the sorted column:

1. The active [group](grouping.md), if any, sorts first — rows have to arrive together to be shown together.
2. If the requested column has a custom sort, `applyCustomSort()` runs and nothing else does.
3. Otherwise, if it declared a sort relation, `applyRelationshipSort()` runs and nothing else does.
4. Otherwise `orderBy(getSortColumn(), $direction)`.
5. If no sortable column was requested, `orderBy($defaultSortColumn ?? $model->getKeyName(), $defaultSortDirection)`.

## What travels in the URL

```text
?sort=created_at&direction=desc
```

A relation table's state is namespaced so several tables on one record page can sort independently:

```text
?relations[posts][sort]=title&relations[posts][direction]=asc
```

The applied values are echoed back in `state`:

```php
use PandaPanel\Tables\TableQuery;

$state = (new TableQuery($schema, $request))->state();

$state['sort'];        // 'created_at', or null when the request named nothing sortable
$state['direction'];   // 'asc' | 'desc'
```

## Remembering the order

```php
use PandaPanel\Tables\TableSchema;

TableSchema::persistSortInSession(bool $persist = true): self   // off by default
TableSchema::persistsSortInSession(): bool
```

This remembers `sort`, `direction`, **and** the active group — the three are one decision about how the table is arranged. The request wins whenever it says anything, including that a value is now empty, and a remembered column goes through the same whitelist a fresh one does, so a stale session naming a column the table no longer has is ignored exactly as a hand-typed one would be. See [Persisted state](persisted-state.md).

## Sorting an array table

`PandaPanel\Tables\ArrayTableData` sorts in PHP with `Collection::sortBy()` over `Column::getSortColumn()`, applying the same whitelist: an unknown or non-sortable column is ignored. `sortUsing()` and `sortableByRelation()` are query strategies and do not apply there.

```php
use PandaPanel\Tables\ArrayTableData;

$data = ArrayTableData::make($schema, $records, $request);

$data->sortableColumns();   // list<Column> this data source can honour
```

See [Array data tables](array-data.md).

## Notes

- **Reordering fixes the sort.** `TableSchema::reorderable('position')` also calls `defaultSort('position', SortDirection::Ascending)`: an order the user arranged only means something while the table is showing it. See [Reordering](reordering.md).
- **A group sorts before everything else.** With an active group the table is ordered by the group column first and the chosen column second, which is what keeps a band together. See [Grouping](grouping.md).
- **`sortable()` after an aggregate resets the sort column.** `sortable(bool, ?string $column = null)` assigns `$column` unconditionally, so `->counts('posts')->sortable()` sorts by the column's own name. Name the column `posts_count`, or pass `sortable(column: 'posts_count')`.
- **A dotted column name is left alone.** `TextColumn::make('author.name')->sortable()` would order by a column that does not exist locally; use `sortableByRelation()`.
- **Summaries never leave an `order by` behind.** They clone the query and call `reorder()` before aggregating, so a summary cannot disturb the ordering the table just paginated. See [Summaries](summaries.md).
- **Ordering by a column with no index is the usual cause of a slow list.** The framework will happily order by anything the schema declares; whether the database can is a question for the database.

## See also

- [Search](search.md)
- [Grouping](grouping.md)
- [Reordering](reordering.md)
- [Relationship columns](relationships.md)
- [Persisted state](persisted-state.md)
- [Pagination](pagination.md)
- [Summaries](summaries.md)
- [Columns](columns.md)
- [Tables overview](overview.md)
