# Widgets

A widget is a self-contained block of read-only summary on a panel page: a row of figures, a chart, a short table, or a Vue component of your own. You reach for one when a page should say something *about* records rather than list them — how many users signed up, what the last five orders were, whether the queue is backing up. Everything a widget knows it computes on the server; what crosses to the browser is a serialized description with no closures and no class names in it.

## A minimal working example

Generate one:

```bash
php artisan make:panel-widget UserStats --panel=Admin --type=stats
```

Then fill it in:

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Widgets;

use App\Models\User;
use PandaPanel\Widgets\Enums\StatColor;
use PandaPanel\Widgets\StatsWidget;
use PandaPanel\Widgets\Support\Stat;

final class UserStats extends StatsWidget
{
    protected static int $sort = 10;

    protected static ?string $heading = 'Accounts';

    /**
     * @return list<Stat>
     */
    public function stats(): array
    {
        return [
            Stat::make('Total users', User::query()->count())->icon('users'),

            Stat::make('Verified', User::query()->whereNotNull('email_verified_at')->count())
                ->icon('shield')
                ->color(StatColor::Success),
        ];
    }
}
```

Point the panel at the directory it lives in:

```php
use PandaPanel\Core\Panel;

public function panel(Panel $panel): Panel
{
    return $panel
        ->id('admin')
        ->path('admin')
        ->discoverWidgets(app_path('Panels/Admin/Widgets'));
}
```

`GET /admin` now renders the dashboard with the widget on it.

## The four types

Each type is a base class with one thing to implement. The type is what the frontend switches on to pick a renderer.

| Type | Base class | You implement | `type()` returns |
| --- | --- | --- | --- |
| stats | `PandaPanel\Widgets\StatsWidget` | `stats(): list<Stat>` | `WidgetType::Stats` (`'stats'`) |
| table | `PandaPanel\Widgets\TableWidget` | `table(TableSchema): TableSchema`, `query(): Builder` | `WidgetType::Table` (`'table'`) |
| chart | `PandaPanel\Widgets\ChartWidget` | `labels(): list<string>`, `series(): list<ChartSeries>` | `WidgetType::Chart` (`'chart'`) |
| custom | `PandaPanel\Widgets\CustomWidget` | `$component`, `data(): array` | `WidgetType::Custom` (`'custom'`) |

`PandaPanel\Widgets\Enums\WidgetType` is a closed enum. Adding a case without a Vue renderer is a compile error on the frontend rather than an empty card, because `WidgetRenderer.vue` switches over the union exhaustively.

Each type has its own page: [Stats](stats.md), [Tables](tables.md), [Charts](charts.md), [Custom Vue widgets](custom-vue.md).

## Where widgets appear

Three places, and the difference between them is what the widget is handed.

```php
use PandaPanel\Pages\Dashboard;
use PandaPanel\Pages\Page;
use PandaPanel\Resources\Pages\ListRecords;
use PandaPanel\Widgets\Widget;

// 1. The panel dashboard: every widget in the panel's registry.
//    PandaPanel\Pages\Dashboard::widgets() reads the registry.

// 2. Any standalone page, by naming classes.
final class Reports extends Page
{
    /** @return list<class-string<Widget>> */
    public function widgets(): array
    {
        return [RevenueChart::class];
    }
}

// 3. A resource page, above or below its own content.
final class ListOrders extends ListRecords
{
    /** @return list<class-string<Widget>> */
    public function headerWidgets(): array
    {
        return [OrderStats::class];
    }

    /** @return list<class-string<Widget>> */
    public function footerWidgets(): array
    {
        return [];
    }
}
```

A dashboard or standalone page gives its widgets **filters** but no page context. A resource page gives its widgets **page context** but no filters — see [Filters](filters.md) and the notes below.

## Registering widgets

```php
/** @param list<class-string> $widgets */
public function widgets(array $widgets): self

public function discoverWidgets(string ...$paths): self
```

```php
use App\Panels\Admin\Widgets\UserStats;

$panel
    ->widgets([UserStats::class])
    ->discoverWidgets(app_path('Panels/Admin/Widgets'));
```

Both lists are merged into one `PandaPanel\Core\WidgetRegistry` per panel, keyed by widget id, so a class that is both named and discovered is registered once. Discovery finds any class implementing `PandaPanel\Contracts\WidgetContract` under the given paths. See [Discovery](../concepts/discovery.md).

A widget's id is derived from its class name and nothing else:

```php
public static function id(): string   // Str::kebab(class_basename(static::class))
```

`App\Panels\Admin\Widgets\RecentUsers` is `recent-users`. Two widgets in one panel producing the same id throw `PanelRegistrationException::duplicateWidgetId()` at registration — the id is what keys the deferred payload, the filter group, and a table widget's query-string namespace, so it has to be unique.

Read the registry directly when you need to:

```php
use PandaPanel\Core\PanelManager;

