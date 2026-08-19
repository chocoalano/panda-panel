# Core API Reference

The types that make a panel exist: the builder, the provider that configures it, the manager that holds it, the registries it fills, and the request-scoped context every other part reads. Reach for this page when you are writing something that has to *find* a panel rather than something that lives inside one.

## Namespaces

| Class | Purpose |
| --- | --- |
| `PandaPanel\Core\Panel` | One panel's configuration |
| `PandaPanel\Core\PanelProvider` | The class an application writes to configure a panel |
| `PandaPanel\Core\PanelManager` | Registration, resolution, and the current panel |
| `PandaPanel\Core\PanelRegistry` | Every registered panel, keyed by id |
| `PandaPanel\Core\ResourceRegistry` | One panel's resources, keyed by slug |
| `PandaPanel\Core\PageRegistry` | One panel's pages, keyed by slug |
| `PandaPanel\Core\WidgetRegistry` | One panel's widgets, keyed by id |
| `PandaPanel\Core\NavigationRegistry` | One panel's declared sidebar groups |
| `PandaPanel\Support\PanelContext` | The current panel, request-scoped |
| `PandaPanel\Facades\PandaPanel` | The manager, without constructor injection |
| `PandaPanel\Discovery\PanelDiscoverer` | Finds classes under a panel's discovery paths |
| `PandaPanel\Discovery\ClassResolver` | Turns a file path into a class name |
| `PandaPanel\Cache\PanelManifest` | The cached class lists |
| `PandaPanel\Cache\DiscoveryFingerprint` | Whether a manifest still describes the tree |
| `PandaPanel\Routing\PanelRouteRegistrar` | One route group per panel |
| `PandaPanel\Support\NavigationBuilder` | The sidebar, per request |
| `PandaPanel\Support\NavigationItem` | One entry in it |
| `PandaPanel\Support\Breadcrumb` | One crumb in a trail |
| `PandaPanel\Clusters\Cluster` | A prefix and a sub-navigation shared by several classes |
| `PandaPanel\PandaPanelServiceProvider` | The wiring |

## A panel, end to end

```php
<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
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
            ->canAccess(static fn (?Authenticatable $user): bool => $user instanceof User && $user->is_admin);
    }
}
```

```php
// config/panda-panel.php
'panels' => [
    App\Providers\AdminPanelProvider::class,
],
```

That is the whole registration. The provider is listed by hand; the classes *inside* the panel are discovered.

```php
use PandaPanel\Facades\PandaPanel;

PandaPanel::has('admin');            // true
PandaPanel::get('admin')->getPath(); // 'admin'
panel()?->getId();                   // 'admin' inside a panel request, null outside one
```

## `Panel`

`final class`. Every setter returns `self`; every reader is prefixed `get`, `has`, or `is`. `Panel::make()` and the provider's `build()` are the only two ways one is constructed.

```php
public static function make(?string $id = null): self;
```

Without an id the panel is unnamed and `getId()` throws `PanelRegistrationException::missingPanelId()`. Inside a provider the id is already seeded from the class name, so `->id()` is only for a panel that wants a different one.

### Method index

Grouped by what they configure. Every one is documented with its default in [Panel API reference](../panels/api.md); this table is the map.

