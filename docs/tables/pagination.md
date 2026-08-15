# Pagination

Every panel table is paginated by the database. The schema declares which page sizes are on offer and which one is used before anybody chooses; the URL carries the rest. You reach for this page when a table needs a different page size, or when you are building a table outside a resource index and have to call the paginator yourself.

## A minimal example

```php
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

return $table
    ->columns([TextColumn::make('name')->sortable()])
    ->perPageOptions([25, 50, 100])
    ->defaultPerPage(50);
```

The table opens on 50 rows and offers 25, 50, and 100 in its page-size control.

## The two declarations

| Method | Signature | Default |
| --- | --- | --- |
| `perPageOptions()` | `perPageOptions(array $options): self` | `[10, 25, 50, 100]` |
| `defaultPerPage()` | `defaultPerPage(int $perPage): self` | `25` |

`perPageOptions()` drops anything that is not greater than zero, removes duplicates, and sorts ascending. Whatever order you write them in, the frontend receives them low to high.

```php
$table->perPageOptions([100, 25, 0, 25, -5]);

$table->getPerPageOptions();   // [25, 100]
```

`getDefaultPerPage()` clamps: if the declared default is not one of the options, the first option is used instead, and `25` if there are no options at all.

```php
$table->perPageOptions([10, 20])->defaultPerPage(15);

$table->getDefaultPerPage();   // 10
```

## What the URL controls

| Parameter | Read as | Rejected value |
| --- | --- | --- |
| `?page=` | `max(1, (int) $value)` | anything below 1 becomes 1 |
| `?perPage=` | an exact match against `perPageOptions()` | falls back to `getDefaultPerPage()` |

`?perPage=100000` cannot ask the database for every row: a value outside the declared options is not clamped to the nearest one, it is ignored in favour of the default. `state()['perPage']` reports what was applied, so the control never shows a size the query did not use.

A page number past the last page is not an error. Laravel returns an empty page, `lastPage` still says where the data ends, and the frontend's next-page button is already disabled there.

## Paginating

```php
use PandaPanel\Tables\TableQuery;
use PandaPanel\Tables\TableSchema;

$schema = OrderResource::table(TableSchema::make());
$query = OrderResource::query();

$tableQuery = new TableQuery($schema, request());

$records = $tableQuery->paginate($query);   // LengthAwarePaginator
```

| Method | Signature | Use |
| --- | --- | --- |
| `constrain()` | `constrain(Builder $query): void` | apply everything except the page and page size |
| `paginate()` | `paginate(Builder $query): LengthAwarePaginator` | the ordinary case |
| `paginateRelation()` | `paginateRelation(Relation $relation): LengthAwarePaginator` | a relation table |

`paginate()` calls `constrain()` first — column queries, base-query filters, search, filters, sort — then `$query->paginate(perPage, page)->withQueryString()`, so generated links keep the rest of the table state.

### `constrain()` on its own

```php
$tableQuery->constrain($query);

$query->each(static fn ($record) => /* ... */);
```

Everything the table state says about *which* records and in what order, and nothing about how many. An export wants exactly this: the rows the list was showing, all of them. `ExportAction` shares the method rather than repeating the calls, which is what keeps a file from containing a different set of records from the screen it was started from.

### `paginateRelation()`

```php
$records = $tableQuery->paginateRelation($manager::relationForTable($owner));
```

Not interchangeable with `paginate($relation->getQuery())` for a many-to-many. `getQuery()` hands back the underlying builder, which knows nothing about the pivot, so paginating it produces rows with no `pivot` relation and a pivot column reads as null. `BelongsToMany::paginate()` selects the aliased pivot columns and hydrates them afterwards. The state still goes on to the same builder instance the relation holds, so search, filters, and sort apply exactly as they do above.

## The payload

`ListRecords::pagination()` sends six counters and nothing else:

```php
[
    'page' => 2,
    'perPage' => 25,
    'total' => 137,
    'lastPage' => 6,
    'from' => 26,
    'to' => 50,
]
```

The paginator's own link array is deliberately not sent. The frontend builds URLs from the current query string, which keeps the URL the single source of truth for table state — a server-rendered link array would have to encode the search, the filters, the sort, and the column arrangement all over again.

`from` and `to` are cast from `firstItem()` and `lastItem()`, which are null on an empty page and therefore arrive as `0`.

Relation tables and table widgets send the same six keys, so one Vue pagination component renders all three.

## More than one table on a page

A record page can carry several relation tables, and a dashboard several table widgets. A shared `?page=` would move all of them together, so each table's state lives under a namespace:

```php
new TableQuery($schema, $request, namespace: 'relations.tasks');
// reads ?relations[tasks][page]=2&relations[tasks][perPage]=10
```

| Context | Namespace |
| --- | --- |
| resource index | none |
| relation manager | `relations.{manager key}` |
| table widget | `widgets.{kebab-cased widget class}` |

`TableQuery::namespace()` reads it back, and `RelationTable` sends it to the frontend as `stateKey` so the client writes to the same place.

## Page size on a table widget

`PandaPanel\Widgets\TableWidget` fixes the page size rather than offering a control:

```php
final class RecentOrders extends TableWidget
{
    protected static int $perPage = 5;
}
```

The widget builds its schema and then applies `perPageOptions([$perPage])->defaultPerPage($perPage)`, so a request asking for anything else falls back to that one option. A dashboard table is read at a glance.

## Pagination over an array

`PandaPanel\Tables\ArrayTableData` paginates in memory with the same declarations:

```php
$data = ArrayTableData::make($schema, $records, $request);
$page = $data->paginate();

$data->pagination($page);   // the same six keys
$data->rows($page);         // serialized rows
```

It reads `?page=` and uses `getDefaultPerPage()`; it does **not** read `?perPage=`. See [Array data tables](array-data.md).

## Gotchas

- **`perPage` is never remembered in the session.** Search, sort, filters, and columns can be persisted; the page size is read from the request every time and falls back to the schema's default.
- **`paginate()` leaves a limit and an offset on the builder it was given.** Anything computed from that builder afterwards would describe one page while claiming to describe the result — which is why `TableSchema::summaries()` clones it, clears the limit and offset, and drops the ordering before aggregating.
- **`defaultPerPage()` is silently clamped.** If the page size you set is not in `perPageOptions()`, the first option is used and nothing complains.
- **`?page=` is not bounded above.** A bookmarked page 900 of a table that now has three pages renders empty rather than redirecting.
- **A table with no `perPageOptions()` call still has four.** The default list is `[10, 25, 50, 100]`; call `perPageOptions()` to narrow it, not to enable it.
- **Grouping does not change the page.** A band split across two pages is the honest behaviour of a server-paginated table — see [Grouping](grouping.md).

## See also

- [TableSchema basics](overview.md)
- [Persisted table state](persisted-state.md)
- [Grouping](grouping.md) and [Summaries](summaries.md)
- [Array data tables](array-data.md)
- [Relation tables](../relations/relation-tables.md)
- [Table widgets](../widgets/tables.md)
- [Export action](../import-export/export-action.md)
- [Table API reference](api.md)
