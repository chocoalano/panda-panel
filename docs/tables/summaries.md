# Summaries

A summary is a figure under a column: a total, an average, a count, a range. Reach for one when the column is a number somebody adds up in their head. Summaries are computed by the database over the **filtered** query, not by adding up the rows on screen — a total that changed when you paged would be a different number wearing the same label.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Orders\Tables;

use PandaPanel\Tables\Columns\NumberColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Summaries\Average;
use PandaPanel\Tables\Summaries\Sum;
use PandaPanel\Tables\TableSchema;

final class OrdersTable
{
    public static function configure(TableSchema $table): TableSchema
    {
        return $table->columns([
            TextColumn::make('reference')->searchable(),

            NumberColumn::make('total')
                ->prefix('$')
                ->decimals(2)
                ->summarize([Sum::make(), Average::make()]),
        ]);
    }
}
```

The table now renders two footer rows under the `total` column: the sum and the average of every order the current search and filters left.

## Declaring them

```php
use PandaPanel\Tables\Columns\Column;
use PandaPanel\Tables\Summaries\Summarizer;

Column::summarize(array $summarizers): static     // array<array-key, Summarizer>
Column::getSummarizers(): array                   // list<Summarizer>
Column::hasSummaries(): bool
Column::summaryColumn(): string                   // aggregateAttribute() ?? getSortColumn()
Column::summaryUsesAggregate(): bool
```

```php
use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Tables\Group;
use PandaPanel\Tables\TableSchema;

TableSchema::hasSummaries(): bool
TableSchema::summaries(Builder $query, array $records): array
TableSchema::groupSummaries(Builder $query, array $records, Group $group): array
```

A table that declares none pays nothing: `hasSummaries()` is `false`, the page ships `summaries: []`, and no aggregate query runs.

## The four summarizers

| Class | `aggregate()` | Reduces to | Default name |
| --- | --- | --- | --- |
| `PandaPanel\Tables\Summaries\Sum` | `sum` | `float\|int` | `sum` |
| `PandaPanel\Tables\Summaries\Average` | `avg` | `?float` | `average` |
| `PandaPanel\Tables\Summaries\Count` | `count` | `int` | `count` |
| `PandaPanel\Tables\Summaries\Range` | `null` | `array{min, max}\|null` | `range` |

`Range` needs two aggregates rather than one, so it does not fit the single-aggregate shape and computes both itself; its `format()` renders `min – max`, or the single value when they are equal, or `—` when there is nothing.

```php
use PandaPanel\Tables\Columns\DateTimeColumn;
use PandaPanel\Tables\Columns\NumberColumn;
use PandaPanel\Tables\Summaries\Average;
use PandaPanel\Tables\Summaries\Count;
use PandaPanel\Tables\Summaries\Range;
use PandaPanel\Tables\Summaries\Sum;

NumberColumn::make('total')->summarize([
    Sum::make(),
    Average::make(),
    Count::make(),
    Range::make(),
]),

DateTimeColumn::make('created_at')->summarize([Range::make()->label('Between')]),
```

## The `Summarizer` API

```php
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use PandaPanel\Tables\Summaries\Summarizer;

Summarizer::make(string $name = ''): static
Summarizer::label(string $label): static
Summarizer::formatUsing(Closure(mixed): string $callback): static
Summarizer::perPage(bool $perPage = true): static
Summarizer::isPerPage(): bool
Summarizer::getName(): string
Summarizer::getLabel(): string
Summarizer::aggregate(): ?string
Summarizer::summarize(QueryBuilder $query, string $column): mixed
Summarizer::summarizeRecords(array $records, string $column): mixed
Summarizer::format(mixed $value): string
Summarizer::toArray(mixed $value): array
```

| Method | Default | Notes |
| --- | --- | --- |
| `make($name)` | `''` | an empty name falls back to the lowercased class basename — `sum`, `average`, `count`, `range` |
| `label($label)` | `Str::headline(getName())` | the caption beside the figure |
| `formatUsing($callback)` | none | replaces the default formatting entirely |
| `perPage($perPage)` | `false` | describes the page rather than the whole result |

```php
use PandaPanel\Tables\Summaries\Sum;