| Group | Setters | Readers |
| --- | --- | --- |
| Identity | `id`, `name`, `path`, `domain` | `getId`, `getName`, `getPath`, `getDomain` |
| Middleware | `middleware`, `authMiddleware`, `auth` | `getMiddleware`, `getBaseMiddleware`, `getAuthMiddleware` |
| Access | `canAccess`, `requireTwoFactor` | `isAccessibleTo`, `requiresTwoFactor` |
| Panel auth pages | `login`, `registration`, `passwordReset`, `emailVerification` | `hasLogin`, `hasRegistration`, `hasPasswordReset`, `hasEmailVerification` |
| Registration | `resources`, `pages`, `widgets` | `getResources`, `getPages`, `getWidgets`, `getResourceConfigurations` |
| Discovery | `discoverResources`, `discoverPages`, `discoverWidgets` | `getResourceDiscoveryPaths`, `getPageDiscoveryPaths`, `getWidgetDiscoveryPaths` |
| Landing page | `dashboard`, `dashboards` | `getDashboard`, `getExtraDashboards` |
| Navigation | `navigationGroups`, `navigation`, `topbar`, `breadcrumbs`, `userMenuItems` | `getNavigationGroups`, `getNavigationGroupParents`, `hasNavigation`, `hasTopbar`, `hasBreadcrumbs`, `getUserMenuItems` |
| Shell | `sidebar`, `topNavigation`, `sidebarWidth`, `collapsedSidebarWidth`, `sidebarComponent`, `topbarComponent`, `maxContentWidth` | `getSidebar`, `getShell`, `getMaxContentWidth` |
| Branding | `brandName`, `brandLogo`, `darkBrandLogo`, `icon`, `darkIcon`, `favicon`, `darkFavicon`, `darkMode`, `colors`, `cssHooks` | `getBrandName`, `getBrandLogo`, `getDarkBrandLogo`, `getIcon`, `getDarkIcon`, `getFavicon`, `getDarkFavicon`, `hasDarkMode`, `getTheme`, `getCssHooks` |
| Behaviour | `databaseTransactions`, `strictAuthorization`, `unsavedChangesAlerts`, `settings`, `bootUsing`, `configureActions` | `hasDatabaseTransactions`, `hasStrictAuthorization`, `hasUnsavedChangesAlerts`, `hasSettings`, `getBootCallbacks`, `actionConfigurator` |
| Navigation behaviour | `prefetch`, `fullPageUrls`, `errorNotification`, `hideErrorNotification` | `getPrefetch`, `getFullPageUrls`, `isFullPageUrl`, `getErrorNotifications` |
| Search and realtime | `globalSearch`, `broadcasting`, `notifications` | `hasGlobalSearch`, `getGlobalSearchLimit`, `getGlobalSearchDebounce`, `getGlobalSearchKeyBindings`, `hasBroadcasting`, `getBroadcastChannel`, `hasNotifications` |
| Extension | `assets`, `renderHook`, `subNavigationPosition`, `plugins` | `getAssets`, `getRenderHooks`, `getSubNavigationPosition`, `getPlugins`, `hasPlugin`, `plugin` |
| Tenancy | `tenant`, `tenantUrlUsing` | `hasTenancy`, `getTenantModel`, `resolveTenant`, `getTenantUrl` |
| Routing | — | `getRouteNamePrefix`, `routeName` |
| Serialization | — | `toSharedArray` |
| Lifecycle | — | `boot` |

### Defaults worth knowing

```php
private array $middleware = ['web'];
private bool $darkMode = true;
private bool $settings = true;
private bool $databaseTransactions = true;      // on, unlike Filament
private bool $strictAuthorization = false;
private bool $unsavedChangesAlerts = true;
private ?string $prefetch = 'hover';
private bool $broadcasting = true;
private bool $notifications = true;
private bool $globalSearch = true;              // but searches nothing until a resource opts in
private int $globalSearchLimit = 50;
private int $globalSearchDebounce = 300;
private array $globalSearchKeyBindings = ['mod+k'];
private string $sidebarWidth = '16rem';
private string $collapsedSidebarWidth = '3rem';
private string $sidebarVariant = 'sidebar';     // or 'header'
private string $sidebarAppearance = 'inset';    // or 'floating', 'sidebar'
private SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;
private string $dashboard = PandaPanel\Pages\Dashboard::class;
```

`getName()` falls back to `Str::headline($id)`, `getPath()` to the id, and `getBrandName()` to `config('app.name')`.

### Accumulating setters

`discoverResources()`, `discoverPages()`, `discoverWidgets()`, `assets()`, `fullPageUrls()`, `navigationGroups()`, `pages()`, `widgets()`, `resources()`, `userMenuItems()`, `renderHook()`, and `bootUsing()` all add to what is there. Everything else replaces.

```php
$panel
    ->discoverResources(app_path('Panels/Admin/Resources'))
    ->discoverResources(base_path('modules/Billing/Resources')); // both are scanned
```

