# Relationship Columns

A table column can read a related record instead of a column of its own table: how many posts an author has, whether an order has any refunds, the name on a linked profile. Reach for this when the value belongs to another table but the row it describes is this one.

Three separate things live here, kept apart because the query needs different work from each: **aggregates** are computed in the select, **sorting** by a related column uses a correlated subquery, and **searching** a relation is a `whereHas`. They are all on the base `Column`, so every column type has them.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Authors\Tables;

use PandaPanel\Tables\Columns\NumberColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

final class AuthorsTable
{
    public static function configure(TableSchema $table): TableSchema
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),

            // Computed in the select: one query for the whole page.
            NumberColumn::make('posts_count')->counts('posts')->sortable(),
        ]);
    }
}
```

## Aggregates

```php
use PandaPanel\Tables\Columns\Column;

Column::counts(string $relation): static
Column::exists(string $relation): static
Column::sum(string $relation, string $column): static
Column::avg(string $relation, string $column): static
Column::min(string $relation, string $column): static
Column::max(string $relation, string $column): static
```

```php
use PandaPanel\Tables\Columns\BooleanColumn;
use PandaPanel\Tables\Columns\NumberColumn;

NumberColumn::make('posts_count')->counts('posts'),
BooleanColumn::make('refunds_exists')->exists('refunds'),
NumberColumn::make('orders_sum_total')->sum('orders', 'total')->prefix('$')->decimals(2),
NumberColumn::make('orders_avg_total')->avg('orders', 'total')->decimals(2),
NumberColumn::make('orders_min_total')->min('orders', 'total'),
NumberColumn::make('orders_max_total')->max('orders', 'total'),
```

`TableSchema::applyColumnQueries()` runs before the paginator and lets every column shape the query, so the aggregate is asked for once for the whole page whatever the row count. Reading it per record would be a query per record — the thing eager loading exists to prevent.

Each case maps to one Eloquent call and to the attribute Eloquent generates for it:

| Method | `PandaPanel\Tables\Enums\RelationshipAggregate` | Eloquent call | Generated attribute |
| --- | --- | --- | --- |
| `counts('posts')` | `Count` | `withCount('posts')` | `posts_count` |
| `exists('posts')` | `Exists` | `withExists('posts')` | `posts_exists` |
| `sum('orders', 'total')` | `Sum` | `withAggregate('orders', 'total', 'sum')` | `orders_sum_total` |
| `avg('orders', 'total')` | `Avg` | `withAggregate(..., 'avg')` | `orders_avg_total` |
| `min('orders', 'total')` | `Min` | `withAggregate(..., 'min')` | `orders_min_total` |
| `max('orders', 'total')` | `Max` | `withAggregate(..., 'max')` | `orders_max_total` |

A dotted relation path has its dots replaced with underscores in the attribute name, exactly as Eloquent does it: `counts('posts.comments')` lands on `posts_comments_count`.

The cell reads that generated attribute, not the column's own name:

```php
use PandaPanel\Tables\Columns\Column;

Column::aggregateAttribute(): ?string        // 'posts_count', or null for a plain column
Column::getAggregateRelation(): ?string      // 'posts'
Column::applyQuery(Builder $query): void     // called by TableSchema::applyColumnQueries()
Column::summaryUsesAggregate(): bool
```

```php
use PandaPanel\Tables\Columns\NumberColumn;

// The column may be called anything; it still reads `posts_count`.
NumberColumn::make('published')->label('Posts')->counts('posts')->aggregateAttribute(); // 'posts_count'
```

### Sorting an aggregate

The generated attribute is a real column of the result set, so ordering by it is an ordinary `ORDER BY` and needs no special strategy. Calling an aggregate method sets the sort column to that attribute for you:

```php
use PandaPanel\Tables\Columns\NumberColumn;

// Name matches the generated attribute — the common case, and the safe one.
NumberColumn::make('posts_count')->counts('posts')->sortable(),
```

`sortable(bool $sortable = true, ?string $column = null)` assigns `$column` unconditionally, so calling it *after* an aggregate with no argument clears the sort column back to the column's own name. When the two differ, say so:

```php
use PandaPanel\Tables\Columns\NumberColumn;

NumberColumn::make('published')
    ->label('Posts')
    ->counts('posts')
    ->sortable(column: 'posts_count'),
```

### Summarizing an aggregate

```php
use PandaPanel\Tables\Columns\NumberColumn;
use PandaPanel\Tables\Summaries\Average;
use PandaPanel\Tables\Summaries\Sum;

NumberColumn::make('posts_count')
    ->counts('posts')
    ->summarize([Sum::make()->label('Total'), Average::make()]),
```

`Column::summaryColumn()` returns the aggregate attribute when there is one, so the figure sums what the query actually selected. Because `posts_count` exists only in the SELECT list, the summary is computed *outside* a subquery that produced it rather than as `sum(posts_count)` against the table, which would be an unknown column. See [Summaries](summaries.md).

## Sorting by a related column

```php
use PandaPanel\Tables\Columns\Column;

