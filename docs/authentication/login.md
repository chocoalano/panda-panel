# Login

A panel's own front door: a login page at the panel's path, carrying the
panel's brand, posting to Fortify's login endpoint. You turn it on when a panel
should not send its users through the application's generic login screen — a
customer portal and a staff back office that look nothing alike, for instance.

## A minimal working example

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
            ->brandName('Acme Operations')
            ->icon('shield')
            ->auth()
            ->login();
    }
}
```

```bash
curl -sI https://example.test/admin | grep Location
# Location: https://example.test/admin/login
```

A guest who opens any `/admin` URL is redirected to `/admin/login`, the
intended URL is kept, and signing in returns them to where they were going.

## The API

```php
public function login(bool $login = true): self
public function hasLogin(): bool
```

`login()` does three things:

1. Registers `GET {path}/login`, named `panel.{id}.auth.login`, rendering the
   `panel/auth/Login` Inertia component.
2. Gates the other three front-door pages — registration, password reset,
   email verification — which register nothing when `hasLogin()` is false.
3. Changes where a guest is *sent*, through
   `PandaPanel\Support\PanelLoginRedirect`.

```php
use PandaPanel\Core\PanelManager;

$panel = app(PanelManager::class)->get('admin');

$panel->hasLogin();                        // true
$panel->routeName('auth.login');           // 'panel.admin.auth.login'
route($panel->routeName('auth.login'));    // 'https://example.test/admin/login'
```

## The middleware the page runs

The login route is registered *outside* the panel's route group. It gets the
panel's base middleware plus panel resolution, and deliberately not `auth`:

```php
[
    ...$panel->getBaseMiddleware(),          // ['web'] by default
    PandaPanel\Http\Middleware\ResolvePanel::class.':'.$panel->getId(),
]
```

The session, the CSRF token and the Inertia middleware all have to be there or
the form cannot be submitted. `auth` must not be, because sending somebody who
cannot sign in to the page that tells them to sign in is a loop.

## The props

`PandaPanel\Http\Controllers\PanelAuthController::login()`:

```php
public function login(Request $request): Response
{
    return Inertia::render('panel/auth/Login', [
        'panel' => $this->panel()->toSharedArray(),
        'canResetPassword' => Features::enabled(Features::resetPasswords())
            && $this->panel()->hasPasswordReset(),
        'canRegister' => Features::enabled(Features::registration())
            && $this->panel()->hasRegistration(),
        'status' => $request->session()->get('status'),
    ]);
}
```

| Prop | Type | Source |
| --- | --- | --- |
| `panel` | `PanelDefinition` | `Panel::toSharedArray()` — id, name, path, brand, icon, theme, CSS hooks |
| `canResetPassword` | `bool` | Fortify's `resetPasswords` feature **and** `Panel::hasPasswordReset()` |
| `canRegister` | `bool` | Fortify's `registration` feature **and** `Panel::hasRegistration()` |
| `status` | `string\|null` | The `status` flash key, which Fortify writes after a password reset |

Both link props require both answers. A panel that called `registration()` in
an application where Fortify's registration feature is off draws no sign-up
link, because the POST behind it would not exist.

## What the page submits to

`resources/js/pages/panel/auth/Login.vue` binds Fortify's own endpoint through
Wayfinder:

```vue
<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { store } from '@/routes/login';
</script>

<template>
    <Form v-bind="store.form()" :reset-on-success="['password']">
        <!-- email, password, remember -->
    </Form>
</template>
```

`store` is `POST /login`, route name `login.store`, which is Fortify's. That is
the whole design: rate limiting, session regeneration, remember-me, the
two-factor challenge and the passkey flow are all behind that one endpoint, and
every panel in the application uses the same one.

The page also renders `PasskeyVerify.vue` above the form, which calls
`usePasskeyVerify()` from `@laravel/passkeys/vue` and hides itself entirely
when the browser has no WebAuthn support. See [Passkeys](passkeys.md).

## Branding

The page is wrapped in `PanelAuthLayout.vue`, which draws the panel's icon and
brand name and nothing else — no navigation, no notification bell, no user
menu, because none of the shell applies to a guest. Everything it draws comes
from the `panel` prop:

```php
$panel
    ->brandName('Acme Operations')   // getBrandName(), defaults to config('app.name')
    ->icon('shield')                 // resolved through the panel icon registry
    ->theme([...]);                  // the CSS variables the page is painted with
