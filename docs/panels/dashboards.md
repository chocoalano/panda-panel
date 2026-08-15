# Dashboards

The page a panel lands on. A dashboard is an ordinary `PandaPanel\Pages\Page` whose widgets come from the panel's widget registry rather than from its own list, which means metadata, breadcrumbs, header actions, authorization, filters and lazy loading all work here exactly as they do anywhere else. A panel may have more than one.

## The default

Every panel gets `PandaPanel\Pages\Dashboard` at its path with no configuration at all:

```php
$panel->path('admin');   // GET /admin renders PandaPanel\Pages\Dashboard
```

```php
route('panel.admin.dashboard', absolute: false);   // '/admin'
```

It shows every widget registered in the panel that the current user may view:

```php
$panel->discoverWidgets(app_path('Panels/Admin/Widgets'));
```

## Replacing it

```php
/** @param  class-string<Page>  $page */
public function dashboard(string $page): self

/** @return class-string<Page> */
public function getDashboard(): string
```

```php
use App\Panels\Admin\Pages\Overview;

$panel->dashboard(Overview::class);
```

A `Page` class rather than a component name, so the landing page gets the same metadata, authorization and widget handling as every other page. `PanelDashboardController` does nothing but instantiate it and call `render()`.

The simplest custom dashboard extends the built-in one and names its own widgets:

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Pages;

use App\Panels\Admin\Widgets\RecentUsers;
use App\Panels\Admin\Widgets\UserStats;
use PandaPanel\Pages\Dashboard;
use PandaPanel\Widgets\Widget;

final class Overview extends Dashboard
{
    protected static ?string $title = 'Overview';

    /** @return list<class-string<Widget>> */
    public function widgets(): array
    {
        return [UserStats::class, RecentUsers::class];
    }
}
```

Extending `Dashboard` and *not* overriding `widgets()` keeps the registry behaviour:

```php
use PandaPanel\Core\PanelManager;

public function widgets(): array
{
    return app(PanelManager::class)->widgets($this->panel())->all();
}
```

## More than one dashboard

```php
/** @param  array<array-key, class-string<Page>>  $pages */
public function dashboards(array $pages): self

/** @return list<class-string<Page>> */
public function getExtraDashboards(): array
```

```php
use App\Panels\Admin\Pages\AccountsDashboard;
use PandaPanel\Pages\Dashboard;

$panel->dashboards([
    Dashboard::class,           // the panel root, /admin
    AccountsDashboard::class,   // its own route, /admin/accounts
]);
```

The first entry becomes the panel root — `dashboards()` sets `dashboard()` from it — and the rest are registered as ordinary pages under the panel prefix, at the path each one's slug produces. An empty array is ignored.

They are Page classes like any other, so each one authorizes, appears in navigation, and carries its own filters independently. An operations dashboard and a finance dashboard are two pages of widgets, not one page with a dropdown: they answer different questions and are read by different people.

Extra dashboards are deduplicated by class, because one that also lives under a discovered path arrives twice and is still one page.

## A second dashboard, in full

This is `examples/app/Panels/Admin/Pages/AccountsDashboard.php`:

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Pages;

use App\Panels\Admin\Widgets\RecentUsers;
use App\Panels\Admin\Widgets\UserGrowth;
use App\Panels\Admin\Widgets\UserStats;
use BackedEnum;
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Pages\Dashboard;
use PandaPanel\Widgets\Widget;

final class AccountsDashboard extends Dashboard
{
    protected static ?string $title = 'Accounts';

    protected static ?string $slug = 'accounts';

    protected static ?string $navigationIcon = 'users';

    protected static string|BackedEnum|null $navigationGroup = 'User Management';

    protected static ?string $subheading = 'Sign-ups and verification at a glance.';

    public function filterSchema(): FormSchema
    {
        return FormSchema::make()->schema([
            Select::make('period')
                ->label('Period')
                ->options([
                    'month' => 'This month',
                    'quarter' => 'This quarter',
                    'year' => 'This year',
                ])
                ->default('month'),
        ]);
    }

    /** @return list<class-string<Widget>> */
    public function widgets(): array
    {
        return [UserStats::class, UserGrowth::class, RecentUsers::class];
    }
}
```

It answers at `/admin/accounts`, under the route name `panel.admin.pages.accounts`.

## What `Dashboard` gives you

