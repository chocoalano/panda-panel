# Panels

A panel is one configured admin area: a URL prefix, a middleware stack, an
access rule, and the resources, pages, and widgets that live inside it. Every
panel is an instance of `PandaPanel\Core\Panel`, built once during provider
boot and never mutated by a request. You reach for this page when you need to
know what a panel can be told, and what it answers when asked.

## A minimal panel

Two edits. First, a provider that configures the panel:

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin;

use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelProvider;

final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->path('admin')
            ->name('Administrator')
            ->auth()
            ->discoverResources(app_path('Panels/Admin/Resources'))
            ->discoverPages(app_path('Panels/Admin/Pages'))
            ->discoverWidgets(app_path('Panels/Admin/Widgets'));
    }
}
```

Then list it in `config/panda-panel.php`:

```php
'panels' => [
    App\Panels\Admin\AdminPanelProvider::class,
],
```

That is a working panel at `/admin`, behind `web`, `auth`, and `verified`,
with a dashboard, the three account settings pages, and everything found under
the three discovery paths.

Panels are listed by hand on purpose. Registration order decides which panel a
user is sent to when a request does not name one, and adding a panel should be
a deliberate edit rather than a filesystem side effect. The classes *inside* a
panel are discovered — see [Discovery](discovery.md).

## Building a panel outside a provider

`Panel::make()` is the constructor everything else uses:

```php
use PandaPanel\Core\Panel;
use PandaPanel\Facades\PandaPanel;

$panel = Panel::make('reports')->path('reports');

PandaPanel::register($panel);
```

| Method | Signature | Notes |
| --- | --- | --- |
| `make` | `static make(?string $id = null): self` | Without an id you must call `id()` before anything reads it. |
| `register` | `PandaPanel::register(Panel $panel): Panel` | See [Panel Providers](panel-providers.md). |

`getId()` throws `PandaPanel\Exceptions\PanelRegistrationException` when no id
was ever set, rather than returning an empty string that would silently become
a route name of `panel..dashboard`.

## Naming conventions

Fluent setters keep the bare name (`->path()`, `->middleware()`); readers are
prefixed `get` (`getPath()`, `getMiddleware()`). PHP cannot overload, and a
combined setter/getter returning `string|static` is exactly the kind of magic
this framework avoids.

Two behaviours are worth knowing before the tables below:

- **Discovery paths, navigation groups, full-page patterns, and assets
  accumulate.** Calling them twice adds; it does not replace. That is what
  lets a module contribute to a panel without a core change.
- **`middleware()` replaces.** It is the base stack, and the default is
  `['web']`.

## Identity

```php
$panel
    ->id('admin')
    ->name('Administrator')
    ->path('back-office')
    ->domain('admin.example.com');
```

| Method | Signature | Default when unset |
| --- | --- | --- |
| `id` | `id(string $id): self` | Seeded from the provider class name. |
| `name` | `name(string $name): self` | `Str::headline($id)`. |
| `path` | `path(string $path): self` | The panel id. Slashes are trimmed. |
| `domain` | `domain(?string $domain): self` | `null` — matches every host. |

| Reader | Returns |
| --- | --- |
| `getId()` | `string`, throws when never set |
| `getName()` | `string` |
| `getPath()` | `string` |
| `getDomain()` | `string\|null` |
| `getRouteNamePrefix()` | `string` — `"panel.{id}."` |
| `routeName(string $name)` | `string` — the prefix plus `$name` |

```php
$panel->routeName('resources.users.index');   // panel.admin.resources.users.index
```

## Access

```php
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

$panel
    ->middleware(['web'])
    ->authMiddleware(['auth', 'verified'])
    ->auth()
    ->canAccess(static fn (?Authenticatable $user): bool => $user instanceof User && $user->is_admin);
