# Grouping

Grouping bands a table's rows under headings — orders by status, users by role, tasks by project. You reach for it when the rows fall into a handful of categories and reading them run together loses the shape of the data.

Grouping is presentation, not aggregation. It does not change which records the query returns, so paging still works exactly as it did.

## A minimal grouped table

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Group;
use PandaPanel\Tables\TableSchema;

return $table
    ->columns([
        TextColumn::make('reference')->searchable(),
        TextColumn::make('status'),
    ])
    ->groups([
        Group::make('status')
            ->label('Status')
            ->titleUsing(static fn (Model $record): string => Str::headline($record->status)),
    ])
    ->defaultGroup('status');
```

The user picks a grouping from the table's controls; the choice lives in the URL as `?group=status`.

## `TableSchema`

| Method | Signature | Default |
| --- | --- | --- |
| `groups()` | `groups(array $groups): self` | `[]` |
| `defaultGroup()` | `defaultGroup(string $name): self` | `null` |
| `getGroups()` | `getGroups(): list<Group>` | — |
| `getGroup()` | `getGroup(string $name): ?Group` | — |
| `getDefaultGroup()` | `getDefaultGroup(): ?string` | — |

A table may declare several ways to band its rows; only one is active at a time.

## `Group`

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Tables\Enums\SortDirection;
use PandaPanel\Tables\Group;

Group::make('project_id')
    ->label('Project')
    ->column('project_id')
    ->direction(SortDirection::Descending)
    ->titleUsing(static fn (Model $record): string => $record->project?->name ?? 'Unassigned')
    ->descriptionUsing(static fn (Model $record): ?string => $record->project?->code);
```

| Method | Signature | Default |
| --- | --- | --- |
| `make()` | `static make(string $name): self` | — |
| `label()` | `label(string $label): self` | `Str::headline()` of the name |
| `column()` | `column(string $column): self` | the group's name |
| `direction()` | `direction(SortDirection $direction): self` | `SortDirection::Ascending` |
| `titleUsing()` | `titleUsing(Closure $callback): self` | the raw key, or `Ungrouped` when it is empty |
| `descriptionUsing()` | `descriptionUsing(Closure $callback): self` | `null` |

Both closures take the record and return a string (`?string` for the description). They are resolved on the server, so a key can become a name without the frontend knowing how.

Read-side: `getName()`, `getLabel()`, `getColumn()`, `keyFor(Model $record): string`, `titleFor(Model $record): string`, `descriptionFor(Model $record): ?string`, `applySort(Builder $query): void`, `toArray(): array`.

`toArray()` sends `name` and `label` only. The titles ride on the rows, because they are per record.

## Which band a row falls in

```php
$group->keyFor($record);   // the raw value of the group column, cast to string
```

The key is the raw value, not the title: two records with the same key belong together even if a title closure would render them differently. A non-scalar value keys as an empty string.

Each serialized row carries its band:

```php
$schema->toRow($record, $group);

// [
//     'key' => 12,
//     'group' => ['key' => '3', 'title' => 'Apollo', 'description' => null],
//     'cells' => [...],
//     'cellMeta' => [...],
//     'actions' => [...],
// ]
```

`group` is `null` when the table is not grouped. The frontend draws a band heading wherever the key changes from the previous row.

## What grouping changes about the query

One thing: the ordering. Rows have to arrive together to be shown together, so an active group sorts before anything else the table is sorted by.

```php
// TableQuery::applySort(), in order
$group?->applySort($query);          // order by the group column, ascending by default
// then the requested sort, the custom sort, the relation sort, or the default
```

It does **not** collapse the result into groups. A band can be split across two pages exactly as any run of rows can — the honest behaviour for a server-paginated table, where collapsing the whole result into groups would mean fetching all of it.

## The active group

```php
$tableQuery->activeGroup();   // ?Group
$tableQuery->state()['group'];   // ?string
```

Resolution follows the same rules as every other piece of table state:

| Request | Result |
| --- | --- |
| `?group=status` naming a declared group | that group |
| `?group=deleted_at` naming an undeclared one | `null` — ignored, not an error |
| `?group=` | `null` — an explicit empty value turns grouping off |
| no `group` key | `defaultGroup()`, or `null` |

The explicit empty case is what makes a table with a `defaultGroup()` ungroupable at all.

Grouping is remembered alongside sort: `persistSortInSession()` covers `group` too, because the two are one decision about how the rows are arranged.

## Group summaries

```php
use PandaPanel\Tables\Columns\NumberColumn;
use PandaPanel\Tables\Summaries\Count;
use PandaPanel\Tables\Summaries\Sum;

$table->columns([
    NumberColumn::make('total')->summarize([Sum::make(), Count::make()]),
]);
```

When a table is grouped and its columns declare summarizers, `ListRecords` also sends `groupSummaries`:

```php
$schema->groupSummaries($query, array_values($records->items()), $group);

// ['3' => ['total' => [['name' => 'sum', 'label' => 'Sum', 'value' => '1,240', ...]]]]
```

Keyed by group key, then by column, then a list of figures. Each band's figures are computed over the **whole** band rather than over the rows of it on this page — the same reason the table's own totals are: a band total that changed when you paged would be a different number wearing the same label. That costs one query per band on screen, which is a handful. A summarizer marked `perPage()` still reduces from the records shown.

`groupSummaries()` returns an empty array when no column declares a summarizer. See [Summaries](summaries.md).

## Testing

```php
use App\Models\Task;
use Illuminate\Http\Request;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Group;
use PandaPanel\Tables\TableQuery;
use PandaPanel\Tables\TableSchema;

$group = Group::make('project_id');

$schema = TableSchema::make()
    ->columns([TextColumn::make('name')])
    ->groups([$group]);

$request = Request::create('/', 'GET', ['group' => 'project_id']);
$request->setLaravelSession(app('session.store'));

$tableQuery = new TableQuery($schema, $request);
$records = $tableQuery->paginate(Task::query());

$keys = array_map(
    static fn ($record): string => $schema->toRow($record, $group)['group']['key'],
    $records->items(),
);

// Rows arrive together, because the group sorts before anything else: two
// tasks of one project, then the task of the other.
expect($tableQuery->activeGroup()?->getName())->toBe('project_id')
    ->and($keys)->toBe(['1', '1', '2']);
```

## Gotchas

- **A band can straddle a page boundary.** That is the correct behaviour for a server-paginated table, not a bug. If the bands must be whole, page by band: filter to one band and drop the grouping.
- **`Group::make()` does not check the column exists.** An unknown column becomes an `order by` that the database rejects. Unlike sort columns, groups are not validated against the schema's columns — only against the declared *groups*.
- **`titleUsing()` does not affect banding.** Two records with the same key are one band whatever the titles say; two records with different keys are two bands even if the titles match.
- **Grouping adds an `order by` in front of the user's sort.** A table sorted by `created_at` and grouped by `status` is ordered by status first. That is what makes the bands contiguous.
- **`descriptionUsing()` is evaluated per row** even though only the first row of a band draws it. Keep it cheap, and do not query inside it.

## See also

- [TableSchema basics](overview.md)
- [Summaries](summaries.md)
- [Sorting](sorting.md)
- [Tabs](tabs.md) — a scope over the query, where grouping is a view of it
- [Pagination](pagination.md)
- [Persisted table state](persisted-state.md)
- [Table API reference](api.md)
