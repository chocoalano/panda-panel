# Register and Boot

A plugin has two phases, and which one a piece of work belongs in is the single
thing plugin authors get wrong. Reach for this page when deciding where code
goes, or when a plugin works in development and behaves strangely in
production, under Octane, or across several requests in one test.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Plugins;

use App\Panels\Admin\Resources\Reports\ReportResource;
use PandaPanel\Core\Panel;
use PandaPanel\Enums\RenderHook;
use PandaPanel\Plugins\Plugin;

final class ReportingPlugin extends Plugin
{
    public function register(Panel $panel): void
    {
        // Configuration. No request exists yet.
        $panel->resources([ReportResource::class]);
    }

    public function boot(Panel $panel): void
    {
        // A request exists, the user is known, routes are registered.
        $panel->renderHook(RenderHook::PageStart, 'Panels/Admin/Hooks/ReportBanner');
    }
}
```

```php
$panel->plugins([new ReportingPlugin]);
```

## The two phases

| | `register(Panel $panel)` | `boot(Panel $panel)` |
| --- | --- | --- |
| Called from | `Panel::plugins()` | `Panel::boot()` |
| Triggered by | the panel provider, during application boot | `ResolvePanel` middleware, per request |
| Runs for | every request the application serves | only requests that reach this panel |
| Runs how often | once per application boot | once per matching request |
| Container available | not warm | yes |
| `$request->user()` | no | yes |
| `route()` / URLs | no | yes |
| Database | do not | yes |

## Phase 1: `register()`

`Panel::plugins()` does four things per plugin, in this order:

1. reads `id()`, and throws `PandaPanel\Exceptions\PanelRegistrationException`
   if another plugin already claimed it;
2. calls `PluginCompatibility::assert()`, which reads `metadata()` and throws if
   `requiresPanel` is not satisfied;
3. stores the plugin under its id;
4. calls `register($panel)`.

The check happens before `register()` on purpose: that is the last moment
before the plugin starts changing the panel, and the earliest at which the
answer is knowable.

Plugins are processed in array order, so a later plugin sees what an earlier one
registered. Relying on that makes the order in `plugins([...])` load-bearing,
which is worth avoiding.

```php
$panel->plugins([new ReportingPlugin]);   // register() has already run
$panel->getResources();                   // contains ReportResource::class
```

### Where in the application lifecycle this sits

`PandaPanelServiceProvider::boot()` builds every configured panel:

```text
provider boot
  └── PanelManager::registerProvider(AdminPanelProvider::class)
        └── AdminPanelProvider::build()
              └── AdminPanelProvider::panel(Panel::make('admin'))
                    └── Panel::plugins([...])
                          ├── PluginCompatibility::assert()
                          └── ReportingPlugin::register($panel)
        └── PanelManager::register($panel)
              └── buildRegistries($panel)     ← resources, pages, widgets, navigation
  └── PanelRouteRegistrar::registerAll()      ← routes
```

Two consequences fall out of that diagram, and both are absolute:

- **Everything a plugin registers must be registered in `register()`.** The
  resource, page, widget and navigation registries are built immediately after
  the provider returns, and routes are registered from them straight after. A
  `$panel->resources([...])` call in `boot()` mutates the panel and reaches
  nothing: no registry entry, no route, no navigation item, no error.
- **`register()` runs during service provider boot, for every request.** A
  request for a favicon pays for it. Nothing there may query, resolve a route,
  or read the current user — there is no user yet, and doing it anyway is a
  database hit on every asset request in production that nobody notices in
  development.

## Phase 2: `boot()`

`ResolvePanel` is the last middleware in every panel route group:

```php
// PandaPanel\Http\Middleware\ResolvePanel
$this->manager->setCurrentPanel($panel);

abort_unless($panel->isAccessibleTo($request->user()), 403);

// After the access check, never before: a user who is refused the
// panel must not be able to trigger its boot work.
$panel->boot();
```

So by the time `boot()` runs: the panel is the current panel, `panel()` answers
it, the user is authenticated and authorized for the panel, and routes exist.

`Panel::boot()` runs plugins first and the panel's own callbacks second:

```php
public function boot(): void
{
    foreach ($this->plugins as $plugin) {
        $plugin->boot($this);
    }

    foreach ($this->bootCallbacks as $callback) {
        $callback($this);
    }
}
```

That ordering is the guarantee an application depends on: a `bootUsing()`
callback is the application's own last word, and it should be able to undo what
a plugin did.

```php
use PandaPanel\Core\Panel;

$panel
    ->plugins([new ReportingPlugin])
    ->bootUsing(static function (Panel $panel): void {
        // Runs after every plugin's boot(). This wins.
        $panel->cssHooks(['page' => 'no-report-banner']);
    });