```

| Method | Signature | Notes |
| --- | --- | --- |
| `middleware` | `middleware(array $middleware): self` | Replaces the base stack. Default `['web']`. |
| `authMiddleware` | `authMiddleware(array $middleware): self` | Replaces the auth stack, appended after the base one. |
| `auth` | `auth(bool $verified = true): self` | Merges `auth`, and `verified` unless `$verified` is false. |
| `canAccess` | `canAccess(Closure $callback): self` | `Closure(?Authenticatable): bool`. |

| Reader | Returns |
| --- | --- |
| `getMiddleware()` | `list<string>` — base plus auth, deduplicated |
| `getBaseMiddleware()` | `list<string>` |
| `getAuthMiddleware()` | `list<string>` |
| `isAccessibleTo(?Authenticatable $user)` | `bool` |

`isAccessibleTo()` asks two questions and both must agree: the panel's own
predicate, and `PanelUser::canAccessPanel()` on the user model when it
implements the contract. A user model implementing neither is refused nothing.
An authenticated user who fails the check gets **403**, not a redirect — see
[Authorization](authorization.md).

## Registration

```php
use App\Panels\Admin\Resources\Users\UserResource;
use App\Panels\Admin\Widgets\UserStats;

$panel
    ->resources([UserResource::class])
    ->pages([App\Panels\Admin\Pages\Settings::class])
    ->widgets([UserStats::class])
    ->discoverResources(app_path('Panels/Admin/Resources'))
    ->discoverPages(app_path('Panels/Admin/Pages'))
    ->discoverWidgets(app_path('Panels/Admin/Widgets'));
```

| Method | Signature |
| --- | --- |
| `resources` | `resources(array $resources): self` — class strings or `ResourceConfiguration` instances |
| `pages` | `pages(array $pages): self` |
| `widgets` | `widgets(array $widgets): self` |
| `discoverResources` | `discoverResources(string ...$paths): self` |
| `discoverPages` | `discoverPages(string ...$paths): self` |
| `discoverWidgets` | `discoverWidgets(string ...$paths): self` |

| Reader | Returns |
| --- | --- |
| `getResources()` | `list<class-string>` — explicitly registered only |
| `getResourceConfigurations()` | `list<ResourceConfiguration>` |
| `getPages()` | `list<class-string>` — the built-in settings pages first, unless `settings(false)` |
| `getWidgets()` | `list<class-string>` |
| `getResourceDiscoveryPaths()` | `list<string>` |
| `getPageDiscoveryPaths()` | `list<string>` |
| `getWidgetDiscoveryPaths()` | `list<string>` |

Explicit registration and discovery merge; a class named by both appears once.
What each panel actually holds is the registry, not these lists — see
[Panel Providers](panel-providers.md#registries).

## The landing page

```php
use PandaPanel\Pages\Dashboard;
use App\Panels\Admin\Pages\AccountsDashboard;

$panel->dashboards([
    Dashboard::class,
    AccountsDashboard::class,
]);
```

| Method | Signature | Notes |
| --- | --- | --- |
| `dashboard` | `dashboard(string $page): self` | A `class-string<Page>` rendered at the panel root. |
| `dashboards` | `dashboards(array $pages): self` | The first is the root; the rest get routes of their own. |

| Reader | Returns |
| --- | --- |
| `getDashboard()` | `class-string<Page>`, default `PandaPanel\Pages\Dashboard` |
| `getExtraDashboards()` | `list<class-string<Page>>` |

Extra dashboards are Page classes like any other, so each authorizes, appears
in navigation, and carries its own filters.

## Presentation

```php
$panel
    ->brandName('Acme Admin')
    ->brandLogo('/images/logo.svg')
    ->icon('shield')
    ->favicon('/favicon-admin.ico')
    ->darkMode()
    ->maxContentWidth('7xl');