That is what lets a plugin contribute to a panel without displacing the panel's own configuration.

### `boot()`

```php
public function boot(): void;
```

Runs the plugins' `boot()` first, then the panel's own `bootUsing()` callbacks — so the application always gets the last word over a plugin it installed. Called once per request by `ResolvePanel`, after access has been granted, and never during registration.

```php
$panel->bootUsing(function (Panel $panel): void {
    $panel->cssHooks(['page' => 'tenant-'.tenant()?->getKey()]);
});
```

### `toSharedArray()`

```php
public function toSharedArray(): array;
```

The `panel` prop. Keys: `id`, `name`, `path`, `brandName`, `brandLogo`, `darkBrandLogo`, `icon`, `darkIcon`, `favicon`, `darkFavicon`, `darkMode`, `maxContentWidth`, `unsavedChangesAlerts`, `prefetch`, `errorNotifications`, `renderHooks`, `sidebar`, `shell`, `theme`, `cssHooks`. Middleware, discovery paths, transactions, and strict authorization are deliberately absent: they are server concerns and never cross.

## `PanelProvider`

```php
abstract class PanelProvider
{
    abstract public function panel(Panel $panel): Panel;

    public function panelId(): string;   // 'AdminPanelProvider' => 'admin'
    public function build(): Panel;      // $this->panel(Panel::make($this->panelId()))
}
```

`panel()` runs during provider boot, before the container is warm for request-scoped bindings. Resolve nothing there; use `bootUsing()` for anything that needs a container, a user, or a URL.

## `PanelManager`

Bound as a singleton. Injected, or reached through the facade.

```php
public function registerProvider(string $provider): Panel;   // class-string<PanelProvider>
public function register(Panel $panel): Panel;
public function all(): array;                                // list<Panel>, sorted by id
public function has(string $id): bool;
public function get(string $id): Panel;                      // throws unknownPanel()
public function resolveFromRequest(Request $request): ?Panel;
public function firstAccessibleTo(?Authenticatable $user): ?Panel;
public function setCurrentPanel(?Panel $panel): void;
public function currentPanel(): ?Panel;
public function hasCurrentPanel(): bool;
public function resources(Panel|string $panel): ResourceRegistry;
public function pages(Panel|string $panel): PageRegistry;
public function widgets(Panel|string $panel): WidgetRegistry;
public function navigation(Panel|string $panel): NavigationRegistry;
```

`register()` fills the four registries immediately, merging the panel's explicit classes with whatever the manifest supplies — the cached list when one exists, discovery otherwise.

```php
use PandaPanel\Core\PanelManager;

$manager = app(PanelManager::class);

$manager->register(
    Panel::make('reports')->path('reports')->auth(),
);

$manager->resources('reports')->slugs();   // ['invoices', 'ledgers']
```

`resolveFromRequest()` matches on domain then on path prefix, longest path first, so a panel at `/admin/reports` wins over one at `/admin`. `firstAccessibleTo()` walks `PanelRegistry::all()`, which is sorted by panel id, so an `admin` panel is considered before an `app` one however `config('panda-panel.panels')` is ordered.

Queue and console work that needs a panel sets one explicitly:

```php
$manager->setCurrentPanel($manager->get('admin'));
```

Both `RunPanelExport` and `RunPanelImport` do exactly that before touching a resource.

## `panel()` and the facade

```php
function panel(?string $id = null): ?Panel;
```

No id returns the panel resolved for this request, or `null` outside one. With an id it returns that panel and throws `PanelRegistrationException` when it is not registered — an unknown id is a developer error, not a state.

```php
use PandaPanel\Facades\PandaPanel;

PandaPanel::all();                        // list<Panel>
PandaPanel::currentPanel();               // Panel|null
PandaPanel::firstAccessibleTo(auth()->user());
PandaPanel::resolveFromRequest(request());
```

The facade proxies `PanelManager`. Use `panel()` for "which panel am I in" and the facade for everything else.

## `PanelContext`

Bound with `$this->app->scoped()`, so it lives exactly as long as one request or one test case. Nothing here is static, which is what keeps panel state from leaking between requests under Octane and between tests.

