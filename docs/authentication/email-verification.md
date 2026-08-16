# Email Verification

A "check your inbox" page at the panel's own path, in the panel's brand, with a
resend button that posts to Fortify. You turn it on for a panel people sign
themselves up to, so a fresh registrant is not bounced out of the panel's shell
into the application's generic notice. The verification *link* itself is
Laravel's, and this page does not change where it points.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Portal;

use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelProvider;

final class PortalPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->path('portal')
            ->auth()             // 'auth' and 'verified'
            ->login()
            ->registration()
            ->emailVerification();
    }
}
```

```bash
php artisan route:list --name=panel.portal.auth.verification
```

```text
GET  portal/verify-email  panel.portal.auth.verification.notice
```

## The API

```php
public function emailVerification(bool $emailVerification = true): self
public function hasEmailVerification(): bool
```

```php
use PandaPanel\Core\PanelManager;

$panel = app(PanelManager::class)->get('portal');

$panel->hasEmailVerification();                          // true
$panel->routeName('auth.verification.notice');           // 'panel.portal.auth.verification.notice'
route($panel->routeName('auth.verification.notice'));    // '…/portal/verify-email'
```

Two things read the flag:

| Reader | Effect when false |
| --- | --- |
| `PanelRouteRegistrar::registerAuth()` | The route is not registered — the URL 404s |
| `PanelAuthController::verifyEmail()` | `abort_unless($this->panel()->hasEmailVerification(), 404)` |

Like the other front-door pages, it needs `login()`: `registerAuth()` returns
immediately when `hasLogin()` is false.

Unlike the login page's two link props, this page does **not** consult
`Laravel\Fortify\Features::emailVerification()`. It checks the panel's own flag
and nothing else, so a panel can render the notice in an application where
Fortify's verification feature is off — and the resend endpoint behind the button
will not exist. Turn both on or neither.

## The page

```php
public function verifyEmail(Request $request): Response
{
    abort_unless($this->panel()->hasEmailVerification(), 404);

    return Inertia::render('panel/auth/VerifyEmail', [
        'panel' => $this->panel()->toSharedArray(),
        'status' => $request->session()->get('status'),
    ]);
}
```

| Prop | Type | Source |
| --- | --- | --- |
| `panel` | `PanelDefinition` | `Panel::toSharedArray()` |
| `status` | `string\|null` | The `status` flash key; `'verification-link-sent'` after a resend |

`resources/js/pages/panel/auth/VerifyEmail.vue` draws two controls inside
`PanelAuthLayout`:

```vue
<script setup lang="ts">
import { Form, Link } from '@inertiajs/vue3';
import { logout } from '@/routes';
import { send } from '@/routes/verification';
</script>

<template>
    <Form v-slot="{ processing }" v-bind="send.form()">
        <Button type="submit" :disabled="processing">Resend the link</Button>

        <Link :href="logout()" as="button">Log out</Link>
    </Form>
</template>
```

| Control | Target | Route name |
| --- | --- | --- |
| Resend the link | `POST /email/verification-notification` | `verification.send` (Fortify) |
| Log out | `POST /logout` | `logout` (Fortify) |

Both come from Wayfinder's generated modules. The package registers neither: a
second resend endpoint per panel would mean a second throttle to keep in step
with Fortify's `fortify.limiters.verification`.

The page is a guest-stack route — the panel's base middleware plus
`ResolvePanel` — but the two buttons only do anything for somebody signed in,
which is who actually lands here.

## The route the middleware redirects to

This is the part worth reading twice. `->auth()` adds `verified`, which is
Laravel's `Illuminate\Auth\Middleware\EnsureEmailIsVerified`:

```php
public function auth(bool $verified = true): self
```

```php
$panel->auth();                  // appends ['auth', 'verified']
$panel->auth(verified: false);   // appends ['auth']
```

`EnsureEmailIsVerified` redirects to `URL::route($redirectToRoute ?: 'verification.notice')`
— the **application's** route name, not the panel's. So on a panel with
`->auth()->emailVerification()`, an unverified user visiting `/portal/dashboard`
is sent to the application's `/email/verify`, and the panel's page at
`/portal/verify-email` is reached only when something links to it.

Point the middleware at the panel's page with the framework's own parameterized
form:

```php
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use PandaPanel\Core\Panel;

$panel
    ->auth(verified: false)
    ->authMiddleware([
        'auth',
        EnsureEmailIsVerified::redirectTo('panel.portal.auth.verification.notice'),
    ]);
```

`redirectTo()` is a static helper that returns the middleware string with the
route name appended:

```php
EnsureEmailIsVerified::redirectTo('panel.portal.auth.verification.notice');
// 'Illuminate\Auth\Middleware\EnsureEmailIsVerified:panel.portal.auth.verification.notice'
```

Note the ordering constraint: `authMiddleware()` *replaces* the auth stack, so
`auth` has to be listed again. Call it instead of `auth()`, not after it.

## Verification as a panel access rule

There is a second, blunter way to hold an unverified account out — the
`PanelUser` contract on the model:

```php
use PandaPanel\Contracts\PanelUser;
use PandaPanel\Core\Panel;

