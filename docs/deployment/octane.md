# Octane

Under Octane the application boots once and the workers stay resident, so
anything a request leaves behind is still there for the next one. The panel is
built for that: the current panel, the current parent record and the current
tenant all live in a scoped container binding, and nothing in the package holds
request state in a static. Reach for this page when deploying onto Swoole,
RoadRunner or FrankenPHP, or when panel state appears to leak between requests.

## A minimal working example

```bash
php artisan octane:start --workers=4
```

And on every deploy, after the caches are rebuilt:

```bash
php artisan optimize
php artisan octane:reload
```

Nothing panel-specific has to be configured. What follows is why, and the one
setting that would break it.

## Where request state lives

```php
use PandaPanel\Support\PanelContext;

// PandaPanelServiceProvider::register()
$this->app->scoped(PanelContext::class);
```

`scoped` is the binding type Octane forgets between requests. Everything a
request decides goes in there:

| Method | Signature | Holds |
| --- | --- | --- |
| `setPanel` | `setPanel(?Panel $panel): void` | the panel this request resolved |
| `panel` | `panel(): ?Panel` | |
| `hasPanel` | `hasPanel(): bool` | |
| `set` | `set(string $key, mixed $value): void` | the generic bag — tenancy and the parent record use it |
| `get` | `get(string $key, mixed $default = null): mixed` | |
| `forget` | `forget(): void` | clears the panel and the whole bag |

```php
use PandaPanel\Support\PanelContext;

$context = app(PanelContext::class);

$context->hasPanel();       // false outside a panel request
$context->get('tenant');    // null until ResolveTenant binds one
```

Nothing here is static, which is what keeps panel state from leaking between
requests and between tests. `PandaPanel\Core\PanelManager::setCurrentPanel()`
and `currentPanel()` are thin wrappers over the same object, so a job or a
console command that sets the current panel is setting it on the scoped
instance too.

`PandaPanel\Tenancy\Tenancy` stores the tenant under the key `panel.tenant` in
that same bag, for the same reason.

## The middleware that enforces it

```php
// PandaPanelServiceProvider::WEB_MIDDLEWARE
ResetPanelContext::class,
RedirectPanelHome::class,
ShareFlashToast::class,
SharePanelData::class,
```

`ResetPanelContext` calls `PanelContext::forget()` at the start of every web
request, and it is registered on the **whole `web` group** rather than on the
panel route groups:

```php
public function handle(Request $request, Closure $next): Response
{
    $this->context->forget();

    return $next($request);
}
```

`ResolvePanel` only runs inside panel route groups, so without this a non-panel
route would keep whatever the previous request left behind. In a classic PHP
request the container is rebuilt each time and the leak is invisible; under
Octane it is not. Enforcing the invariant on the whole group makes "no current
panel outside a panel" true in every environment rather than true by accident.

**This is the one configuration that matters under Octane:**

```php
// config/panda-panel.php
'register_web_middleware' => true,     // leave this on
```

Turning it off and forgetting to register the middleware yourself in
`bootstrap/app.php` is the one way to get panel state surviving a request
boundary.

## What legitimately persists

These are singletons and they stay resolved for the life of the worker, which is
correct — every one of them holds configuration rather than request state:

| Binding | Holds |
| --- | --- |
| `PandaPanel\Core\PanelRegistry` | the registered `Panel` objects |
| `PandaPanel\Core\PanelManager` | per-panel resource, page, widget and navigation registries — class names |
| `PandaPanel\Discovery\PanelDiscoverer` | nothing; it is stateless |
| `PandaPanel\Cache\PanelManifest` | the loaded manifest |
| `PandaPanel\Support\NavigationBuilder` | nothing per user; it recomputes visibility and active state per call |
| `PandaPanel\Routing\PanelRouteRegistrar` | the router and the manager |

A panel's registries are lists of class names, and a class name is the same for
every user. Authorization results, navigation active state, badge values, record
data and widget data are recomputed on every request precisely so that none of
them can end up in one of these.

## The four statics in the package

All four exist, all four are safe, and it is worth knowing what they are.

```php
// PandaPanel\Discovery\ClassResolver
private static ?array $prefixes = null;
```

Composer's PSR-4 prefix map, memoized for the life of the process. It cannot
change while the process runs — a new autoloader means a new process — and it is
read-only after the first call.

```php
// PandaPanel\Support\MissingPolicyNotice
private static array $reported = [];
```

Which models have already been reported as having no policy, so one missing
policy is one log line rather than one per navigation build per request. It is
development-only — the notice never fires when debug mode is off outside `local`
and `testing` — and it holds class names, not user data.

```php
use PandaPanel\Support\MissingPolicyNotice;

MissingPolicyNotice::forget();   // only for tests, which build navigation many times
```

```php
// PandaPanel\Resources\Resource
private static array $integrationSettings = [];
```