```php
public function setPanel(?Panel $panel): void;
public function panel(): ?Panel;
public function hasPanel(): bool;
public function set(string $key, mixed $value): void;
public function get(string $key, mixed $default = null): mixed;
public function forget(): void;      // clears the panel and the bag
```

`ResetPanelContext` calls `forget()` at the start of every `web` request.

## Registries

### `ResourceRegistry`

```php
public function register(string|ResourceConfiguration $resource): void;
public function configurationFor(string $resource): ?ResourceConfiguration;
public function slugFor(string $resource): string;
public function has(string $slug): bool;
public function bySlug(string $slug): ?string;
public function contains(string $resource): bool;
public function all(): array;      // list<class-string>
public function slugs(): array;    // list<string>
public function count(): int;
```

The registry owns the slug, not the class: the same resource may be `users` in one panel and `people` in another. Two classes claiming one slug throw `PanelRegistrationException::duplicateResourceSlug()`.

### `PageRegistry`

```php
public function register(string $page): void;
public function has(string $slug): bool;
public function bySlug(string $slug): ?string;
public function all(): array;
public function count(): int;
```

Constructed with the panel's `ResourceRegistry`, so a page whose slug is already a resource slug throws `slugCollidesWithResource()` rather than shadowing it at route-matching time.

### `WidgetRegistry`

```php
public function register(string $widget): void;
public function has(string $id): bool;
public function byId(string $id): ?string;
public function all(): array;
public function count(): int;
```

### `NavigationRegistry`

```php
public function __construct(array $groups = []);
public function collapsible(bool $collapsible): self;
public function isCollapsible(?string $label): bool;
public function declaredGroups(): array;
public function isDeclared(string $label): bool;
public function sortFor(?string $label, array $undeclared = []): int;
```

Groups named in `Panel::navigationGroups()` keep that order; anything else is appended.

## Discovery

```php
use PandaPanel\Discovery\PanelDiscoverer;

$discoverer = app(PanelDiscoverer::class);

$discoverer->resources($panel);  // list<class-string<ResourceContract>>
$discoverer->pages($panel);      // list<class-string<PageContract>>
$discoverer->widgets($panel);    // list<class-string<WidgetContract>>
```

A class is included only when it is concrete and implements the expected contract; a base class or a trait in the same directory is skipped silently. Results are sorted by class name, so two machines produce the same manifest.

```php
use PandaPanel\Discovery\ClassResolver;

ClassResolver::forPath($path);   // ?string — reads the file's namespace and class
```

## `PanelManifest`

```php
public static function path(): string;                // bootstrap/cache/panels.php
public function exists(): bool;
public function for(Panel $panel): array;             // {resources, pages, widgets}
public function write(PanelRegistry $registry): array;
public function clear(): bool;
public function warnIfStale(PanelRegistry $registry): void;
```

Only class names are stored. Authorization results, badge values, active navigation state, record data, and widget data are all per-user or per-URL and are recomputed every request.

```bash
php artisan panel:cache    # write it
php artisan panel:clear    # remove it
php artisan optimize       # includes panel:cache
```

`DiscoveryFingerprint::of($panels)` hashes what discovery would find; `isStale($panels, $recorded)` is what `warnIfStale()` asks in a development environment.

## Route names

Every panel route is named `panel.{id}.*`. `Panel::routeName('dashboard')` builds one; `getRouteNamePrefix()` returns `panel.{id}.`.

