# Custom Pages

`PandaPanel\Pages\Page` is a panel screen that is not a resource: no model, no records, no table. You reach for one when the panel needs something that is not CRUD — a settings screen, a report, a second dashboard, an import console — and you still want the shell, the navigation entry, the breadcrumbs, the header, and the authorization that every other panel screen gets.

A page needs no Vue file. Leaving `$component` at its default renders the generic page shell, so the cheapest useful page is a class with a title and nothing else.

## A minimal working example

```bash
php artisan make:panel-page Settings --panel=Admin
```

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Pages;

use BackedEnum;
use PandaPanel\Pages\Page;

final class Settings extends Page
{
    protected static ?string $title = 'Settings';

    protected static ?string $subheading = 'Application-wide configuration.';

    protected static ?string $navigationIcon = 'settings';

    protected static string|BackedEnum|null $navigationGroup = 'System';

    protected static int $navigationSort = 100;

    /**
     * @return array<string, mixed>
     */
    public function props(): array
    {
        return [
            'settings' => [
                ['label' => 'Environment', 'value' => app()->environment()],
                ['label' => 'Timezone', 'value' => (string) config('app.timezone')],
            ],
        ];
    }
}
```

With `discoverPages(app_path('Panels/Admin/Pages'))` on the panel, that is the whole setup. The page is now at `/admin/settings`, routed as `panel.admin.pages.settings`, listed in the sidebar under `System`, and rendered by `resources/js/pages/panel/Page.vue`.

## The route

One GET route per page, registered by `PandaPanel\Routing\PanelRouteRegistrar` inside the panel's group.

| Piece | Value | Comes from |
| --- | --- | --- |
| Route name | `panel.{panelId}.pages.{slug}` | `Page::slug()` |
| Path | `{panelPath}/{routePath}` | `Page::routePath()` |
| Controller | `PandaPanel\Http\Controllers\PanelPageController` | fixed |
| Middleware | the panel's stack, plus `Page::middleware()` | `$middleware` |

The page class is bound into the route defaults rather than read from the URL, so the controller never resolves a class name from a request. It does one thing:

```php
return (new $page)->render();
```

`slug()` and `routePath()` are separate on purpose. The slug is the route name and the registry key; the path is what the address bar shows. Override `routePath()` to put a page on a nested path while its slug stays one segment — which is exactly what the built-in settings pages do:

```php
use PandaPanel\Pages\Page;

final class ProfileSettings extends Page
{
    protected static ?string $slug = 'settings-profile';

    public static function routePath(): string
    {
        return 'settings/profile';
    }
}
```

That page answers at `/admin/settings/profile` and is still named `panel.admin.pages.settings-profile`.

## Static properties

All are `protected static` and all have a working default.

| Property | Type | Default | Effect |
| --- | --- | --- | --- |
| `$title` | `?string` | `Str::headline(class_basename())` | Browser tab title, and the fallback for everything else |
| `$heading` | `?string` | `title()` | The `<h1>` above the content |
| `$subheading` | `?string` | `null` | The line under the heading |
| `$slug` | `?string` | `Str::kebab(class_basename())` | Route name suffix and registry key |
| `$component` | `string` | `'panel/Page'` | The Inertia component to render |
| `$navigationLabel` | `?string` | `title()` | Sidebar label |
| `$navigationIcon` | `?string` | `null` | Icon registry key |
| `$activeNavigationIcon` | `?string` | `$navigationIcon` | The icon worn while the item is active |
| `$navigationGroup` | `string\|BackedEnum\|null` | `null` | Sidebar heading; `null` is the ungrouped bucket |
| `$navigationSort` | `int` | `0` | Order inside the group |
| `$shouldRegisterNavigation` | `bool` | `true` | `false` keeps the route and drops the sidebar entry |
| `$cluster` | `class-string<Cluster>\|null` | `null` | The cluster this page belongs to |
| `$middleware` | `list<string>` | `[]` | Appended to this page's route |

```php
use BackedEnum;
use Illuminate\Auth\Middleware\RequirePassword;
use PandaPanel\Clusters\Cluster;
use PandaPanel\Pages\Page;

final class AuditLog extends Page
{
    protected static ?string $title = 'Audit log';

    protected static ?string $heading = 'Recent activity';

