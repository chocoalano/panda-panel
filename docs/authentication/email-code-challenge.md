# Email Code Challenge

The panel's own second factor: a six-digit code sent to the account's address,
answered once per session. It exists for somebody who will not install an
authenticator app — weaker than TOTP and much stronger than nothing, which is
the choice it actually competes with. You reach for this page to know how the
code is issued, stored and spent, and to drive any of it yourself.

## A minimal working example

Nothing to register. Every panel already has the routes; a user turns the factor
on from the security page, and the middleware does the rest:

```bash
php artisan route:list --name=panel.admin.auth.two-factor
```

```text
GET   admin/two-factor/challenge  panel.admin.auth.two-factor.challenge
POST  admin/two-factor/send       panel.admin.auth.two-factor.send
POST  admin/two-factor/verify     panel.admin.auth.two-factor.verify
POST  admin/two-factor/enable     panel.admin.auth.two-factor.enable
POST  admin/two-factor/disable    panel.admin.auth.two-factor.disable
```

Turning it on for an account in code is one column:

```php
use App\Models\User;

$user = User::query()->where('email', 'ada@example.com')->firstOrFail();

$user->forceFill(['two_factor_email_confirmed_at' => now()])->save();
```

From the next session on, every panel page redirects to
`/{panel}/two-factor/challenge` until a code has been answered.

## Why a session challenge rather than a login step

Fortify owns signing in — its rate limiting, its passkeys, its TOTP — and
reaching into that pipeline to add a channel would mean owning a fork of it.
This works the way password confirmation does instead: the session carries a
mark once a code is answered, and the panel's own middleware holds every page
until it is there.

The consequences are the useful part. A new session on a new device is
challenged even though the password was right. The mark is a timestamp in the
session, so it dies with the session — a second factor that survived signing out
would not be one. And nothing about Fortify's login POST changes, so both doors
into the application still behave identically.

## The lifecycle

```text
security page  ── POST enable ──▶  two_factor_email_confirmed_at = now()
                                   session marked (they just proved a password)

new session    ── any panel URL ─▶ RequireEmailCode: no mark
                                   url.intended stored, redirect to challenge

challenge page ── GET ───────────▶ issue() if nothing pending, mail the code
                                   render panel/auth/EmailCode

               ── POST verify ───▶ verify(): correct → session regenerated,
                                   marked, redirect()->intended()
```

## `EmailCodeChallenge`

`PandaPanel\Auth\EmailCodeChallenge` is where the code lives. It is resolvable
from the container and every method takes an
`Illuminate\Contracts\Auth\Authenticatable`.

| Method | Signature | Returns |
| --- | --- | --- |
| `issue()` | `issue(Authenticatable $user): ?string` | the plain six-digit code, or `null` when the send limit is spent |
| `verify()` | `verify(Authenticatable $user, string $code): bool` | whether this is the code, spending it on success |
| `pending()` | `pending(Authenticatable $user): bool` | whether a code is currently outstanding |
| `secondsUntilNextSend()` | `secondsUntilNextSend(Authenticatable $user): int` | `0` when another may be sent now |
| `forget()` | `forget(Authenticatable $user): void` | throws away anything outstanding |

A complete round trip, which is also what the controller does:

```php
use PandaPanel\Auth\EmailCodeChallenge;
use PandaPanel\Notifications\TwoFactorCode;

$challenge = app(EmailCodeChallenge::class);

$code = $challenge->issue($user);

if ($code === null) {
    // Five sends an hour is spent. Say so rather than pretending: a user
    // waiting for an email that is not coming will ask for five more.
    $wait = $challenge->secondsUntilNextSend($user);   // e.g. 2841

    return back()->withErrors(['code' => "Try again in {$wait}s."]);
}

$user->notify(new TwoFactorCode($code));

$challenge->pending($user);          // true
$challenge->verify($user, $code);    // true — and spends it
$challenge->verify($user, $code);    // false, the same code twice
$challenge->forget($user);           // nothing outstanding, guesses reset
```

`issue()` returns the plain code so the caller can send it. Nothing else ever
sees it again: what goes into the cache is `Hash::make($code)`.

The behaviour that is not configurable, because it is a credential rather than a
preference:

