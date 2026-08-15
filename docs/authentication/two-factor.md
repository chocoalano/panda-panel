# Two-Factor Authentication

Fortify owns the second factor at the login POST — TOTP, recovery codes,
passkeys — and the panel does not duplicate any of it. What the panel adds is
two things Fortify has no opinion about: a way to *demand* that a user has a
second factor before they may use a panel at all, and a second factor of its
own for somebody who will not install an authenticator app. You reach for this
page to turn either on.

## A minimal working example

One call on the panel:

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
            ->requireTwoFactor();
    }
}
```

A signed-in user with no second factor now lands on the panel's security page
instead of wherever they were going, carrying a `warning` flash:

```bash
curl -I https://example.test/admin
# 302 → /admin/settings/security
```

Once they have set one up — an authenticator app, a passkey, or the panel's own
emailed code — the redirect stops and never comes back.

## What counts as a second factor

`RequireTwoFactor` asks three questions in this order and stops at the first
yes:

| Factor | The check | What the user model needs |
| --- | --- | --- |
| Authenticator app (TOTP) | `method_exists($user, 'hasEnabledTwoFactorAuthentication') && $user->hasEnabledTwoFactorAuthentication()` | `Laravel\Fortify\TwoFactorAuthenticatable` |
| Emailed code | `PandaPanel\Auth\EmailCodeFactor::isEnabledFor($user)` | the `two_factor_email_confirmed_at` column |
| Passkey | `method_exists($user, 'passkeys') && $user->passkeys()->exists()` | `Laravel\Fortify\PasskeyAuthenticatable` |

Every check is `method_exists()` rather than an `instanceof`. Fortify ships
`TwoFactorAuthenticatable` as a trait, not an interface, so the question can
only be asked of the object; a user model without the trait simply has
two-factor off rather than throwing.

A passkey counts deliberately. A panel that demanded an authenticator app from
somebody already using a hardware key would be demanding a downgrade.

## The panel API

```php
public function requireTwoFactor(bool $required = true): self
public function requiresTwoFactor(): bool
```

```php
use PandaPanel\Core\Panel;

Panel::make('admin')->requireTwoFactor()->requiresTwoFactor();        // true
Panel::make('kiosk')->requireTwoFactor(false)->requiresTwoFactor();   // false
Panel::make('app')->requiresTwoFactor();                              // false — the default
```

The boolean parameter exists so the decision can be an expression:

```php
$panel->requireTwoFactor(app()->isProduction());
```

## The middleware

`PandaPanel\Http\Middleware\RequireTwoFactor` is registered in every panel's
route group by `PanelRouteRegistrar`, with the panel id as a parameter. There is
nothing to add:

```php
'middleware' => [
    ...$panel->getMiddleware(),
    ResolvePanel::class.':'.$panel->getId(),
    RequireTwoFactor::class.':'.$panel->getId(),
    RequireEmailCode::class.':'.$panel->getId(),
],
```

```php
public function handle(Request $request, Closure $next, ?string $panelId = null): Response
```

The id is passed explicitly so the panel is never inferred from path matching.
Omitting it falls back to `PanelManager::currentPanel()`, which is what makes
the class usable on a route of your own:

```php
use PandaPanel\Http\Middleware\RequireTwoFactor;

Route::get('/reports/payroll', PayrollController::class)
    ->middleware(['auth', RequireTwoFactor::class.':admin']);
```

It runs after `ResolvePanel`, so the panel is resolved and the user is known by
the time it asks anything. It passes the request through untouched when:

- the panel is unknown, or `requiresTwoFactor()` is false;
- nobody is signed in — a guest is the guest redirect's problem, not this one's;
- the user already has one of the three factors;
- the panel has no security page route to send them to;
- the request is already for the security page, or for **any** of the panel's
  standalone pages (`panel.{id}.pages.*`).

Otherwise:

```php
return redirect()
    ->route($panel->routeName('pages.'.SecuritySettings::slug()))
    ->with('warning', 'Set up two-factor authentication to continue.');
