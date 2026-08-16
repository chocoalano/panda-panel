# Service Provider Behavior

`PandaPanel\PandaPanelServiceProvider` is everything the package does to an application at boot:
seven bindings, eight boot steps, four publish groups, thirteen commands. It is auto-discovered by
Composer, so nothing has to be registered by hand. Reach for this page when you need to know what
runs in what order, what a config key actually switches off, or how to boot the framework yourself
in a harness that is not a Laravel application.

## A minimal working example

```json
{
    "extra": {
        "laravel": {
            "providers": ["PandaPanel\\PandaPanelServiceProvider"],
            "aliases": { "PandaPanel": "PandaPanel\\Facades\\PandaPanel" }
        }
    }
}
```

That is the package's own `composer.json`. `composer require chocoalano/panel` is the whole
installation step — Laravel's package discovery registers the provider and the facade alias.

To see what it did:

```php
use PandaPanel\Core\PanelManager;

app(PanelManager::class)->all();       // list<Panel>
app()->getLoadedProviders();           // includes PandaPanel\PandaPanelServiceProvider
```

## `register()`

Runs before any provider's `boot()`, and does two things: merges the config, and binds the
container.

```php
$this->mergeConfigFrom($this->packagePath('config/panda-panel.php'), 'panda-panel');
```

`mergeConfigFrom()` is a shallow `array_merge` with the application's published file taking
precedence, and it is skipped entirely when the configuration is cached — in which case the cached
file already contains the merged result.

| Binding | Lifetime | Role |
| --- | --- | --- |
| `PandaPanel\Core\PanelRegistry` | singleton | The registered panels, keyed by id. |
| `PandaPanel\Support\PanelContext` | **scoped** | The current panel for this request. Reset per request under Octane. |
| `PandaPanel\Discovery\PanelDiscoverer` | singleton | Scans a panel's discovery paths. |
| `PandaPanel\Cache\PanelManifest` | singleton | The cached class lists, or discovery when there is no cache. |
| `PandaPanel\Core\PanelManager` | singleton | The entry point for everything panel-related. |
| `PandaPanel\Support\NavigationBuilder` | singleton | Builds the sidebar tree for a panel and a path. |
| `PandaPanel\Routing\PanelRouteRegistrar` | singleton | One route group per panel. Constructed with the `Registrar` contract and the manager. |

`PanelContext` is `scoped` rather than `singleton` on purpose: it holds request state, and under
Octane a singleton would carry one request's panel into the next. `ResetPanelContext` enforces the
same invariant from the other side.

```php
use PandaPanel\Core\PanelManager;
use PandaPanel\Support\PanelContext;

app(PanelManager::class);    // same instance every time
app(PanelContext::class);    // same instance within one request
```

## `boot()`

Eight steps, in this order, and the order is load-bearing:

```php
public function boot(): void
{
    $this->registerPanels();
    $this->registerMiddleware();
    $this->registerGuestRedirect();
    $this->registerRoutes();
    $this->registerIntegrations();
    $this->registerMigrations();
    $this->registerPublishing();
    $this->registerCommands();
}
```

| Step | Config key | What it does |
| --- | --- | --- |
| `registerPanels()` | `panels` | Builds every configured provider, then checks the manifest for staleness. |
| `registerMiddleware()` | `register_web_middleware` | Registers four aliases always; appends four `web` middleware unless told not to. |
| `registerGuestRedirect()` | `register_guest_redirect` | Points `Authenticate::redirectUsing()` and `AuthenticationException::redirectUsing()` at `PanelLoginRedirect`. |
| `registerRoutes()` | `register_routes` | `PanelRouteRegistrar::registerAll()`. |
| `registerIntegrations()` | — | Registers the model observer for every resource that enabled integrations. |
| `registerMigrations()` | `load_migrations` | `loadMigrationsFrom(package/database/migrations)`. |
| `registerPublishing()` | — | Console only. Four publish groups. |
| `registerCommands()` | — | Console only. Thirteen commands, plus the `optimize` hook. |

Panels are registered first because everything after it asks them something: the route registrar
needs their paths and middleware, the integration observer needs their resource registries.