Column::sortableByRelation(string $relation, string $column): static
Column::getSortRelation(): ?string
Column::applyRelationshipSort(Builder $query, SortDirection $direction): void
```

```php
use PandaPanel\Tables\Columns\TextColumn;

TextColumn::make('author_name')
    ->label('Author')
    ->sortableByRelation('author', 'name'),
```

This makes the column sortable and takes over the ordering. The generated order is a correlated subquery:

```sql
order by (select name from profiles where profiles.author_id = authors.id limit 1) asc
```

Never a join: a join against a to-many relation multiplies rows and quietly breaks both the page size and the total. A test asserts exactly that — sorting two projects by their `hasOne` brief still reports a total of two.

The subquery compares the relation's qualified foreign key with the qualified parent key, which is the shape of a `HasOne`, `HasMany`, and their morph variants — the foreign key lives on the related table. A `BelongsTo` keeps the foreign key on *this* table, so the comparison would be between two columns of the same table; order those with [`sortUsing()`](sorting.md) instead.

## Searching a relation

```php
use PandaPanel\Tables\Columns\TextColumn;

TextColumn::make('author.name')->label('Author')->searchable(),
```

A searchable name containing a dot is routed to `whereHas` rather than to a `LIKE`: `author.name` is not a column of this table, and matching it as one is a SQL error rather than an empty result. The last dot separates the relation path from the column, so `author.profile.city` searches the `author.profile` relation for `city`.

`TableSchema` keeps the two kinds apart:

```php
use PandaPanel\Tables\TableSchema;

TableSchema::getSearchColumns(): array     // local names only
TableSchema::getSearchRelations(): array   // the dotted ones
TableSchema::isSearchable(): bool          // true when either is non-empty
```

```php
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

$schema = TableSchema::make()->columns([
    TextColumn::make('name')->searchable(),
    TextColumn::make('tasks.name')->searchable(),
]);

$schema->getSearchColumns();    // ['name']
$schema->getSearchRelations();  // ['tasks.name']
```

A column can also point at several places at once, mixing local and related:

```php
use PandaPanel\Tables\Columns\TextColumn;

TextColumn::make('name')->searchable(columns: ['first_name', 'last_name', 'company.name']),
```

Each word of the term is matched inside its own `where(...)` group, so a relation search never widens a filter that was already applied. See [Search](search.md).

## Reading a related value in a cell

`Column::resolveValue()` reads the attribute with `data_get()`, so dot notation works for display as well as for search:

```php
use Illuminate\Support\Collection;
use PandaPanel\Tables\Columns\TextColumn;

TextColumn::make('author.name')->label('Author')->placeholder('Unassigned'),

// A to-many path gives a collection of values, so format it into a string.
TextColumn::make('tags.name')
    ->label('Tags')
    ->placeholder('None')
    ->formatUsing(static fn (mixed $value): ?string => $value instanceof Collection && $value->isNotEmpty()
        ? $value->implode(', ')
        : null),
```

Reading a relation this way loads it, which would be a query per row. **A relation a column names is eager loaded for you**: the table reads the dotted name, verifies each segment really is a relation, and loads it for the page. `TextColumn::make('author.name')` loads `author`; a name it cannot verify — a JSON column addressed as `meta.total` — is left alone.

`Resource::$with` is still the answer for a relation with no name to read it from:

```php
/**
 * @var list<string>
 */
protected static array $with = ['author', 'tags'];
```

Reach for it when the relation is read by a `formatUsing()` or `urlUsing()` closure, by `recordTitle()`, or by a policy — none of which the derivation can see. See [Query performance](../resources/performance.md).

An aggregate needs neither — that is the point of computing it in the select.

## Notes

- **Three tools, three jobs.** An aggregate answers "how many"; `sortableByRelation()` answers "in whose order"; a dotted `searchable()` answers "does the related record match". Using one for another's job is where the surprising queries come from.
- **The aggregate attribute is derived with Eloquent's own rule**, so the column reads exactly what the query wrote. Name the column after it and everything — cell, sort, summary — lines up without a second declaration.
- **`->sortable()` after an aggregate resets the sort column.** Pass the attribute explicitly, or call `sortable()` before the aggregate method.
- **`exists()` is cheaper than `counts()`** when the number is not what is being shown; the cell is a boolean.
- **A dotted searchable name is not a sortable one.** `Column::getSortColumn()` leaves a dotted name alone rather than sorting by a column that does not exist; use `sortableByRelation()`.
- **Filters and the query builder do not traverse relations.** A `QueryBuilderFilter` constraint names a column of the queried table. Narrow a relation with a `FormFilter` and a `whereHas` closure. See [Query builder](query-builder.md).
- **Relation *managers* are a different feature.** A relationship column shows related data inside a resource table; a relation manager gives a record's related records a table of their own. See [Relation tables](../relations/relation-tables.md).

## See also

- [Columns](columns.md)
- [Search](search.md)
- [Sorting](sorting.md)
- [Summaries](summaries.md)
- [Query builder](query-builder.md)
- [Resource queries](../resources/queries.md)
- [Relation tables](../relations/relation-tables.md)
- [Tables overview](overview.md)
