# Array Data Tables

`PandaPanel\Tables\ArrayTableData` renders a table over records that are not in the database: an API response, a config file, a computed report. The columns, the search and sort declarations, and the serialization are all the table builder's own — only where the rows come from and who does the work differ.

You reach for it on a custom page that has rows to show and no model behind them. For anything backed by Eloquent, use a resource table so the database does the work.

## A minimal example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Pages;

use App\Support\WeatherRow;
use PandaPanel\Pages\Page;
use PandaPanel\Tables\ArrayTableData;
use PandaPanel\Tables\Columns\NumberColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

final class Weather extends Page
{
    protected static ?string $title = 'Weather';

    protected static string $component = 'Panels/Admin/Pages/Weather';

    /**
     * @return array<string, mixed>
     */
    public function props(): array
    {
        $schema = TableSchema::make()->columns([
            TextColumn::make('city')->searchable()->sortable(),
            NumberColumn::make('temperature')->suffix('°C')->sortable(),
        ]);

        $records = collect([
            ['city' => 'Oslo', 'temperature' => 4],
            ['city' => 'Lisbon', 'temperature' => 19],
            ['city' => 'Cairo', 'temperature' => 33],
        ])->map(static fn (array $row): WeatherRow => WeatherRow::make($row));

        $data = ArrayTableData::make($schema, $records, request());
        $page = $data->paginate();

        return [
            'table' => $schema->toArray(),
            'state' => $data->state(),
            'rows' => $data->rows($page),
            'pagination' => $data->pagination($page),
        ];
    }
}
```

The payload keys are the same four a resource index sends, so the same Vue table component renders it.

## The rows must be models

A column reads its value with `data_get()` and every renderer downstream expects that shape, so `ArrayTableData` takes `Model` instances. A non-persisted model is the cheapest way to get one: it needs no table and no migration.

```php
<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

final class WeatherRow extends Model
{
    protected $guarded = [];

    public $timestamps = false;
}
```

`Model::make($attributes)` on a `$guarded = []` class is the whole ceremony. The row key sent to the frontend is `$record->getKey()`, so set an `id` attribute if the rows need stable identity — for actions, for instance.

## The API

```php
public function __construct(
    TableSchema $schema,
    Collection $records,
    Request $request,
    ?string $namespace = null,
)

public static function make(
    TableSchema $schema,
    iterable $records,
    Request $request,
    ?string $namespace = null,
): self
```

| Method | Signature | Returns |
| --- | --- | --- |
| `paginate()` | `paginate(): LengthAwarePaginator` | the current page, after searching and sorting |
| `rows()` | `rows(LengthAwarePaginator $records): array` | serialized rows, through `TableSchema::toRow()` |
| `pagination()` | `pagination(LengthAwarePaginator $records): array` | `page`, `perPage`, `total`, `lastPage`, `from`, `to` |
| `state()` | `state(): array` | the state that was applied |
| `sortableColumns()` | `sortableColumns(): list<Column>` | the columns this data source can order by |

`ArrayTableData` is `readonly`. Build a new one per request; there is nothing to reuse.

## What it honours

**Search** is a case-insensitive substring test over the columns that declared `searchable()`:

```php
TextColumn::make('city')->searchable();
```

Same whitelist rule as the query layer: a column that did not declare itself searchable is never matched, and a table where nothing is searchable ignores `?search=` entirely.

**Sort** reads `?sort=` and `?direction=`, and applies it only when the named column declared `sortable()`. It orders with `Collection::sortBy()` over `Column::getSortColumn()`, so `sortable(column: 'other_attribute')` works. An unknown or non-sortable column is ignored and `state()['sort']` comes back `null`.

**Pagination** reads `?page=` and uses `TableSchema::getDefaultPerPage()`:

```php
$schema->perPageOptions([2, 10])->defaultPerPage(2);
```

**Namespacing** works the same way it does for a relation table:

```php
$data = ArrayTableData::make($schema, $records, $request, namespace: 'readings');
// reads ?readings[page]=2&readings[sort]=city
```

**Row serialization** goes through `TableSchema::toRow()`, so cells, `cellMeta`, and per-record actions are produced exactly as they are on a resource index. Every column type works, including `CustomColumn`.

## What it does not do

| Feature | Behaviour |
| --- | --- |
| Filters | none applied; `state()['filters']` and `filterIndicators` are always empty |
| Per-column search | not read; `state()['columnSearches']` is always empty |
| Grouping | not applied; `state()['group']` is always `null` |
| Column manager | `state()['columns']` always reports the schema's declared visibility and order |
| Session persistence | there is no session key, so nothing is remembered |
| `?perPage=` | ignored; the schema's default is used |
| Relationship aggregates | `applyColumnQueries()` is never called, so `counts()`, `sum()`, and friends read nothing |
| Relation search | `getSearchRelations()` is not consulted; a dotted searchable name is not matched |
| Summaries | not computed here; call `TableSchema::summaries()` yourself if you need them, and note it expects an Eloquent builder |

Reach for a resource table when a list needs any of that. These are not gaps waiting to be filled: filters and grouping over an in-memory set would each need a second implementation of behaviour the query layer already owns.

## The honest limitation is scale

Search, sort, and pagination all happen in memory over the full set. This is for tens or hundreds of rows — the size a config file or an API page actually is. Anything larger belongs in a query, where the database can do the work.

## Testing

```php
use Illuminate\Http\Request;
use PandaPanel\Tables\ArrayTableData;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

$schema = TableSchema::make()->columns([
    TextColumn::make('city')->searchable()->sortable(),
]);

$data = ArrayTableData::make(
    $schema,
    collect([['city' => 'Oslo'], ['city' => 'Cairo']])
        ->map(static fn (array $row): WeatherRow => WeatherRow::make($row)),
    Request::create('/', 'GET', ['sort' => 'city', 'direction' => 'asc']),
);

$cities = collect($data->rows($data->paginate()))->pluck('cells.city')->all();

expect($cities)->toBe(['Cairo', 'Oslo'])
    ->and($data->state()['sort'])->toBe('city');
```

## Gotchas

- **Rows with no key serialize with `key => null`.** Give the model an `id` attribute if rows have to be told apart — a row action addresses a record by key.
- **Sorting is PHP's, not the database's.** `Collection::sortBy()` compares with PHP semantics, so mixed types sort differently from an `ORDER BY`.
- **The search is a substring test, not a `LIKE`.** No wildcards, no escaping question, and no relation traversal.
- **`state()['search']` is the raw parameter.** Unlike the query layer, it is not trimmed or truncated before being echoed back.
- **The whole set is held in memory for every request.** If building the collection is expensive, cache the collection, not the table.
- **Actions still work, but they need somewhere to run.** A row action posts to a panel action endpoint that resolves the record through a *resource*; a table over rows that belong to no resource has no such lookup.

## See also

- [TableSchema basics](overview.md)
- [Columns](columns.md)
- [Pagination](pagination.md)
- [Search](search.md) and [Sorting](sorting.md)
- [Custom pages](../pages-navigation/custom-pages.md)
- [Table API reference](api.md)
