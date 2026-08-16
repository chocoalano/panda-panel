# Guest Redirect

Where a guest is sent when they open a panel URL: that panel's own login when it has one, and the
application's `login` route otherwise. The package registers this rule for you unless config says
not to. Reach for this page when your own `redirectGuestsTo()` stopped taking effect, or when a
guest is landing on the wrong login.

## A minimal working example

```php
// config/panda-panel.php

'register_guest_redirect' => true,
```

```php
// A panel with its own front door.

$panel->path('door')->auth()->login();
```

```php
$this->get('/door')->assertRedirect('/door/login');

session('url.intended');   // '…/door' — they land where they were going
```

A panel that never called `login()` has no login route of its own, and a guest opening it is sent
to `route('login')` — which is what Laravel does by default. Turning this on adds a case rather
than replacing one.

## `PandaPanel\Support\PanelLoginRedirect`

```php
public static function for(Request $request): ?string
```

One method, and its whole decision:

```php
$panel = app(PanelManager::class)->resolveFromRequest($request);

if ($panel === null || ! $panel->hasLogin()) {
    return Route::has('login') ? route('login') : null;
}

$login = $panel->routeName('auth.login');

return Route::has($login) ? route($login) : null;
```

| Request | Answer |
| --- | --- |
| Inside a panel that called `login()` | `route('panel.{id}.auth.login')`, absolute |
| Inside a panel that did not | `route('login')` when the application has one |
| Outside any panel | `route('login')` when the application has one |
| Neither route exists | `null` |

Both `Route::has()` checks matter. The panel's login route only exists once the panel registered
it, so a panel whose login was turned off after boot — or a panel in an application with
`register_routes => false` — would otherwise redirect to a route name that does not resolve.

A `null` answer is not a failure mode the package invents. `Illuminate\Auth\Middleware\Authenticate`
throws `AuthenticationException` with a null redirect, and Laravel's handler answers
`response()->noContent(401)`. A JSON request never consults the callback at all: `Authenticate`
passes `null` for anything that expects JSON, and the handler returns a 401 with the message.

Call it yourself wherever you need the same answer:

```php
use PandaPanel\Support\PanelLoginRedirect;

PanelLoginRedirect::for($request);          // string|null
PanelLoginRedirect::for(...);               // first-class callable, which is how it is registered
```

## How it is registered

```php
private function registerGuestRedirect(): void
{
    if ($this->app->make('config')->get('panda-panel.register_guest_redirect') !== true) {
        return;
    }

    $redirect = static function (): void {
        Authenticate::redirectUsing(PanelLoginRedirect::for(...));
        AuthenticationException::redirectUsing(PanelLoginRedirect::for(...));
    };

    if ($this->app->resolved(Kernel::class)) {
        $redirect();
    }

    $this->app->afterResolving(Kernel::class, $redirect);
}
```

Two static callbacks, on `Illuminate\Auth\Middleware\Authenticate` and on
`Illuminate\Auth\AuthenticationException`. The first covers the `auth` middleware; the second
covers an `AuthenticationException` thrown from anywhere else, including Fortify.

The `afterResolving` hook is not decoration. Laravel's own `withMiddleware()` registers its
default — `fn () => route('login')` — inside its own `afterResolving(Kernel::class)` hook, so
anything a provider set earlier is overwritten when the HTTP kernel resolves. Registering a
*later* hook is the only ordering that survives, and calling `$redirect()` immediately covers the
case where the kernel has already resolved.

This is safe to do from a provider because `PanelLoginRedirect` is a strict superset of the
framework's default: a request that is not a panel request, or a panel with no login of its own,
still gets `route('login')`. The one thing it overrides is an application that set its own custom
redirect — which is what the config key is for.

## Keeping your own rule

```php
// config/panda-panel.php

'register_guest_redirect' => false,
```

```php
// bootstrap/app.php

use Illuminate\Foundation\Configuration\Middleware;
use PandaPanel\Support\PanelLoginRedirect;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->redirectGuestsTo(
        fn ($request) => PanelLoginRedirect::for($request) ?? route('welcome'),
    );
})
```

`redirectGuestsTo()` is the supported place to say this and it is the last word — it runs inside
the same hook the framework's default does, and after it.

`PanelLoginRedirect::for()` already falls back to `route('login')` for a request that is not a
panel's, so the `??` branch only fires when the application has no `login` route at all.

Turning the key off without writing a replacement leaves Laravel's default in place: every guest,
including one who opened a panel with its own front door, goes to `route('login')`.

## What this does not cover

- **`AuthenticateSession`.** Laravel's `redirectGuestsTo()` also points
  `Illuminate\Session\Middleware\AuthenticateSession::redirectUsing()` at the same callback. The
  package sets only the two above. If you use `AuthenticateSession` and want panel-aware redirects
  from it too, register it yourself alongside.
- **Where a user goes *after* signing in.** That is Fortify's, and the panel's counterpart to it
  is [Home Redirect](home-redirect.md).
- **Authorization.** A signed-in user who fails `canAccess()` gets a 403 from `ResolvePanel`,
  never a redirect. Hiding a panel behind a login is not an access control.

## Panel-side prerequisites

The redirect can only reach a panel login that exists:

```php
$panel
    ->login()                // GET {path}/login       → panel.{id}.auth.login
    ->registration()         // GET {path}/register
    ->passwordReset()        // GET {path}/forgot-password, {path}/reset-password/{token}
    ->emailVerification();   // GET {path}/verify-email
```

```php
panel('door')->hasLogin();   // bool — what PanelLoginRedirect asks
```

Those pages are registered *outside* the panel's auth stack, with its base middleware and
`ResolvePanel` only. Putting the login page behind `auth` would send somebody who cannot sign in
to the page that tells them to sign in. The forms post to Fortify's own endpoints rather than to
per-panel copies, because duplicating the login POST would mean duplicating rate limiting,
two-factor, passkeys and session handling.

## Gotchas

- **The key is compared with `!== true`.** A string `'false'` — or `'true'` — from an environment
  variable disables the registration.
- **`resolveFromRequest()` decides which panel, by longest path prefix and by `domain()`.** A
  guest on `/admin/reports/x` with panels at `/admin` and `/admin/reports` gets the second one's
  login.
- **The URL returned is absolute**, because `route()` is called without `absolute: false`. The
  home redirect returns a relative one; the two differ deliberately, since a guest redirect can
  legitimately cross to a panel on another domain.
- **`url.intended` is Laravel's**, set by `redirect()->guest()`. The panel does nothing extra to
  preserve it, which is why signing in through a panel's own login returns the user to the page
  they asked for.
- **If both this and your own `redirectGuestsTo()` are active, yours wins.** It is registered
  later. That is the failure this key exists to make explicit rather than mysterious.

## See also

- [config/panda-panel.php](panda-panel.md)
- [Home Redirect](home-redirect.md)
- [Middleware Registration](middleware.md)
- [Service Provider Behavior](service-provider.md)
- [Panel Login](../authentication/login.md)
- [Fortify Integration](../authentication/fortify.md)
- [Middleware and Guards](../panels/middleware.md)
- [Panel Access Rules](../panels/access.md)
- [Login Redirect Problems](../troubleshooting/login-redirects.md)
