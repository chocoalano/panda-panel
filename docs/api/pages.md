# Pages Reference

`PandaPanel\Pages\Page` and everything that surrounds it: the dashboard, the three built-in settings pages, the widget collection a page resolves, and the clusters a page can belong to. A page is a panel screen that is not a resource — no model, no table, no records — that still gets the shell, navigation, breadcrumbs, and authorization.

## The smallest page

```php
<?php

namespace App\Panels\Admin\Pages;

use PandaPanel\Pages\Page;

final class SystemStatus extends Page
{
    protected static ?string $title = 'System status';

    public function props(): array
    {
        return [
            'queue' => config('queue.default'),
            'env' => app()->environment(),
        ];
    }
}
```

Drop it under a discovered path and it is routed at `/admin/system-status`, named `panel.admin.pages.system-status`, and listed in the sidebar. It needs no Vue file: `$component` defaults to the generic renderer, which draws the heading, the breadcrumbs, and whatever `props()` returned.

## Declarations

| Property | Type | Default |
| --- | --- | --- |
| `$title` | `?string` | headline of the class basename |
| `$heading` | `?string` | `null` — follows the title |
| `$subheading` | `?string` | `null` |
| `$slug` | `?string` | kebab of the class basename |
| `$component` | `string` | `'panel/Page'` |
| `$navigationLabel` | `?string` | the title |
| `$navigationIcon` | `?string` | `null` |
| `$activeNavigationIcon` | `?string` | the navigation icon |
| `$navigationGroup` | `string\|BackedEnum\|null` | `null` |
| `$navigationSort` | `int` | `0` |
| `$shouldRegisterNavigation` | `bool` | `true` |
| `$cluster` | `?class-string<Cluster>` | `null` |
| `$middleware` | `list<string>` | `[]` |

```php
use BackedEnum;
use PandaPanel\Pages\Page;

final class Settings extends Page
{
    protected static ?string $title = 'Settings';
    protected static ?string $subheading = 'Application-wide configuration.';
    protected static ?string $slug = 'settings';
    protected static string $component = 'Panels/Admin/Pages/Settings';
    protected static ?string $navigationIcon = 'settings';
    protected static string|BackedEnum|null $navigationGroup = 'System';
    protected static int $navigationSort = 100;
}
```

`$component` is an Inertia page name resolved out of `resources/js/pages/**`. `Panels/Admin/Pages/Settings` is the application's own; `panel/Page` and the other `panel/…` names are the ones the package published.

## Static API

```php
public static function slug(): string;
public static function routePath(): string;                  // slug, or '{cluster}/{slug}'
public static function title(): string;
public static function heading(): string;                    // follows title()
public static function cluster(): ?string;
public static function activeNavigationIcon(): ?string;
public static function middleware(): array;
public static function canAccess(): bool;                    // true
public static function navigationItem(PanelContract $panel): ?NavigationItem;
public static function routeName(Panel|string|null $panel = null): string;
public static function url(Panel|string|null $panel = null): string;
public static function renderHookScope(): string;            // 'page:{slug}'
```

`routeName()` and `url()` resolve the current panel when none is passed, and throw `PanelRegistrationException::noCurrentPanel()` outside one.

```php
Settings::url();               // '/admin/settings'
Settings::url('app');          // the same page in the 'app' panel
Settings::routeName();         // 'panel.admin.pages.settings'
```

`routePath()` is overridable for a page whose URL should be nested while its slug stays one segment — the slug is a route name and a registry key, the path is what the address bar shows:

```php
public static function routePath(): string
{
    return 'settings/profile';
}
```

## Instance API

```php
public function props(): array;              // []
public function widgets(): array;            // [] — list<class-string<Widget>>
public function breadcrumbs(): array;        // list<Breadcrumb>
public function headerActions(): array;      // []
public function filterSchema(): ?FormSchema; // null
public function render(): Response;
```

`render()` opens with `abort_unless(static::canAccess(), 403)` and then sends five props: `page`, `widgets`, `widgetData`, `filters`, and whatever `props()` returned.

### `props()`

Serializable values only. This is the page's own payload.

```php
public function props(): array
{
    return [
        'settings' => [
            ['label' => 'Application name', 'value' => (string) config('app.name')],
            ['label' => 'Environment', 'value' => app()->environment()],
        ],
    ];
}
```

### `breadcrumbs()`

The default is `Dashboard` → the navigation group, if the page names one → the page title, marked current.

```php
use PandaPanel\Support\Breadcrumb;

public function breadcrumbs(): array
{
    return [
        Breadcrumb::make('Dashboard')->url($this->dashboardUrl()),
        Breadcrumb::make('Reports')->url(ReportsIndex::url()),
        Breadcrumb::make(static::title())->current(),
    ];
}
```

