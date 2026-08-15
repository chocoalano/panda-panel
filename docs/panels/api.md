# Panel API Reference

Every public method on `PandaPanel\Core\Panel`, plus the three types that surround it: `PanelProvider`, `PanelManager` and the `panel()` helper. Fluent setters keep bare names (`id()`, `path()`, `middleware()`); readers are prefixed `get` or `has`, because PHP cannot overload and a combined setter/getter returning `string|static` is exactly the kind of magic this framework avoids.

## Building a panel

```php
use PandaPanel\Core\Panel;

$panel = Panel::make('admin')
    ->path('admin')
    ->name('Administrator')
    ->auth()
    ->discoverResources(app_path('Panels/Admin/Resources'));

$panel->getId();     // 'admin'
$panel->getPath();   // 'admin'
```

```php
public static function make(?string $id = null): self
```

`make()` without an id leaves it unset, and `getId()` then throws `PandaPanel\Exceptions\PanelRegistrationException`. Inside a provider the id is already seeded from the class name.

## Identity

| Method | Signature | Default |
| --- | --- | --- |
| `id` | `id(string $id): self` | from `PanelProvider::panelId()` |
| `name` | `name(string $name): self` | `Str::headline($id)` |
| `path` | `path(string $path): self` | the id; slashes trimmed |
| `domain` | `domain(?string $domain): self` | `null` |
| `getId` | `getId(): string` | throws when never set |
| `getName` | `getName(): string` | |
| `getPath` | `getPath(): string` | |
| `getDomain` | `getDomain(): ?string` | |
| `getRouteNamePrefix` | `getRouteNamePrefix(): string` | `"panel.{$id}."` |
| `routeName` | `routeName(string $name): string` | |

```php
panel('admin')->routeName('dashboard');   // 'panel.admin.dashboard'
```

## Middleware and authentication

| Method | Signature | Default |
| --- | --- | --- |
| `middleware` | `middleware(array $middleware): self` | `['web']`, replaces |
| `authMiddleware` | `authMiddleware(array $middleware): self` | `[]`, replaces |
| `auth` | `auth(bool $verified = true): self` | merges `auth` (+ `verified`) |
| `getMiddleware` | `getMiddleware(): list<string>` | base + auth, deduplicated |
| `getBaseMiddleware` | `getBaseMiddleware(): list<string>` | |
| `getAuthMiddleware` | `getAuthMiddleware(): list<string>` | |
| `requireTwoFactor` | `requireTwoFactor(bool $required = true): self` | off |
| `requiresTwoFactor` | `requiresTwoFactor(): bool` | `false` |

```php
Panel::make('admin')->middleware(['web'])->auth()->getMiddleware();
// ['web', 'auth', 'verified']
```

## The panel's own front door

| Method | Signature | Default | Route registered |
| --- | --- | --- | --- |
| `login` | `login(bool $login = true): self` | `false` | `auth.login` at `{path}/login` |
| `registration` | `registration(bool $registration = true): self` | `false` | `auth.register` |
| `passwordReset` | `passwordReset(bool $passwordReset = true): self` | `false` | `auth.password.request`, `auth.password.reset` |
| `emailVerification` | `emailVerification(bool $emailVerification = true): self` | `false` | `auth.verification.notice` |
| `hasLogin` | `hasLogin(): bool` | | |
| `hasRegistration` | `hasRegistration(): bool` | | |
| `hasPasswordReset` | `hasPasswordReset(): bool` | | |
| `hasEmailVerification` | `hasEmailVerification(): bool` | | |

```php
$panel->login()->registration()->passwordReset();
```

The pages are registered outside the panel's auth stack and post to Fortify's own endpoints. `registration()` and the rest only register a page when `login()` is on.

## Access

| Method | Signature |
| --- | --- |
| `canAccess` | `canAccess(Closure $callback): self` — `Closure(?Authenticatable): bool` |
| `isAccessibleTo` | `isAccessibleTo(?Authenticatable $user): bool` |

```php
use Illuminate\Contracts\Auth\Authenticatable;

$panel->canAccess(static fn (?Authenticatable $user): bool => $user?->is_admin === true);
```

