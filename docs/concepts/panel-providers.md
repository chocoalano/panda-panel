# Panel Providers

A panel provider is a class whose only job is to configure one panel. It is
where the fluent `Panel` API is called, and it is the unit `config/panda-panel.php`
lists. You reach for this page when adding a panel to an application, or when
you need to know how a configured panel becomes registered routes and
registries.

## A provider

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

```php
// config/panda-panel.php
'panels' => [
    App\Panels\Admin\AdminPanelProvider::class,
],
```

`php artisan make:panel Admin` writes exactly this file plus the three
directories, and prints the config line to add. It does not edit the config
file for you.

```bash
php artisan make:panel Admin
php artisan make:panel Admin --path=back-office
php artisan make:panel Admin --force
```

## The contract

`PandaPanel\Core\PanelProvider` is abstract and has three methods.

| Method | Signature | Notes |
| --- | --- | --- |
| `panel` | `abstract public function panel(Panel $panel): Panel` | The one thing a subclass writes. |
| `panelId` | `public function panelId(): string` | Derived from the class name unless overridden. |
| `build` | `public function build(): Panel` | `panel(Panel::make($this->panelId()))`. |

`panelId()` takes the class basename, drops a trailing `PanelProvider`, and
kebab-cases the rest:

```php
(new AdminPanelProvider)->panelId();        // 'admin'
(new BackOfficePanelProvider)->panelId();   // 'back-office'
```

Override it when the id should differ from the class name:

```php
final class AdminPanelProvider extends PanelProvider
{
    public function panelId(): string
    {
        return 'administration';
    }

    public function panel(Panel $panel): Panel
    {
        return $panel->path('admin');
    }
}
```

The id seeds `Panel::make()`, so `panel()` receives a panel that already knows
its id. Calling `->id()` inside `panel()` overrides it; there is no reason to
unless the provider is deliberately building a differently-named panel.

Keep service resolution out of `panel()`. It runs during provider boot, before
the container is warm for request-scoped bindings, and a panel is configured
once for every request that follows — anything per-user belongs in
`bootUsing()`.

## Registration

`PandaPanel\PandaPanelServiceProvider::boot()` reads the config list, skips any
entry that is not a `class-string<PanelProvider>` that currently resolves, and
registers the rest in order:

```php
foreach ($this->configuredPanels() as $provider) {
    if (! $manager->has((new $provider)->panelId())) {
        $manager->registerProvider($provider);
    }
}
```

Two consequences worth stating:

- A panel listed twice is registered once. Re-registering would run discovery
  a second time for no change in outcome.
- A class name that no longer resolves is skipped rather than fataling. A
  fatal during boot happens before any route exists, including the one that
  would have shown the error; `php artisan panel:cache` reports the same list
  somewhere the mistake is visible.

Registration order is what `firstAccessibleTo()` walks, so it decides where a
user lands when a request does not name a panel.

## Registering a panel from your own code

A package or a test can register a panel without touching config.

```php
use PandaPanel\Core\Panel;
use PandaPanel\Facades\PandaPanel;

// From a provider class:
PandaPanel::registerProvider(App\Panels\Admin\AdminPanelProvider::class);

// From a built panel:
PandaPanel::register(Panel::make('reports')->path('reports'));
```

| Method | Signature | Returns |
| --- | --- | --- |
| `registerProvider` | `registerProvider(string $provider): Panel` | The registered panel |
| `register` | `register(Panel $panel): Panel` | The same panel |

Both go through `PanelRegistry`, which refuses a duplicate id
(`PanelRegistrationException::duplicatePanelId()`) and a duplicate path/domain
pair (`duplicatePanelPath()`).

Routes are registered separately, by `PanelRouteRegistrar::registerAll()`,
after every configured panel is registered. A panel registered later than that
— from a test, or from a provider that boots after this one — has registries
but no routes. See [Routing](routing.md).

## PanelManager

`PandaPanel\Core\PanelManager` is a container singleton and the entry point for
everything panel-related. `PandaPanel\Facades\PandaPanel` is a facade over it.

```php
use PandaPanel\Core\PanelManager;

$manager = app(PanelManager::class);
```

| Method | Signature | Notes |
| --- | --- | --- |
| `registerProvider` | `registerProvider(string $provider): Panel` | Builds and registers. |
| `register` | `register(Panel $panel): Panel` | Registers and builds its registries. |
| `all` | `all(): list<Panel>` | Sorted by id. |
| `has` | `has(string $id): bool` | |
| `get` | `get(string $id): Panel` | Throws `PanelRegistrationException::unknownPanel()`. |
| `resolveFromRequest` | `resolveFromRequest(Request $request): ?Panel` | Longest path prefix first; honours `domain()`. |
| `firstAccessibleTo` | `firstAccessibleTo(?Authenticatable $user): ?Panel` | Registration order. |
| `currentPanel` | `currentPanel(): ?Panel` | Delegates to `PanelContext`. |
| `hasCurrentPanel` | `hasCurrentPanel(): bool` | |
| `setCurrentPanel` | `setCurrentPanel(?Panel $panel): void` | Called by `ResolvePanel`. |
| `resources` | `resources(Panel\|string $panel): ResourceRegistry` | |
| `pages` | `pages(Panel\|string $panel): PageRegistry` | |
| `widgets` | `widgets(Panel\|string $panel): WidgetRegistry` | |
| `navigation` | `navigation(Panel\|string $panel): NavigationRegistry` | |