### `headerActions()`

Serialized action arrays, not `Action` objects. A standalone page renders links; the array shape is the one `Action::toArray()` produces.

```php
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Pages\Settings\ProfileSettings;

public function headerActions(): array
{
    return [[
        'name' => 'edit-profile',
        'label' => 'Edit profile',
        'icon' => 'settings',
        'variant' => ActionVariant::Default->value,
        'type' => 'link',
        'url' => ProfileSettings::url($this->panel()),
        'confirmation' => null,
    ]];
}
```

### `canAccess()`

Enforced by the route, not only by navigation. A page hidden from the sidebar but reachable by URL would be no protection at all.

```php
public static function canAccess(): bool
{
    return auth()->user()?->can('viewReports') === true;
}
```

It answers yes or no. For a concern that must *redirect* rather than refuse — password confirmation, a signed URL — use `$middleware`:

```php
use Illuminate\Auth\Middleware\RequirePassword;

protected static array $middleware = [RequirePassword::class];
```

Those are appended to the page's route on top of the panel's stack.

## Protected helpers

```php
protected function metadata(): array;
protected function panel(): Panel;
protected function dashboardUrl(): string;
protected function resolveWidgets(?WidgetFilters $filters = null): WidgetCollection;
protected function resolveFilters(): WidgetFilters;
protected function filterSessionKey(): string;               // 'panel.{id}.page.{slug}'
protected static function resolvePanel(Panel|string|null $panel): Panel;
```

`metadata()` builds the `page` prop: `title`, `heading`, `subheading`, `breadcrumbs`, `headerActions`, `scope`, `cluster`.

## Widgets on a page

```php
use PandaPanel\Widgets\Widget;

/** @return list<class-string<Widget>> */
public function widgets(): array
{
    return [UserStats::class, UserGrowth::class, RecentUsers::class];
}
```

Resolution runs through `PandaPanel\Pages\WidgetCollection`:

```php
public static function for(array $classes, ?PageContext $context = null, ?WidgetFilters $filters = null): self;
public static function filterSchemas(array $classes): array;   // array<string, FormSchema> keyed by widget id
public function merge(self $other): self;
public function definitions(): array;                          // list<array>
public function deferred(): mixed;                             // Inertia::defer(...) or null
```

`canView()` is asked first and once, so a widget the user may not see never has `data()` called and therefore never runs a query. Widgets are sorted by `[sort(), id()]`, so the order is the same on every request.

Eager widgets carry their data inline. Lazy ones ship a definition with null data plus one deferred prop holding every lazy widget's payload, so a slow aggregate delays only itself. When no widget is lazy, `deferred()` returns `null` and the page does not advertise a second request it will never make.

## Page-level filters

A page may declare one form that filters every widget on it.

```php
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\FormSchema;

public function filterSchema(): ?FormSchema
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
```

`null` — the default — sends `filters: null`, and the frontend renders nothing rather than an empty bar.

State lives in the query string, like table state, so a filtered dashboard is a link somebody can send. It is remembered per page rather than per panel: two dashboards filtered differently are two different questions.

A widget can still declare a filter of its own on top; a widget reads them merged, with its own winning.

## `Dashboard`

```php
namespace PandaPanel\Pages;

class Dashboard extends Page
{
    protected static ?string $title = 'Dashboard';
    protected static ?string $slug = 'dashboard';
    protected static string $component = 'panel/Dashboard';
    protected static ?string $navigationIcon = 'layout-grid';

    public function widgets(): array;       // the panel's widget registry
    public function breadcrumbs(): array;   // one crumb: 'Dashboard', current
}
```

A dashboard is a page whose widgets come from the panel's registry rather than from its own list. It renders at the panel root through `PanelDashboardController`, which resolves `Panel::getDashboard()`.

Subclass it for a second dashboard that names its own widgets:

```php
use PandaPanel\Pages\Dashboard;

final class AccountsDashboard extends Dashboard
{
    protected static ?string $title = 'Accounts';
    protected static ?string $slug = 'accounts';
    protected static ?string $navigationIcon = 'users';

    public function widgets(): array
    {
        return [UserStats::class, UserGrowth::class, RecentUsers::class];
    }
}
```

Then wire both on the panel:

```php
$panel->dashboards([
    Dashboard::class,
    AccountsDashboard::class,
]);
```

The first is the panel root. The rest are registered as ordinary pages, so each one authorizes, appears in navigation, and carries its own filters independently. `Panel::getExtraDashboards()` is that remainder.

## Built-in settings pages

Every panel gets three unless `Panel::settings(false)` says otherwise. They are ordinary `Page` subclasses, so they route, authorize, and appear in navigation like any other.