```

| Method | Signature | Default |
| --- | --- | --- |
| `brandName` | `brandName(string $brandName): self` | `config('app.name')` |
| `brandLogo` | `brandLogo(?string $brandLogo): self` | `null` |
| `icon` | `icon(?string $icon): self` | `null` — an icon registry key, never a path |
| `favicon` | `favicon(?string $favicon): self` | `null` |
| `darkMode` | `darkMode(bool $darkMode = true): self` | `true` |
| `maxContentWidth` | `maxContentWidth(?string $maxContentWidth): self` | `null` |

`maxContentWidth` is a token the frontend maps to a literal class: `full`,
`7xl`, `6xl`, `5xl`, `4xl`, `3xl`. Anything else falls back to `max-w-full`,
because a Tailwind class built by interpolation would not exist in the bundle.

### Colours and CSS hooks

```php
$panel
    ->colors(
        light: ['primary' => '#4f46e5', 'sidebar' => 'oklch(0.98 0 0)'],
        dark: ['primary' => '#818cf8'],
    )
    ->cssHooks([
        'topbar' => 'border-b-2 border-amber-500',
        'table-row' => 'hover:bg-amber-50',
    ]);
```

| Method | Signature |
| --- | --- |
| `colors` | `colors(array $light, array $dark = []): self` |
| `cssHooks` | `cssHooks(array $classes): self` |
| `getTheme()` | `array{light: array<string, string>, dark: array<string, string>}` |
| `getCssHooks()` | `array<string, string>` |

Both guard silently. A colour property the stylesheet does not read is
dropped, and so is a value that does not parse as `#rgb`, `rgb()`, `hsl()`, or
`oklch()` — the value lands in a `style` attribute, and `red; content: url(…)`
is a stylesheet rather than a colour. `cssHooks` accepts only the eleven names
in `PandaPanel\Support\CssHooks::HOOKS`: `shell`, `sidebar`, `topbar`, `page`,
`page-header`, `table`, `table-row`, `form`, `infolist`, `widget`, `modal`.
Two calls targeting one hook append; both meant it.

## The shell

```php
$panel
    ->sidebar(collapsible: true, defaultOpen: true, variant: 'sidebar', appearance: 'inset')
    ->topNavigation(false)
    ->sidebarWidth('18rem', '4rem')
    ->navigation()
    ->topbar()
    ->breadcrumbs()
    ->sidebarComponent('Panels/Admin/Shell/Sidebar')
    ->topbarComponent('Panels/Admin/Shell/Topbar')
    ->userMenuItems([
        ['label' => 'Status page', 'url' => 'https://status.example.com', 'icon' => 'link'],
    ]);
```

| Method | Signature | Default |
| --- | --- | --- |
| `sidebar` | `sidebar(bool $collapsible = true, bool $defaultOpen = true, string $variant = 'sidebar', string $appearance = 'inset'): self` | as shown |
| `topNavigation` | `topNavigation(bool $topNavigation = true): self` | sets `variant` to `header` or `sidebar` |
| `sidebarWidth` | `sidebarWidth(string $width, ?string $collapsedWidth = null): self` | `'16rem'` |
| `collapsedSidebarWidth` | `collapsedSidebarWidth(string $width): self` | `'3rem'` |
| `navigation` | `navigation(bool $navigation = true): self` | `true` |
| `topbar` | `topbar(bool $topbar = true): self` | `true` |
| `breadcrumbs` | `breadcrumbs(bool $breadcrumbs = true): self` | `true` |
| `sidebarComponent` | `sidebarComponent(?string $component): self` | `null` |
| `topbarComponent` | `topbarComponent(?string $component): self` | `null` |
| `userMenuItems` | `userMenuItems(array $items): self` | `[]` |

`$variant` is `'sidebar'` or `'header'`. `$appearance` is `'sidebar'`,
`'floating'`, or `'inset'`, and the header shell ignores it. Widths are CSS
lengths because they become custom properties; a number would have to become a
class, and a class built by interpolation would not exist in the bundle.

The two component options take a build-time registry key under
`resources/js/pages/Panels/{Panel}/Shell/`, never markup and never a path —
see [Component Registries](component-registries.md).

