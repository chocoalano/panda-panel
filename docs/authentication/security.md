# Security Settings

The account page where a user changes their password, enrols in two-factor
authentication, manages passkeys, and turns the panel's emailed-code factor on.
Every panel gets one at its own path, in its own shell. You reach for this page
when you need to know what the screen renders, what it posts to, and what
happens when a Fortify feature behind it is switched off.

## A minimal working example

There is nothing to register. A panel that says nothing about settings has the
page already:

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
        return $panel->path('admin')->auth();
    }
}
```

```bash
php artisan route:list --name=panel.admin.pages.settings-security
```

```text
GET  admin/settings/security  panel.admin.pages.settings-security
```

Opening it as a signed-in user whose session has not confirmed a password
redirects to `route('password.confirm')` first. Confirm, and the page renders.

## The page class

`PandaPanel\Pages\Settings\SecuritySettings` is a `PandaPanel\Pages\Page` like
any other — it authorizes, appears in navigation, and carries breadcrumbs the
same way a page you wrote would.

| Member | Value |
| --- | --- |
| `$title` | `'Security'` |
| `$subheading` | `'Password, two-factor authentication, and passkeys.'` |
| `$slug` | `'settings-security'` |
| `$component` | `'panel/settings/Security'` |
| `$navigationIcon` | `'shield'` |
| `$navigationGroup` | `'Account'` |
| `$navigationSort` | `20` |
| `$middleware` | `[Illuminate\Auth\Middleware\RequirePassword::class]` |
| `routePath()` | `'settings/security'` |

The slug is one segment while the path is two. The slug is the route name and
the registry key; `routePath()` is what the address bar shows.

URLs are asked for rather than written:

```php
use PandaPanel\Pages\Settings\SecuritySettings;

SecuritySettings::routeName('admin');   // 'panel.admin.pages.settings-security'
SecuritySettings::url('admin');         // '/admin/settings/security'
SecuritySettings::url('app');           // '/app/settings/security'
```

```php
public static function routeName(PandaPanel\Core\Panel|string|null $panel = null): string
public static function url(PandaPanel\Core\Panel|string|null $panel = null): string
```

Both accept a `Panel`, a panel id, or `null` to resolve the panel of the
current request.

## Password confirmation

`RequirePassword` is on the **route**, not in `canAccess()`:

```php
/** @var list<string> */
protected static array $middleware = [RequirePassword::class];
```

`canAccess()` can only answer yes or no, and a stale session must be *sent to*
the confirmation screen rather than refused. That means the application needs a
`password.confirm` view, which is Fortify's route and your provider's view:

```php
use Inertia\Inertia;
use Laravel\Fortify\Fortify;

Fortify::confirmPasswordView(static fn () => Inertia::render('auth/ConfirmPassword'));
```

Without it, this page redirects into a route that renders nothing. It is the one
Fortify view a panel keeping its settings pages cannot skip.

## The props

`SecuritySettings::props()` builds the same shape a starter kit's own security
screen builds, so the two-factor and passkey components need no panel-specific
branch.

| Prop | Type | Source | Always present |
| --- | --- | --- | --- |
| `canManageTwoFactor` | `bool` | `Laravel\Fortify\Features::canManageTwoFactorAuthentication()` | yes |
| `canManagePasskeys` | `bool` | `Features::canManagePasskeys()` | yes |
| `passkeys` | `array<int, array<string, mixed>>` | the user's passkeys, `[]` when passkeys are off or the model is not a `PasskeyUser` | yes |
| `passwordRules` | `string` | `PandaPanel\Support\PasswordRules::attribute()` | yes |
| `emailCodeEnabled` | `bool` | `PandaPanel\Auth\EmailCodeFactor::isEnabledFor($user)` | yes |
| `emailCodeUrls` | `array{enable: string, disable: string}` | the panel's own two-factor routes | yes |
| `twoFactorEnabled` | `bool` | `$user->hasEnabledTwoFactorAuthentication()` | only when `canManageTwoFactor` |
| `requiresConfirmation` | `bool` | `Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm')` | only when `canManageTwoFactor` |

The two URLs are relative and named under the panel's own prefix:

```php
'emailCodeUrls' => [
    'enable' => route($this->panel()->routeName('auth.two-factor.enable'), absolute: false),
    'disable' => route($this->panel()->routeName('auth.two-factor.disable'), absolute: false),
],
```

`twoFactorEnabled` is asked of the object rather than of its type:

```php
$props['twoFactorEnabled'] = $user !== null
    && method_exists($user, 'hasEnabledTwoFactorAuthentication')
    && $user->hasEnabledTwoFactorAuthentication();
```

`Laravel\Fortify\TwoFactorAuthenticatable` is a trait, not an interface, so
there is no type to check. A user model without it has two-factor off rather
than throwing.

## The two accessors the page exposes

The page uses Fortify's `Laravel\Fortify\InteractsWithTwoFactorState` trait,
which is written against a `FormRequest` and needs exactly two methods. The page
supplies them rather than the framework reaching for an application FormRequest:

```php
public function user(): ?Illuminate\Contracts\Auth\Authenticatable   // Auth::user()
public function session(): Illuminate\Contracts\Session\Session      // request()->session()
```

```php
use PandaPanel\Pages\Settings\SecuritySettings;

$page = new SecuritySettings;

$page->user();      // the signed-in user, or null
$page->session();   // the current session
```

`ensureStateIsValid()` runs on every render, but only when
`canManageTwoFactor` is true:

```php
if (Features::canManageTwoFactorAuthentication()) {
    $this->ensureStateIsValid();
    // …
}
```

It is Fortify's own state repair: with `confirm` on, a two-factor setup the user
started and abandoned is disabled again rather than left half-applied. With
`confirm` off it returns immediately.

## The passkey list

Built by a private method, so what crosses the wire is fixed:

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

`credential` is selected because `authenticator` is derived from it, and it is
not among the keys that are sent. The dates cross humanized rather than raw:
the screen shows "3 days ago", and a timestamp the frontend has to format is a
timezone question nobody asked. The list is `[]` when the user model does not
implement `Laravel\Fortify\Contracts\PasskeyUser`. See [Passkeys](passkeys.md).

## What the screen renders

`resources/js/pages/panel/settings/Security.vue`, inside `PanelLayout`, is four
sections in this order.

**Password.** An Inertia `<Form>` bound to the application's own controller
through Wayfinder:

```vue
<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import PasswordInput from '@/components/PasswordInput.vue';
</script>

<template>
    <Form
        v-slot="{ errors, processing }"
        v-bind="SecurityController.update.form()"
        :options="{ preserveScroll: true }"
        reset-on-success
        :reset-on-error="['password', 'password_confirmation', 'current_password']"
    >
        <!-- current_password, password, password_confirmation -->
    </Form>
</template>
```

The three fields are `current_password`, `password` and
`password_confirmation`. The two new-password inputs carry
`:passwordrules="props.passwordRules"`, which is what makes Safari's suggested
password satisfy the policy the server will validate.

**Two-factor.** `ManageTwoFactor.vue`, which posts to Fortify's own
`@/routes/two-factor` (`enable` is `POST /user/two-factor-authentication`,
`disable` is `DELETE` on the same path) and draws the QR code through the host's
`TwoFactorSetupModal`. It renders nothing when `canManageTwoFactor` is false.

**Passkeys.** `ManagePasskeys.vue`, which lists `passkeys` and posts to
`@/actions/Laravel/Passkeys/Http/Controllers/PasskeyRegistrationController`. It
renders nothing when `canManagePasskeys` is false.

**Email codes.** The panel's own card. One button, whose action flips with the
current state:

```vue
<Form
    v-slot="{ processing }"
    :action="emailCodeEnabled ? emailCodeUrls.disable : emailCodeUrls.enable"
    method="post"
>
    <Button type="submit" :disabled="processing">
        {{ emailCodeEnabled ? 'Turn off' : 'Turn on' }}
    </Button>
</Form>
```

Both endpoints are the panel's, and both are behind `RequirePassword` on their
own routes as well as on this page — so turning the factor on is always somebody
who just proved they are the account holder. See
[Email Code Challenge](email-code-challenge.md).

## Panel routes this page links to

Registered for every panel by `PandaPanel\Routing\PanelRouteRegistrar`:

| Route name | Verb | Path | Extra middleware |
| --- | --- | --- | --- |
| `panel.{id}.auth.two-factor.enable` | POST | `{panel}/two-factor/enable` | `RequirePassword` |
| `panel.{id}.auth.two-factor.disable` | POST | `{panel}/two-factor/disable` | `RequirePassword` |
| `panel.{id}.auth.two-factor.challenge` | GET | `{panel}/two-factor/challenge` | — |
| `panel.{id}.auth.two-factor.send` | POST | `{panel}/two-factor/send` | — |
| `panel.{id}.auth.two-factor.verify` | POST | `{panel}/two-factor/verify` | — |

Everything else the screen posts to belongs to Fortify or to the application.

## Turning the page off

```php
public function settings(bool $settings = true): self
public function hasSettings(): bool
```

```php
$panel->settings(false);   // no profile, security, or appearance page
```

It is all-or-nothing: the three account pages are removed together. A panel that
wants its own security screen turns the built-ins off and registers its own
page, because two pages claiming the slug `settings-security` throws
`PandaPanel\Exceptions\PanelRegistrationException::duplicatePageSlug()` at boot.

## Testing it

```php
use Inertia\Testing\AssertableInertia;

it('guards the security page with password confirmation', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/app/settings/security')
        ->assertRedirect(route('password.confirm'));

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get('/app/settings/security')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('panel/settings/Security')
            ->where('panel.id', 'app')
            ->has('passwordRules')
            ->has('emailCodeUrls.enable')
        );
});
```

`withSession(['auth.password_confirmed_at' => time()])` is the shortcut for
everything behind `RequirePassword`, including the two email-code endpoints:

```php
$this->actingAs($user)
    ->withSession(['auth.password_confirmed_at' => now()->timestamp])
    ->post('/app/two-factor/enable')
    ->assertRedirect();
```

The suite is `tests/Feature/Panel/PanelSettingsTest.php`, with the email-code
half in `tests/Feature/Panel/EmailCodeTest.php`.

## Gotchas

- **The page renders; it does not write.** The route is GET only — a POST to
  `/admin/settings/security` answers 405. Password changes go to the
  application's `SecurityController`, two-factor and passkeys to Fortify, and
  only the emailed-code toggle is the panel's own endpoint.
- **`password.confirm` must exist and must render something.** Fortify
  registers the route; the view is `Fortify::confirmPasswordView()` in your
  provider. A missing view turns this page into a redirect to a blank screen.
- **Two props disappear rather than turning false.** `twoFactorEnabled` and
  `requiresConfirmation` are absent entirely when Fortify's two-factor feature
  is off. The Vue props default to `false`, so the card is not drawn at all —
  but a test asserting `->where('twoFactorEnabled', false)` fails.
- **`passwordRules` is a hint, not a rule.** It is the browser `passwordrules`
  attribute built from `Illuminate\Validation\Rules\Password::defaults()`. What
  is enforced is whatever your controller validates.
- **The passkey list is not paginated.** A user with fifty passkeys sends fifty
  rows on every render of this page. In practice a user has two or three.
- **`requireTwoFactor()` sends people here, and exempts every panel page.** The
  exemption in `PandaPanel\Http\Middleware\RequireTwoFactor` is
  `$request->routeIs($panel->routeName('pages.*'))`, not this page alone — a
  standalone page you registered stays reachable for a user without a second
  factor. See [Two-Factor Authentication](two-factor.md).
- **`settings(false)` does not satisfy `requireTwoFactor()`.** With no security
  page route to redirect to, the middleware now fails closed with 403 rather than
  letting an unenrolled user into a panel that demanded a second factor.

## See also

- [Two-Factor Authentication](two-factor.md)
- [Email Code Challenge](email-code-challenge.md)
- [Passkeys](passkeys.md)
- [Profile Settings](profile.md), [Appearance Settings](appearance.md)
- [Fortify Integration](fortify.md)
- [User Model Requirements](user-model.md)
- [Settings Pages](../panels/settings-pages.md)
- [Page URLs and Route Names](../pages-navigation/urls.md)
- [Host Modules](../frontend/host-modules.md)
