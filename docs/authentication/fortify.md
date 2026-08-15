# Fortify Integration

Panda Panel does not implement signing in. It renders auth *screens* — a login,
a registration form, a password reset, an email verification notice — inside a
panel's own brand and at the panel's own path, and posts every one of them to
[Laravel Fortify](https://laravel.com/docs/fortify), which the package requires
as a dependency. You reach for this page when a panel needs a front door of its
own, or when you need to know which half of the stack owns a given behaviour.

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
php artisan route:list --name=panel.admin.auth
```

```text
GET  admin/login            panel.admin.auth.login
GET  admin/forgot-password  panel.admin.auth.password.request
GET  admin/reset-password/{token}  panel.admin.auth.password.reset
```

A guest opening `/admin` now lands on `/admin/login`, sees the panel's brand,
and submits to Fortify's `login.store`.

## Why the forms are Fortify's

Duplicating the login POST per panel would mean duplicating rate limiting,
two-factor, passkeys, and session fixation handling — four things that must
never disagree between two doors into the same application. So the panel owns
the presentation and nothing else:

| Concern | Owner |
| --- | --- |
| The login/register/reset/verify *pages* | The panel (`PandaPanel\Http\Controllers\PanelAuthController`) |
| The login/register/reset/verify *POSTs* | Fortify |
| Throttling, session regeneration, remember-me | Fortify |
| TOTP two-factor, recovery codes | Fortify |
| Passkey registration and login | Fortify (`laravel/passkeys`) |
| What a new user is made of | Your `Fortify::createUsersUsing()` action |
| The emailed-code second factor | The panel (`PandaPanel\Auth\EmailCodeChallenge`) |
| Whether this user may *enter the panel* | The panel (`canAccess()` and `PanelUser`) |

The one authentication concern the package implements itself is the emailed
code, and it is deliberately not part of the login POST — see
[Email Code Challenge](email-code-challenge.md).

## Requiring a signed-in user

`auth()` is the shorthand. It appends to the panel's *auth* middleware, which
is merged after the base stack:

```php
public function auth(bool $verified = true): self
```

```php
$panel->auth();                   // ['auth', 'verified'] appended
$panel->auth(verified: false);    // ['auth'] appended
```

The two stacks are separate because the panel's guest pages need one and not
the other:

| Method | Signature | Default | Notes |
| --- | --- | --- | --- |
| `middleware` | `middleware(array $middleware): self` | `['web']` | Replaces the base stack. |
| `authMiddleware` | `authMiddleware(array $middleware): self` | `[]` | Replaces the auth stack. `auth()` merges into it. |
| `getBaseMiddleware` | `getBaseMiddleware(): array` | — | The base stack alone. What the login page runs. |
| `getAuthMiddleware` | `getAuthMiddleware(): array` | — | The auth stack alone. |
| `getMiddleware` | `getMiddleware(): array` | — | Both, deduplicated, in that order. |

```php
use App\Http\Middleware\EnsureOnCorporateNetwork;

$panel
    ->middleware(['web', EnsureOnCorporateNetwork::class])
    ->auth();

$panel->getBaseMiddleware();   // ['web', EnsureOnCorporateNetwork::class]
$panel->getAuthMiddleware();   // ['auth', 'verified']
$panel->getMiddleware();       // ['web', EnsureOnCorporateNetwork::class, 'auth', 'verified']
```

`PandaPanel\Routing\PanelRouteRegistrar` then builds the panel's route group
from `getMiddleware()` and appends four framework entries in a fixed order:

```text
…panel middleware…
ResolvePanel:{id}        // binds the panel, and 403s a user who may not enter
RequireTwoFactor:{id}    // no-op unless the panel called requireTwoFactor()
RequireEmailCode:{id}    // no-op unless this account turned emailed codes on
ResolveTenant:{id}       // only for a panel with tenancy
```

The panel's own auth *pages* are registered outside that group, with
`getBaseMiddleware()` plus `ResolvePanel` only. Putting a login page behind
`auth` would send somebody who cannot sign in to the page that tells them to
sign in.

## The four front-door toggles

Each one registers pages, and each has a matching reader. All four default to
off.

| Method | Signature | Reader | Routes registered |
| --- | --- | --- | --- |
| `login` | `login(bool $login = true): self` | `hasLogin(): bool` | `auth.login` |
| `registration` | `registration(bool $registration = true): self` | `hasRegistration(): bool` | `auth.register` |
| `passwordReset` | `passwordReset(bool $passwordReset = true): self` | `hasPasswordReset(): bool` | `auth.password.request`, `auth.password.reset` |
| `emailVerification` | `emailVerification(bool $emailVerification = true): self` | `hasEmailVerification(): bool` | `auth.verification.notice` |

```php
$panel
    ->auth()
    ->login()
    ->registration()
    ->passwordReset()
    ->emailVerification();
```

`login()` is the gate for all four: `registerAuth()` returns immediately when
`hasLogin()` is false, so a panel that asks for registration without a login
gets neither route. The three that depend on it also 404 individually when
their own flag is off, because `PanelAuthController` calls
`abort_unless($this->panel()->hasRegistration(), 404)` and so on.

Every route name is prefixed with the panel's own:

```php
use PandaPanel\Core\PanelManager;

$panel = app(PanelManager::class)->get('admin');

$panel->routeName('auth.login');   // 'panel.admin.auth.login'
route($panel->routeName('auth.login'));   // 'https://example.test/admin/login'
```

## Demanding a second factor

```php
public function requireTwoFactor(bool $required = true): self
public function requiresTwoFactor(): bool
```

```php
$panel->auth()->requireTwoFactor();
```

`PandaPanel\Http\Middleware\RequireTwoFactor` then holds a user at the panel's
security page until they have one. Three things count, and any of them is
enough:

```php
$user->hasEnabledTwoFactorAuthentication()   // Fortify's TOTP
PandaPanel\Auth\EmailCodeFactor::isEnabledFor($user)   // the panel's emailed code
$user->passkeys()->exists()                  // a registered passkey
```

A passkey counts because a panel that demanded an authenticator app from
somebody already using a hardware key would be demanding a downgrade. See
[Two-Factor Authentication](two-factor.md) and [Passkeys](passkeys.md).

## The Fortify features the package reads

The package never enables a Fortify feature. It asks whether one is on, and
draws accordingly:

| Call site | Feature check | Effect |
| --- | --- | --- |
| `PanelAuthController::login()` | `Features::enabled(Features::resetPasswords())` | The `canResetPassword` prop, which draws the "forgot your password" link |
| `PanelAuthController::login()` | `Features::enabled(Features::registration())` | The `canRegister` prop, which draws the "sign up" link |
| `SecuritySettings::props()` | `Features::canManageTwoFactorAuthentication()` | The two-factor card, `twoFactorEnabled`, `requiresConfirmation` |
| `SecuritySettings::props()` | `Features::canManagePasskeys()` | The passkeys card and the `passkeys` prop |
| `SecuritySettings::props()` | `Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm')` | Whether setup asks for a confirming code |

Both props on the login page require *both* answers: the Fortify feature and
the panel's own flag. A panel that called `registration()` in an application
where Fortify's registration feature is off shows no sign-up link, and its
`/register` page renders while the POST behind it does not exist.

## What your application still owns

Three things, none of which a panel framework has any business writing.

**A `FortifyServiceProvider`.** The panel's screens do not replace the
application's own. This is
`examples/app/Providers/FortifyServiceProvider.php`, which the test suite runs
against:

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Fortify;

final class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);

        Fortify::loginView(static fn (): Response => Inertia::render('auth/Login'));
        Fortify::registerView(static fn (): Response => Inertia::render('auth/Register'));
        Fortify::confirmPasswordView(static fn (): Response => Inertia::render('auth/ConfirmPassword'));
        Fortify::twoFactorChallengeView(static fn (): Response => Inertia::render('auth/TwoFactorChallenge'));
    }
}
```

`confirmPasswordView` is not optional for a panel that keeps its settings
pages: `PandaPanel\Pages\Settings\SecuritySettings` runs behind
`Illuminate\Auth\Middleware\RequirePassword`, which redirects to
`password.confirm`.

**A user model.** The package asks three things of it — `Notifiable` for the
notification centre and the emailed code, `TwoFactorAuthenticatable` for the
security page, and optionally `PandaPanel\Contracts\PanelUser` for a rule about
the account. See [User Model Requirements](user-model.md).

**The host frontend modules.** The published Vue components import
`@/routes/login`, `@/routes/register`, `@/routes/password`,
`@/routes/two-factor`, `@/routes/verification` and
`@/actions/Laravel/Passkeys/Http/Controllers/PasskeyRegistrationController`,
all of which [Wayfinder](../frontend/wayfinder.md) generates from your own
route table. `php artisan panel:install` checks for each and names the ones
that are missing.

## Where a guest is sent, and where a user lands

Two redirects, in opposite directions, both registered by the package.

`PandaPanel\Support\PanelLoginRedirect` answers the question "a guest opened a
panel URL — where do they go":

```php
public static function for(Request $request): ?string
```

It returns the panel's own login when the request resolves to a panel that has
one, `route('login')` when it does not, and `null` when the application has no
`login` route at all. The service provider wires it into
`Illuminate\Auth\Middleware\Authenticate::redirectUsing()` and
`Illuminate\Auth\AuthenticationException::redirectUsing()` inside an
`afterResolving(Kernel::class)` hook — later than the framework's own default,
which is why it is not written in `bootstrap/app.php` any more.

`PandaPanel\Http\Middleware\RedirectPanelHome` answers the other one: a
signed-in user who lands on the starter kit's `/dashboard` is sent to the first
panel they can enter.

```php
// config/panda-panel.php
'register_guest_redirect' => true,