```

## What each phase can usefully change

| Panel method | `register()` | `boot()` |
| --- | --- | --- |
| `resources()`, `pages()`, `widgets()` | yes | **no effect** — registries are already built |
| `discoverResources()`, `discoverPages()`, `discoverWidgets()` | yes | **no effect** — discovery has already run |
| `navigationGroups()` | yes | **effectively no** — the group order is fixed in the navigation registry at registration; only the parent mapping is re-read per request |
| `renderHook()` | yes | yes |
| `cssHooks()`, `colors()` | yes | yes |
| `assets()` | yes | yes |
| `userMenuItems()` | yes | yes |
| `configureActions()` | yes | yes |
| `brandName()`, `brandLogo()`, `favicon()`, `icon()` | yes | yes |
| `middleware()`, `authMiddleware()` | yes | **no effect** — routes are already registered |
| `bootUsing()` | yes | too late for this request |

The rule behind the table: anything read once at registration is fixed by the
time `boot()` runs; anything read per request from the panel object can still be
changed there.

## Boot runs per request, so make it idempotent

`Panel::boot()` has no once-guard. In a classic PHP request that does not
matter — the container and every panel object are rebuilt from scratch each
time. Under Octane, in a queue worker, or in a test that issues several
requests, the same `Panel` instance survives, and `boot()` runs again on it.

Panel methods differ in how they take that:

| Method | Repeated call |
| --- | --- |
| `resources()`, `pages()`, `widgets()`, `navigationGroups()`, `discover*()` | deduplicated — `array_unique` on merge |
| `renderHook()` | **appends** — the component is injected twice |
| `cssHooks()` | **appends** — the class string grows every request |
| `userMenuItems()` | **appends** — the menu entry repeats |
| `brandName()`, `favicon()`, `colors()`, `configureActions()` | overwritten, so harmless |

So a `boot()` that appends must guard itself:

```php
use PandaPanel\Core\Panel;
use PandaPanel\Enums\RenderHook;

private bool $booted = false;

public function boot(Panel $panel): void
{
    if ($this->booted) {
        return;
    }

    $this->booted = true;

    $panel->renderHook(RenderHook::PageStart, 'Panels/Admin/Hooks/ReportBanner');
}
```

The flag lives on the plugin instance, which is per panel, so two panels with
the same plugin class each boot once.

The guard is wrong when the work depends on the current user or request — a
per-user render hook has to run per request. In that case do not guard, and use
something idempotent: `cssHooks()` accumulating one class per request is a
growing string, but `configureActions()` replacing its closure per request is
not.

## Failing loudly

Both phases throw rather than degrade. `PanelRegistrationException` extends
`RuntimeException` and is deliberately fatal: these are developer errors that
must fail during boot and during `panel:cache`, not turn into a panel that
half-works.

A throw from `register()` breaks application boot, including the route that
would have shown the error. A throw from `boot()` breaks every request into
that panel and nothing else.

## Caching

`panel:cache` writes a manifest of the classes each panel owns — resource, page
and widget class names, nothing more. Plugin objects are never serialized, so:

- `register()` still runs on every application boot, cached or not. The
  manifest replaces the *filesystem scan*, not the panel provider.
- A plugin's `discoverResources()` paths are scanned when the manifest is
  written and the results are cached like any other, so a plugin's directory of
  resources costs nothing per request in production.
- Changing what a plugin registers means rebuilding the manifest:
  `php artisan panel:cache`, or `optimize`, which includes it.

## Gotchas

- **`boot()` never runs for a user who is refused the panel.** The `abort_unless`
  is above it. A plugin cannot use `boot()` as an audit log of attempted access.
- **`boot()` never runs on a non-panel request.** `ResolvePanel` only exists
  inside panel route groups. Console commands, queue jobs and the rest of the
  application never boot a panel, so a plugin that only registers things in
  `boot()` is invisible to `panel:plugins`, `panel:publish` and every artisan
  command.
- **`register()` also runs in the console.** `php artisan migrate` builds every
  panel provider, so a `register()` that touches the database can break a
  migration on a fresh install — the table it queries does not exist yet.
- **A resource registered in `boot()` produces no error at all.** It is in
  `$panel->getResources()` and nowhere else. If a plugin's resource is missing
  from the sidebar, check which method registered it first.
- **Two panels mean two `register()` calls on two instances.** Per-instance
  state is isolated; static state on the plugin class is shared, including
  between panels that configured it differently.

## See also

- [Plugin Concepts](concepts.md)
- [Creating a Plugin](creating-plugins.md)
- [Plugin Contract](contract.md)
- [Version Compatibility](compatibility.md)
- [Testing Plugins](testing.md)
- [Request Lifecycle](../concepts/request-lifecycle.md)
- [Panel Providers](../concepts/panel-providers.md)
- [Caching](../concepts/caching.md)
- [Panel Access Rules](../panels/access.md)
- [Render Hooks](../panels/render-hooks.md)
- [panel:cache](../cli/panel-cache.md)