app(PanelManager::class)->widgets('admin')->all();      // list<class-string>, sorted
app(PanelManager::class)->widgets('admin')->byId('recent-users');
app(PanelManager::class)->widgets('admin')->has('recent-users');
app(PanelManager::class)->widgets('admin')->count();
```

## The generator

```bash
php artisan make:panel-widget {name} --panel=Admin [--type=stats] [--force]
```

| Option | Values | Default |
| --- | --- | --- |
| `--panel` | a panel name, studly-cased for you | required |
| `--type` | `stats`, `table`, `chart`, `custom` | `stats` |
| `--force` | overwrite an existing file | off |

The class is written to `app/Panels/{Panel}/Widgets/{Name}.php`. For `--type=custom` a Vue component is written as well, to `resources/js/pages/Panels/{Panel}/Widgets/{Name}.vue`, because a custom widget without its component renders only the fallback. An unknown `--type` fails without writing anything. See [make:panel-widget](../cli/make-panel-widget.md).

## The shared API

Everything below lives on `PandaPanel\Widgets\Widget` and works for all four types.

| Member | Signature | Default | Purpose |
| --- | --- | --- | --- |
| `$sort` | `protected static int` | `0` | Order on the page, ascending. |
| `$columnSpan` | `protected static int\|string\|array` | `1` | Grid width. See [Layout](layout.md). |
| `$lazy` | `protected static bool` | `false` | Defer `data()` off the first response. See [Lazy loading](lazy-loading.md). |
| `$heading` | `protected static ?string` | `null` | Title above the widget. |
| `$description` | `protected static ?string` | `null` | Sub-line under the heading. |
| `$pollingInterval` | `protected static ?int` | `null` | Seconds between refreshes. See [Polling](polling.md). |
| `id()` | `public static function id(): string` | kebab class basename | Stable identity. |
| `sort()` | `public static function sort(): int` | `$sort` | |
| `isLazy()` | `public static function isLazy(): bool` | `$lazy` | |
| `heading()` | `public static function heading(): ?string` | `$heading` | |
| `description()` | `public static function description(): ?string` | `$description` | |
| `pollingInterval()` | `public static function pollingInterval(): ?int` | `$pollingInterval` | |
| `columnSpan()` | `public static function columnSpan(): array` | normalized `$columnSpan` | One value per breakpoint. |
| `canView()` | `public static function canView(): bool` | `true` | Checked before `data()`. See [Authorization](authorization.md). |
| `type()` | `abstract public static function type(): WidgetType` | — | Supplied by the base class you extend. |
| `data()` | `abstract public function data(): array` | — | The payload. Scalars, arrays and nulls only. |
| `filterSchema()` | `public function filterSchema(): ?FormSchema` | `null` | See [Filters](filters.md). |
| `filtersInModal()` | `public static function filtersInModal(): bool` | `false` | Filter form in a dialog. |
| `withFilters()` | `public function withFilters(array $filters): static` | — | Called by the page. |
| `filter()` | `protected function filter(string $name, mixed $default = null): mixed` | — | One filter value. |
| `filters()` | `protected function filters(): array` | — | All of them. |
| `withPageContext()` | `public function withPageContext(PageContext $context): static` | — | Called by the page. |
| `context()` | `protected function context(): PageContext` | — | Throws when there is none. |
| `toDefinition()` | `public function toDefinition(): array` | — | The serialized widget. |
| `toArray()` | `public function toArray(): array` | — | Alias of `toDefinition()`. |

```php
use PandaPanel\Widgets\StatsWidget;

final class QueueDepth extends StatsWidget
{
    protected static int $sort = 5;

    protected static int|string|array $columnSpan = ['default' => 1, 'md' => 2];

    protected static bool $lazy = true;

    protected static ?string $heading = 'Queue';

    protected static ?string $description = 'Jobs waiting to run.';

    protected static ?int $pollingInterval = 15;

    public static function canView(): bool
    {
        return auth()->user()?->can('view-operations') === true;
    }