```

## Where a user sets one up

`PandaPanel\Pages\Settings\SecuritySettings`, at `{panel}/settings/security`,
route name `panel.{id}.pages.settings-security`. It is behind
`Illuminate\Auth\Middleware\RequirePassword`, so a stale session is sent to the
password confirmation screen first — enabling a factor is always somebody who
just proved they are the account holder.

The props it renders with, all built in `props()`:

| Prop | Type | Source |
| --- | --- | --- |
| `canManageTwoFactor` | `bool` | `Laravel\Fortify\Features::canManageTwoFactorAuthentication()` |
| `canManagePasskeys` | `bool` | `Features::canManagePasskeys()` |
| `passkeys` | `array` | the user's passkeys, or `[]` when the model is not a `PasskeyUser` |
| `passwordRules` | `string` | `PandaPanel\Support\PasswordRules::attribute()` |
| `emailCodeEnabled` | `bool` | `EmailCodeFactor::isEnabledFor($user)` |
| `emailCodeUrls` | `array{enable: string, disable: string}` | the panel's own two-factor routes |
| `twoFactorEnabled` | `bool` | present only when Fortify's two-factor feature is on |
| `requiresConfirmation` | `bool` | `Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm')` |

The page uses Fortify's `InteractsWithTwoFactorState` trait and calls
`ensureStateIsValid()` on every render, which clears a setup the user abandoned
half-way. The trait needs `user()` and `session()`, which the page supplies:

```php
public function user(): ?Authenticatable      // Auth::user()
public function session(): Session            // request()->session()
```

TOTP enrolment itself posts to Fortify's own `two-factor` endpoints. The panel
renders the screen; it does not own the write. See
[Security Settings](security.md).

## The panel's own emailed code

A one-time code sent to the account's address. Weaker than TOTP and much
stronger than nothing, which is the choice it actually competes with.

It is **not** part of the login POST. Fortify owns signing in, and reaching into
that pipeline to add a channel would mean owning a fork of it. This works the
way password confirmation does: the session carries a mark once a code has been
answered, and middleware holds every page until it is there. A new session on a
new device is challenged even though the password was right, and the mark dies
with the session — a second factor that survived signing out would not be one.

### Turning it on

Two POSTs, both behind `RequirePassword`:

```php
public function enable(Request $request): RedirectResponse
public function disable(Request $request): RedirectResponse
```

```php
use PandaPanel\Core\PanelManager;

$panel = app(PanelManager::class)->get('admin');

route($panel->routeName('auth.two-factor.enable'));    // /admin/two-factor/enable
route($panel->routeName('auth.two-factor.disable'));   // /admin/two-factor/disable
```

`enable()` writes the timestamp and marks the current session as already having
passed the challenge — making somebody answer a code the moment they turned the
feature on would be asking them to prove something they just proved with their
password:

```php
$user->forceFill(['two_factor_email_confirmed_at' => now()])->save();
$request->session()->put(PanelTwoFactorController::SESSION_KEY, now()->timestamp);
```

`disable()` nulls the column, forgets any outstanding code, and drops the
session mark.

### Answering the challenge

```php
public function challenge(Request $request): Response          // GET  {panel}/two-factor/challenge
public function send(Request $request): RedirectResponse       // POST {panel}/two-factor/send
public function verify(Request $request): RedirectResponse     // POST {panel}/two-factor/verify
```

`challenge()` renders the Inertia component `panel/auth/EmailCode` and issues a
code first when none is outstanding, so opening the page is enough. Its props:

| Prop | Type | Meaning |
| --- | --- | --- |
| `panel` | `array` | `Panel::toSharedArray()` |
| `sentTo` | `string` | the address obscured — `gr***@example.test` |
| `retryAfter` | `int` | seconds until another code may be sent, `0` when one may be now |

`send()` issues another and throws a `ValidationException` on `code` when the
account has asked for too many. It says so plainly rather than pretending one
was sent — a user waiting for an email that is not coming will ask for five
more. It aborts 500 when the user model has no `notify()`.

`verify()` validates `['code' => ['required', 'string', 'digits:6']]`, then
regenerates the session before marking it (a fixed id is the one thing that
would let somebody else inherit the mark) and redirects to
`redirect()->intended(...)`, falling back to the panel dashboard.

```php
use PandaPanel\Http\Controllers\PanelTwoFactorController;

PanelTwoFactorController::SESSION_KEY;   // 'panel.mfa.email.confirmed_at'
```

### The gate

`PandaPanel\Http\Middleware\RequireEmailCode`, registered next to
`RequireTwoFactor` in the same group:

```php
public function handle(Request $request, Closure $next, ?string $panelId = null): Response
```

It passes the request through when there is no panel or no user, when the
account never turned the factor on, when the session already carries the mark,
when the panel has no challenge route, or when the request is already for one of
the panel's `auth.two-factor.*` routes. Otherwise it stores
`url.intended` and redirects to the challenge — so answering it returns the user
where they were going.

