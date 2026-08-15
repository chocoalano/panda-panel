# Registration

A sign-up form at the panel's own path, in the panel's brand, posting to
Fortify's register endpoint. You turn it on for a panel people sign themselves
up to — a customer portal, a partner area — and leave it off for a back office,
where accounts are created by an administrator or by
[`panel:user`](panel-user-command.md).

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
            ->auth(verified: false)
            ->login()
            ->registration();
    }
}
```

```bash
php artisan route:list --name=panel.portal.auth
```

```text
GET  portal/login     panel.portal.auth.login
GET  portal/register  panel.portal.auth.register
```

The login page now draws a "Don't have an account? Sign up" link, and
`/portal/register` renders a form that posts to `POST /register`.

## The API

```php
public function registration(bool $registration = true): self
public function hasRegistration(): bool
```

Three things read the flag:

| Reader | Effect when false |
| --- | --- |
| `PanelRouteRegistrar::registerAuth()` | The `auth.register` route is not registered — the URL 404s |
| `PanelAuthController::register()` | `abort_unless($this->panel()->hasRegistration(), 404)` |
| `PanelAuthController::login()` | The `canRegister` prop is false, so the login page draws no link |

```php
use PandaPanel\Core\PanelManager;

$panel = app(PanelManager::class)->get('portal');

$panel->hasRegistration();                     // true
$panel->routeName('auth.register');            // 'panel.portal.auth.register'
route($panel->routeName('auth.register'));     // '…/portal/register'
```

`registration()` needs `login()`. `registerAuth()` returns early when
`hasLogin()` is false, so a panel that asks for registration alone registers
nothing at all.

## The props

```php
public function register(): Response
{
    abort_unless($this->panel()->hasRegistration(), 404);

    return Inertia::render('panel/auth/Register', [
        'panel' => $this->panel()->toSharedArray(),
        'passwordRules' => PasswordRules::attribute(),
    ]);
}
```

| Prop | Type | Source |
| --- | --- | --- |
| `panel` | `PanelDefinition` | `Panel::toSharedArray()` |
| `passwordRules` | `string` | `PandaPanel\Support\PasswordRules::attribute()` |

There is no `status` prop and no `canLogin` prop: the page always links back
to `/{panel.path}/login`, which exists whenever this page does.

## The password policy, twice

`PandaPanel\Support\PasswordRules` turns the application's own
`Illuminate\Validation\Rules\Password` policy into the browser `passwordrules`
attribute. Safari and iOS read that attribute when generating a suggested
password, so a policy expressed only in server-side validation produces a
suggestion the form then rejects.

```php
public static function attribute(?Illuminate\Validation\Rules\Password $password = null): string
```

Called with no argument it uses `Password::defaults()`.

```php
use Illuminate\Validation\Rules\Password;
use PandaPanel\Support\PasswordRules;

PasswordRules::attribute(Password::min(8));
// 'minlength: 8;'

PasswordRules::attribute(Password::min(12)->max(64)->mixedCase()->numbers()->symbols());
// 'minlength: 12; maxlength: 64; required: lower; required: upper; required: digit; required: special;'

PasswordRules::attribute(Password::min(10)->letters());
// 'minlength: 10; required: lower;'
```

| Policy call | Emits |
| --- | --- |
| `min(n)` | `minlength: n` — always present, defaulting to 8 |
| `max(n)` | `maxlength: n` |
| `letters()` | `required: lower` |
| `mixedCase()` | `required: lower; required: upper` (replaces the `letters()` branch) |
| `numbers()` | `required: digit` |
| `symbols()` | `required: special` |

On Laravel 13 it delegates to the framework's own
`Password::toPasswordRulesString()`. On Laravel 12, which has no such method,
it rebuilds the string from `appliedRules()` — byte for byte the same output,
which is what `tests/Feature/Panel/PasswordRulesTest.php` asserts.

The Vue page prints the string under the password field and the security
settings page passes it to `PasswordInput` as the `passwordrules` attribute.

## What the form posts to

`resources/js/pages/panel/auth/Register.vue`:

```vue
<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { store } from '@/routes/register';
</script>

<template>
    <Form
        v-bind="store.form()"
        :reset-on-success="['password', 'password_confirmation']"
    >
        <!-- name, email, password, password_confirmation -->
    </Form>
</template>
```

`store` is Fortify's `POST /register`, route name `register.store`. The four
field names are exactly what Fortify's `CreatesNewUsers` action receives.

## What an account is made of

The package writes no user. Fortify hands the input to whatever you registered:

```php
use App\Actions\Fortify\CreateNewUser;
use Laravel\Fortify\Fortify;

Fortify::createUsersUsing(CreateNewUser::class);
```

The example action, `examples/app/Actions/Fortify/CreateNewUser.php`, names
every attribute rather than passing the request through:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

final class CreateNewUser implements CreatesNewUsers
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ])->validate();

        return User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => Hash::make($input['password']),
        ]);
    }
}
```

That is the second half of keeping a privilege flag out of `$fillable`: a
guard on the model stops a mass assignment, and writing the attributes out
stops anybody adding one later without noticing. A registration form that could
set `is_admin` is a privilege anyone can grant themselves.

## Registering is not the same as being admitted

Fortify signs the new user in and redirects. Whether they may then *enter the
panel* is a separate question, asked by `ResolvePanel` on the next request and
answered by two independent rules:

```php
$panel->isAccessibleTo($user);   // canAccess() closure, and PanelUser::canAccessPanel()
```

So a panel with public registration and a restrictive predicate signs somebody
up and then 403s them. That combination is usually a mistake; when it is
deliberate — sign up now, wait for approval — give the refusal somewhere to
land by admitting the account and gating the *content* instead.

The example user model refuses until the address is verified:

```php
public function canAccessPanel(Panel $panel): bool
{
    return $this->hasVerifiedEmail();
}
```

Paired with `->emailVerification()`, that is the ordinary flow: register,
land on the panel's verify-email notice, click the link, come back.

## Gotchas

- **Fortify's feature flag wins over the panel's.** `canRegister` is
  `Features::enabled(Features::registration()) && $panel->hasRegistration()`.
  With the Fortify feature off, the panel's `/register` page still renders — it
  checks only its own flag — while `POST /register` does not exist. Turn both
  on or neither.
- **`passwordRules` is a hint, not a rule.** It changes what Safari suggests.
  What is *enforced* is whatever your `CreatesNewUsers` action validates, and
  the example validates `min:8` regardless of `Password::defaults()`.
- **There is no per-panel registration action.** One application, one
  `createUsersUsing`. A panel that needs to stamp something on the new account
  — a tenant, a role — does it in that action, branching on the panel if it
  must: `app(PanelManager::class)->currentPanel()?->getId()`.
- **New accounts are unverified.** `->auth()` includes `verified`, so a panel
  with the default auth stack and no `emailVerification()` page sends a fresh
  registrant to the *application's* verification notice.

## See also

- [Login](login.md) — the page that links here
- [Email Verification](email-verification.md) — the usual next step
- [Fortify Integration](fortify.md) — the features these props read
- [Panels: access rules](../panels/access.md) — why a new account can still 403
- [The PanelUser contract](panel-user-contract.md)
- [panel:user](panel-user-command.md) — creating an account without a form