    public function stats(): array { /* ... */ }
}
```

`$heading`, `$description`, `$sort`, `$columnSpan`, `$lazy` and `$pollingInterval` are static, so they are the same for every request. Anything that has to vary per user belongs in `data()`, or in `canView()`.

## What crosses to the browser

`toDefinition()` is the whole contract:

```php
[
    'id' => 'user-stats',
    'type' => 'stats',
    'sort' => 10,
    'columnSpan' => ['default' => 1, 'md' => 2, 'lg' => 3, 'xl' => 4],
    'lazy' => false,
    'heading' => 'Accounts',
    'description' => null,
    'polling' => 60,            // seconds, or null
    'filters' => null,          // or ['inModal' => bool, 'form' => FormDefinition]
    'data' => ['stats' => [/* ... */]],   // null when lazy
]
```

`CustomWidget` adds one key, `component`. Nothing else is ever added, and no PHP class name appears anywhere in it — the package has a test asserting exactly that.

## How a page resolves its widgets

`PandaPanel\Pages\WidgetCollection` does the work, in this order:

1. `canView()` is called on the class. A widget that refuses is dropped **before it is constructed**, so it never runs a query.
2. The survivors are instantiated, given page context if the page has one, and given their filter values if the page resolved any.
3. They are sorted by `[sort(), id()]` — the id is the tiebreaker, so two widgets with the same `$sort` still have a stable order.
4. `definitions()` serializes each one, calling `data()` inline for eager widgets and leaving `null` for lazy ones.
5. `deferred()` returns a single `Inertia::defer()` prop holding `{widgetId: data}` for the lazy widgets, or `null` when none are lazy.

```php
use PandaPanel\Pages\WidgetCollection;
use PandaPanel\Widgets\PageContext;
use PandaPanel\Widgets\Support\WidgetFilters;

$collection = WidgetCollection::for(
    [UserStats::class, RecentUsers::class],
    PageContext::forRecord($order),          // optional
    WidgetFilters::none(),                   // optional
);

$collection->definitions();   // list<array<string, mixed>>
$collection->deferred();      // Inertia deferred prop, or null
$collection->merge($other);   // one collection for a single deferred prop
```

The props a page ships:

| Page | Definition props | Deferred prop |
| --- | --- | --- |
| `Dashboard` / `Page` | `widgets` | `widgetData` |
| resource pages | `headerWidgets`, `footerWidgets` | `widgetData` |

## Page context

A widget on a resource page is handed a `PandaPanel\Widgets\PageContext` describing what the page is showing.

```php
public static function forRecord(Model $record): self
public static function forQuery(Closure $query): self

public function record(): ?Model
public function query(): ?Builder
public function count(): int
```

`ListRecords` builds it with `PageContext::forQuery()` from the query the table actually ran, tab scoping included, so a widget counts what the user is looking at rather than the whole table. `ViewRecord`, `EditRecord` and `ManageRelatedRecords` build it with `PageContext::forRecord()`.

```php
use PandaPanel\Widgets\StatsWidget;
use PandaPanel\Widgets\Support\Stat;

final class SelectionSummary extends StatsWidget
{
    public function stats(): array
    {
        return [
            Stat::make('Matching orders', $this->context()->count()),
        ];
    }
}
```

`count()` is memoized on the context object, and every widget on the page shares one instance — three widgets asking for the count run one query, and a page whose widgets never ask runs none.

`context()` throws `LogicException` when the widget was rendered without one. That is deliberate: a widget reading a record it was never given is on the wrong page, and a zero would hide it. Dashboards and standalone pages pass no context.

## Gotchas

- `canView()` is static and takes no arguments. It runs before the widget exists, so it cannot see the page's record. Per-record hiding has to happen inside `data()`, or by not naming the widget in `headerWidgets()`.
- Filters are resolved by `Page`, not by `ResourcePage`. A widget with a `filterSchema()` placed in `headerWidgets()` will render its controls, but nothing reads the values back — `$this->filter()` returns the default every time.
- Widget data is never cached by `panel:cache`. The manifest caches class names; counts, rows and series are computed per request.
- `widgetData` is *absent* from the first response rather than null, because it is a deferred prop. A Vue component reading it must declare it optional.
- Two widgets whose class basenames kebab-case to the same string cannot live in one panel. `App\Panels\Admin\Widgets\UserStats` and `App\Panels\Admin\Reports\UserStats` are both `user-stats`, and registering the second throws.
- There are no widget-specific testing helpers. Widgets are tested through the page's Inertia props, the way the package's own `WidgetRenderingTest` does.

## See also

- [Stats widgets](stats.md)
- [Chart widgets](charts.md)
- [Table widgets](tables.md)
- [Custom Vue widgets](custom-vue.md)
- [Filters](filters.md)
- [Lazy loading](lazy-loading.md)
- [Polling](polling.md)
- [Authorization](authorization.md)
- [Column span and layout](layout.md)
- [Dashboards](../panels/dashboards.md)
- [Resource pages](../resources/resource-pages.md)
- [make:panel-widget](../cli/make-panel-widget.md)