`isAccessibleTo()` also consults `PandaPanel\Contracts\PanelUser::canAccessPanel()` when the user model implements it. Both must agree.

## Registering classes

| Method | Signature |
| --- | --- |
| `resources` | `resources(array $resources): self` — class strings or `ResourceConfiguration` |
| `pages` | `pages(array $pages): self` |
| `widgets` | `widgets(array $widgets): self` |
| `discoverResources` | `discoverResources(string ...$paths): self` |
| `discoverPages` | `discoverPages(string ...$paths): self` |
| `discoverWidgets` | `discoverWidgets(string ...$paths): self` |
| `getResources` | `getResources(): list<class-string>` |
| `getPages` | `getPages(): list<class-string>` — settings pages first when enabled |
| `getWidgets` | `getWidgets(): list<class-string>` |
| `getResourceConfigurations` | `getResourceConfigurations(): list<ResourceConfiguration>` |
| `getResourceDiscoveryPaths` | `getResourceDiscoveryPaths(): list<string>` |
| `getPageDiscoveryPaths` | `getPageDiscoveryPaths(): list<string>` |
| `getWidgetDiscoveryPaths` | `getWidgetDiscoveryPaths(): list<string>` |

All six registration methods accumulate and deduplicate.

```php
Panel::make('modules')
    ->discoverResources('/one')
    ->discoverResources('/two', '/one')
    ->getResourceDiscoveryPaths();   // ['/one', '/two']
```

## Landing pages

| Method | Signature | Default |
| --- | --- | --- |
| `dashboard` | `dashboard(string $page): self` — `class-string<Page>` | `PandaPanel\Pages\Dashboard` |
| `dashboards` | `dashboards(array $pages): self` | first becomes the root, rest are pages |
| `getDashboard` | `getDashboard(): class-string<Page>` | |
| `getExtraDashboards` | `getExtraDashboards(): list<class-string<Page>>` | `[]` |

```php
$panel->dashboards([Dashboard::class, AccountsDashboard::class]);
$panel->getExtraDashboards();   // [AccountsDashboard::class]
```

## Built-in settings pages

| Method | Signature | Default |
| --- | --- | --- |
| `settings` | `settings(bool $settings = true): self` | `true` |
| `hasSettings` | `hasSettings(): bool` | `true` |

With it on, `getPages()` starts with `ProfileSettings`, `SecuritySettings` and `AppearanceSettings`.

## Navigation and shell

| Method | Signature | Default |
| --- | --- | --- |
| `navigationGroups` | `navigationGroups(array $groups): self` | `[]`, accumulates |
| `getNavigationGroups` | `getNavigationGroups(): list<string>` | |
| `getNavigationGroupParents` | `getNavigationGroupParents(): array<string, string>` | `[]` |
| `sidebar` | `sidebar(bool $collapsible = true, bool $defaultOpen = true, string $variant = 'sidebar', string $appearance = 'inset'): self` | as shown |
| `topNavigation` | `topNavigation(bool $topNavigation = true): self` | `variant = 'sidebar'` |
| `sidebarWidth` | `sidebarWidth(string $width, ?string $collapsedWidth = null): self` | `'16rem'` |
| `collapsedSidebarWidth` | `collapsedSidebarWidth(string $width): self` | `'3rem'` |
| `sidebarComponent` | `sidebarComponent(?string $component): self` | `null` |
| `topbarComponent` | `topbarComponent(?string $component): self` | `null` |
| `navigation` | `navigation(bool $navigation = true): self` | `true` |
| `topbar` | `topbar(bool $topbar = true): self` | `true` |
| `breadcrumbs` | `breadcrumbs(bool $breadcrumbs = true): self` | `true` |
| `userMenuItems` | `userMenuItems(array $items): self` | `[]`, accumulates |
| `maxContentWidth` | `maxContentWidth(?string $maxContentWidth): self` | `null` |
| `hasNavigation` | `hasNavigation(): bool` | |
| `hasTopbar` | `hasTopbar(): bool` | |
| `hasBreadcrumbs` | `hasBreadcrumbs(): bool` | |
| `getUserMenuItems` | `getUserMenuItems(): list<array<string, mixed>>` | |
| `getMaxContentWidth` | `getMaxContentWidth(): ?string` | |
| `getSidebar` | `getSidebar(): array<string, mixed>` | |
| `getShell` | `getShell(): array<string, mixed>` | |