### `registerPanels()`

```php
foreach ($this->configuredPanels() as $provider) {
    if (! $manager->has((new $provider)->panelId())) {
        $manager->registerProvider($provider);
    }
}

$this->app->make(PanelManifest::class)->warnIfStale($this->app->make(PanelRegistry::class));
```

A panel listed twice is registered once — the registry keys by panel id, and re-registering would
run discovery a second time for no change in outcome.

`configuredPanels()` filters the config list to entries that are strings *and* subclasses of
`PandaPanel\Core\PanelProvider`. Anything else is skipped silently, because a class name that no
longer resolves would fatal during boot — before any route, including the one that would have
shown you the error. `php artisan panel:cache` prints how many panels it cached, so a skipped one
shows up there as a count that is one short.

The staleness check runs after every panel is registered, so it sees all of their discovery paths.
It is a no-op unless a manifest exists *and* the environment is `local`, `testing`, or has debug
mode on.

### `registerIntegrations()`

```php
foreach ($manager->all() as $panel) {
    $registry = $manager->resources($panel);

    foreach ($registry->all() as $resource) {
        $settings = $resource::integrationSettings();

        if (! $settings->enabled()) {
            continue;
        }

        IntegrationObserver::register(
            $resource::getModel(),
            $panel->getId(),
            $registry->slugFor($resource),
            $settings,
        );
    }
}
```

At boot rather than in a page, because the point of hanging these off Eloquent is that a record
written from a console command or a queued job fires them too — and neither of those has ever
rendered a panel screen. A resource that has not opted in registers nothing, so the cost of this
feature to everybody else is one method call per resource during boot.

### `registerPublishing()`

Console only — `registerPublishing()` returns immediately unless `$this->app->runningInConsole()`.
That is the only context `vendor:publish` exists in, but it also means a test that boots over HTTP
and then asks `ServiceProvider::publishableGroups()` sees nothing.

| Tag | Source | Destination |
| --- | --- | --- |
| `panda-panel-config` | `config/panda-panel.php` | `config_path('panda-panel.php')` |
| `panda-panel-migrations` | `database/migrations` | `database_path('migrations')` |
| `panda-panel-stubs` | `stubs/panel` | `base_path('stubs/panel')` |
| `panda-panel-assets` | `PublishedAssets::map()` | `resources/js/**`, `resources/css/panda-panel.css` |

The first, second and fourth are also members of the umbrella tag `panda-panel`. The stubs tag
deliberately is not: publishing stubs changes what every future generator writes, which is not
something an umbrella publish should do by accident.

The asset map comes from `PandaPanel\Support\Installer\PublishedAssets` rather than being written
out here, so `vendor:publish` and `panel:assets` read one list. Two copies would drift the first
time a directory was added, and the symptom would be a file that publishes but is never reported
as out of date.

### `registerCommands()`

Console only, and thirteen commands:

| Command | Class |
| --- | --- |
| `panel:cache` | `CachePanelsCommand` |
| `panel:clear` | `ClearPanelsCommand` |
| `panel:install` | `InstallPanelCommand` |
| `make:panel` | `MakePanelCommand` |
| `make:panel-resource` | `MakePanelResourceCommand` |
| `make:panel-page` | `MakePanelPageCommand` |
| `make:panel-relation-manager` | `MakePanelRelationManagerCommand` |
| `panel:user` | `MakePanelUserCommand` |
| `make:panel-widget` | `MakePanelWidgetCommand` |
| `panel:assets` | `PanelAssetsCommand` |
| `panel:plugins` | `PanelPluginsCommand` |
| `panel:publish` | `PublishPanelAssetsCommand` |
| `panel:icons` | `SyncPanelIconsCommand` |

And one hook that puts the panel manifest on the same footing as the config and route caches:

```php
$this->optimizes(
    optimize: 'panel:cache',
    clear: 'panel:clear',
    key: 'panels',
);
```

```bash
php artisan optimize         # runs panel:cache along with config:cache and route:cache
php artisan optimize:clear   # runs panel:clear
```

A deploy that already runs `optimize` gets panel caching for free.