    protected static ?string $subheading = 'Everything written in the last 30 days.';

    protected static ?string $slug = 'audit';

    protected static string $component = 'Panels/Admin/Pages/AuditLog';

    protected static ?string $navigationLabel = 'Audit';

    protected static ?string $navigationIcon = 'shield';

    protected static ?string $activeNavigationIcon = 'shield-check';

    protected static string|BackedEnum|null $navigationGroup = 'System';

    protected static int $navigationSort = 20;

    protected static bool $shouldRegisterNavigation = true;

    /** @var class-string<Cluster>|null */
    protected static ?string $cluster = null;

    /** @var list<string> */
    protected static array $middleware = [RequirePassword::class];
}
```

`$middleware` exists for what authorization cannot express. `canAccess()` answers yes or no, so turning "confirm your password first" into a `canAccess()` check would produce a 403 where a redirect to the confirmation screen is what the user needs. See [Page authorization](authorization.md).

## Static methods

```php
public static function slug(): string;
public static function routePath(): string;
public static function cluster(): ?string;
public static function title(): string;
public static function heading(): string;
public static function activeNavigationIcon(): ?string;
public static function middleware(): array;                 // list<string>
public static function canAccess(): bool;
public static function navigationItem(PanelContract $panel): ?NavigationItem;
public static function routeName(Panel|string|null $panel = null): string;
public static function url(Panel|string|null $panel = null): string;
public static function renderHookScope(): string;
```

```php
use App\Panels\Admin\Pages\Settings;

Settings::slug();            // 'settings'
Settings::routePath();       // 'settings'
Settings::title();           // 'Settings'
Settings::heading();         // 'Settings'
Settings::routeName('admin');// 'panel.admin.pages.settings'
Settings::url('admin');      // '/admin/settings'
Settings::renderHookScope(); // 'page:settings'
```

`routeName()` and `url()` take a `Panel`, a panel id, or nothing. Nothing means the panel resolved for the current request, and outside a panel request that throws `PandaPanel\Exceptions\PanelRegistrationException`. `url()` is always route-name based, so a panel that changes its path moves every link at once. See [Full page URLs](urls.md).

`renderHookScope()` is a slug, never a class name — nothing in page metadata may name a PHP class. See [Render hooks](../panels/render-hooks.md).

## Instance methods

```php
public function props(): array;                                  // array<string, mixed>
public function widgets(): array;                                // list<class-string<Widget>>
public function breadcrumbs(): array;                            // list<Breadcrumb>
public function headerActions(): array;                          // list<array<string, mixed>>
public function filterSchema(): ?FormSchema;
public function render(): Inertia\Response;

protected function metadata(): array;                            // array<string, mixed>
protected function filterSessionKey(): string;
protected function resolveFilters(): WidgetFilters;
protected function resolveWidgets(?WidgetFilters $filters = null): WidgetCollection;
protected function panel(): Panel;
protected function dashboardUrl(): string;
protected static function resolvePanel(Panel|string|null $panel): Panel;
```

### `props()`

Serializable values only. They are spread last into the Inertia response, so a page prop wins over the framework's own keys of the same name.

```php
use App\Models\Invoice;

/**
 * @return array<string, mixed>
 */
public function props(): array
{
    return [
        'outstanding' => Invoice::query()->whereNull('paid_at')->count(),
        'currency' => config('app.currency'),
    ];
}
```

### `widgets()`

Widget classes in the order they should appear. A page hosts widgets exactly as a dashboard does, because the dashboard is itself a page.

```php
use App\Panels\Admin\Widgets\RecentUsers;
use App\Panels\Admin\Widgets\UserStats;
use PandaPanel\Widgets\Widget;

/**
 * @return list<class-string<Widget>>
 */
public function widgets(): array
{
    return [UserStats::class, RecentUsers::class];
}
```

`PandaPanel\Pages\WidgetCollection` filters by `Widget::canView()` **before** instantiating anything, so an unauthorized widget never runs a query, then sorts by `[sort, id]`. Lazy widgets ship a definition with null data plus one deferred prop holding every lazy payload. See [Widgets](../widgets/overview.md) and [Lazy loading](../widgets/lazy-loading.md).

### `breadcrumbs()`

Returns `list<PandaPanel\Support\Breadcrumb>`. The default trail is dashboard → navigation group → this page:

```php
use PandaPanel\Support\Breadcrumb;