```

The layout links the brand back to `/{panel.path}`, and the "forgot your
password" and "sign up" links are built the same way — from `panel.path`
rather than from a generated route, so they follow a panel that moves.

## Where a guest is sent

`PandaPanel\Support\PanelLoginRedirect` is the rule:

```php
public static function for(Illuminate\Http\Request $request): ?string
```

| Situation | Result |
| --- | --- |
| The request resolves to a panel with `hasLogin()` | That panel's `auth.login` route |
| The request resolves to a panel without one | `route('login')`, or `null` when the application has no such route |
| The request resolves to no panel | The same |
| The panel's login route is not registered | `route('login')` or `null` |

It is registered by `PandaPanelServiceProvider` into
`Illuminate\Auth\Middleware\Authenticate::redirectUsing()` and
`Illuminate\Auth\AuthenticationException::redirectUsing()`, inside an
`afterResolving(Kernel::class)` hook. That timing matters: Laravel registers
its own default (`fn () => route('login')`) inside `withMiddleware()`'s
`afterResolving` hook, so anything a plain service provider sets is
overwritten on every request.

To keep your own rule and still get panel logins, turn the automatic
registration off and call into it:

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

## Where a user lands afterwards

Fortify's `LoginResponse` does `redirect()->intended(Fortify::redirects('login'))`.
The intended URL was stored by the guest redirect, so a user who was sent to
`/admin/login` from `/admin/users/3/edit` lands back on that record.

A user who opened the login page directly has no intended URL and falls through
to Fortify's configured home, which on a Laravel Vue starter kit is
`/dashboard` — the placeholder screen. That is what
`PandaPanel\Http\Middleware\RedirectPanelHome` is for: it sends a signed-in
user who lands on `/dashboard` into the first panel they can enter, without
touching the application's route, its name, or its page component.

```php
// config/panda-panel.php
'home_redirect' => [
    'enabled' => true,
    'paths' => ['dashboard'],
],
```

See [Home Redirect](../configuration/home-redirect.md).

## Testing it

```php
use Inertia\Testing\AssertableInertia;

it('serves the panel\'s own login page to a guest', function (): void {
    $this->get('/admin/login')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('panel/auth/Login')
            ->where('panel.name', 'Administrator')
            ->where('canResetPassword', true)
        );
});

it('sends a guest to this panel\'s door, keeping where they were going', function (): void {
    $this->get('/admin')->assertRedirect('/admin/login');

    expect(session('url.intended'))->toContain('/admin');
});
```

That is `tests/Feature/Panel/PanelAuthTest.php`, near enough verbatim.

## Gotchas

- **`canAccess()` runs on the login page too.** `ResolvePanel` is in the guest
  group, and it calls `isAccessibleTo($request->user())` with `null` for a
  guest. A predicate like `fn (?Authenticatable $u) => $u?->is_admin === true`
  answers false for a guest, and the login page 403s. Admit the guest
  explicitly when a panel combines the two:

  ```php
  ->canAccess(static fn (?Authenticatable $user): bool => $user === null || $user->is_admin === true)
  ```

  The panel's real pages are still safe: `auth` runs before `ResolvePanel` in
  the panel's own group, so a guest never reaches the predicate there.
- **A panel with `login()` but no `auth()` has a door on a building with no
  walls.** The page registers from `hasLogin()` alone.
- **Logging out is the application's route.** The package registers no logout;
  the panel shell and the verify-email page both link to Wayfinder's `logout`
  from `@/routes`.
- **`/{panel}/login` for an already signed-in user renders the login page.**
  There is no `guest` middleware on it, by design — the stack that would add it
  is the application's `redirectUsersTo`, not the panel's.
- **Two panels, two logins, one session.** Signing in at `/admin/login` signs
  you in everywhere; the panels differ in who they *admit*, not in who they
  authenticate.

## See also

- [Fortify Integration](fortify.md) — which half owns what
- [Registration](registration.md), [Password Reset](password-reset.md),
  [Email Verification](email-verification.md)
- [Panels: access rules](../panels/access.md) — `canAccess()` and `PanelUser`
- [Panels: branding](../panels/branding.md) — brand name, icon, theme
- [Configuration: guest redirect](../configuration/guest-redirect.md),
  [home redirect](../configuration/home-redirect.md)
- [Troubleshooting: login redirect loops](../troubleshooting/login-redirects.md)
