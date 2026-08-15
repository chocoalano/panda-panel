# Table Widgets

A table widget is a short, sortable, searchable table on a dashboard or a page: the last five sign-ups, the ten largest open invoices, today's failed jobs. You reach for one when the answer is a handful of records rather than a figure, and the reader needs to see them without leaving the page they are on.

It is built by the same `TableSchema` a resource index uses, and run by the same `TableQuery`, so a column renders identically in both places. It is still not a resource index: no bulk actions, no column manager, no filter tabs. A widget is a summary you can look through, not a second place records are managed from.

## A minimal working example

```bash
php artisan make:panel-widget RecentUsers --panel=Admin --type=table
```

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Widgets;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;
use PandaPanel\Widgets\TableWidget;

final class RecentUsers extends TableWidget
{
    protected static ?string $heading = 'Recent sign-ups';

    public function table(TableSchema $table): TableSchema
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('email'),
        ]);
    }

    /**
     * @return Builder<User>
     */
    public function query(): Builder
    {
        return User::query()->select(['id', 'name', 'email']);
    }
}
```

Five rows, a search box, a sortable `Name` header, and Previous/Next links when there is more than one page.

## The class

`PandaPanel\Widgets\TableWidget` extends `PandaPanel\Widgets\Widget`.

| Member | Signature | Default |
| --- | --- | --- |
| `$columnSpan` | `protected static int\|string\|array` | `['default' => 1, 'md' => 2, 'lg' => 2, 'xl' => 2]` |
| `$emptyMessage` | `protected static string` | `'Nothing to show yet.'` |
| `$perPage` | `protected static int` | `5` |
| `type()` | `public static function type(): WidgetType` | `WidgetType::Table` |
| `table()` | `abstract public function table(TableSchema $table): TableSchema` | — |
| `query()` | `abstract public function query(): Builder` | — |
| `data()` | `public function data(): array` | see below |
| `stateNamespace()` | `public static function stateNamespace(): string` | `'widgets.'.kebab(basename)` |

```php
protected static string $emptyMessage = 'No one has signed up yet.';

protected static int $perPage = 10;
```

`$perPage` is deliberately short: a dashboard table is read at a glance, and a widget raises it on purpose rather than by accident.

### `table()`

```php
abstract public function table(TableSchema $table): TableSchema
```

Handed a fresh `PandaPanel\Tables\TableSchema`, and must return one. Every column type works here — see [Columns](../tables/columns.md).

```php
use PandaPanel\Tables\Columns\DateTimeColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Enums\SortDirection;