'home_redirect' => [
    'enabled' => true,
    'paths' => ['dashboard'],
],
```

Both are documented in full under
[Guest Redirect](../configuration/guest-redirect.md) and
[Home Redirect](../configuration/home-redirect.md).

## Gotchas

- **`login()` without `auth()` is a login page nobody needs.** The pages are
  registered from `hasLogin()` alone, so a panel anyone can open still gets a
  `/login` URL. It is harmless and confusing; pair the two.
- **The panel's reset-password page is not what the reset email links to.**
  Laravel's `ResetPassword` notification builds its URL from the application's
  `password.reset` route. Pointing it at a panel is a call to
  `Illuminate\Auth\Notifications\ResetPassword::createUrlUsing()` in your own
  provider — see [Password Reset](password-reset.md).
- **`auth()` merges, it does not replace.** Calling it twice is idempotent
  because `mergePaths()` deduplicates, but `authMiddleware(['auth'])` after
  `auth()` *replaces* the stack and silently drops `verified`.
- **Fortify's `home` config still points wherever it pointed.** The package
  does not edit it. `RedirectPanelHome` intercepts the path instead, which is
  why turning the config key off gives the starter kit's screen back exactly
  as it was.
- **Route caching is safe.** Every panel route points at a controller method,
  never a closure.

## See also

- [Login](login.md), [Registration](registration.md),
  [Password Reset](password-reset.md),
  [Email Verification](email-verification.md)
- [Two-Factor Authentication](two-factor.md),
  [Email Code Challenge](email-code-challenge.md), [Passkeys](passkeys.md)
- [Profile Settings](profile.md), [Security Settings](security.md),
  [Appearance Settings](appearance.md)
- [The PanelUser contract](panel-user-contract.md),
  [User model requirements](user-model.md),
  [panel:user](panel-user-command.md)
- [Panels: access rules](../panels/access.md),
  [Panels: middleware](../panels/middleware.md)
- [Troubleshooting: login redirect loops](../troubleshooting/login-redirects.md)