/**
 * @return list<Breadcrumb>
 */
public function breadcrumbs(): array
{
    return [
        Breadcrumb::make('Dashboard')->url($this->dashboardUrl()),
        Breadcrumb::make('Reports')->url('/admin/reports'),
        Breadcrumb::make('Throughput')->current(),
    ];
}
```

See [Breadcrumbs](breadcrumbs.md).

### `headerActions()`

Plain arrays matching the frontend's `ActionDefinition`, rendered to the right of the heading by `panel/Page`.

```php
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Pages\Settings\ProfileSettings;

/**
 * @return list<array<string, mixed>>
 */
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

Use `'type' => 'link'` with a `url`. The generic page renderer draws each entry with `ActionButton`, and a non-link button emits a `run` event that nothing on a standalone page listens for. A page that needs a callback action must render its own component and wire `useActions()` itself.

### `filterSchema()`

One form every widget on the page reads. Null by default, which is why the frontend renders no filter bar at all rather than an empty one.

```php
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\FormSchema;

public function filterSchema(): FormSchema
{
    return FormSchema::make()->schema([
        Select::make('period')
            ->label('Period')
            ->options(['month' => 'This month', 'year' => 'This year'])
            ->default('month'),
    ]);
}
```

State is remembered per page, under `panel.{panelId}.page.{slug}` — `filterSessionKey()` — because two dashboards filtered differently are two different questions. See [Widget filters](../widgets/filters.md).

The filter bar is drawn by `panel/Dashboard`, not by `panel/Page`. A page that declares filters should extend `PandaPanel\Pages\Dashboard`, set `$component = 'panel/Dashboard'`, or render the bar in its own component.

### `render()`

Rarely overridden. It authorizes, resolves filters and widgets, and returns the Inertia response:

```php
abort_unless(static::canAccess(), 403);

return Inertia::render(static::$component, [
    'page' => $this->metadata(),
    'widgets' => $widgets->definitions(),
    'widgetData' => $widgets->deferred(),
    'filters' => $schema === null ? null : ['form' => $schema->toArrayWithState(null, $filters->dashboard())],
    ...$this->props(),
]);
```

### `metadata()`

The `page` prop every panel screen ships:

| Key | Type | Source |
| --- | --- | --- |
| `title` | `string` | `title()` |
| `heading` | `string` | `heading()` |
| `subheading` | `string\|null` | `$subheading` |
| `breadcrumbs` | `list<array>` | `breadcrumbs()` |
| `headerActions` | `list<array>` | `headerActions()` |
| `scope` | `string` | `renderHookScope()` |
| `cluster` | `array\|null` | `ClusterNavigation::for()`, null outside a cluster |

Override it to compute a subheading at runtime — there is no `subheading()` accessor on `Page`:

```php
/**
 * @return array<string, mixed>
 */
protected function metadata(): array
{
    return [
        ...parent::metadata(),
        'subheading' => 'Last run '.$this->lastRunAt()->diffForHumans(),
    ];
}
```

### `panel()` and `dashboardUrl()`

`panel()` returns the panel resolved for the current request and throws `PanelRegistrationException::noCurrentPanel()` outside one. `dashboardUrl()` is that panel's root, used by the default breadcrumb trail.

## Giving a page its own Vue component

```bash
php artisan make:panel-page AuditLog --panel=Admin --component
```

That writes two files:

| File | Purpose |
| --- | --- |
| `app/Panels/Admin/Pages/AuditLog.php` | the page class, with `$component = 'Panels/Admin/Pages/AuditLog'` |
| `resources/js/pages/Panels/Admin/Pages/AuditLog.vue` | the component |

`$component` is an Inertia component name resolved against `resources/js/pages`, so `panel/Page` is `resources/js/pages/panel/Page.vue` and `Panels/Admin/Pages/AuditLog` is `resources/js/pages/Panels/Admin/Pages/AuditLog.vue`. The root of the generated tree is configurable through `panda-panel.frontend.pages_path`.