| Member | Value | Purpose |
| --- | --- | --- |
| `$title` | `'Dashboard'` | Page title and heading. |
| `$slug` | `'dashboard'` | Route name suffix and registry key. |
| `$component` | `'panel/Dashboard'` | The Inertia component. |
| `$navigationIcon` | `'layout-grid'` | Sidebar icon. |
| `$shouldRegisterNavigation` | `true` | Whether it wants a sidebar entry. |
| `widgets()` | the panel's widget registry | Overridable. |
| `breadcrumbs()` | `[Breadcrumb::make('Dashboard')->current()]` | One crumb, marked current. |

Everything else comes from `Page`: `props()`, `headerActions()`, `filterSchema()`, `canAccess()`, `middleware()`, `routePath()`.

```php
use PandaPanel\Support\Breadcrumb;

public function breadcrumbs(): array
{
    return [
        Breadcrumb::make('Dashboard')->url($this->dashboardUrl()),
        Breadcrumb::make('Accounts')->current(),
    ];
}
```

## Widget ordering and authorization

A dashboard's widget list is resolved by `WidgetCollection::for()`, which:

1. drops any widget whose `canView()` is false — before `data()` is called, so an unauthorized widget never runs a query;
2. sorts what remains by `[Widget::sort(), Widget::id()]`;
3. serializes each definition, inlining `data()` for eager widgets and deferring it for lazy ones.

```php
use PandaPanel\Widgets\StatsWidget;

final class UserStats extends StatsWidget
{
    protected static int $sort = 0;

    protected static int|string|array $columnSpan = 2;

    protected static bool $lazy = false;

    protected static ?int $pollingInterval = 60;

    public static function canView(): bool
    {
        return auth()->user()?->is_admin === true;
    }
}
```

The panel's own registry (`WidgetRegistry::all()`) returns class names sorted alphabetically; `$sort` is what actually decides the order on the page, with the widget id as the tiebreaker. Widget ids must be unique within a panel — two classes producing the same kebab-cased basename throw at registration.

## Filters

A dashboard may declare one form that every widget on it reads:

```php
public function filterSchema(): ?FormSchema
```

Values arrive from the query string, are narrowed by the schema that declared them, and are persisted per page under `panel.{panelId}.page.{slug}` — two dashboards filtered differently are two different questions, and restoring one over the other would answer neither.

A widget can also declare a filter of its own on top, and `Widget::filtersInModal()` decides whether it opens in a dialog. See [Widget Filters](../widgets/filters.md).

The rendered props of a dashboard:

| Prop | Contents |
| --- | --- |
| `page` | title, heading, subheading, breadcrumbs, header actions, render-hook scope, cluster |
| `widgets` | the definitions, in order, with inline data for eager widgets |
| `widgetData` | a single deferred prop holding every lazy widget's payload, or absent when there are none |
| `filters` | `null` for a page with no filter schema, otherwise the serialized form |

## Access

A dashboard is a page, so it authorizes like one:

```php
final class FinanceDashboard extends Dashboard
{
    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-finance') === true;
    }
}
```

`canAccess()` is enforced on the route, not only in navigation. The panel root is different: it is reached through `PanelDashboardController`, which renders the nominated page, and `Page::render()` calls `abort_unless(static::canAccess(), 403)` itself — so a dashboard that refuses the user 403s at the panel root rather than rendering an empty shell.

## Notes

- The panel's root dashboard is reached at the panel path, so it is not registered as a page and has no sidebar entry. Add `->pages([Dashboard::class])` if you want one — it then also answers at `/admin/dashboard`.
- Extra dashboards are pages in every sense but discovery, so they need no `discoverPages()` entry to be routed. A dashboard that lives under a discovered path and is also named in `dashboards()` is registered once.
- `dashboards([])` does nothing at all rather than clearing the dashboard: a panel with no landing page has no root route.
- Widget data is never cached with the panel manifest. Only class names are cached; counts, rows and charts are computed per request.
- A widget placed on a resource page through `headerWidgets()` or `footerWidgets()` gets a `PageContext`; a widget on a dashboard does not, and calling `context()` there throws with a message saying so.

## See also

- [Defining a Panel](defining-panels.md)
- [Navigation Groups](navigation-groups.md)
- [Settings Pages](settings-pages.md)
- [Panel API Reference](api.md)
- [Widgets Overview](../widgets/overview.md)
- [Widget Layout](../widgets/layout.md)
- [Widget Filters](../widgets/filters.md)
- [Lazy Loading](../widgets/lazy-loading.md)
- [Polling](../widgets/polling.md)
- [Custom Pages](../pages-navigation/custom-pages.md)