final class User extends Authenticatable implements MustVerifyEmail, PanelUser
{
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasVerifiedEmail();
    }
}
```

That is what the example model in this repository does. The difference matters:

| Mechanism | Answer for an unverified user |
| --- | --- |
| `verified` middleware | **302** to a verification notice |
| `canAccessPanel()` returning false | **403**, with no redirect |

`ResolvePanel` aborts rather than redirects on purpose — hiding a panel behind a
redirect would tell an unauthorized user that a different panel exists.

That has a consequence worth knowing before you combine the two. `ResolvePanel`
is on the panel's *guest* routes as well, including this page, and it asks
`isAccessibleTo($request->user())` there too. So a signed-in but unverified user
opening `/portal/verify-email` is refused by the same rule — 403 on the page
that exists to fix the problem. A guest is unaffected: `null` is not a
`PanelUser`, so the contract is never asked and only the panel's closure runs.

Pick one mechanism per panel. Use the `verified` middleware when you want the
panel's own notice page; use `canAccessPanel()` when an unverified account
should be refused outright and sent to the application's screen.

## What Fortify owns

| Concern | Owner |
| --- | --- |
| `GET /email/verify` — the application's notice | Fortify (`verification.notice`) |
| `GET /email/verify/{id}/{hash}` — the signed link | Fortify (`verification.verify`) |
| `POST /email/verification-notification` — resending | Fortify (`verification.send`) |
| Throttling both | `config('fortify.limiters.verification')`, `6,1` by default |
| The email itself | Laravel's `Illuminate\Auth\Notifications\VerifyEmail` |
| Where a user lands after clicking | `redirect()->intended(Fortify::redirects('email-verification').'?verified=1')` |

The signed link is built from the application's `verification.verify` route.
The package does not repoint it, and there is no per-panel verify URL. To send a
user into a panel after verifying, set Fortify's redirect:

```php
// config/fortify.php
'redirects' => [
    'email-verification' => '/portal',
],
```

`redirect()->intended()` still wins when an intended URL was stored, which it is
for anyone who was sent to the login from a panel URL.

## The profile page's notice

The panel's profile settings page carries the same resend action, for a user who
changed their address:

```php
// PandaPanel\Pages\Settings\ProfileSettings::props()
'mustVerifyEmail' => Auth::user() instanceof MustVerifyEmail,
'status' => session('status'),
```

The notice is drawn only when `mustVerifyEmail` is true and
`user.email_verified_at` is null, and it links to the same `verification.send`
endpoint. See [Profile Settings](profile.md).

## Accounts created from the console

`php artisan panel:user` sets `email_verified_at` to `now()`:

```php
$user->forceFill([
    'name' => $attributes['name'],
    'email' => $attributes['email'],
    'password' => Hash::make($attributes['password']),
    'email_verified_at' => now(),
])->save();
```

A user created from the console has, by definition, been verified by whoever ran
the command. Leaving it null would put the first account straight into the
verify-email wall on a panel whose `->auth()` includes `verified`. See
[panel:user](panel-user-command.md).

## Testing it

```php
use Inertia\Testing\AssertableInertia;

it('serves the panel\'s own verification notice', function (): void {
    $this->get('/portal/verify-email')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('panel/auth/VerifyEmail')
            ->where('panel.id', 'portal')
        );
});

it('404s on a panel that did not ask for one', function (): void {
    $this->get('/admin/verify-email')->assertNotFound();
});

it('registers the pages a panel asked for and no others', function (): void {
    expect(Route::has('panel.door.auth.verification.notice'))->toBeTrue()
        ->and(Route::has('panel.admin.auth.login'))->toBeFalse();
});
```

The last one is from `tests/Feature/Panel/PanelAuthTest.php`. A panel whose
users must be verified is easiest to exercise with a factory state:

```php
$this->actingAs(User::factory()->unverified()->create())
    ->get('/portal')
    ->assertRedirect(route('verification.notice'));
```

## Gotchas

- **The panel's notice is not where `verified` sends people.** Laravel's
  middleware redirects to the application's `verification.notice` unless you
  pass a route name with `EnsureEmailIsVerified::redirectTo()`. Registering the
  panel's page does not change that on its own.
- **`authMiddleware()` replaces; `auth()` merges.** Calling `authMiddleware()`
  after `auth()` drops `auth` and `verified` unless you list them yourself.
- **A `canAccessPanel()` that requires verification 403s the panel's own notice
  page too.** `ResolvePanel` runs on the guest routes as well and asks the same
  question of a signed-in user. Combining that rule with
  `->emailVerification()` leaves an unverified account with a 403 on the very
  page that would fix it.
- **The resend button needs Fortify's feature on.** The page checks only the
  panel's flag. With `Features::emailVerification()` absent, `@/routes/verification`
  either is not generated at all — a build error — or points at a route that
  does not exist.
- **`status` is the only feedback.** The page shows "A new link has been sent to
  your address." for exactly the value `'verification-link-sent'`, which is what
  Fortify flashes. A custom resend endpoint flashing something else renders
  nothing.
- **Logging out is the application's route.** The package registers no logout;
  the page posts to Wayfinder's `logout` from `@/routes`.
- **Registration leaves the account unverified.** A panel with
  `registration()` and `->auth()` sends a brand new user straight into this
  wall, which is the intended flow — pair the two deliberately rather than by
  accident. See [Registration](registration.md).

## See also

- [Registration](registration.md) — the step before this one
- [Login](login.md), [Password Reset](password-reset.md)
- [Profile Settings](profile.md) — the other resend button
- [Fortify Integration](fortify.md)
- [The `PanelUser` Contract](panel-user-contract.md) — verification as an access rule
- [panel:user](panel-user-command.md)
- [Panel Middleware](../panels/middleware.md)
- [Panel Access Rules](../panels/access.md)