```vue
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PageHeader from '@/panel/components/PageHeader.vue';
import PanelLayout from '@/panel/layouts/PanelLayout.vue';
import type { PageMetadata } from '@/panel/types/page';

defineOptions({ layout: PanelLayout });

defineProps<{
    page: PageMetadata;
    settings: { label: string; value: string }[];
}>();
</script>

<template>
    <Head :title="page.title" />

    <div class="flex flex-col gap-6">
        <PageHeader :heading="page.heading" :subheading="page.subheading" />

        <dl class="grid gap-2">
            <div v-for="row in settings" :key="row.label" class="flex gap-2">
                <dt class="text-muted-foreground">{{ row.label }}</dt>
                <dd>{{ row.value }}</dd>
            </div>
        </dl>
    </div>
</template>
```

`defineOptions({ layout: PanelLayout })` is what puts the page inside the panel shell, and it is the one line you have to add yourself: `stubs/panel/page-component.stub` writes only a `<Head>` and a `PageHeader`. A component that names no layout takes whatever the application's Inertia entry gives a page it has no case for, which on the starter kit is the signed-in application shell — HTTP 200, the host's sidebar, and the panel's own navigation nowhere.

## The generator

```bash
php artisan make:panel-page {name} --panel=Admin [--component] [--force]
```

| Option | Effect |
| --- | --- |
| `--panel=` | Required. `Admin` and `admin` both mean the same panel; the studly form is canonical |
| `--component` | Also writes the Vue file and points `$component` at it |
| `--force` | Overwrite files that already exist |

Without `--panel` the command errors and returns a failure exit code. Existing files are skipped with a warning rather than clobbered. Publish `stubs/panel/page.stub` into the application to change what is generated. See [make:panel-page](../cli/make-panel-page.md).

## Registering the page

Discovery is the normal route:

```php
$panel->discoverPages(app_path('Panels/Admin/Pages'));
```

Explicit registration works too and merges with discovery, so a class named in both appears once:

```php
$panel->pages([\App\Panels\Admin\Pages\Settings::class]);
```

See [Page discovery](discovery.md).

## Gotchas

- **A hidden page is still reachable by URL, and still refuses.** `$shouldRegisterNavigation = false` only removes the sidebar entry. `render()` starts with `abort_unless(static::canAccess(), 403)`, which is the actual control.
- **Page props override framework props.** `props()` is spread last, so returning a key named `page`, `widgets`, `widgetData` or `filters` replaces the framework's. Name page props for what they hold.
- **A page slug may not collide with a resource slug in the same panel.** `PageRegistry` throws `PanelRegistrationException::slugCollidesWithResource()` at registration rather than letting two routes fight over one path.
- **The root dashboard is not a page route.** It answers at the panel path and is registered separately, so it has no `pages.*` route. Extra dashboards declared with `dashboards()` do get one. See [Dashboards](../panels/dashboards.md).
- **`filters` is sent even when the component ignores it.** `panel/Page` declares no `filters` prop; only `panel/Dashboard` renders the bar.
- **Header actions on a standalone page must be links.** Nothing on the generic renderer handles a callback action's `run` event.
- **`panel()` throws outside a panel request.** A page instantiated in a unit test needs `app(PanelManager::class)->setCurrentPanel(panel('admin'))` first, which is what the framework's own tests do.
- **A generated Vue component declares no layout.** The published components under `resources/js/pages/panel` all declare one — a test asserts it — but the `--component` stub does not, so add `defineOptions({ layout: PanelLayout })` to anything the generator writes.
- **`make:panel-page` exits non-zero when every file it would write already exists.** `report()` returns failure when nothing was created and something was skipped, which is what makes `--force` visible in CI rather than a silent no-op.

## See also

- [Page discovery](discovery.md)
- [Page authorization](authorization.md)
- [Page headings](headings.md)
- [Breadcrumbs](breadcrumbs.md)
- [Clusters](clusters.md)
- [Sub navigation](sub-navigation.md)
- [Full page URLs](urls.md), [Prefetching](prefetching.md), [Error notifications](error-notifications.md)
- [Dashboards](../panels/dashboards.md), [Settings pages](../panels/settings-pages.md)
- [Navigation groups](../panels/navigation-groups.md), [Render hooks](../panels/render-hooks.md)
- [Widgets](../widgets/overview.md), [Widget filters](../widgets/filters.md)
- [Resource pages](../resources/resource-pages.md)
- [make:panel-page](../cli/make-panel-page.md)