| Rule | Value | Where |
| --- | --- | --- |
| Code shape | six digits, zero-padded, from `random_int()` | `issue()` |
| Lifetime | 10 minutes | `TTL_MINUTES` |
| Storage | `Cache`, key `panel.mfa.email.code.{auth id}`, value hashed | `issue()` |
| Sends | 5 per hour per user, key `panel.mfa.email.send.{auth id}` | `SEND_LIMIT` |
| Guesses | 5 per minute per user, key `panel.mfa.email.attempt.{auth id}` | `ATTEMPT_LIMIT` |
| Single use | a correct code is forgotten on acceptance; a wrong guess never spends it | `verify()` |

Two limits because they are two different attacks: how often a code may be
*sent* (a mailbox is not a place to be flooded) and how often one may be
*guessed* (six digits is a million tries at machine speed). The code lives in the
cache rather than a table — a row that outlives a cache flush is a row somebody
has to remember to prune, and an expiry the storage enforces cannot be
forgotten. It is hashed because a cache a support engineer can read is a cache
that can be read, and a code in it is a password for one login.

The three constants are `private`. An installation that could raise the lifetime
is an installation that will.

## `EmailCodeFactor`

Whether an account has the factor on:

```php
use PandaPanel\Auth\EmailCodeFactor;

public static function isEnabledFor(?object $user): bool
```

```php
EmailCodeFactor::isEnabledFor($user);   // true once the column is set
EmailCodeFactor::isEnabledFor(null);    // false
EmailCodeFactor::isEnabledFor(new stdClass);   // false — not an Eloquent model
```

It reads the raw attribute rather than going through `getAttribute()`:

```php
return ($user->getAttributes()['two_factor_email_confirmed_at'] ?? null) !== null;
```

An application with `Model::preventAccessingMissingAttributes()` on would
otherwise throw for a user loaded with a narrowed select — a table widget
picking four columns. "Was not selected" is not "is turned on", and the honest
answer to a column nobody asked for is no.

## The controller

`PandaPanel\Http\Controllers\PanelTwoFactorController`, five methods and one
constant.

```php
public const SESSION_KEY = 'panel.mfa.email.confirmed_at';

public function challenge(Request $request): Inertia\Response
public function send(Request $request): RedirectResponse
public function verify(Request $request): RedirectResponse
public function enable(Request $request): RedirectResponse
public function disable(Request $request): RedirectResponse
```

**`challenge()`** renders `panel/auth/EmailCode`, issuing a code first when none
is outstanding — so opening the page is enough, and refreshing it does not send a
second one.

| Prop | Type | Meaning |
| --- | --- | --- |
| `panel` | `PanelDefinition` | `Panel::toSharedArray()` |
| `sentTo` | `string` | the address obscured — `al*******@example.test` |
| `retryAfter` | `int` | seconds until another code may be sent, `0` when one may be now |

The obscuring keeps the first two characters of the local part and replaces the
rest with one asterisk each — `alexandra@example.test` becomes
`al*******@example.test`, and a one-character local part still gets at least one
asterisk. Enough to recognise which inbox to open, not enough to learn one from
somebody else's screen. It is derived on each render, never stored.

**`send()`** issues another and throws a `ValidationException` on the `code` key
when the account has asked for too many:

```php
throw ValidationException::withMessages([
    'code' => 'Too many codes requested. Try again later.',
]);
```

It aborts **500** with "The user model is not notifiable." when the model has no
`notify()`. Emailing a code needs Laravel's own `Notifiable` trait, which a
starter kit ships; a model without it cannot receive one, and saying so is
better than a fatal.

**`verify()`** validates and marks the session:

```php
$request->validate(['code' => ['required', 'string', 'digits:6']]);

// Regenerated first: the session is about to be marked as having passed a
// second factor, and a fixed id is the one thing that would let somebody else
// inherit that.
$request->session()->regenerate();
$request->session()->put(self::SESSION_KEY, now()->timestamp);

return redirect()->intended(route($this->panel()->routeName('dashboard'), absolute: false));
```

A wrong or expired code throws a `ValidationException` on `code` with "That code
is wrong or has expired." — one message for both, because telling somebody which
of the two it was is telling them whether a code exists.

**`enable()`** writes the column and marks the current session:

```php
$user->forceFill(['two_factor_email_confirmed_at' => now()])->save();

// Making somebody answer a code the moment they turned the feature on would be
// asking them to prove something they just proved with their password.
$request->session()->put(self::SESSION_KEY, now()->timestamp);
```

