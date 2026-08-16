# Passkeys

Panda Panel implements no part of WebAuthn. Passkeys belong to Fortify and
`laravel/passkeys`; what the panel adds is three touch points — a passkey card on
its security page, a passkey button on its login page, and the rule that a
registered passkey counts as a second factor for `requireTwoFactor()`. You reach
for this page to know which half owns what, and what your user model and
frontend need for the panel's screens to work.

## A minimal working example

Turn Fortify's feature on, make the user model a passkey user, and the panel's
security page grows a passkey card:

```php
// config/fortify.php
use Laravel\Fortify\Features;

'features' => [
    Features::registration(),
    Features::resetPasswords(),
    Features::twoFactorAuthentication(['confirm' => true]),
    Features::passkeys(),
],
```

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;

final class User extends Authenticatable implements PasskeyUser
{
    use PasskeyAuthenticatable;
}
```

```bash
php artisan route:list --name=passkey
```

```text
GET     passkeys/login/options   passkey.login-options
POST    passkeys/login           passkey.login
GET     user/passkeys/options    passkey.registration-options
POST    user/passkeys            passkey.store
DELETE  user/passkeys/{passkey}  passkey.destroy
```

Every one of those is Fortify's. The panel registers none of them.

## Which half owns what

| Concern | Owner |
| --- | --- |
| WebAuthn ceremonies, challenge storage, credential verification | `laravel/passkeys` |
| The routes above, and their `password.confirm` guard | Fortify |
| Signing in with a passkey | Fortify's `passkey.login` |
| The `passkeys` table and the `Passkey` model | `laravel/passkeys` |
| Drawing the passkey card inside a panel | `PandaPanel\Pages\Settings\SecuritySettings` |
| Drawing a passkey button on a panel's own login | `resources/js/pages/panel/auth/Login.vue` |
| Counting a passkey as a second factor | `PandaPanel\Http\Middleware\RequireTwoFactor` |

## What the user model needs

```php
use Laravel\Fortify\Contracts\PasskeyUser;   // the interface
use Laravel\Fortify\PasskeyAuthenticatable;  // the trait that satisfies it
```

The trait wraps `Laravel\Passkeys\PasskeyAuthenticatable` and gives the model
five members:

| Member | Signature | Used by |
| --- | --- | --- |
| `passkeys()` | `passkeys(): HasMany` | `SecuritySettings::passkeys()`, `RequireTwoFactor` |
| `hasPasskeysEnabled()` | `hasPasskeysEnabled(): bool` | your own code; the panel does not call it |
| `getPasskeyUserHandle()` | `getPasskeyUserHandle(): string` | WebAuthn registration |
| `getPasskeyDisplayName()` | `getPasskeyDisplayName(): string` | falls back `name` → `email` → auth identifier |
| `getPasskeyUsername()` | `getPasskeyUsername(): string` | falls back `email` → auth identifier |

The example model in this repository implements both halves alongside
everything else a panel asks for:

```php
final class User extends Authenticatable implements MustVerifyEmail, PanelNotifiable, PanelUser, PasskeyUser
{
    use Notifiable;
    use PasskeyAuthenticatable;
    use TwoFactorAuthenticatable;
}
```

See [User Model Requirements](user-model.md).

## The security page card

`SecuritySettings::props()` sends two things the passkey card needs:

```php
'canManagePasskeys' => Features::canManagePasskeys(),
'passkeys' => Features::canManagePasskeys() ? $this->passkeys() : [],
```

`passkeys()` is private and its output shape is fixed:

```php
$user->passkeys()
    ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
    ->latest()
    ->get()
    ->map(static fn ($passkey): array => [
        'id' => $passkey->id,
        'name' => $passkey->name,
        'authenticator' => $passkey->authenticator,
        'created_at_diff' => $passkey->created_at->diffForHumans(),
        'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
    ])
    ->values()
    ->all();
```

| Key | Type | Notes |
| --- | --- | --- |
| `id` | `int` | The row key, which the delete request uses |
| `name` | `string` | Whatever the user called it at registration |
| `authenticator` | `string\|null` | Derived by the `Passkey` model from the credential's AAGUID; null for an unrecognised authenticator |
| `created_at_diff` | `string` | `diffForHumans()`, so no timezone question crosses the wire |
| `last_used_at_diff` | `string\|null` | Null for a passkey never used to sign in |

`credential` is selected because `authenticator` is computed from it, and is not
among the keys sent. The list is `[]` when the user model is not a
`Laravel\Fortify\Contracts\PasskeyUser` — the method guards with an `instanceof`
rather than assuming the relation exists.

The matching TypeScript type is in `resources/js/types/auth.ts`:

```ts
export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};
```

## The components

Two ship with the package; three more are the application's.

**`resources/js/components/ManagePasskeys.vue`** renders the card on the
security page:

```ts
export type Props = {
    canManagePasskeys?: boolean;   // default false
    passkeys?: Passkey[];          // default []
};
```

It renders nothing at all when `canManagePasskeys` is false. Deleting posts
straight at Fortify through Wayfinder, and registering reloads the page so the
new key appears:

```ts
import { destroy } from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyRegistrationController';