| Reader | Returns |
| --- | --- |
| `getSidebar()` | `array{collapsible, defaultOpen, variant, appearance, width, collapsedWidth, component}` |
| `getShell()` | `array{navigation, topbar, breadcrumbs, topbarComponent, userMenuItems}` |
| `hasNavigation()`, `hasTopbar()`, `hasBreadcrumbs()` | `bool` |
| `getUserMenuItems()` | `list<array<string, mixed>>` |
| `getMaxContentWidth()` | `string\|null` |

## Behaviour

```php
$panel
    ->settings()
    ->databaseTransactions()
    ->strictAuthorization()
    ->unsavedChangesAlerts()
    ->bootUsing(fn (Panel $panel) => /* every request into this panel */ null);
```

| Method | Signature | Default | Reader |
| --- | --- | --- | --- |
| `settings` | `settings(bool $settings = true): self` | `true` | `hasSettings()` |
| `databaseTransactions` | `databaseTransactions(bool $databaseTransactions = true): self` | `true` | `hasDatabaseTransactions()` |
| `strictAuthorization` | `strictAuthorization(bool $strictAuthorization = true): self` | `false` | `hasStrictAuthorization()` |
| `unsavedChangesAlerts` | `unsavedChangesAlerts(bool $unsavedChangesAlerts = true): self` | `true` | `hasUnsavedChangesAlerts()` |
| `bootUsing` | `bootUsing(Closure $callback): self` | none | `getBootCallbacks()` |
| `configureActions` | `configureActions(Closure $callback): self` | none | `actionConfigurator()` |

`settings(true)` puts `ProfileSettings`, `SecuritySettings`, and
`AppearanceSettings` at the front of `getPages()`, so discovery, caching, and
route registration treat them exactly like any other page.

Boot callbacks accumulate and run in `ResolvePanel`, after the access check —
a user refused the panel never triggers its boot work. `boot()` runs plugin
`boot()` methods first, then the panel's own callbacks, so the application has
the last word.

`configureActions()` takes a `Closure(Action): void` applied to every action
the panel builds, as it is built, so a schema that states its own still wins:

```php
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Enums\ActionVariant;

$panel->configureActions(static function (Action $action): void {
    if ($action->getVariant() === ActionVariant::Destructive) {
        $action->requiresConfirmation();
    }
});
```

## Navigation behaviour

```php
$panel
    ->navigationGroups([
        'Content',
        'Access' => 'System',   // nests Access under System
    ])
    ->prefetch('hover')
    ->fullPageUrls('/admin/exports/*')
    ->errorNotification(403, 'Not allowed', 'Ask an administrator.')
    ->hideErrorNotification(404)
    ->subNavigationPosition(SubNavigationPosition::Start);
```

| Method | Signature | Default |
| --- | --- | --- |
| `navigationGroups` | `navigationGroups(array $groups): self` | `[]` — strings or backed enums; a string key names the parent |
| `prefetch` | `prefetch(bool\|string $prefetch = 'hover'): self` | `'hover'`; `false` becomes `null`, `true` becomes `'hover'` |
| `fullPageUrls` | `fullPageUrls(string ...$patterns): self` | `[]` — `Str::is()` patterns |
| `errorNotification` | `errorNotification(int $status, string $title, ?string $body = null): self` | defaults for 403, 404, 419, 429, 500, 503 |
| `hideErrorNotification` | `hideErrorNotification(int $status): self` | — |
| `subNavigationPosition` | `subNavigationPosition(SubNavigationPosition $position): self` | `SubNavigationPosition::Top` |

| Reader | Returns |
| --- | --- |
| `getNavigationGroups()` | `list<string>` |
| `getNavigationGroupParents()` | `array<string, string>` keyed by the child label |
| `getPrefetch()` | `'hover'\|'mount'\|'click'\|null` |
| `getFullPageUrls()` | `list<string>` |
| `isFullPageUrl(string $url)` | `bool` — matches the URL, and its path when absolute |
| `getErrorNotifications()` | `array<int, array{title: string, body: string\|null}\|null>` |
| `getSubNavigationPosition()` | `SubNavigationPosition` |