**`disable()`** nulls the column, calls `forget()`, and drops the session mark.

Both are behind `Illuminate\Auth\Middleware\RequirePassword` on their own routes,
in addition to sitting on a page that is.

## `RequireEmailCode`

`PandaPanel\Http\Middleware\RequireEmailCode` is registered in every panel's
route group by `PanelRouteRegistrar`, with the panel id as a parameter:

```php
public function handle(Request $request, Closure $next, ?string $panelId = null): Response
```

```php
'middleware' => [
    ...$panel->getMiddleware(),
    ResolvePanel::class.':'.$panel->getId(),
    RequireTwoFactor::class.':'.$panel->getId(),
    RequireEmailCode::class.':'.$panel->getId(),
],
```

The id is passed explicitly so the panel is never inferred from path matching.
Omitting it falls back to `PanelManager::currentPanel()`, which is what makes the
class usable on a route of your own:

```php
use PandaPanel\Http\Middleware\RequireEmailCode;

Route::get('/exports/payroll', PayrollController::class)
    ->middleware(['auth', RequireEmailCode::class.':admin']);
```

It passes the request through untouched when:

- there is no panel, or nobody is signed in;
- the account never turned the factor on (`EmailCodeFactor::isEnabledFor()`);
- the session already carries `PanelTwoFactorController::SESSION_KEY`;
- the panel has no `auth.two-factor.challenge` route — refusing every page would
  lock an account out of a panel it is entitled to;
- the request is for any of the panel's `auth.two-factor.*` routes, or answering
  the challenge would be refused by the thing being answered.

Otherwise it stores the intended URL and redirects:

```php
$request->session()->put('url.intended', $request->fullUrl());

return redirect()->route($panel->routeName('auth.two-factor.challenge'));
```

So answering the challenge returns the user where they were going.

`RequireEmailCode` and `RequireTwoFactor` are different questions. The first is
about *this session* and applies to any account that opted in, on any panel. The
second is about *enrolment* and is a panel-level policy. See
[Two-Factor Authentication](two-factor.md).

## The notification

`PandaPanel\Notifications\TwoFactorCode`:

```php
public function __construct(private readonly string $code)
public function via(object $notifiable): array          // ['mail']
public function toMail(object $notifiable): MailMessage
```

```php
$user->notify(new PandaPanel\Notifications\TwoFactorCode('123456'));
```

It implements `Illuminate\Contracts\Queue\ShouldQueue`, because sending is the
slowest part of signing in and nobody should watch an SMTP handshake. The code
is already in the cache by the time it is dispatched, so a delayed mail delays
the login rather than breaking it — which does mean the queue has to be running.

It is deliberately not a panel notification: this is a credential going to one
address, not something to persist in a bell somebody else might open.

The mail body says the code expires in ten minutes, can be used once, and tells
the reader to change their password if they were not signing in.

## The column

The package ships a migration adding `two_factor_email_confirmed_at` to `users`:
nullable, positioned after Fortify's `two_factor_confirmed_at` when that column
exists and appended when it does not. It runs from the package unless
`panda-panel.load_migrations` is false:

```bash
php artisan vendor:publish --tag=panda-panel-migrations
php artisan migrate
```

A timestamp rather than a boolean, for the same reason `email_verified_at` is
one: "when did they turn this on" is worth being able to answer, and "is it on"
is `!== null`. The migration guards on both the table and the column, so an
application that already has either is untouched, and `down()` drops the column
and only the column.

Cast it on the model if you read it directly:

```php
protected function casts(): array
{
    return ['two_factor_email_confirmed_at' => 'datetime'];
}
```

## The challenge page

`resources/js/pages/panel/auth/EmailCode.vue`, inside `PanelAuthLayout`, is two
forms posting at the panel's own paths:

```vue
<Form :action="`/${panel.path}/two-factor/verify`" method="post">
    <Input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" />
</Form>

<Form :action="`/${panel.path}/two-factor/send`" method="post">
    <Button :disabled="processing || retryAfter > 0">
        {{ retryAfter > 0 ? `Wait ${retryAfter}s before asking again` : 'Send another code' }}
    </Button>
</Form>
```

The paths are built from `panel.path` rather than from a generated route module,
because these routes are the panel's and Wayfinder has no reason to know about
them. `autocomplete="one-time-code"` is what lets iOS offer the code from the
notification banner.