router.delete(destroy.url(id), { preserveScroll: true, onError });
```

It imports `@/components/PasskeyItem`, `@/components/PasskeyRegister` and
`@/components/Heading`, none of which the package ships — they are the
application's design, and a starter kit already has them.

**`resources/js/components/PasskeyVerify.vue`** is the button above the panel
login form:

```ts
type Props = {
    routes?: { options: UrlMethodPair; submit: UrlMethodPair };
    label?: string;          // 'Sign in with a passkey'
    loadingLabel?: string;   // 'Authenticating...'
    separator?: string;      // 'Or continue with email'
};
```

```vue
<PasskeyVerify
    label="Use your security key"
    separator="Or sign in with a password"
/>
```

It calls `usePasskeyVerify()` from `@laravel/passkeys/vue`, and hides itself
entirely — button and separator — when `isSupported` is false, so a browser
without WebAuthn sees the email and password form and nothing else. With no
`routes` prop it falls back to that library's own defaults,
`/passkeys/login/options` and `/passkeys/login`, which are the paths Fortify
registers.

On success it navigates:

```ts
onSuccess: (response) => {
    router.visit(response.redirect ?? '/dashboard');
},
```

## A passkey as a second factor

`RequireTwoFactor` accepts three things, and a passkey is the third:

```php
private function hasSecondFactor(object $user): bool
{
    if (method_exists($user, 'hasEnabledTwoFactorAuthentication')
        && $user->hasEnabledTwoFactorAuthentication()) {
        return true;
    }

    if (EmailCodeFactor::isEnabledFor($user)) {
        return true;
    }

    return method_exists($user, 'passkeys') && $user->passkeys()->exists();
}
```

```php
$panel->auth()->requireTwoFactor();
```

A user with one registered passkey and no authenticator app walks into that
panel. A panel that demanded an authenticator app from somebody already using a
hardware key would be demanding a downgrade. See
[Two-Factor Authentication](two-factor.md).

Note the check is `method_exists()` rather than `instanceof PasskeyUser`. It is
the same reason the TOTP check is: a user model without the trait answers no
rather than throwing.

## The host modules this needs

The published components import three things Wayfinder or a starter kit
provides:

| Specifier | Provided by |
| --- | --- |
| `@/actions/Laravel/Passkeys/Http/Controllers/PasskeyRegistrationController` | Wayfinder, from Fortify's routes |
| `@/components/PasskeyItem` | Your starter kit |
| `@/components/PasskeyRegister` | Your starter kit |

```bash
php artisan wayfinder:generate
php artisan panel:install     # names every host module the application is missing
```

`@laravel/passkeys` itself is an npm dependency; the installer reports it with
the rest of the package list. See [Host Modules](../frontend/host-modules.md).

## Testing

The panel side is what there is to test, and it is ordinary HTTP:

```php
use Inertia\Testing\AssertableInertia;

it('sends the passkey list to the security page', function (): void {
    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->get('/admin/settings/security')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('panel/settings/Security')
            ->has('canManagePasskeys')
            ->has('passkeys')
        );
});
```

For a panel with `requireTwoFactor()`, giving a user a passkey means creating a
row in the `passkeys` table — the middleware asks
`$user->passkeys()->exists()` and nothing else. The registration ceremony itself
is Fortify's to test, not yours.

## Gotchas

- **The panel registers no passkey routes.** They are absolute application
  paths (`/passkeys/login`, `/user/passkeys`), the same ones for every panel.
  Signing in with a passkey at `/admin/login` posts to `/passkeys/login`.
- **`PasskeyVerify` falls back to `/dashboard`.** When the server response
  carries no `redirect`, the component navigates there rather than into the
  panel. With `home_redirect` on, `RedirectPanelHome` then forwards a signed-in
  user to the first panel they can enter — which is why that config exists. See
  [Home Redirect](../configuration/home-redirect.md).
- **A panel on its own domain is a different WebAuthn origin.**
  `Panel::domain('admin.example.com')` puts the login on a host that must be
  covered by `fortify.passkeys.relying_party_id` and
  `fortify.passkeys.allowed_origins` — Fortify copies both onto the
  `passkeys.*` config it drives — or the browser refuses the ceremony. Passkeys
  are bound to the relying party id, which defaults to the host of
  `config('app.url')`.
- **Managing passkeys is behind `password.confirm`.** Fortify sets
  `passkeys.management_middleware` from
  `fortify-options.passkeys.confirmPassword`, which is on by default, and it is
  the same bar the panel's security page sets. An application that removes it
  loosens both.
- **`canManagePasskeys` false hides the card, silently.** The prop is always
  sent; the component renders nothing. If the card is missing, check
  `Features::passkeys()` is in `config/fortify.php`.
- **The list is not paginated and is rebuilt on every render** of the security
  page. Two or three rows in practice; it is a `select` on one user's own
  relation.
- **`RequireTwoFactor` costs an `exists()` query** for a user with neither TOTP
  nor emailed codes on a panel that demands a factor — once per request, on the
  panel's own middleware.

## See also

- [Security Settings](security.md) — the page the card renders on
- [Two-Factor Authentication](two-factor.md) — what counts, and how it is enforced
- [Login](login.md) — the page the verify button renders on
- [Fortify Integration](fortify.md)
- [User Model Requirements](user-model.md)
- [Host Modules](../frontend/host-modules.md), [Wayfinder](../frontend/wayfinder.md)
- [Panel IDs, Paths and Domains](../panels/ids-paths-domains.md)