Error notifications merge over the framework defaults, so a panel customizes
one status without restating the rest. An entry set to `null` suppresses both
the toast and Inertia's overlay; a status with no entry at all is left to
Inertia.

## Search, notifications, broadcasting

```php
$panel
    ->globalSearch(enabled: true, limit: 50, debounce: 300, keyBindings: ['mod+k'])
    ->notifications()
    ->broadcasting();
```

| Method | Signature | Default |
| --- | --- | --- |
| `globalSearch` | `globalSearch(bool $enabled = true, int $limit = 50, int $debounce = 300, array $keyBindings = ['mod+k']): self` | as shown |
| `notifications` | `notifications(bool $notifications = true): self` | `true` |
| `broadcasting` | `broadcasting(bool $broadcasting = true): self` | `true` |

| Reader | Returns |
| --- | --- |
| `hasGlobalSearch()` | `bool` |
| `getGlobalSearchLimit()` | `int` — across the whole search, not per resource |
| `getGlobalSearchDebounce()` | `int` milliseconds |
| `getGlobalSearchKeyBindings()` | `list<string>` |
| `hasNotifications()` | `bool` |
| `hasBroadcasting()` | `bool` |
| `getBroadcastChannel(?Authenticatable $user)` | `string\|null` |

`getBroadcastChannel()` is null when broadcasting is off or nobody is signed
in, so the frontend has nothing to subscribe to rather than a channel it would
be refused.

## Panel authentication

```php
$panel
    ->login()
    ->registration()
    ->passwordReset()
    ->emailVerification()
    ->requireTwoFactor();
```

| Method | Signature | Default | Reader |
| --- | --- | --- | --- |
| `login` | `login(bool $login = true): self` | `false` | `hasLogin()` |
| `registration` | `registration(bool $registration = true): self` | `false` | `hasRegistration()` |
| `passwordReset` | `passwordReset(bool $passwordReset = true): self` | `false` | `hasPasswordReset()` |
| `emailVerification` | `emailVerification(bool $emailVerification = true): self` | `false` | `hasEmailVerification()` |
| `requireTwoFactor` | `requireTwoFactor(bool $required = true): self` | `false` | `requiresTwoFactor()` |