| Name | Verb and path | Controller |
| --- | --- | --- |
| `dashboard` | `GET /` | `PanelDashboardController` |
| `search` | `GET /search` | `PanelSearchController` |
| `options` | `GET /options` | `PanelFormOptionsController` |
| `uploads` | `POST /uploads` | `PanelUploadController` |
| `form-state` | `POST /form-state` | `PanelFormStateController` |
| `export-file` | `GET /exports/{file}` | `PanelExportController` |
| `import-file` | `GET /imports/{file}` | `PanelImportController` |
| `notifications.index` / `.read` / `.clear` | `GET`/`POST`/`POST` under `/notifications` | `PanelNotificationController` |
| `auth.two-factor.challenge` / `.send` / `.verify` / `.enable` / `.disable` | under `/two-factor` | `PanelTwoFactorController` |
| `actions.record` / `.bulk` / `.reorder` / `.cell` / `.table` / `.infolist` | `POST` under `/actions` | `PanelActionController` |
| `actions.form` / `actions.submit` | `GET`/`POST /actions/form` | `PanelActionFormController` |
| `relations.form` / `.save` / `.action` / `.bulk` | under `/relations` | `PanelRelationController` |
| `pages.{slug}` | `GET {routePath}` | `PanelPageController` |
| `resources.{slug}.{page}` | per `Resource::pages()` | the page class itself |
| `auth.login`, `auth.register`, `auth.password.request`, `auth.password.reset`, `auth.verification.notice` | guest routes, only when `login()` is on | `PanelAuthController` |

Every route points at a controller method, never a closure, so `route:cache` keeps working.

## Middleware

| Class | Alias | Where it runs |
| --- | --- | --- |
| `ResetPanelContext` | — | `web` group, first |
| `RedirectPanelHome` | — | `web` group |
| `ShareFlashToast` | — | `web` group |
| `SharePanelData` | — | `web` group |
| `ResolvePanel` | `panel` | each panel route group, with the panel id as a parameter |
| `RequireTwoFactor` | `panel.two-factor` | after `ResolvePanel` |
| `RequireEmailCode` | `panel.email-code` | after `RequireTwoFactor` |
| `ResolveTenant` | — | last, only when the panel declares `tenant()` |
| `ResolveParentRecord` | `panel.parent` | on a nested resource's route group |

The registrar names the classes directly. The aliases exist for applications that want to reference them in their own route definitions.

## Shared Inertia props

`SharePanelData` shares ten props, each as a closure, so a request that never reaches a panel pays for none of them.

| Prop | Shape |
| --- | --- |
| `panel` | `Panel::toSharedArray()`, or null outside a panel |
| `navigation` | `list<array>` from `NavigationBuilder` |
| `panels` | the panels this user may enter, for the switcher |
| `broadcasting` | `{enabled, channel}` |
| `search` | `{enabled, url, debounce, keyBindings}` |
| `notifications` | `{enabled, indexUrl, readUrl, clearUrl, unread}` |
| `tenancy` | `{current, available}` or null |

The application's own `HandleInertiaRequests` is untouched — this merges through `Inertia::share()`.

## `NavigationItem`

```php
public static function make(
    string $label,
    string $href,
    ?string $icon = null,
    string|int|Closure|null $badge = null,
    int $sort = 0,
    string|BackedEnum|null $group = null,
    array $children = [],
    ?string $activeIcon = null,
): self;

public function withActive(bool $active): self;
public function withChildren(array $children): self;
public function withFullPage(bool $fullPage): self;
public function resolveBadge(): string|int|null;
public function toArray(): array;
```

A badge may be a closure; it is invoked on the server and only its result crosses.

## `Breadcrumb`

```php
public static function make(string $label): self;
public function url(?string $href): self;
public function current(bool $current = true): self;
public function toArray(): array;   // {label, href, current}
```

`readonly` — each method returns a new instance. Labels are plain text; Vue renders them as text, so never build one containing markup.

## `Cluster`

```php
abstract class Cluster
{
    protected static ?string $title = null;
    protected static ?string $slug = null;
    protected static ?string $navigationIcon = null;
    protected static ?string $activeNavigationIcon = null;
    protected static string|BackedEnum|null $navigationGroup = null;
    protected static int $navigationSort = 0;
    protected static bool $shouldRegisterNavigation = true;
    protected static ClusterPosition $position = ClusterPosition::Header;

    public static function title(): string;
    public static function slug(): string;
    public static function navigationIcon(): ?string;
    public static function activeNavigationIcon(): ?string;
    public static function navigationGroup(): string|BackedEnum|null;
    public static function navigationSort(): int;
    public static function shouldRegisterNavigation(): bool;
    public static function position(): ClusterPosition;
    public static function canAccess(): bool;                        // true
    public static function navigationItem(Panel $panel): ?NavigationItem;  // null
}
```

