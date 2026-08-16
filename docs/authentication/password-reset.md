# Password Reset

Two guest pages at the panel's own path — "email me a link" and "set a new
password" — carrying the panel's brand and posting to Fortify's own reset
endpoints. You turn this on for a panel with a login of its own, so somebody who
forgot their password never leaves the panel's shell to recover it.

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
            ->auth()
            ->login()
            ->passwordReset();
    }
}
```

```bash
php artisan route:list --name=panel.admin.auth.password
```

```text
GET  admin/forgot-password         panel.admin.auth.password.request
GET  admin/reset-password/{token}  panel.admin.auth.password.reset
```

The panel's login page now draws a "Forgot your password?" link, and both pages
render inside `PanelAuthLayout` with the panel's icon and brand name.

## The API

```php
public function passwordReset(bool $passwordReset = true): self
public function hasPasswordReset(): bool
```

```php
use PandaPanel\Core\PanelManager;

$panel = app(PanelManager::class)->get('admin');

$panel->hasPasswordReset();                          // true
$panel->routeName('auth.password.request');          // 'panel.admin.auth.password.request'
route($panel->routeName('auth.password.request'));   // 'https://example.test/admin/forgot-password'
```

Three things read the flag:

| Reader | Effect when false |
| --- | --- |
| `PanelRouteRegistrar::registerAuth()` | Neither route is registered — both URLs 404 |
| `PanelAuthController::requestPasswordReset()` and `resetPassword()` | `abort_unless($this->panel()->hasPasswordReset(), 404)` |
| `PanelAuthController::login()` | `canResetPassword` is false, so the login page draws no link |

`passwordReset()` needs `login()`. `registerAuth()` returns immediately when
`hasLogin()` is false, so a panel that asks for a reset page without a login
registers nothing at all.

The boolean parameter is there so the decision can be an expression:

```php
$panel->passwordReset(! app()->isProduction());
```

## The two pages

Both are guest routes: the panel's *base* middleware plus panel resolution, and
deliberately not `auth`.

**`requestPasswordReset()`** renders `panel/auth/ForgotPassword`:

```php
public function requestPasswordReset(Request $request): Response
{
    abort_unless($this->panel()->hasPasswordReset(), 404);

    return Inertia::render('panel/auth/ForgotPassword', [
        'panel' => $this->panel()->toSharedArray(),
        'status' => $request->session()->get('status'),
    ]);
}
```

| Prop | Type | Source |
| --- | --- | --- |
| `panel` | `PanelDefinition` | `Panel::toSharedArray()` |
| `status` | `string\|null` | The `status` flash key, which Fortify writes after a link is sent |

**`resetPassword()`** renders `panel/auth/ResetPassword`:

```php
public function resetPassword(Request $request): Response
{
    abort_unless($this->panel()->hasPasswordReset(), 404);

    return Inertia::render('panel/auth/ResetPassword', [
        'panel' => $this->panel()->toSharedArray(),
        'email' => $request->query('email'),
        'token' => (string) $request->route('token'),
        'passwordRules' => PasswordRules::attribute(),
    ]);
}
```

| Prop | Type | Source |
| --- | --- | --- |
| `panel` | `PanelDefinition` | `Panel::toSharedArray()` |
| `email` | `string\|null` | The `email` **query string** parameter, which is how Laravel's reset link carries it |
| `token` | `string` | The `{token}` route parameter |
| `passwordRules` | `string` | `PandaPanel\Support\PasswordRules::attribute()` |

## What the forms post to

Both post at Fortify, through Wayfinder's generated `@/routes/password` module.

```vue
<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { email } from '@/routes/password';
</script>

<template>
    <Form v-slot="{ errors, processing }" v-bind="email.form()">
        <!-- email -->
    </Form>
</template>
```

```vue
<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { update } from '@/routes/password';
</script>

<template>
    <Form v-slot="{ errors, processing }" v-bind="update.form()">
        <input type="hidden" name="token" :value="token" />
        <!-- email (readonly), password, password_confirmation -->
    </Form>
</template>
```

| Wayfinder export | Verb and path | Fortify route name |
| --- | --- | --- |
| `email` | `POST /forgot-password` | `password.email` |
| `update` | `POST /reset-password` | `password.update` |

That is the whole design: throttling, token validation, the `PasswordReset`
event and session handling all sit behind those two endpoints, and every panel
in the application uses the same pair. Duplicating them per panel would mean
duplicating four things that must never disagree.

The "Back to log in" link is built from `panel.path` rather than from a
generated route, so it follows a panel that moves:

```vue
<TextLink :href="`/${panel.path}/login`">Back to log in</TextLink>
```

## The password policy

`PandaPanel\Support\PasswordRules` turns the application's own
`Illuminate\Validation\Rules\Password` policy into the browser `passwordrules`
attribute, which is what Safari and iOS read when generating a suggested
password:

```php
public static function attribute(?Illuminate\Validation\Rules\Password $password = null): string
```

```php
use Illuminate\Validation\Rules\Password;
use PandaPanel\Support\PasswordRules;

