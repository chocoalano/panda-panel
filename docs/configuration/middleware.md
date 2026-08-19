# Middleware Registration

The package registers four middleware on the whole `web` group and four middleware aliases, both
from `PandaPanel\PandaPanelServiceProvider::registerMiddleware()`. One config key decides whether
the first half happens. Reach for this page when you need those four somewhere specific in the
stack, or when you are working out why one of them is not running.

## A minimal working example

```php
// config/panda-panel.php

'register_web_middleware' => true,
```

That is the default, and with it installing the package is enough — no edit to
`bootstrap/app.php`, and no step an application can forget. To verify:

```php
use Illuminate\Contracts\Http\Kernel;

app(Kernel::class)->getMiddlewareGroups()['web'];
// [..., ResetPanelContext::class, RedirectPanelHome::class, ShareFlashToast::class, SharePanelData::class]
```

## The four `web` middleware

Appended in this order, which is the order they must run in:

| Class | Role |
| --- | --- |
| `PandaPanel\Http\Middleware\ResetPanelContext` | Clears the resolved panel at the start of every request. |
| `PandaPanel\Http\Middleware\RedirectPanelHome` | Sends a signed-in user who lands on the starter kit's `/dashboard` into the panel. |
| `PandaPanel\Http\Middleware\ShareFlashToast` | Maps Laravel's flash keys onto the single toast channel the frontend listens on. |
| `PandaPanel\Http\Middleware\SharePanelData` | Shares the props every panel screen is built from. |

None of them belongs to a panel route group, and each for its own reason.

### `ResetPanelContext`

```php
public function handle(Request $request, Closure $next): Response
```

`ResolvePanel` only runs inside panel route groups, so without this a non-panel route would keep
whatever the previous request left in `PandaPanel\Support\PanelContext`. In a classic PHP request
the container is rebuilt each time and the leak is invisible; under Octane, or inside a test that
issues several requests, it is not. Running it first makes "no current panel outside a panel" true
in every environment rather than true by accident.

### `RedirectPanelHome`

```php
public function handle(Request $request, Closure $next): Response
```

GET only, and never for a request that wants JSON. It answers `/dashboard` — the application's own
route, not a panel's — before the application does, which is why it is middleware rather than a
competing route registration. It runs before the two below it because a request it answers never
reaches a panel screen, so there is nothing to share props for. What it redirects, and how to turn
it off, is [Home Redirect](home-redirect.md).

### `ShareFlashToast`

```php
public function handle(Request $request, Closure $next): Response
```

Reads `error`, `warning`, `success`, `info` from the session in that order — severity first, so an
error is surfaced ahead of a success when a request somehow flashes both — and republishes the
first non-empty one as `Inertia::flash('toast', ['type' => …, 'message' => …])`. A request with no
session is passed straight through, and an explicit `Inertia::flash('toast', …)` already set is
never overwritten.

It has to be on the `web` group rather than the panel group because it must run for redirects back
*out* of a panel, which are answered by the application's routes.

```php
return redirect()
    ->route('panel.admin.resources.users.index')
    ->with('success', 'User created.');
```

### `SharePanelData`

```php
public function handle(Request $request, Closure $next): Response
```

Shares ten props through `Inertia::share()`, which merges — the application's own
`HandleInertiaRequests` is untouched, and `auth`, `errors` and anything else it shares still
arrive.

| Prop | Shape |
| --- | --- |
| `panel` | `Panel::toSharedArray()`, or `null` outside a panel |
| `navigation` | the sidebar tree for the current panel, empty outside one |
| `panels` | the panels this user may enter, for the header switcher |
| `broadcasting` | `{enabled, channel}` — both the panel and the application must be able to broadcast |
| `search` | `{enabled, url, debounce, keyBindings}` |
| `notifications` | `{enabled, indexUrl, readUrl, clearUrl, unread}` |
| `tenancy` | `{current, available}`, or `null` for a panel with no tenancy |

Every value is a closure, so a request that never reaches a panel pays for none of them, and
nothing here is ever cached: visibility, badge counts, active state and the unread count are all
per-user and per-URL.

It belongs to the package rather than to the application's `HandleInertiaRequests` because a prop
added in a new version would otherwise break every application that did not copy it across. See
[Server Metadata to Vue](../concepts/metadata-to-vue.md).

## How they are appended

Through the HTTP kernel rather than the router, and after the kernel resolves rather than
immediately:

```php
$append = static function (mixed $kernel): void {
    if (! method_exists($kernel, 'appendMiddlewareToGroup')) {
        return;
    }

    foreach (self::WEB_MIDDLEWARE as $middleware) {
        $kernel->appendMiddlewareToGroup('web', $middleware);
    }
};

if ($this->app->resolved(Kernel::class)) {
    $append($this->app->make(Kernel::class));
}

$this->app->afterResolving(Kernel::class, $append);
```