Each resource's resolved `Integrations` object, memoized by resource class:
`self::$integrationSettings[static::class] ??= static::integrations(...)`. It is
configuration the class declares, identical for every user and every request.

```php
// PandaPanel\Integrations\IntegrationObserver
private static array $registered = [];
```

Which `model|panel|resource` combinations already have Eloquent listeners
attached, so two panels sharing a model do not each add one and send everything
twice. Keys, not records. `IntegrationObserver::forget()` exists for tests,
which register the same model more than once.

## The manifest inside a worker

`PanelManifest` loads `bootstrap/cache/panels.php` once and keeps the parsed
array. A worker that has already read it keeps serving it until the worker is
recycled:

```bash
php artisan panel:clear     # deletes the file; does not reach a running worker
php artisan octane:reload   # this is what reaches it
```

`clear()` forgets the in-memory copy for the instance it was called on, and a
CLI invocation is a different process from the workers. Deploys restart workers
anyway, which they already do for every other reason.

The upside is the one Octane is for: with a manifest in place, a request does no
filesystem work at all, and under Octane it also does no autoloading, no
provider boot and no route registration. Discovery is not merely cached — it does
not happen.

## Deploying

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
npm ci && npm run build
php artisan optimize          # config, routes, events, views — and panel:cache
php artisan octane:reload     # workers pick up the new code and the new manifest
php artisan queue:restart     # the other long-lived process
```

`octane:reload` after `optimize`, not before: a worker that reloads first comes
back holding the previous release's caches.

Watched directories in development are a different matter — `octane:start --watch`
reloads on file changes, which is what makes a cached manifest in development
particularly confusing. Do not cache panels locally.

## Memory

Two panel operations are worth watching in a long-lived worker, and both have a
number you can turn.

| Operation | Bound by | Default |
| --- | --- | --- |
| A synchronous export | `Exporter::chunkSize()` | `500` records held at once |
| A synchronous import | `Importer::chunkSize()` | `200` rows |

Anything larger is handed to a queue instead:

```php
use PandaPanel\Actions\Exports\Exporter;

Exporter::queueAfter();   // 2000 records — above this, a worker does it
```

Under Octane the argument for queueing is stronger than under FPM, because a
request that peaks at a gigabyte leaves a worker that has peaked at a gigabyte.
Lowering `queueAfter()` is the cheapest way to keep that out of the request
process. See [Queues](queues.md).

## What is not claimed

This package is not tested against Octane in CI. What is tested is the invariant
Octane depends on: that no panel state survives a request. The design constraint
is stated in the [compatibility matrix](../getting-started/compatibility.md) —
nothing in this package holds request state in a static — and the suite asserts
context isolation directly:

```php
it('keeps context out of static state so it cannot leak between requests', function (): void {
    $context = app(PanelContext::class);
    $context->setPanel(app(PanelManager::class)->get('admin'));

    $context->forget();

    expect($context->hasPanel())->toBeFalse();
});
```

If you run Octane, run your own suite against it too. The rules the panel follows
are the rules an application's own resources have to follow as well.

## Rules for your own panel code

- **Never memoize a per-user answer in a static.** A resource's `canViewAny()`, a
  navigation badge, a table query — all of them are per user, and a static cache
  of one is one user's answer served to everybody.
- **Never memoize the current panel.** Ask `PanelManager::currentPanel()` each
  time; it is a scoped read, not work.
- **Static properties on a `Resource` are configuration.** `$slug`, `$label`,
  `$navigationIcon` describe the class, not a request, and are safe.
- **A queued job is outside all of this.** It sets its own panel and, if the
  panel is tenant-scoped, enters the tenant explicitly.

## Gotchas

- **`panel:clear` from the CLI does not reach a running worker.** Reload.
- **`register_web_middleware => false` without registering `ResetPanelContext`
  yourself is a state leak**, and the symptom is a non-panel route reporting a
  current panel from somebody else's request.
- **A cached manifest under `octane:start --watch`** is worse than under FPM: the
  reload picks up the new class, the manifest still does not name it, and the
  file watcher makes it look like the reload did nothing.
- **Octane keeps opcache warm across requests, not across deploys.** The manifest
  is PHP source like `config.php` and `routes-v7.php`, and is covered by whatever
  the deploy already does about opcache.
- **A synchronous export in an Octane worker holds its peak memory** for the life
  of that worker. Queue anything large.

## See also

- [Production checklist](production-checklist.md), [Panel cache](panel-cache.md), [Queues](queues.md)
- [Panel context](../concepts/panel-context.md), [Request lifecycle](../concepts/request-lifecycle.md)
- [Caching](../concepts/caching.md)
- [Middleware configuration](../configuration/middleware.md)
- [Tenancy concepts](../tenancy/concepts.md), [Tenancy in queues](../tenancy/queues.md), [Scope leaks](../troubleshooting/tenancy-scope-leaks.md)
- [Compatibility matrix](../getting-started/compatibility.md)