`login()` registers the panel's own guest pages outside its auth middleware,
and changes where a guest opening a panel URL is sent. `registration()`,
`passwordReset()`, and `emailVerification()` only add pages, and only when
`login()` is on — see [Routing](routing.md#guest-routes).

## Tenancy

```php
use App\Models\Team;
use Illuminate\Http\Request;

$panel
    ->tenant(Team::class, fn (Request $request) => Team::query()
        ->where('slug', $request->route('team'))
        ->first())
    ->tenantUrlUsing(fn (Team $team, Panel $panel): string => "/{$panel->getPath()}/{$team->slug}");
```

| Method | Signature |
| --- | --- |
| `tenant` | `tenant(string $model, Closure $resolver): self` — `Closure(Request, ?Authenticatable): ?Model` |
| `tenantUrlUsing` | `tenantUrlUsing(Closure $url): self` — `Closure(Model, Panel): string` |

| Reader | Returns |
| --- | --- |
| `hasTenancy()` | `bool` |
| `getTenantModel()` | `class-string<Model>\|null` |
| `resolveTenant(Request $request, ?Authenticatable $user)` | `Model\|null` |
| `getTenantUrl(Model $tenant)` | `string\|null` |

A resolver returning something other than the declared model is treated as no
tenant, which is a 404 — a mistyped resolver returning the *user* would
otherwise scope every query by a user id and look, at a glance, like it
worked. Without `tenantUrlUsing()` the switcher does not render.

## Plugins

```php
$panel->plugins([
    new AcmeBillingPlugin,
]);
```

| Method | Signature |
| --- | --- |
| `plugins` | `plugins(array $plugins): self` — `PanelPlugin` instances |
| `getPlugins()` | `array<string, PanelPlugin>` keyed by plugin id |
| `hasPlugin(string $id)` | `bool` |
| `plugin(string $id)` | `PanelPlugin\|null` |

`register()` runs immediately, while the panel is being built. `boot()` runs
in `boot()`, once the panel is resolved for a request. A duplicate plugin id
throws `PanelRegistrationException::duplicatePlugin()`.

## Render hooks and assets

```php
use PandaPanel\Enums\RenderHook;
use App\Panels\Admin\Resources\Users\UserResource;

$panel
    ->renderHook(
        RenderHook::HeaderEnd,
        'Panels/Admin/Hooks/Announcement',
        ['message' => 'Maintenance at 5pm'],
        [UserResource::class],
    )
    ->assets('resources/css/panels/admin.css');
```

| Method | Signature |
| --- | --- |
| `renderHook` | `renderHook(RenderHook $hook, string $component, array $data = [], array $scopes = []): self` |
| `assets` | `assets(string ...$entrypoints): self` |
| `getRenderHooks()` | `array<string, list<array{component: string, data: array, scopes: list<string>}>>` |
| `getAssets()` | `list<string>` |

Scopes are reduced to slugs at registration: a `Resource` subclass becomes
`resource:{slug}`, a `Page` subclass becomes `page:{slug}`, and anything else
is taken as already being one. No class name is ever serialized. An empty
scope list means every page in the panel.

`RenderHook` has eight cases: `BodyStart`, `BodyEnd`, `SidebarStart`,
`SidebarEnd`, `HeaderStart`, `HeaderEnd`, `PageStart`, `PageEnd`.

Assets are Vite entrypoints, appended to the application's own on that panel's
pages and nowhere else. The list never crosses to the frontend — see
[Frontend Assets](frontend-assets.md).

## What crosses to Vue

```php
$panel->toSharedArray();
```

Returns `id`, `name`, `path`, `brandName`, `brandLogo`, `icon`, `favicon`,
`darkMode`, `maxContentWidth`, `unsavedChangesAlerts`, `prefetch`,
`errorNotifications`, `renderHooks`, `sidebar`, `shell`, `theme`, and
`cssHooks`. Nothing else: transactions, strict authorization, boot callbacks,
middleware, discovery paths, and the asset list are server concerns and stay
on the server. `SharePanelData` puts the result on every panel page as the
`panel` prop — see [Server Metadata to Vue](metadata-to-vue.md).

## Notes

- A second panel with the same id throws
  `PanelRegistrationException::duplicatePanelId()`. A second panel with the
  same path *and* domain throws `duplicatePanelPath()`. Both are developer
  errors that would otherwise surface as one route silently shadowing another.
- `PandaPanel::all()` returns panels sorted by id, so route registration order
  is stable across runs. `firstAccessibleTo()` walks that same order.
- Path matching resolves longest-first, so a panel at `/admin/reports` wins
  over one at `/admin` for a request to `/admin/reports/x`.
- `getPages()` includes the built-in settings pages; `getResources()` and
  `getWidgets()` include only what was registered by hand. Discovered classes
  live in the registries, not on the panel.
- A panel is configured once at boot and shared across requests in a
  long-running worker. Anything per-user belongs in a boot callback or in the
  page, never in `panel()`.

## See also

- [Panel Providers](panel-providers.md)
- [Request Lifecycle](request-lifecycle.md)
- [Panel Context](panel-context.md)
- [Discovery](discovery.md)
- [Routing](routing.md)
- [Authorization](authorization.md)
- [Caching](caching.md)
- [Defining Panels](../panels/defining-panels.md)
- [Panel API](../panels/api.md)