Sum::make()->label('Total revenue')
    ->formatUsing(static fn (mixed $value): string => '$'.number_format((float) $value, 2)),

// Two summarizers of the same class on one column need distinct names: the
// name identifies the figure in the payload, and it defaults to the class.
Sum::make('sum_eur')->label('Total (EUR)')
    ->formatUsing(static fn (mixed $value): string => number_format((float) $value * 0.92, 2).' €'),
```

### Default formatting

`format()` is applied on the server, so what crosses the wire is finished text plus the raw figure for anything that wants it:

| Value | Rendered |
| --- | --- |
| `null` | `—` |
| `float` | `number_format($v, 2)` with trailing zeros and a trailing dot trimmed |
| `int` | `number_format($v)` |
| other scalar | cast to string |
| anything else | `—` |

### What one figure serializes to

```php
[
    'name' => 'sum',
    'label' => 'Total revenue',
    'value' => '$12,480.00',   // format()
    'raw' => 12480.0,          // the scalar, or null when it is not one
    'perPage' => false,
]
```

`TableSchema::summaries()` returns those keyed by column name, each column holding a list in the order the summarizers were declared:

```php
[
    'total' => [
        ['name' => 'sum', 'label' => 'Sum', 'value' => '12,480', 'raw' => 12480, 'perPage' => false],
        ['name' => 'average', 'label' => 'Average', 'value' => '124.8', 'raw' => 124.8, 'perPage' => false],
    ],
]
```

## Whole result or this page

Off by default, because "the total" almost always means the total, and a number that silently meant "of these twenty rows" would be the more surprising of the two.

```php
use PandaPanel\Tables\Summaries\Count;
use PandaPanel\Tables\Summaries\Sum;

Sum::make()->label('All orders'),                  // the filtered result
Count::make()->label('On this page')->perPage(),   // the records on screen
```

| | Computed by | Reads |
| --- | --- | --- |
| default | the database, over the filtered query | every row the search and filters left |
| `perPage()` | PHP, over the records passed in | only the rows on this page |

`summarizeRecords()` reads each record with `data_get()`, drops the nulls, and reduces what is left — `Count` counts the non-null values, `Sum` casts each to float and adds them, `Average` divides, `Range` takes `min` and `max`.

## How the whole-result figure is computed

`TableSchema::summarySource()` prepares the query once per column, and only when something actually needs it — a per-page figure never touches the database.

```php
$base = $query->clone()->reorder()->toBase();
$base->limit = null;
$base->offset = null;
```

Cloned and reordered, so a summary cannot leave an `order by` on the builder the table just paginated. The limit and offset are cleared because `paginate()` sets them on the builder it was given, and a summary computed from it afterwards would describe one page while claiming to describe the result.

Then one of two shapes, and confusing them is a SQL error rather than a wrong number:

- **A plain column** is aggregated straight from the table: `sum(total)`.
- **A generated alias** — `posts_count` from `withCount()` — exists only in the SELECT list, so `sum(posts_count)` is an unknown column. The base query is wrapped in a subquery (`from (…) as panel_summary_source`) and the aggregate runs outside it.

The wrapping is not unconditional because it is not free: a subquery around a select carrying correlated count columns computes every one of them for every row, which is exactly the cost a plain `sum(total)` should not pay. `Column::summaryUsesAggregate()` is what decides.

```php
use PandaPanel\Tables\Columns\NumberColumn;
use PandaPanel\Tables\Summaries\Average;
use PandaPanel\Tables\Summaries\Sum;

// Wrapped: `passkeys_count` is an alias, not a column.
NumberColumn::make('passkeys_count')
    ->counts('passkeys')
    ->summarize([Sum::make()->label('Total'), Average::make()]),
```

`Column::summaryColumn()` returns the aggregate attribute when the column has one and `getSortColumn()` otherwise, so a summary always aggregates what the query actually selected. See [Relationship columns](relationships.md).

## Group summaries

When the table is grouped, each band gets its own figures, computed over the **whole** band rather than the rows of it on this page.

```php
use PandaPanel\Tables\Columns\NumberColumn;
use PandaPanel\Tables\Group;
use PandaPanel\Tables\Summaries\Count;
use PandaPanel\Tables\TableSchema;