## Route reference

| Route name | Verb | Path | Method | Extra middleware |
| --- | --- | --- | --- | --- |
| `panel.{id}.auth.two-factor.challenge` | GET | `{panel}/two-factor/challenge` | `challenge` | — |
| `panel.{id}.auth.two-factor.send` | POST | `{panel}/two-factor/send` | `send` | — |
| `panel.{id}.auth.two-factor.verify` | POST | `{panel}/two-factor/verify` | `verify` | — |
| `panel.{id}.auth.two-factor.enable` | POST | `{panel}/two-factor/enable` | `enable` | `RequirePassword` |
| `panel.{id}.auth.two-factor.disable` | POST | `{panel}/two-factor/disable` | `disable` | `RequirePassword` |

All five sit **inside** the panel's own middleware group: they are for somebody
already signed in. All five are also exempt from `RequireEmailCode`, which
matches on `auth.two-factor.*` — answering the challenge must not be refused by
the thing being answered, and neither must turning the factor off.

## Testing

```php
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use PandaPanel\Auth\EmailCodeChallenge;
use PandaPanel\Http\Controllers\PanelTwoFactorController;
use PandaPanel\Notifications\TwoFactorCode;

it('holds a session that has not answered a code', function (): void {
    Notification::fake();

    $this->user->forceFill(['two_factor_email_confirmed_at' => now()])->save();

    $this->actingAs($this->user)
        ->get('/coded')
        ->assertRedirect(route('panel.coded.auth.two-factor.challenge', absolute: false));

    expect(session('url.intended'))->toContain('/coded');
});

it('lets the session through once the code is answered', function (): void {
    $this->user->forceFill(['two_factor_email_confirmed_at' => now()])->save();

    $code = (string) app(EmailCodeChallenge::class)->issue($this->user);

    $this->actingAs($this->user)
        ->post('/coded/two-factor/verify', ['code' => $code])
        ->assertRedirect();

    $this->actingAs($this->user)->get('/coded')->assertOk();
});
```

Two shortcuts. Mark a session as having answered:

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

Rate limits are per user and survive between tests in the same process. Clear
them when a test issues codes:

```php
afterEach(function (): void {
    RateLimiter::clear('panel.mfa.email.send.'.$this->user->getKey());
    RateLimiter::clear('panel.mfa.email.attempt.'.$this->user->getKey());
});
```

The suite is `tests/Feature/Panel/EmailCodeTest.php`.

## Gotchas

- **Opening the challenge page sends an email.** That is what makes "we emailed
  you" true when the page first opens. A refresh does not send a second one —
  `pending()` is checked — but once the outstanding code expires, the next GET
  spends a send.
- **Enabling is not a challenge for the current session.** `enable()` marks the
  session immediately. The next session is the first one asked.
- **The mark is per session, not per panel.** One session key covers every
  panel, so answering the challenge in `/admin` also satisfies `/app`. The user
  is one person and the factor is about the session.
- **A queue that is not running holds up every sign-in.** `TwoFactorCode` is
  `ShouldQueue`. On `QUEUE_CONNECTION=sync` it sends inline, which is slow but
  works; on a real connection with no worker, the code is issued and never
  arrives.
- **The cache is the storage.** `php artisan cache:clear` invalidates every
  outstanding code, and a cache driver shared between deploys is what keeps the
  challenge working across them. The `array` store the suite runs on lives in
  the container, so a code issued in a test survives the requests that test
  makes and is gone by the next one.
- **The rate limiter keys are the auth identifier**, so they are stable across
  sessions and devices for one account. Five sends an hour is per account, not
  per browser.
- **`send()` 500s on a model without `Notifiable`.** Deliberate: the alternative
  is a fatal error deeper in the stack.
- **Turning the factor off does not sign anybody out.** `disable()` drops the
  mark and forgets the code; existing sessions continue.

## See also

- [Two-Factor Authentication](two-factor.md) — `requireTwoFactor()` and what counts
- [Security Settings](security.md) — the page it is turned on from
- [Passkeys](passkeys.md), [Fortify Integration](fortify.md)
- [User Model Requirements](user-model.md)
- [Migrations](../configuration/migrations.md)
- [Queues](../deployment/queues.md)
- [Panel Middleware](../panels/middleware.md)