`bootstrap/app.php` configures the `web` group inside `withMiddleware()`'s
`afterResolving(Kernel::class)` hook, and that hook calls `$kernel->setMiddlewareGroups(...)`,
overwriting whatever the router was holding. A package that pushed straight onto the router would
have its middleware silently dropped on the way in. Registering a *later* hook puts this after
that one, which is the only ordering that survives — and the branch above it covers the case where
the kernel has already resolved, which happens in a test that boots the application first.

`appendMiddlewareToGroup()` is idempotent, so a provider booted twice does not append twice.

## Turning it off

```php
// config/panda-panel.php

'register_web_middleware' => false,
```

The value is compared with `!== true`, so anything other than boolean `true` disables it.

Then register them yourself, in an order that keeps the four rules above:

```php
// bootstrap/app.php

use Illuminate\Foundation\Configuration\Middleware;
use PandaPanel\Http\Middleware\RedirectPanelHome;
use PandaPanel\Http\Middleware\ResetPanelContext;
use PandaPanel\Http\Middleware\ShareFlashToast;
use PandaPanel\Http\Middleware\SharePanelData;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->web(append: [
        ResetPanelContext::class,
        RedirectPanelHome::class,
        ShareFlashToast::class,
        SharePanelData::class,
    ]);
})
```

This is the right call when you need one of them at a particular position — `SharePanelData`
before your own Inertia middleware, say, or `ResetPanelContext` ahead of something that reads the
panel context. It is not a way to drop them: a panel screen without `SharePanelData` renders a
shell with no navigation, no switcher and no notifications.

Dropping `RedirectPanelHome` alone is a supported arrangement, and the same effect is available
without editing the middleware stack — see [Home Redirect](home-redirect.md).

## The aliases

Registered unconditionally, before the config key is even read, so turning the web middleware off
does not take them with it:

| Alias | Class |
| --- | --- |
| `panel` | `PandaPanel\Http\Middleware\ResolvePanel` |
| `panel.two-factor` | `PandaPanel\Http\Middleware\RequireTwoFactor` |
| `panel.email-code` | `PandaPanel\Http\Middleware\RequireEmailCode` |
| `panel.parent` | `PandaPanel\Http\Middleware\ResolveParentRecord` |

```php
use Illuminate\Support\Facades\Route;

Route::get('/reports/export', ExportController::class)->middleware('panel:admin');
```

They exist for applications that want to reference these classes in their own route definitions.
The route registrar names the classes directly rather than going through the aliases, so removing
an alias cannot break panel routing.

Each takes an optional panel id. `ResolvePanel` without one falls back to
`PanelManager::resolveFromRequest()`, which is what makes the `panel` alias usable outside a panel
group. `ResolveParentRecord` is different: its parameter is the nested resource's class, not a
panel id, and it is required.

## What is *not* registered here

`ResolvePanel`, `RequireTwoFactor`, `RequireEmailCode` and `ResolveTenant` are attached by
`PandaPanel\Routing\PanelRouteRegistrar` to each panel's route group, with the panel id passed as
a parameter. They are not on the `web` group and are not affected by this config key. See
[Route Registration](routes.md) and [Middleware and Guards](../panels/middleware.md).

## Gotchas

- **Order is not alphabetical or arbitrary.** `ResetPanelContext` must run before anything reads
  the panel context, and `RedirectPanelHome` before `SharePanelData` so a redirected request does
  not pay for props it will never render.
- **`register_web_middleware => false` and no manual registration is a broken panel**, and the
  symptom is not an error: the shell renders with an empty sidebar because `navigation` is
  missing from the props.
- **These middleware are on `web` only.** A panel whose `middleware()` does not include `web` gets
  none of them, and also has no session. See [Middleware and Guards](../panels/middleware.md).
- **A non-`web` route still gets `ResetPanelContext`** only if it is in the `web` group. An API
  route that resolves a panel by hand should clear the context itself, or accept that the context
  is whatever the last handler left.
- **`ShareFlashToast` needs a session.** A request without one is passed through untouched, which
  is why a toast flashed from a stateless API route never appears.

## See also

- [config/panda-panel.php](panda-panel.md)
- [Route Registration](routes.md)
- [Guest Redirect](guest-redirect.md)
- [Home Redirect](home-redirect.md)
- [Service Provider Behavior](service-provider.md)
- [Middleware and Guards](../panels/middleware.md)
- [Request Lifecycle](../concepts/request-lifecycle.md)
- [Panel Context](../concepts/panel-context.md)
- [Flash Toast Bridge](../notifications/flash-bridge.md)
- [Server Metadata to Vue](../concepts/metadata-to-vue.md)