public function table(TableSchema $table): TableSchema
{
    return $table
        ->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('email')->searchable(),
            DateTimeColumn::make('created_at')->label('Joined')->relative()->sortable(),
        ])
        ->defaultSort('created_at', SortDirection::Descending);
}
```

`->searchable()` on any column makes the widget's search box appear. `->sortable()` makes that header clickable. `->defaultSort()` decides the order before the reader touches anything.

### `query()`

```php
/** @return Builder<covariant Model> */
abstract public function query(): Builder
```

A query builder rather than a collection, so the table builder can search, sort and page it.

```php
public function query(): Builder
{
    return User::query()->select(['id', 'name', 'email', 'created_at']);
}
```

Narrow it here. Select only the columns the table displays — a wide `users` table otherwise becomes a wide payload — and constrain it to what the widget is about. A dashboard table showing every order ever placed is a dashboard nobody opens twice.

### `data()`

```php
public function data(): array
```

`data()` builds the schema, forces `perPageOptions([$perPage])` and `defaultPerPage($perPage)` over whatever `table()` set, runs a `PandaPanel\Tables\TableQuery` against `query()` under this widget's namespace, and serializes:

```php
[
    'columns' => [/* the same column definitions a resource index sends */],
    'rows' => [/* TableSchema::toRow() per record */],
    'emptyMessage' => 'No one has signed up yet.',
    'state' => ['search' => null, 'sort' => 'created_at', 'direction' => 'desc', /* ... */],
    'pagination' => [
        'page' => 1,
        'perPage' => 5,
        'total' => 9,
        'lastPage' => 2,
        'from' => 1,
        'to' => 5,
    ],
    'namespace' => 'widgets.recent-users',
    'searchable' => true,
]
```

Because `$perPage` is forced onto the schema, a `perPageOptions()` or `defaultPerPage()` call inside `table()` has no effect. Change `$perPage` instead.

### `stateNamespace()`

```php
public static function stateNamespace(): string
```

Where this widget's table state lives in the query string. It is `'widgets.'` plus the kebab-cased class basename, so `RecentUsers` is `widgets.recent-users`, dotted on the server and bracketed in a URL:

```text
/admin?widgets[recent-users][page]=2&widgets[recent-users][sort]=name&widgets[recent-users][direction]=asc
```

That namespace is what makes two table widgets on one dashboard possible at all — they would otherwise fight over `page` — and it is the same arrangement a relation manager already uses. See [Persisted table state](../tables/persisted-state.md).

## What the renderer draws

| Feature | Supported | Notes |
| --- | --- | --- |
| Columns and cell rendering | yes | Identical to a resource index, through the same cell component. |
| Global search | yes | Shown when any column is `searchable()`. Submits on Enter or blur. |
| Sorting | yes | Clickable headers for `sortable()` columns. Resets to page 1. |
| Pagination | yes | Previous/Next plus a `from–to of total` count, shown only when `lastPage > 1`. |
| Empty state | yes | `$emptyMessage` in a single spanning row. |
| Record actions | no | `recordActions()` on the schema is not rendered. |
| Header, toolbar and bulk actions | no | Not rendered. |
| Filters and filter tabs | no | No filter UI is drawn. |
| Column manager, reordering, grouping, summaries | no | Not rendered. |

If a widget's table is growing toolbar buttons and filters, it wants to be a resource index page, and the honest move is a link to one — a [stat with a `url()`](stats.md#url) or a header action on the page.

## A full example

This is `examples/app/Panels/Admin/Widgets/RecentUsers.php`:

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Widgets;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Tables\Columns\DateTimeColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Enums\SortDirection;
use PandaPanel\Tables\TableSchema;
use PandaPanel\Widgets\TableWidget;

final class RecentUsers extends TableWidget
{
    protected static int $sort = 20;

    protected static string $emptyMessage = 'No one has signed up yet.';

    protected static ?string $heading = 'Recent sign-ups';

    protected static ?string $description = 'The newest accounts, searchable and sortable.';

    public function table(TableSchema $table): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable(),
                DateTimeColumn::make('created_at')->label('Joined')->relative()->sortable(),
            ])
            ->defaultSort('created_at', SortDirection::Descending);
    }

    /**
     * @return Builder<User>
     */
    public function query(): Builder
    {
        return User::query()->select(['id', 'name', 'email', 'created_at']);
    }
}
```

## Scoping to the page

On a resource page, a table widget can narrow itself to what the page is showing:

```php
use Illuminate\Database\Eloquent\Builder;

public function query(): Builder
{
    // On a ListRecords page: the query the index actually ran, tab scoping included.
    return $this->context()->query() ?? User::query();
}
```

```php
public function query(): Builder
{
    // On a ViewRecord or EditRecord page: rows belonging to the record on screen.
    return $this->context()->record()->orders()->getQuery();
}
```

`context()` throws when the widget was rendered without one, so a table widget written this way must be placed in `headerWidgets()` or `footerWidgets()` of a resource page and not on a dashboard. See [Overview](overview.md#page-context).

## Gotchas

- The `widget-table` stub the generator writes declares a `rows(): Collection` method and does not implement `query()`. `query()` is abstract, so the generated class will not load until you replace `rows()` with a `query(): Builder`. Publish your own stub with `php artisan vendor:publish --tag=panda-panel-stubs` if you generate these often.
- Search, sort and page live in the same `widgets[{id}]` query-string group as the widget's [filters](filters.md). A widget that declares both will see the group counted as *present* when only table state is in the URL, which resolves its filters to `null` rather than their declared defaults. Always read filters with an explicit default: `$this->filter('window', 30)`.
- `perPageOptions()` and `defaultPerPage()` set inside `table()` are overwritten by `$perPage`.
- `TableSchema::toRow()` resolves record actions, so they are present in the payload, but the widget renderer does not draw them. Do not rely on the widget as a place to run actions.
- Two table widgets whose class basenames kebab-case identically share a namespace and will fight over state. Widget ids are unique per panel, which prevents this inside one panel.
- The query runs on every render, including every [poll](polling.md). Keep it indexed and bounded.

## See also

- [Widgets overview](overview.md)
- [Tables overview](../tables/overview.md)
- [Columns](../tables/columns.md)
- [Search](../tables/search.md)
- [Sorting](../tables/sorting.md)
- [Pagination](../tables/pagination.md)
- [Persisted table state](../tables/persisted-state.md)
- [Filters](filters.md)
- [Lazy loading](lazy-loading.md)
