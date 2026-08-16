# Route Registration

Panel routes are registered during boot, one group per panel, from
`PandaPanel\Routing\PanelRouteRegistrar`. One config key turns the whole thing off. Reach for this
page when you want to control *when* panel routes are registered — a test harness that boots
panels without HTTP, a package that registers a panel of its own after boot — or when you need to
know what registration still does after you have switched it off.

## A minimal working example

```php
// config/panda-panel.php

'register_routes' => true,
```

```bash
php artisan route:list --path=admin
```

```php
use Illuminate\Support\Facades\Route;

Route::has('panel.admin.dashboard');                          // true
route('panel.admin.dashboard', absolute: false);              // '/admin'
route('panel.admin.resources.users.index', absolute: false);  // '/admin/users'
```

That is the default. A panel listed in `config('panda-panel.panels')` answers on its path with no
further step.

## What registration does

`PandaPanelServiceProvider::boot()` calls the registrar once, after every panel is registered:

```php
private function registerRoutes(): void
{
    if ($this->app->make('config')->get('panda-panel.register_routes') !== true) {
        return;
    }

    $this->app->make(PanelRouteRegistrar::class)->registerAll();
}
```

One group per panel:

```php
$attributes = [
    'prefix' => $panel->getPath(),
    'as' => $panel->getRouteNamePrefix(),          // 'panel.admin.'
    'middleware' => [
        ...$panel->getMiddleware(),                 // ['web', 'auth', 'verified']
        ResolvePanel::class.':'.$panel->getId(),
        RequireTwoFactor::class.':'.$panel->getId(),
        RequireEmailCode::class.':'.$panel->getId(),
        ...($panel->hasTenancy() ? [ResolveTenant::class.':'.$panel->getId()] : []),
    ],
];

if ($panel->getDomain() !== null) {
    $attributes['domain'] = $panel->getDomain();
}
```

Inside that group the registrar adds, in order: the panel's guest auth pages (outside the auth
stack), the dashboard, search, form options, uploads, form state, export and import downloads, the
notification centre, the two-factor challenge, the action endpoints, the relation endpoints, every
standalone page, and every resource's pages. The complete inventory of names, verbs and paths is
in [Routing](../concepts/routing.md).

Two properties hold for all of them. Every route points at a controller method, never a closure,
so `php artisan route:cache` keeps working. And the panel id is passed to `ResolvePanel` as a
middleware parameter rather than matched from the path, so two panels sharing a prefix are never
ambiguous.

## `PandaPanel\Routing\PanelRouteRegistrar`

```php
public function __construct(Registrar $router, PanelManager $manager);

public function registerAll(): void;

public function register(Panel $panel): void;
```

The class is a container singleton, constructed with Laravel's `Illuminate\Contracts\Routing\Registrar`
and the panel manager.

```php
use PandaPanel\Routing\PanelRouteRegistrar;

app(PanelRouteRegistrar::class)->registerAll();          // every registered panel
app(PanelRouteRegistrar::class)->register(panel('admin')); // one
```

`register()` is the method to call for a panel that was registered after boot. Laravel caches
route name lookups, so refresh them afterwards or `route('panel.x.dashboard')` will not resolve:

```php
use Illuminate\Support\Facades\Route;
use PandaPanel\Core\Panel;
use PandaPanel\Facades\PandaPanel;
use PandaPanel\Routing\PanelRouteRegistrar;

$panel = PandaPanel::register(Panel::make('door')->path('door')->login());

app(PanelRouteRegistrar::class)->register($panel);

Route::getRoutes()->refreshNameLookups();
```

That is exactly what the package's own test suite does for panels it defines inside a test file.

## Turning it off

```php
// config/panda-panel.php

'register_routes' => false,
```

Compared with `!== true`, so anything other than boolean `true` disables it.

What still happens:

| Still happens | Stops happening |
| --- | --- |
| Panels are registered and their registries built | No route group for any panel |
| Discovery runs (or the manifest is read) | No URL answers |
| The four `web` middleware are appended | — |
| The four middleware aliases are registered | — |
| Migrations load, publishing and commands register | — |
| The guest redirect is registered | …but has nowhere panel-specific to send anyone: `PanelLoginRedirect` finds no `panel.{id}.auth.login` route and answers `null` for a panel that declared `login()`, which Laravel turns into a 401 |

```php
use PandaPanel\Facades\PandaPanel;

PandaPanel::resources('admin')->all();   // still answers
Route::has('panel.admin.dashboard');     // false
```

This is the switch for a test harness that boots panels to inspect their metadata without wanting
forty route registrations per test, and for an application that registers the groups itself:

```php
// A provider of your own, booted after PandaPanelServiceProvider.

public function boot(): void
{
    if (! $this->app->routesAreCached()) {
        app(PanelRouteRegistrar::class)->registerAll();
    }
}
```

There is no per-panel version of this key. A panel that should not be routed is a panel that
should not be in `config('panda-panel.panels')`.

## Route caching

`php artisan route:cache` works with panel routes and is expected in production. Nothing the
registrar produces is a closure, and every middleware parameter is a string, for exactly that
reason.

```bash
php artisan route:cache
php artisan panel:cache
```

The two caches answer different questions and both are worth running. `route:cache` freezes the
routes; `panel:cache` freezes the *class lists* discovery would otherwise rebuild on every boot.
A panel added to config after `route:cache` has run is not routed until the cache is rebuilt.

`php artisan optimize` runs `panel:cache` too — the provider registers it through
`$this->optimizes(optimize: 'panel:cache', clear: 'panel:clear', key: 'panels')`.

## Registration failures

Two things fail the boot rather than shipping a broken panel:

```php
PandaPanel\Exceptions\PanelRegistrationException::collidingRoutePath($path, $existing, $incoming)
PandaPanel\Exceptions\PanelRegistrationException::unregisteredParentResource($resource, $parent)
```

The first is two resources in one panel claiming the same path shape — parameter names are erased
before comparing, so `{record}` and `{parentRecord}` compare equal, and a `ManageRelatedRecords`
page at `projects/{record}/tasks` collides with a nested resource at
`projects/{parentRecord}/tasks`. Laravel would match the first and silently ignore the second.

The second is a nested resource whose parent is not registered in the same panel, which would
produce a path pointing at a route that does not exist there.

Both are `RuntimeException`s and nothing catches them.

## Gotchas

- **Turning this off does not stop discovery.** Registries are still built, so the cost of booting
  panels is unchanged. It only stops routing.
- **A panel registered after boot has no routes.** The registrar runs once. Call `register()`
  yourself, then `Route::getRoutes()->refreshNameLookups()`.
- **Changing a panel's middleware after boot changes nothing.** The stack was copied into the
  route group at registration time.
- **`route:cache` failing after you add a page is not this.** Nothing here is a closure; look for
  one in `routes/web.php`, or a route default holding an object.
- **Panels are routed in id order.** `PanelRegistry::all()` sorts by id, so route registration
  order is stable across runs and `route:cache` output is deterministic whatever order the config
  file lists them in.

## See also

- [config/panda-panel.php](panda-panel.md)
- [Middleware Registration](middleware.md)
- [Service Provider Behavior](service-provider.md)
- [Panel Config](panel-config.md)
- [Routing](../concepts/routing.md)
- [Request Lifecycle](../concepts/request-lifecycle.md)
- [Caching](../concepts/caching.md)
- [panel:cache](../cli/panel-cache.md)
- [Route Cache](../deployment/route-cache.md)
- [Panel Routes 404](../troubleshooting/panel-routes-404.md)
