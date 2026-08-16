# Route Cache

`php artisan route:cache` compiles the whole route table into one file and uses
it instead of asking providers to register routes again. Panel routes are
cacheable by construction: every one points at a controller method, never a
closure. Reach for this page when adding the command to a deploy, or when a
panel URL 404s on a server and works locally.

## A minimal working example

```bash
php artisan route:cache
php artisan route:list --path=admin
```

Or as part of the set:

```bash
php artisan optimize          # config, routes, events, views — and panel:cache
```

## Why the panel is cacheable

`route:cache` refuses a route table containing a closure, because a closure
cannot be serialized. `PandaPanel\Routing\PanelRouteRegistrar` never registers
one:

```php
$this->router->get('/', PanelDashboardController::class)->name('dashboard');
$this->router->post('read', [PanelNotificationController::class, 'read'])->name('read');
$this->router->get($page::routePath(), PanelPageController::class)->defaults('page', $page);
```

Three shapes and all three are serializable: an invokable controller class, a
`[class, method]` pair, and a route default holding a class name as a string.
Resource pages are registered the same way, with the page class as the
controller:

```php
$this->router->{$verb}($path, [$page, $method])->name($routeName);
```

If `route:cache` fails on your application, the closure is in your own routes
files. Nothing the panel registers can cause it.

## What gets registered, and when

The provider registers one route group per panel during `boot()`:

```php
// PandaPanelServiceProvider::registerRoutes()
if ($this->app->make('config')->get('panda-panel.register_routes') !== true) {
    return;
}

$this->app->make(PanelRouteRegistrar::class)->registerAll();
```

```php
$attributes = [
    'prefix' => $panel->getPath(),
    'as' => $panel->getRouteNamePrefix(),      // 'panel.admin.'
    'middleware' => [
        ...$panel->getMiddleware(),
        ResolvePanel::class.':'.$panel->getId(),
        RequireTwoFactor::class.':'.$panel->getId(),
        RequireEmailCode::class.':'.$panel->getId(),
        ...($panel->hasTenancy() ? [ResolveTenant::class.':'.$panel->getId()] : []),
    ],
];
```

| Method | Signature |
| --- | --- |
| `registerAll` | `registerAll(): void` — every registered panel |
| `register` | `register(Panel $panel): void` — one panel |

Middleware carries the panel id as a parameter rather than being matched from
the path, which keeps two panels sharing a prefix unambiguous — and a
parameterized middleware string caches like any other string.

## The names that end up in the cache

Every route name begins with `panel.{id}.`:

```php
panel('admin')->getRouteNamePrefix();      // 'panel.admin.'
panel('admin')->routeName('dashboard');    // 'panel.admin.dashboard'
```

| Route name | Path | Registered by |
| --- | --- | --- |
| `panel.admin.dashboard` | `/admin` | the panel |
| `panel.admin.search` | `/admin/search` | global search |
| `panel.admin.options` | `/admin/options` | searchable select fields |
| `panel.admin.uploads` | `/admin/uploads` | file fields |
| `panel.admin.form-state` | `/admin/form-state` | live fields |
| `panel.admin.export-file` | `/admin/exports/{file}` | finished exports |
| `panel.admin.import-file` | `/admin/imports/{file}` | import failure reports |
| `panel.admin.notifications.index` / `.read` / `.clear` | `/admin/notifications…` | the notification centre |
| `panel.admin.auth.two-factor.*` | `/admin/two-factor/…` | the emailed-code challenge |
| `panel.admin.actions.*` | `/admin/actions/…` | one action endpoint set per panel |
| `panel.admin.relations.*` | `/admin/relations/…` | one relation endpoint set per panel |
| `panel.admin.pages.{slug}` | the page's `routePath()` | standalone pages |
| `panel.admin.resources.{slug}.*` | the resource's slug | resource pages |
| `panel.admin.auth.login` and friends | `/admin/login` | a panel with its own login |

Resource route names stay `resources.{slug}.` even when a cluster changes the
path, so `Resource::url()` keeps working and only the URL moves.

## The one ordering that matters

The panel's route table is built from the panel's registries, and those come
from the manifest when one exists and from discovery when it does not. A route
cache freezes the result.

```bash
php artisan optimize     # runs both, from the same code
```

Running them separately is fine as long as both run in the same deploy, against
the same tree. Getting one and not the other produces two distinct, quiet
failures:

| State | Symptom |
| --- | --- |
| Fresh route cache, stale panel manifest | the URL answers, but the resource has no sidebar entry — navigation is built from the registries |
| Stale route cache, fresh panel manifest | the sidebar shows a link that 404s — the route was never compiled |

Neither logs anything. The fix for both is to run `optimize` after the code is
in place.

## What a route cache does to boot-time registration

With a compiled route table present, Laravel replaces whatever providers
registered during boot with the compiled collection. The panel's
`registerAll()` still runs — it is a provider `boot()` method — but the routes
it produced are not the ones that serve the request; the cached ones are.

Two things follow:

- **Adding a resource requires a route cache rebuild**, not just a manifest
  rebuild.
- **A route cache from a previous release survives a code deploy** unless the
  deploy rebuilds it. Under a release-directory deploy, `bootstrap/cache` must
  belong to the release rather than to the shared directory, for exactly the
  reason described in [Panel cache](panel-cache.md).

## Inspecting the compiled table

```bash
php artisan route:list --path=admin
php artisan route:list --name=panel.admin.resources
```

```php
use Illuminate\Support\Facades\Route;

Route::has('panel.admin.dashboard');                          // true
route('panel.admin.dashboard', absolute: false);              // '/admin'
route('panel.admin.resources.users.index', absolute: false);  // '/admin/users'
```

Building a name from the panel rather than by hand is what keeps a job's URLs
correct after a panel is renamed:

```php
use PandaPanel\Core\PanelManager;

$panel = app(PanelManager::class)->get('admin');

route($panel->routeName('export-file'), ['file' => $file, 'exporter' => $exporter], absolute: false);
```

`absolute: false` matters in a queued job: a worker's `APP_URL` is not always
the host the user is browsing, and a relative URL cannot be wrong about it.

## Wayfinder

The frontend never hardcodes a panel URL. `@/routes/*` and `@/actions/*` are
generated from the application's own route table by
[Wayfinder](../frontend/wayfinder.md):

```bash
php artisan wayfinder:generate
npm run build
```

Generate before building, and generate after any change to the route table —
a new resource changes both. The generated modules are TypeScript compiled into
the bundle, so a stale generation is a build-time problem rather than a runtime
one, which is the better failure.

## Turning route registration off

```php
// config/panda-panel.php
'register_routes' => false,
```

Then nothing is registered and nothing is cached. Register the groups yourself:

```php
use PandaPanel\Core\PanelManager;
use PandaPanel\Routing\PanelRouteRegistrar;

app(PanelRouteRegistrar::class)->register(app(PanelManager::class)->get('admin'));
```

This is for a harness that boots panels without HTTP, or an application that
needs the groups at a specific position. It is not something a normal deploy
touches.

## Clearing it

```bash
php artisan route:clear
php artisan optimize:clear     # config, routes, views, events — and panel:clear
```

## Gotchas

- **`route:cache` is not `panel:cache`.** Two separate caches. `optimize`
  includes both; running one alone leaves the other stale.
- **A cached route table outlives a code deploy.** Rebuild it every release.
- **A panel added to `config/panda-panel.php` after `route:cache`** has no
  routes at all. Its URL 404s and there is nothing in the log.
- **Route names change when a panel id changes.** `panel.{id}.*` is derived from
  the id, so renaming a panel invalidates every generated Wayfinder module and
  every `route()` call naming the old id.
- **Nested resources add a parent segment.** A nested resource whose parent is
  not registered in the same panel throws
  `PandaPanel\Exceptions\PanelRegistrationException` at boot rather than
  shipping dead links, so this fails loudly at `route:cache` time.
- **A resource with integrations disabled registers no integration routes**, so
  the URL 404s rather than answering 403. That is intentional: there is no
  screen to be refused.

## See also

- [Production checklist](production-checklist.md)
- [Panel cache](panel-cache.md), [Config cache](config-cache.md), [Rollbacks](rollbacks.md)
- [Routing](../concepts/routing.md) — the full route table, group by group
- [Resource URLs and routes](../resources/urls-routes.md)
- [Wayfinder](../frontend/wayfinder.md), [Frontend build](frontend-build.md)
- [Routes configuration](../configuration/routes.md)
- [Panel routes 404](../troubleshooting/panel-routes-404.md)