```php
$panel->navigationGroups(['Content', 'System', 'Access' => 'System']);
$panel->getNavigationGroupParents();   // ['Access' => 'System']
```

## Branding and theme

| Method | Signature | Default |
| --- | --- | --- |
| `brandName` | `brandName(string $brandName): self` | `config('app.name')` |
| `brandLogo` | `brandLogo(?string $brandLogo): self` | `null` |
| `favicon` | `favicon(?string $favicon): self` | `null` |
| `icon` | `icon(?string $icon): self` | `null`, an icon registry key |
| `darkMode` | `darkMode(bool $darkMode = true): self` | `true` |
| `colors` | `colors(array $light, array $dark = []): self` | `[]` / `[]` |
| `cssHooks` | `cssHooks(array $classes): self` | `[]` |
| `getBrandName` | `getBrandName(): string` | |
| `getBrandLogo` | `getBrandLogo(): ?string` | |
| `getFavicon` | `getFavicon(): ?string` | |
| `getIcon` | `getIcon(): ?string` | |
| `hasDarkMode` | `hasDarkMode(): bool` | |
| `getTheme` | `getTheme(): array{light: array<string, string>, dark: array<string, string>}` | |
| `getCssHooks` | `getCssHooks(): array<string, string>` | |

Unknown colour properties, invalid colour values and unknown hook names are dropped rather than refused.

## Behaviour

| Method | Signature | Default |
| --- | --- | --- |
| `databaseTransactions` | `databaseTransactions(bool $databaseTransactions = true): self` | `true` |
| `strictAuthorization` | `strictAuthorization(bool $strictAuthorization = true): self` | `false` |
| `unsavedChangesAlerts` | `unsavedChangesAlerts(bool $unsavedChangesAlerts = true): self` | `true` |
| `bootUsing` | `bootUsing(Closure $callback): self` — `Closure(Panel): void` | none, accumulates |
| `configureActions` | `configureActions(Closure $callback): self` — `Closure(Action): void` | `null`, replaces |
| `hasDatabaseTransactions` | `hasDatabaseTransactions(): bool` | |
| `hasStrictAuthorization` | `hasStrictAuthorization(): bool` | |
| `hasUnsavedChangesAlerts` | `hasUnsavedChangesAlerts(): bool` | |
| `getBootCallbacks` | `getBootCallbacks(): list<Closure>` | |
| `actionConfigurator` | `actionConfigurator(): ?Closure` | |
| `boot` | `boot(): void` | runs plugins first, then boot callbacks |

`boot()` is called by `ResolvePanel` once per request, after the access check. Do not call it yourself.

## Navigation behaviour

| Method | Signature | Default |
| --- | --- | --- |
| `prefetch` | `prefetch(bool\|string $prefetch = 'hover'): self` — `'hover'`, `'mount'`, `'click'`, `true`, `false` | `'hover'` |
| `fullPageUrls` | `fullPageUrls(string ...$patterns): self` | `[]`, accumulates |
| `errorNotification` | `errorNotification(int $status, string $title, ?string $body = null): self` | see below |
| `hideErrorNotification` | `hideErrorNotification(int $status): self` | |
| `getPrefetch` | `getPrefetch(): ?string` | `null` when off |
| `getFullPageUrls` | `getFullPageUrls(): list<string>` | |
| `isFullPageUrl` | `isFullPageUrl(string $url): bool` | matches an absolute URL on its path |
| `getErrorNotifications` | `getErrorNotifications(): array<int, array{title: string, body: string\|null}\|null>` | |

Defaults shipped for 403, 404, 419, 429, 500 and 503; a panel replaces one without restating the rest. An entry set to `null` by `hideErrorNotification()` suppresses both the toast and Inertia's overlay.

```php
Panel::make('patterns')->fullPageUrls('/reports/*')->isFullPageUrl('https://example.test/reports/monthly');
// true
```

## Global search