`title()` and `slug()` both strip a trailing `Cluster` from the class name. Membership is declared by the member — a resource or page sets `protected static ?string $cluster = SettingsCluster::class;` — so nothing is kept in two lists that can disagree. A cluster prefixes the *path* only; route names are untouched.

## Enums

| Enum | Cases |
| --- | --- |
| `PandaPanel\Enums\RenderHook` | `BodyStart`, `BodyEnd`, `SidebarStart`, `SidebarEnd`, `HeaderStart`, `HeaderEnd`, `PageStart`, `PageEnd` |
| `PandaPanel\Enums\SubNavigationPosition` | `Top`, `Start`, `End` |
| `PandaPanel\Enums\ClusterPosition` | `Header`, `RightBar`, `Sidebar` |

## Configuration keys

`config/panda-panel.php`, merged from the package.

| Key | Default | Effect |
| --- | --- | --- |
| `panels` | `[]` | The provider classes to register, in order |
| `register_routes` | `true` | Whether the package registers the route groups |
| `register_web_middleware` | `true` | Whether the four `web` middleware are appended |
| `register_guest_redirect` | `true` | Whether a guest on a panel URL is sent to that panel's login |
| `home_redirect.enabled` | `true` | Whether a signed-in user on `/dashboard` is sent into a panel |
| `home_redirect.paths` | `['dashboard']` | `Request::is()` patterns handed over |
| `load_migrations` | `true` | Whether the package's two migrations run from the package |
| `integrations.allowed_hosts` | `[]` | Allowlist for outbound integration URLs |
| `integrations.block_private_networks` | `true` | Refuse unresolved, private, loopback, and link-local hosts |
| `integrations.history.enabled` | `true` | Keep a delivery log |
| `integrations.history.keep_per_integration` | `50` | Hard row cap per integration |
| `integrations.history.retention_days` | `30` | Window; `0` keeps only the cap |
| `frontend.panel_path` | `'js/panel'` | Where the panel's Vue tree is published |
| `frontend.pages_path` | `'js/pages/Panels'` | Where generators write panel-specific pages |

## Publish tags

```bash
php artisan vendor:publish --tag=panda-panel-config
php artisan vendor:publish --tag=panda-panel-migrations
php artisan vendor:publish --tag=panda-panel-assets
php artisan vendor:publish --tag=panda-panel-stubs
php artisan vendor:publish --tag=panda-panel      # config + migrations + assets
```

## Notes

- **A panel that is listed twice is registered once.** The service provider checks `$manager->has((new $provider)->panelId())` first, so re-listing a provider does not run discovery a second time.
- **A provider class that no longer resolves is skipped, not fatal.** Failing during boot would happen before any route existed to show the error. `panel:cache` reports the same list, where a mistake is visible.
- **Two panels cannot share a path and domain.** `PanelRegistry::register()` throws `duplicatePanelPath()`, because the second panel's routes would be unreachable.
- **`getMiddleware()` is base plus auth, deduplicated.** `getBaseMiddleware()` is what the guest auth routes get — session, CSRF, and Inertia, but not `auth`.
- **`Panel::plugins()` runs `register()` immediately.** Compatibility is asserted first, so an incompatible plugin never gets to change the panel.
- **Nothing dynamic reaches Vue.** Icons, custom columns, fields, widgets, and shell components are all build-time registry keys. A name that is not registered renders nothing rather than being fetched.

## See also

- [Panels](../concepts/panels.md)
- [Panel providers](../concepts/panel-providers.md)
- [Panel API reference](../panels/api.md)
- [Panel context](../concepts/panel-context.md)
- [Discovery](../concepts/discovery.md)
- [Routing](../concepts/routing.md)
- [Caching](../concepts/caching.md)
- [Configuration reference](../configuration/panda-panel.md)
- [Contracts reference](contracts.md)
- [Exceptions reference](exceptions.md)
- [Events, jobs and controllers](events-jobs-controllers.md)