Note the difference between the two middlewares. `RequireTwoFactor` checks
*enrolment*, once, and is a panel-level policy. `RequireEmailCode` checks *this
session*, on every request, and applies to any account that opted in — even on a
panel that never called `requireTwoFactor()`.

### Reading the flag

```php
use PandaPanel\Auth\EmailCodeFactor;

public static function isEnabledFor(?object $user): bool
```

```php
EmailCodeFactor::isEnabledFor($user);        // true once the column is set
EmailCodeFactor::isEnabledFor(null);         // false
```

It reads `$user->getAttributes()['two_factor_email_confirmed_at']` directly
rather than through `getAttribute()`. An application with
`preventAccessingMissingAttributes()` on would otherwise throw for a user loaded
with a narrowed select — a table widget picking four columns. "Was not selected"
is not "is turned on", and the honest answer to a column nobody asked for is no.
A non-`Model` argument is also false.

### The challenge itself

`PandaPanel\Auth\EmailCodeChallenge` is resolvable from the container and is
where the code lives. Every method takes an `Illuminate\Contracts\Auth\Authenticatable`.

| Method | Signature | Returns |
| --- | --- | --- |
| `issue()` | `issue(Authenticatable $user): ?string` | the plain six-digit code, or `null` when the send limit is spent |
| `verify()` | `verify(Authenticatable $user, string $code): bool` | whether this is the code, spending it on success |
| `pending()` | `pending(Authenticatable $user): bool` | whether a code is currently outstanding |
| `secondsUntilNextSend()` | `secondsUntilNextSend(Authenticatable $user): int` | `0` when another may be sent now |
| `forget()` | `forget(Authenticatable $user): void` | throws away anything outstanding |

```php
use PandaPanel\Auth\EmailCodeChallenge;
use PandaPanel\Notifications\TwoFactorCode;

$challenge = app(EmailCodeChallenge::class);

$code = $challenge->issue($user);

if ($code === null) {
    // Five sends an hour is spent.
    return back()->withErrors(['code' => 'Try again in '.$challenge->secondsUntilNextSend($user).'s.']);
}

$user->notify(new TwoFactorCode($code));

$challenge->pending($user);                 // true
$challenge->verify($user, $code);           // true — and spends it
$challenge->verify($user, $code);           // false, the same code twice
```

The behaviour that is not configurable, because it is a credential rather than a
preference:

| Rule | Value |
| --- | --- |
| Code | six digits, zero-padded, from `random_int()` |
| Lifetime | 10 minutes |
| Storage | `Cache`, key `panel.mfa.email.code.{auth id}`, value `Hash::make($code)` |
| Sends | 5 per hour per user, key `panel.mfa.email.send.{auth id}` |
| Guesses | 5 per minute per user, key `panel.mfa.email.attempt.{auth id}` |
| Single use | a correct code is forgotten on acceptance; a wrong guess never spends it |

The code lives in the cache rather than a table: a row that outlives a cache
flush is a row somebody has to remember to prune, and an expiry the storage
enforces cannot be forgotten. It is hashed because a cache a support engineer
can read is a cache that can be read, and a code in it is a password for one
login.

### The email

`PandaPanel\Notifications\TwoFactorCode`:

```php
public function __construct(private readonly string $code)
public function via(object $notifiable): array      // ['mail']
public function toMail(object $notifiable): MailMessage
```

It implements `ShouldQueue`, because sending is the slowest part of signing in
and nobody should watch an SMTP handshake. The code is already in the cache by
the time it is dispatched, so a delayed mail delays the login rather than
breaking it. It is deliberately not a panel notification: this is a credential
going to one address, not something to persist in a bell somebody else might
open.

### The column

The package ships a migration adding `two_factor_email_confirmed_at` to `users`,
nullable, positioned after Fortify's `two_factor_confirmed_at` when that column
exists. It runs from the package unless `panda-panel.load_migrations` is false:

```bash
php artisan vendor:publish --tag=panda-panel-migrations
php artisan migrate
```

A timestamp rather than a boolean, for the same reason `email_verified_at` is
one: "when did they turn this on" is worth being able to answer, and "is it on"
is `!== null`.

## Route reference

Registered for every panel, inside the panel's own middleware — these are for
somebody already signed in.