| Class | Slug | Path | Component | Group |
| --- | --- | --- | --- | --- |
| `PandaPanel\Pages\Settings\ProfileSettings` | `settings-profile` | `settings/profile` | `panel/settings/Profile` | Account, sort 10 |
| `PandaPanel\Pages\Settings\SecuritySettings` | `settings-security` | `settings/security` | `panel/settings/Security` | Account, sort 20 |
| `PandaPanel\Pages\Settings\AppearanceSettings` | `settings-appearance` | `settings/appearance` | `panel/settings/Appearance` | Account, sort 30 |

`ProfileSettings` renders only — saving still goes to the application's own profile controller, so there remains exactly one place that writes a profile.

`SecuritySettings` carries `RequirePassword` in `$middleware` rather than in `canAccess()`: a stale session must be sent to the confirmation screen, and `canAccess()` can only answer yes or no, which would turn a re-confirmation into a 403.

`AppearanceSettings` ships no props at all — the choice is held in local storage and a cookie by the frontend.

They join through `Panel::getPages()`, so discovery, caching, and route registration treat them exactly like any other page.

## Clusters

A cluster is a URL prefix and a shared sub-navigation. Membership is declared by the member:

```php
use PandaPanel\Clusters\Cluster;
use PandaPanel\Enums\ClusterPosition;

final class SettingsCluster extends Cluster
{
    protected static ?string $title = 'Settings';
    protected static ?string $navigationIcon = 'settings';
    protected static ClusterPosition $position = ClusterPosition::Header;
}
```

```php
final class RolesPage extends Page
{
    protected static ?string $cluster = SettingsCluster::class;
}
```

`RolesPage::routePath()` becomes `settings/roles`. The route *name* is untouched, so every `Page::url()` keeps working and only the path changes.

The cluster's sub-navigation is built from its members through `PandaPanel\Support\ClusterNavigation`, and each member's own authorization decides whether it appears. A cluster whose every member is refused renders nothing rather than an empty bar.

`ClusterPosition` is `Header`, `RightBar`, or `Sidebar`.

## Route registration

```php
$this->router
    ->get($page::routePath(), PanelPageController::class)
    ->defaults('page', $page)
    ->name('pages.'.$page::slug());
```

The page class is bound into the route defaults rather than taken from the URL, so the controller never resolves a class name from a request. Page slugs are validated against resource slugs at registration — `PageRegistry` throws `slugCollidesWithResource()` — so the two cannot shadow each other.

`Page::middleware()` is applied to the route only when it is non-empty.

## Render hooks

A page's `scope` in its metadata is `page:{slug}`. A hook registered on the panel with that scope renders only there:

```php
use PandaPanel\Enums\RenderHook;

$panel->renderHook(
    RenderHook::PageStart,
    'AnnouncementBanner',
    ['message' => 'Maintenance at 22:00'],
    [Settings::class],          // reduced to 'page:settings' here
);
```

Classes passed as scopes are reduced to slugs before serialization; no class name ever reaches Vue.

## Notes

- **A page with nothing bespoke to draw needs no Vue file.** `panel/Page` renders the heading, breadcrumbs, header actions, widgets, filters, and a generic body from `props()`.
- **`$component` is a build-time name, not a path.** Inertia resolves it out of `resources/js/pages/**`; a name the build never saw does not resolve.
- **`canAccess()` is static.** It runs before the page is constructed, so it cannot read anything the instance computed.
- **The dashboard's slug is `dashboard`, but it is reached at the panel root.** `PandaPanel\Pages\Dashboard` is not registered in the page registry: `PanelDashboardController` instantiates whatever `Panel::getDashboard()` names and calls `render()` on it at `/admin`. Only the *extra* dashboards from `dashboards()` get page routes of their own.
- **`title()` and `heading()` are separate on purpose.** The title is the browser tab; the heading is what is drawn. `heading()` follows `title()` until a page separates them.
- **A page is discovered only if it implements `PageContract`.** An abstract base page in the same directory is skipped rather than registered.

## See also

- [Custom pages](../pages-navigation/custom-pages.md)
- [Page authorization](../pages-navigation/authorization.md)
- [Breadcrumbs](../pages-navigation/breadcrumbs.md)
- [Headings](../pages-navigation/headings.md)
- [Clusters](../pages-navigation/clusters.md)
- [Sub-navigation](../pages-navigation/sub-navigation.md)
- [Page discovery](../pages-navigation/discovery.md)
- [Dashboards](../panels/dashboards.md)
- [Settings pages](../panels/settings-pages.md)
- [Render hooks](../panels/render-hooks.md)
- [Widgets overview](../widgets/overview.md)
- [Widgets reference](widgets.md)
- [Core API reference](core.md)
- [Contracts reference](contracts.md)