## Two `afterResolving(Kernel::class)` hooks

Both the web middleware and the guest redirect are registered through the HTTP kernel, after it
resolves, rather than immediately. The reason is the same in both cases and it is worth
understanding once.

`bootstrap/app.php` calls `withMiddleware()`, which registers an `afterResolving(Kernel::class)`
hook of its own. That hook builds a fresh `Middleware` object, calls
`redirectGuestsTo(fn () => route('login'))` on it, then calls `$kernel->setMiddlewareGroups(...)`.
Anything a provider pushed onto the router or set on `Authenticate` earlier is overwritten at that
moment.

Registering a *later* hook is the only ordering that survives. Both methods also handle the case
where the kernel has already resolved — a test that boots the application before the provider runs
— by doing the work immediately as well:

```php
if ($this->app->resolved(Kernel::class)) {
    $append($this->app->make(Kernel::class));
}

$this->app->afterResolving(Kernel::class, $append);
```

`appendMiddlewareToGroup()` is idempotent, so being called twice appends once.

## Booting the framework yourself

The provider is auto-discovered. To register it by hand — in a package's test harness, or in an
application that has disabled discovery — name it:

```php
// bootstrap/providers.php

return [
    App\Providers\AppServiceProvider::class,
    PandaPanel\PandaPanelServiceProvider::class,
];
```

```json
{
    "extra": {
        "laravel": {
            "dont-discover": ["chocoalano/panel"]
        }
    }
}
```

In a Testbench-based suite:

```php
protected function getPackageProviders($app): array
{
    return [
        Inertia\ServiceProvider::class,
        Laravel\Fortify\FortifyServiceProvider::class,
        PandaPanel\PandaPanelServiceProvider::class,
    ];
}
```

Inertia is not optional. Every panel screen is an Inertia response, and `SharePanelData` calls
`Inertia::share()` on every `web` request.

## Turning steps off

| Key | Set to `false` | Consequence |
| --- | --- | --- |
| `register_routes` | no route group is registered | Registries still build; no URL answers. [Route Registration](routes.md) |
| `register_web_middleware` | the four `web` middleware are not appended | Aliases are still registered. [Middleware Registration](middleware.md) |
| `register_guest_redirect` | `Authenticate::redirectUsing()` is left alone | Laravel's own `route('login')` default applies. [Guest Redirect](guest-redirect.md) |
| `load_migrations` | the package's migrations are not loaded | Publish them instead. [Migration Loading](migrations.md) |

The panel list itself is always read: there is no key that stops panels being registered, because
a framework that registered nothing would have nothing to configure. Remove a provider from
`panels` to remove a panel.

## Gotchas

- **`boot()` runs once per process.** Under Octane, panel definitions, routes and observers are
  built at worker start and reused. Treat a `Panel` as immutable configuration.
- **Discovery runs during `registerPanels()` unless a manifest exists.** On a cold boot with no
  manifest, every panel's discovery paths are scanned before the first route is matched — which is
  why production runs `panel:cache`.
- **A `PanelRegistrationException` during boot is fatal and uncaught.** A duplicate slug or a
  colliding route path fails the boot rather than producing a half-registered panel.
- **`registerPublishing()` and `registerCommands()` are console-only.** Anything that asserts on
  publish groups or command registration has to run through the console kernel.
- **The provider never resolves a request-scoped service.** It boots before there is a request,
  and a panel provider that resolved the current user in `panel()` would be resolving a user who
  does not exist yet.
- **The facade alias is `PandaPanel`.** It proxies `PanelManager`, not the provider.

## See also

- [config/panda-panel.php](panda-panel.md)
- [Panel Config](panel-config.md)
- [Route Registration](routes.md)
- [Middleware Registration](middleware.md)
- [Guest Redirect](guest-redirect.md)
- [Migration Loading](migrations.md)
- [Panel Providers](../concepts/panel-providers.md)
- [Architecture at a Glance](../introduction/architecture.md)
- [Caching](../concepts/caching.md)
- [Publish Tags](../cli/publish-tags.md)
- [Octane](../deployment/octane.md)