| Method | Signature | Default |
| --- | --- | --- |
| `globalSearch` | `globalSearch(bool $enabled = true, int $limit = 50, int $debounce = 300, array $keyBindings = ['mod+k']): self` | as shown |
| `hasGlobalSearch` | `hasGlobalSearch(): bool` | `true` |
| `getGlobalSearchLimit` | `getGlobalSearchLimit(): int` | `50` |
| `getGlobalSearchDebounce` | `getGlobalSearchDebounce(): int` | `300` |
| `getGlobalSearchKeyBindings` | `getGlobalSearchKeyBindings(): list<string>` | `['mod+k']` |

The palette is absent unless a resource in the panel declares `$globalSearchAttributes`, even with `hasGlobalSearch()` true.

## Notifications and broadcasting

| Method | Signature | Default |
| --- | --- | --- |
| `notifications` | `notifications(bool $notifications = true): self` | `true` |
| `broadcasting` | `broadcasting(bool $broadcasting = true): self` | `true` |
| `hasNotifications` | `hasNotifications(): bool` | |
| `hasBroadcasting` | `hasBroadcasting(): bool` | |
| `getBroadcastChannel` | `getBroadcastChannel(?Authenticatable $user): ?string` | `null` when off or no user |

## Extension points

| Method | Signature | Default |
| --- | --- | --- |
| `assets` | `assets(string ...$entrypoints): self` | `[]`, accumulates |
| `getAssets` | `getAssets(): list<string>` | never serialized |
| `renderHook` | `renderHook(RenderHook $hook, string $component, array $data = [], array $scopes = []): self` | |
| `getRenderHooks` | `getRenderHooks(): array<string, list<array{component: string, data: array, scopes: list<string>}>>` | |
| `subNavigationPosition` | `subNavigationPosition(SubNavigationPosition $position): self` | `SubNavigationPosition::Top` |
| `getSubNavigationPosition` | `getSubNavigationPosition(): SubNavigationPosition` | |
| `plugins` | `plugins(array $plugins): self` — `PanelPlugin[]`, runs `register()` | |
| `getPlugins` | `getPlugins(): array<string, PanelPlugin>` | |
| `hasPlugin` | `hasPlugin(string $id): bool` | |
| `plugin` | `plugin(string $id): ?PanelPlugin` | |

```php
use PandaPanel\Enums\RenderHook;

$panel->renderHook(
    RenderHook::HeaderEnd,
    'Panels/Admin/Hooks/Announcement',
    ['message' => 'Maintenance at 5pm'],
    [UserResource::class],
);
```

Scopes are reduced to slugs at registration: a resource becomes `resource:{slug}`, a page becomes `page:{slug}`. Two plugins claiming one id throw.

## Tenancy

| Method | Signature |
| --- | --- |
| `tenant` | `tenant(string $model, Closure $resolver): self` — `Closure(Request, ?Authenticatable): ?Model` |
| `tenantUrlUsing` | `tenantUrlUsing(Closure $url): self` — `Closure(Model, Panel): string` |
| `getTenantUrl` | `getTenantUrl(Model $tenant): ?string` |
| `hasTenancy` | `hasTenancy(): bool` |
| `getTenantModel` | `getTenantModel(): ?class-string<Model>` |
| `resolveTenant` | `resolveTenant(Request $request, ?Authenticatable $user): ?Model` |

`resolveTenant()` is called by `ResolveTenant` and by nothing else. A resolver returning something other than the declared model is treated as no tenant. Without `tenantUrlUsing()` the tenant switcher does not render.

## What crosses to the frontend

```php
public function toSharedArray(): array
```

```php
[
    'id' => 'admin',
    'name' => 'Administrator',
    'path' => 'admin',
    'brandName' => 'Acme',
    'brandLogo' => null,
    'icon' => 'shield',
    'favicon' => null,
    'darkMode' => true,
    'maxContentWidth' => null,
    'unsavedChangesAlerts' => true,
    'prefetch' => 'hover',
    'errorNotifications' => [403 => ['title' => 'Not allowed', 'body' => '…'], /* ... */],
    'renderHooks' => [],
    'sidebar' => ['collapsible' => true, 'defaultOpen' => true, 'variant' => 'sidebar', 'appearance' => 'inset', 'width' => '16rem', 'collapsedWidth' => '3rem', 'component' => null],
    'shell' => ['navigation' => true, 'topbar' => true, 'breadcrumbs' => true, 'topbarComponent' => null, 'userMenuItems' => []],
    'theme' => ['light' => [], 'dark' => []],
    'cssHooks' => [],
]
```