```php
use Illuminate\Http\Request;

$manager->resolveFromRequest(Request::create('/admin/users/3/edit'))?->getId();  // 'admin'
$manager->resolveFromRequest(Request::create('/dashboard'));                     // null
```

## Registries

`register()` calls `buildRegistries()`, which merges explicit registration with
whatever `PanelManifest::for()` supplies — the cached list when one exists,
discovery otherwise. A class named in both appears once, because the
registries are keyed by slug and id.

Resource configurations are registered first, so a class configured for this
panel is never also registered bare and claiming its default slug.

### ResourceRegistry

Keyed by slug. The registry, not the class, owns the effective slug: the same
class may sit in two panels under different slugs.

```php
use App\Panels\Admin\Resources\Users\UserResource;

$resources = $manager->resources('admin');

$resources->all();                              // list<class-string>, sorted by class name
$resources->slugs();                            // list<string>
$resources->bySlug('users');                    // class-string|null
$resources->has('users');                       // bool
$resources->contains(UserResource::class);      // bool
$resources->slugFor(UserResource::class);       // 'users'
$resources->configurationFor(UserResource::class); // ResourceConfiguration|null
$resources->count();                            // int
```

`register(string|ResourceConfiguration $resource): void` throws
`duplicateResourceSlug()` when two classes claim one slug, and when one class
is registered twice under different slugs.

`slugFor()` on a class this panel does not hold falls back to the class's own
`defaultSlug()`, or `''` for a class that is not a `Resource` subclass.

### PageRegistry

Keyed by slug, and validated against the resource registry as well, so a page
can never shadow a resource route inside one panel.

```php
$pages = $manager->pages('admin');

$pages->all();                 // list<class-string>, sorted
$pages->bySlug('settings');    // class-string|null
$pages->has('settings');       // bool
$pages->count();               // int
```

`register(string $page): void` throws `duplicatePageSlug()` for two pages, and
`slugCollidesWithResource()` when a resource already claimed the slug.

### WidgetRegistry

Keyed by widget id, which is `Widget::id()` — kebab-cased class basename
unless the widget states its own.

```php
$widgets = $manager->widgets('admin');

$widgets->all();            // list<class-string>, sorted
$widgets->byId('user-stats');
$widgets->has('user-stats');
$widgets->count();
```

`register(string $widget): void` throws `duplicateWidgetId()`.

### NavigationRegistry

Sidebar group ordering for one panel. Groups the panel declared keep their
declared order; groups that only exist because a class referenced them are
appended alphabetically, so an undeclared group never reshuffles the sidebar
depending on discovery order.

```php
$navigation = $manager->navigation('admin');

$navigation->declaredGroups();           // list<string>, in declaration order
$navigation->isDeclared('System');       // bool
$navigation->isCollapsible('System');    // bool — false for the null group
$navigation->sortFor(null);              // -1, the ungrouped bucket
$navigation->sortFor('System');          // 0-based among declared groups
$navigation->sortFor('Reports', ['Reports', 'Audit']);  // 1000 + alphabetical position
```

`collapsible(bool $collapsible): self` mirrors the panel's
`sidebar(collapsible:)`, and is set for you when the registry is built.

## Turning registration off

`config/panda-panel.php` has three switches that affect what a provider's
registration does:

| Key | Default | Effect when `false` |
| --- | --- | --- |
| `register_routes` | `true` | No route group is registered for any panel. Registries still build. |
| `register_web_middleware` | `true` | The four `web` middleware are not pushed; register them yourself in `bootstrap/app.php`. |
| `register_guest_redirect` | `true` | `Authenticate::redirectUsing()` is left alone. |

The panel list itself is always read. A test harness that boots panels without
HTTP turns `register_routes` off.

## Notes

- `panel()` is called once per panel per process. Under Octane it is not
  re-run per request, so treat the panel as immutable configuration.
- Discovery runs during `register()` unless a manifest exists. On a cold boot
  with no manifest, every panel's discovery paths are scanned before the first
  route is matched — which is why production runs `panel:cache`.
- `PanelRegistrationException` is a `RuntimeException`. Nothing catches it, so
  a duplicate slug fails the boot rather than producing a half-registered
  panel.
- The provider class is never serialized anywhere. `panel:cache` stores
  resource, page, and widget class names per panel id, and nothing about the
  provider.

## See also

- [Panels](panels.md)
- [Discovery](discovery.md)
- [Caching](caching.md)
- [Routing](routing.md)
- [Panel Context](panel-context.md)
- [make:panel](../cli/make-panel.md)
- [Service Provider Configuration](../configuration/service-provider.md)