PasswordRules::attribute();                       // from Password::defaults()
PasswordRules::attribute(Password::min(8));       // 'minlength: 8;'
PasswordRules::attribute(Password::min(12)->mixedCase()->numbers()->symbols());
// 'minlength: 12; required: lower; required: upper; required: digit; required: special;'
```

The reset page prints the string under the new-password field. It changes what
the browser *suggests*; what is enforced is whatever Fortify's
`ResetUserPassword` action validates. See [Registration](registration.md) for
the full mapping table.

## Where the emailed link points

This is the part an install gets wrong. Laravel's
`Illuminate\Auth\Notifications\ResetPassword` builds its URL from the
**application's** `password.reset` route, not the panel's — so the link in the
email lands on `/reset-password/{token}`, the application's screen, even for
somebody who asked from `/admin/forgot-password`.

Point it at a panel in your own service provider:

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;
use PandaPanel\Core\PanelManager;

final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        ResetPassword::createUrlUsing(static function (object $notifiable, string $token): string {
            $panel = app(PanelManager::class)->get('admin');

            return route($panel->routeName('auth.password.reset'), [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ]);
        });
    }
}
```

The `email` query parameter is not decoration: the panel's reset page reads it
straight out of the query string and renders it into a readonly field, and
Fortify's `password.update` requires it in the POST body.

An application with two panels that both reset passwords has one notification
and one link. If the two must differ, branch inside the closure on something the
notifiable carries — a role, a tenant — rather than on the request, which does
not exist when the mail is queued.

## After the reset

Fortify's `PasswordResetResponse` redirects to
`Fortify::redirects('password-reset')`, falling back to `route('login')` when
Fortify's views are enabled, and flashes `status`. That is the application's
login, not the panel's: a user who reset from `/admin/forgot-password` lands on
`/login` with the success message.

To send them back to a panel, set Fortify's redirect:

```php
// config/fortify.php
'redirects' => [
    'password-reset' => '/admin/login',
],
```

The panel's own login page renders `status` when it is present, so the message
survives the redirect either way.

## Testing it

```php
use Inertia\Testing\AssertableInertia;

it('serves the panel\'s own forgot-password page', function (): void {
    $this->get('/admin/forgot-password')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('panel/auth/ForgotPassword')
            ->where('panel.id', 'admin')
        );
});

it('carries the token and address into the reset page', function (): void {
    $this->get('/admin/reset-password/the-token?email=ada@example.com')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('panel/auth/ResetPassword')
            ->where('token', 'the-token')
            ->where('email', 'ada@example.com')
            ->has('passwordRules')
        );
});

it('404s on a panel that did not ask for a reset page', function (): void {
    $this->get('/app/forgot-password')->assertNotFound();
});
```

The route registration itself is asserted in
`tests/Feature/Panel/PanelAuthTest.php`, and `PasswordRules` has its own suite in
`tests/Feature/Panel/PasswordRulesTest.php`.

## Gotchas

- **The reset page renders without a token check.** The route parameter is
  taken as a string and handed to the form; whether it is valid is decided by
  Fortify when the form is submitted. A tampered link therefore renders a form
  that then fails validation, which is the correct order — the page must not
  leak whether a token exists.
- **`email` comes from the query string, not the token.** Open
  `/admin/reset-password/abc` with no `?email=` and the field is empty and
  editable-looking but marked readonly, so the POST arrives without an address
  and fails. Always generate the link with both parameters.
- **Fortify's feature flag gates the login link, not these pages.**
  `canResetPassword` is `Features::enabled(Features::resetPasswords()) && $panel->hasPasswordReset()`,
  while the two pages check only the panel's own flag. With the Fortify feature
  off, `/admin/forgot-password` still renders and `POST /forgot-password` does
  not exist. Turn both on or neither.
- **`canAccess()` runs on these pages.** They are guest routes but still carry
  `ResolvePanel`, which calls `isAccessibleTo(null)` for a guest. A predicate
  like `fn (?Authenticatable $u) => $u?->is_admin === true` answers false and the
  page 403s. Admit the guest explicitly when a panel combines the two.
- **There is no per-panel throttle.** Rate limiting lives on Fortify's
  endpoints, shared by every door into the application. That is the point.
- **Password reset is not password *change*.** Changing a known password is the
  security settings page, which posts to your own controller. See
  [Security Settings](security.md).

## See also

- [Login](login.md) — the page that links here
- [Registration](registration.md) — where the `passwordRules` mapping is documented
- [Email Verification](email-verification.md)
- [Fortify Integration](fortify.md) — which half owns what
- [Security Settings](security.md) — changing a password you still know
- [Panel Branding](../panels/branding.md)
- [Wayfinder](../frontend/wayfinder.md)