Server-only configuration is deliberately absent: transactions, strict authorization, boot callbacks, middleware, discovery paths and the asset list never appear. Nothing here may hold a closure.

## `PanelProvider`

```php
namespace PandaPanel\Core;

abstract class PanelProvider
{
    abstract public function panel(Panel $panel): Panel;

    public function panelId(): string;   // 'AdminPanelProvider' → 'admin'

    public function build(): Panel;      // panel(Panel::make($this->panelId()))
}
```

Keep service resolution out of `panel()`: it runs during provider boot, before request-scoped bindings are warm.

## `PanelManager`

```php
namespace PandaPanel\Core;

final class PanelManager
{
    public function registerProvider(string $provider): Panel;   // class-string<PanelProvider>
    public function register(Panel $panel): Panel;

    /** @return list<Panel> sorted by id */
    public function all(): array;
    public function has(string $id): bool;
    public function get(string $id): Panel;                      // throws when unknown

    public function resolveFromRequest(Request $request): ?Panel;         // longest path first
    public function firstAccessibleTo(?Authenticatable $user): ?Panel;    // registration order

    public function setCurrentPanel(?Panel $panel): void;
    public function currentPanel(): ?Panel;
    public function hasCurrentPanel(): bool;

    public function resources(Panel|string $panel): ResourceRegistry;
    public function pages(Panel|string $panel): PageRegistry;
    public function widgets(Panel|string $panel): WidgetRegistry;
    public function navigation(Panel|string $panel): NavigationRegistry;
}
```

`register()` populates the registries; routes are registered separately by `PandaPanel\Routing\PanelRouteRegistrar`.

## The helper

```php
function panel(?string $id = null): ?Panel
```

```php
panel();          // the panel for this request, or null outside one
panel('admin');   // an explicit panel; throws PanelRegistrationException if unknown
```

## Contracts

```php
namespace PandaPanel\Contracts;

interface PanelContract
{
    public function getId(): string;
    public function getPath(): string;
    public function getDomain(): ?string;
    /** @return list<string> */
    public function getMiddleware(): array;
    public function isAccessibleTo(?Authenticatable $user): bool;
}

interface PanelUser
{
    public function canAccessPanel(Panel $panel): bool;
}
```

`PanelContract` is what the route registrar, the middleware and the navigation builder depend on. `navigationItem()` on resources and pages receives a `PanelContract`, which is why it narrows to `Panel` before calling panel-specific methods.

## Notes

- Setters return `self`, so every call chains. Nothing validates ordering; `sidebar()` after `topNavigation()` resets the variant because it sets all four of its arguments.
- Accumulating methods: `discoverResources`, `discoverPages`, `discoverWidgets`, `resources`, `pages`, `widgets`, `navigationGroups`, `fullPageUrls`, `assets`, `userMenuItems`, `bootUsing`, `cssHooks`, `colors`, `renderHook`, `plugins`. Replacing methods: everything else, including `middleware`, `authMiddleware`, `canAccess` and `configureActions`.
- Readers are safe to call at any time. Setters called after boot do not re-register routes.
- `Panel` is `final`. Extend behaviour with a plugin (`PanelPlugin`), which configures a panel through this same public API and nothing else.

## See also

- [Defining a Panel](defining-panels.md)
- [Panel IDs, Paths, and Domains](ids-paths-domains.md)
- [Middleware and Guards](middleware.md)
- [Panel Access Rules](access.md)
- [Branding, Logo, Icon, Favicon](branding.md)
- [Sidebar and Header Layouts](layouts.md)
- [Navigation Groups](navigation-groups.md)
- [Dashboards](dashboards.md)
- [Panel Assets](assets.md)
- [Panel Cache](cache.md)
- [Render Hooks](render-hooks.md)
- [Core API Reference](../api/core.md)
- [Plugin Contract](../plugins/contract.md)