| Route name | Verb | Path | Method | Extra middleware |
| --- | --- | --- | --- | --- |
| `panel.{id}.auth.two-factor.challenge` | GET | `{panel}/two-factor/challenge` | `challenge` | — |
| `panel.{id}.auth.two-factor.send` | POST | `{panel}/two-factor/send` | `send` | — |
| `panel.{id}.auth.two-factor.verify` | POST | `{panel}/two-factor/verify` | `verify` | — |
| `panel.{id}.auth.two-factor.enable` | POST | `{panel}/two-factor/enable` | `enable` | `RequirePassword` |
| `panel.{id}.auth.two-factor.disable` | POST | `{panel}/two-factor/disable` | `disable` | `RequirePassword` |

## Testing

The challenge is ordinary HTTP, so it tests as ordinary HTTP:

```php
use PandaPanel\Auth\EmailCodeChallenge;
use PandaPanel\Http\Controllers\PanelTwoFactorController;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

it('holds a session that has not answered a code', function (): void {
    Notification::fake();

    $user = User::factory()->create();
    $user->forceFill(['two_factor_email_confirmed_at' => now()])->save();

    $this->actingAs($user)
        ->get('/coded')
        ->assertRedirect(route('panel.coded.auth.two-factor.challenge', absolute: false));

    expect(session('url.intended'))->toContain('/coded');
});
```

Two shortcuts worth knowing. Mark a session as having passed the challenge:

```php
$this->actingAs($user)
    ->withSession([PanelTwoFactorController::SESSION_KEY => now()->timestamp])
    ->get('/admin')
    ->assertOk();
```

And satisfy `RequirePassword` for `enable` / `disable`:

```php
$this->actingAs($user)
    ->withSession(['auth.password_confirmed_at' => now()->timestamp])
    ->post('/admin/two-factor/disable')
    ->assertRedirect();
```

Rate limits are per user and persist between tests in the same process. Clear
them in an `afterEach` when a test issues codes:

```php
RateLimiter::clear('panel.mfa.email.send.'.$user->getKey());
RateLimiter::clear('panel.mfa.email.attempt.'.$user->getKey());
```

For a panel that demands a factor, Fortify's own flag is the quickest way to
give a user one:

```php
$user->forceFill([
    'two_factor_secret' => encrypt('secret'),
    'two_factor_confirmed_at' => now(),
])->save();
```

The full suites are `tests/Feature/Panel/EmailCodeTest.php` and
`tests/Feature/Panel/PanelAuthTest.php`.

## Gotchas

- **`requireTwoFactor()` checks enrolment, not this session.** Whether the
  factor was actually used to sign in is Fortify's business at the login POST.
  The panel-level check asks only whether the account has one. The emailed code
  is the exception: `RequireEmailCode` is a per-session check.
- **Every standalone page is exempt, not only the security page.** The
  exemption is `$request->routeIs($panel->routeName('pages.*'))`, so a custom
  page you registered on the panel stays reachable for a user without a second
  factor. Signing out is a legitimate answer to being asked for one, and the
  account pages sit beside the sign-out. Put anything sensitive in a resource,
  or add your own check to the page.
- **`settings(false)` disarms the demand.** Without a security page route there
  is nowhere to send anybody, so `RequireTwoFactor` lets the request through
  rather than locking every user out of a panel nobody can enter. A panel that
  demands a second factor needs somewhere to set one up.
- **Enabling emailed codes is not enrolment for the current session.**
  `enable()` marks the session immediately, so the person who just turned it on
  is not challenged. The next session is.
- **The challenge page sends a code as a side effect of a GET.** That is what
  makes "we emailed you" true when the page first opens. A refresh does not send
  a second one — `pending()` is checked first — but the send limit still applies
  once the outstanding code expires.
- **`send()` aborts 500 on a user model without `notify()`.** The message is
  "The user model is not notifiable." Add Laravel's own `Notifiable` trait; see
  [User Model Requirements](user-model.md).
- **The obscured address is derived, not stored.** `sentTo` shows the first two
  characters of the local part. A one-character local part still gets at least
  one asterisk.
- **No configuration knobs.** The ten-minute lifetime and the two rate limits
  are private constants in `EmailCodeChallenge`. Deliberate: they are properties
  of a credential, and an installation that could raise them is an installation
  that will.

## See also

- [Security Settings](security.md)
- [Email Code Challenge](email-code-challenge.md)
- [Passkeys](passkeys.md)
- [Fortify Integration](fortify.md)
- [Login](login.md)
- [User Model Requirements](user-model.md)
- [`PanelUser` Contract](panel-user-contract.md)
- [Settings Pages](../panels/settings-pages.md)
- [Panel Middleware](../panels/middleware.md)
- [Authorization](../concepts/authorization.md)
- [Configuration Reference](../configuration/panda-panel.md)