TableSchema::make()
    ->columns([
        NumberColumn::make('total')->summarize([Count::make()]),
    ])
    ->groups([Group::make('status')])
    ->defaultGroup('status');
```

`groupSummaries()` buckets the page's records by `Group::keyFor()`, then re-runs `summaries()` for each band with `where(groupColumn, '=', key)` added. That is one query per band on screen, which is a handful. They render under the band they describe, the way a column of numbers reads. A `perPage()` summarizer still reduces from the records shown.

The result is keyed by band and then by column:

```php
[
    '3' => ['total' => [['name' => 'count', 'label' => 'Count', 'value' => '3', 'raw' => 3, 'perPage' => false]]],
    '7' => ['total' => [['name' => 'count', 'label' => 'Count', 'value' => '1', 'raw' => 1, 'perPage' => false]]],
]
```

A table with no summarizers returns `[]` here whatever the grouping. See [Grouping](grouping.md).

## Writing your own summarizer

Two abstract members: the SQL aggregate name, and the PHP equivalent used for `perPage()`.

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Tables\Summaries;

use Illuminate\Database\Query\Builder as QueryBuilder;
use PandaPanel\Tables\Summaries\Summarizer;

final class DistinctCount extends Summarizer
{
    public function aggregate(): ?string
    {
        return null;
    }

    public function summarize(QueryBuilder $query, string $column): mixed
    {
        return $query->clone()->distinct()->count($column);
    }

    /**
     * @param  list<mixed>  $values
     */
    protected function reduce(array $values): int
    {
        return count(array_unique($values, SORT_REGULAR));
    }
}
```

Returning a name from `aggregate()` is the shortcut: the base `summarize()` calls `$query->clone()->{$aggregate}($column)`. Return `null` and override `summarize()` when the figure needs more than one aggregate, as `Range` does.

## Where the figures come from at render time

`ListRecords` passes the already-constrained builder and the page's records:

```php
'summaries' => $schema->hasSummaries()
    ? $schema->summaries($query, array_values($records->items()))
    : [],
```

The builder handed to `summaries()` is the same instance `paginate()` was given, so it carries every constraint the table applied — without building the constrained query a second time. `RelationTable` does the same with the relation's query, so a relation manager's table summarizes exactly what it is showing.

## Notes

- **A summary aggregates a database column, not a rendered cell.** `formatUsing()` on the column, `prefix()`, `decimals()` — none of them are involved. Format the figure with `Summarizer::formatUsing()`.
- **Summing a non-numeric column is the database's problem, not a validation error.** Point `Sum` at a numeric column, or use `Count`/`Range`, which are meaningful on anything.
- **Two summarizers of the same class on one column need distinct names**, since `make()` defaults to the class basename and the name is what identifies the figure in the payload.
- **`Average` over an empty per-page set is `null`**, which formats as `—`; the database's `avg()` of nothing is `null` too.
- **The figures ignore pagination but respect everything else** — search, per-column search, filters, the query builder, and the resource's own scope.
- **Group summaries cost one query per band on screen.** With a large `perPage` and a high-cardinality group column, that is a lot of bands; group by something with few values.
- **[Card layout](card-layout.md) keeps every figure, as strips rather than footer rows.** Table figures sit under the grid and a band's own close the run of cards it heads. There are no columns for a figure to sit under, so the column name becomes part of its label.
- **A hidden column's summaries are not computed.** The figure would be an aggregate query whose result the frontend discards, so hiding a column with three summarizers saves three queries per page — and three more per band. See [Query performance](../resources/performance.md).

## See also

- [Grouping](grouping.md)
- [Relationship columns](relationships.md)
- [Columns](columns.md)
- [Filters](filters.md)
- [Pagination](pagination.md)
- [Relation tables](../relations/relation-tables.md)
- [Table API reference](api.md)
- [Tables overview](overview.md)
