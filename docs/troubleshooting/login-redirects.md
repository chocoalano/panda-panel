# Login redirects

A guest opening a panel URL is sent somewhere, and a signed-in user landing on the application's
dashboard is sent somewhere else. Two redirects, in opposite directions, both registered by this
package and both overridable. Reach for this page when a guest lands on the wrong login, when
signing in does not lead into the panel, or when a `redirectGuestsTo()` you wrote stopped taking
effect.

## Ask the two functions directly

Neither redirect needs a browser to reproduce. Both are static methods taking a request and
answering a URL or `null`, so the answer can be had from tinker before any theory about middleware:

```php
use Illuminate\Http\Request;
use PandaPanel\Support\PanelHomeRedirect;
use PandaPanel\Support\PanelLoginRedirect;

// Where a guest opening /admin/users is sent.
PanelLoginRedirect::for(Request::create('/admin/users'));
// 'https://example.test/admin/login', or 'https://example.test/login', or null

// Where a signed-in user landing on /dashboard is sent.
$request = Request::create('/dashboard');
$request->setUserResolver(static fn () => $user);

PanelHomeRedirect::for($request);
// '/admin', or null to change nothing
```

`null` from either one means *this redirect is not what moved the browser*, which narrows the
search to the application's own routes, Fortify, or the auth middleware.

| Answer | Read it as |
| --- | --- |
| `PanelLoginRedirect::for()` returns the panel's login | The panel has its own front door and it is registered |
| `PanelLoginRedirect::for()` returns `route('login')` | The request is not a panel's, or the panel never called `login()` |
| `PanelLoginRedirect::for()` returns `null` | The application has no `login` route at all — the response will be a 401 |
| `PanelHomeRedirect::for()` returns `null` | One of five conditions below did not hold |

## 1. A guest lands on the application's login, not the panel's

**Cause.** The panel never called `login()`. `PanelLoginRedirect` asks `Panel::hasLogin()`, and a
panel that has no login of its own has no login route to send anybody to — so the answer falls back
to `route('login')`, which is exactly what Laravel does without this package.

```php
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelProvider;

final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->path('admin')
            ->auth()
            ->login();
    }
}
```

```bash
php artisan route:list --name=panel.admin.auth
```

```text
GET  admin/login  panel.admin.auth.login
```

```php
panel('admin')->hasLogin();                  // true — what PanelLoginRedirect asks
panel('admin')->routeName('auth.login');     // 'panel.admin.auth.login'
```

The four front-door toggles, all off by default, each with a reader:

| Method | Signature | Reader | Route registered |
| --- | --- | --- | --- |
| `login` | `login(bool $login = true): self` | `hasLogin(): bool` | `panel.{id}.auth.login` |
| `registration` | `registration(bool $registration = true): self` | `hasRegistration(): bool` | `panel.{id}.auth.register` |
| `passwordReset` | `passwordReset(bool $passwordReset = true): self` | `hasPasswordReset(): bool` | `panel.{id}.auth.password.request`, `.password.reset` |
| `emailVerification` | `emailVerification(bool $emailVerification = true): self` | `hasEmailVerification(): bool` | `panel.{id}.auth.verification.notice` |

`login()` is the gate for the other three: `PanelRouteRegistrar::registerAuth()` returns
immediately when `hasLogin()` is false, so a panel asking for registration without a login gets
neither route.

## 2. The guest went to *a* panel's login, but the wrong panel's

**Cause.** `PanelManager::resolveFromRequest()` decides which panel a URL belongs to, and it sorts
candidates by **longest path first**, then honours `domain()`.

```php
use PandaPanel\Core\PanelManager;

app(PanelManager::class)->resolveFromRequest(Request::create('/admin/reports/weekly'))?->getId();
// 'reports' — the panel at /admin/reports wins over the one at /admin
```

```php
public function resolveFromRequest(Request $request): ?Panel
```

A panel with a `domain()` is skipped for any request on a different host, so two panels sharing a
path on two domains stay separate. Nothing here reads the route — only the host and the path — so
this answers the same way for a URL that has no route at all.

## 3. The guest got a 401 with no redirect anywhere

**Cause.** `PanelLoginRedirect::for()` answered `null`, and a null redirect is not something this
package invents a behaviour for. `Illuminate\Auth\Middleware\Authenticate` throws
`AuthenticationException`, and Laravel's handler answers `response()->noContent(401)`.

Two ways to reach `null`:

```php
$panel = app(PanelManager::class)->resolveFromRequest($request);

if ($panel === null || ! $panel->hasLogin()) {
    return Route::has('login') ? route('login') : null;   // ← the application has no login route
}

$login = $panel->routeName('auth.login');

return Route::has($login) ? route($login) : null;         // ← the panel's login route is not registered
```

Both `Route::has()` checks matter. The second covers a panel whose `login()` was turned off after
boot, and an application running with `register_routes => false`, where no panel route exists to
redirect to.

A request that expects JSON never consults the callback at all: `Authenticate` passes `null` for
those, and the handler returns a 401 with the message.

## 4. Your own `redirectGuestsTo()` stopped taking effect

**Cause.** The service provider registers `PanelLoginRedirect` in an `afterResolving(Kernel::class)`
hook, which runs *after* the framework's own default and after anything a provider set earlier.
That ordering is deliberate — it is the only one that survives — and it also overrides an
application that had written its own rule.

```php
// PandaPanelServiceProvider::registerGuestRedirect()
$redirect = static function (): void {
    Authenticate::redirectUsing(PanelLoginRedirect::for(...));
    AuthenticationException::redirectUsing(PanelLoginRedirect::for(...));
};

if ($this->app->resolved(Kernel::class)) {
    $redirect();
}

$this->app->afterResolving(Kernel::class, $redirect);
```

**Fix.** Turn the registration off and call into it from your own rule, which keeps panel logins
working alongside whatever else you need:

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
        static fn ($request) => PanelLoginRedirect::for($request) ?? route('welcome'),
    );
})
```

`redirectGuestsTo()` is the supported place to say this and it is the last word. Turning the key
off *without* writing a replacement leaves Laravel's default in place: every guest, including one
who opened a panel with its own front door, goes to `route('login')`.

`redirectGuestsTo()` also points `Illuminate\Session\Middleware\AuthenticateSession::redirectUsing()`
at the same callback. The package sets only the two above; an application using
`AuthenticateSession` and wanting panel-aware redirects from it too registers that one itself.

## 5. Signing in still lands on the starter kit's `/dashboard`

**Cause.** `PandaPanel\Http\Middleware\RedirectPanelHome` answers that request, and
`PanelHomeRedirect::for()` returned `null`. Five conditions produce `null`, and each is worth
checking in this order:

| Condition | Check |
| --- | --- |
| The feature is off, or `paths` is empty | `config('panda-panel.home_redirect')` |
| The path matches none of the patterns | `Request::is()` patterns, no leading slash |
| The request is already inside a panel | a panel answers for its own URLs, so this would loop |
| No panel admits this user | `PandaPanel\Facades\PandaPanel::firstAccessibleTo($user)` is `null` |
| The panel's `dashboard` route does not exist | `register_routes` is off |

```php
// config/panda-panel.php
'home_redirect' => [
    'enabled' => true,

    'paths' => ['dashboard'],
],
```

| Key | Type | Default | Behaviour |
| --- | --- | --- | --- |
| `home_redirect.enabled` | `bool` | `true` | Compared with `!== true`, so anything but boolean `true` turns it off |
| `home_redirect.paths` | `list<string>` | `['dashboard']` | `Request::is()` patterns; non-string and empty entries are dropped |

The middleware itself is narrow on purpose:

```php
public function handle(Request $request, Closure $next): Response
{
    if (! $request->isMethod('GET') || $request->expectsJson()) {
        return $next($request);
    }

    $target = PanelHomeRedirect::for($request);

    return $target === null
        ? $next($request)
        : redirect()->to($target);
}
```

GET only, and never for a request that wants JSON — an application that has hung an API or a form
post off the same path keeps it.

**A signed-in user no panel admits sees the application's own page, unchanged.** There is no error
and no 403; the redirect simply does not happen. Which panel is "first" is
`PanelManager::firstAccessibleTo()`, which walks `PanelRegistry::all()` — **sorted by panel id** —
and returns the first whose `isAccessibleTo()` answers true.

```php
use PandaPanel\Facades\PandaPanel;

PandaPanel::firstAccessibleTo($user)?->getId();   // 'admin'
```

## 6. `/dashboard` is a real screen and it is now unreachable

**Fix.** Turn it off. Your route, its name and its page component were never touched — the request
was answered earlier by a `web` middleware, and that is the whole of it.

```php
'home_redirect' => ['enabled' => false],
```

Or hand over a different section instead, since the values are patterns:

```php
'home_redirect' => ['enabled' => true, 'paths' => ['reports/*']],
```

Or drop the middleware entirely, which also drops `ResetPanelContext`, `ShareFlashToast` and
`SharePanelData` and makes registering all four your job:

```php
'register_web_middleware' => false,
```

`php artisan panel:install` prints a line saying this redirect is now in effect, because a redirect
nobody was told about is a bug report:

```text
  INFO  Signed-in visitors to /dashboard now land in the panel. Set home_redirect.enabled to
  false in config/panda-panel.php to keep your own.
```

## 7. A signed-in user is bounced straight back out of the panel

Three pieces of middleware can move a signed-in user off a panel page, and each one has a
different destination. Read the `Location` header rather than guessing:

| Sent to | Middleware | Why |
| --- | --- | --- |
| the application's `verification.notice` | Laravel's `verified` | `->auth()` adds it by default and the account is unverified |
| `panel.{id}.pages.settings-security` | `PandaPanel\Http\Middleware\RequireTwoFactor` | the panel called `requireTwoFactor()` and the account has no second factor |
| `panel.{id}.auth.two-factor.challenge` | `PandaPanel\Http\Middleware\RequireEmailCode` | the account turned emailed codes on and this session has not answered one |

```php
public function auth(bool $verified = true): self
```

```php
$panel->auth();                   // appends ['auth', 'verified']
$panel->auth(verified: false);    // appends ['auth'] only
```

`verified` is Laravel's own middleware and it redirects to the application's `verification.notice`
route — **not** to the panel's `panel.{id}.auth.verification.notice`, which is a different name.
An application with no `verification.notice` route at all gets a `RouteNotFoundException` rather
than a redirect. `auth(verified: false)` is the fix when a panel does not want that gate.

Neither of the panel's own two can loop, by construction:

- `RequireTwoFactor` lets the request through when the panel's security page route does not exist
  (`Route::has()` is false), and exempts the security page and every other `pages.*` route — so
  there is always somewhere to go and do the thing being demanded.
- `RequireEmailCode` lets the request through when the challenge route does not exist, and exempts
  every `auth.two-factor.*` route, or answering the challenge would be refused by the thing being
  answered. It stores `url.intended` first, so answering returns the user where they were going.

## 8. The login POST succeeds and the next page is the login again

Nothing in this package is involved. The panel's login page posts to **Fortify's** endpoint — the
panel owns the screen and nothing else — so a successful POST followed by a request that is a guest
again is a session that did not survive the redirect. Check `SESSION_DRIVER`, `SESSION_DOMAIN`,
`SESSION_SECURE_COOKIE` against the scheme and host actually being browsed, and the storage the
session driver writes to.

A panel's own auth pages are registered **outside** its auth middleware, with
`Panel::getBaseMiddleware()` plus `ResolvePanel` only, so the login page itself is never behind
`auth` and cannot be the loop.

```php
$panel->getBaseMiddleware();   // ['web'] by default — session, CSRF, Inertia
$panel->getAuthMiddleware();   // ['auth', 'verified'] after auth()
$panel->getMiddleware();       // both, deduplicated, in that order
```

## 9. The panel's `/login`, `/register` or `/forgot-password` 404s

| URL | 404s when |
| --- | --- |
| `{path}/login` | `login()` was never called, or `register_routes` is `false` |
| `{path}/register` | `registration()` is off — `PanelAuthController::register()` calls `abort_unless($this->panel()->hasRegistration(), 404)` |
| `{path}/forgot-password`, `{path}/reset-password/{token}` | `passwordReset()` is off |
| `{path}/verify-email` | `emailVerification()` is off |

The route-level and controller-level checks agree, so turning a flag off after `route:cache` still
404s rather than rendering a page whose form posts nowhere.

```bash
php artisan route:list --name=panel.
php artisan route:clear && php artisan route:cache
```

## Everything this page covers

### `PandaPanel\Support\PanelLoginRedirect`

```php
public static function for(Request $request): ?string
```

```php
use PandaPanel\Support\PanelLoginRedirect;

PanelLoginRedirect::for($request);   // string|null
PanelLoginRedirect::for(...);        // first-class callable, which is how it is registered
```

| Request | Answer |
| --- | --- |
| Inside a panel that called `login()` | `route('panel.{id}.auth.login')`, **absolute** |
| Inside a panel that did not | `route('login')` when the application has one |
| Outside every panel | `route('login')` when the application has one |
| Neither route exists | `null` |

### `PandaPanel\Support\PanelHomeRedirect`

```php
public static function for(Request $request): ?string
```

The URL is **relative** — `route($route, absolute: false)` — because the destination is a page in
this application, and a relative target is what lets an Inertia visit follow it without a full
reload. The guest redirect returns an absolute URL for the opposite reason: it can legitimately
cross to a panel on another domain.

### `PandaPanel\Http\Middleware\RedirectPanelHome`

```php
public function handle(Request $request, Closure $next): Response
```

Appended to the `web` group by the service provider, ahead of `ResetPanelContext`,
`ShareFlashToast` and `SharePanelData`, because a request it answers never reaches a panel screen
and has nothing to share props for.

### Config keys

| Key | Default | Effect |
| --- | --- | --- |
| `register_guest_redirect` | `true` | Registers `PanelLoginRedirect` on `Authenticate` and `AuthenticationException` |
| `home_redirect.enabled` | `true` | Whether `RedirectPanelHome` does anything |
| `home_redirect.paths` | `['dashboard']` | The `Request::is()` patterns it takes over |
| `register_web_middleware` | `true` | Whether the four `web` middleware are appended at all |
| `register_routes` | `true` | Whether panel route groups exist to redirect to |

### Panel members

| Member | Signature |
| --- | --- |
| `auth` | `auth(bool $verified = true): self` |
| `login` | `login(bool $login = true): self` |
| `hasLogin` | `hasLogin(): bool` |
| `registration` / `hasRegistration` | `registration(bool $registration = true): self` / `hasRegistration(): bool` |
| `passwordReset` / `hasPasswordReset` | `passwordReset(bool $passwordReset = true): self` / `hasPasswordReset(): bool` |
| `emailVerification` / `hasEmailVerification` | `emailVerification(bool $emailVerification = true): self` / `hasEmailVerification(): bool` |
| `requireTwoFactor` / `requiresTwoFactor` | `requireTwoFactor(bool $required = true): self` / `requiresTwoFactor(): bool` |
| `routeName` | `routeName(string $name): string` |
| `getRouteNamePrefix` | `getRouteNamePrefix(): string` — `panel.{id}.` |
| `isAccessibleTo` | `isAccessibleTo(?Authenticatable $user): bool` |
| `getBaseMiddleware` / `getAuthMiddleware` / `getMiddleware` | each `(): list<string>` |

### Manager members

| Member | Signature |
| --- | --- |
| `resolveFromRequest` | `resolveFromRequest(Request $request): ?Panel` |
| `firstAccessibleTo` | `firstAccessibleTo(?Authenticatable $user): ?Panel` |

## Asserting it in a test

```php
it('sends a guest to this panel\'s door, keeping where they were going', function (): void {
    $this->get('/door')->assertRedirect('/door/login');

    expect(session('url.intended'))->toContain('/door');
});

it('sends a signed-in user from the starter kit dashboard into their panel', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/dashboard')
        ->assertRedirect('/admin');
});

it('leaves a guest to the application own auth', function (): void {
    // Not this package's redirect: /dashboard is behind `auth`.
    $this->get('/dashboard')->assertRedirect(route('login'));
});
```

```php
use Illuminate\Support\Facades\Config;

Config::set('panda-panel.home_redirect.enabled', false);

$this->actingAs($admin)->get('/dashboard')->assertOk();
```

## Gotchas

- **Both config flags are compared with `!== true`.** A string `'true'` from an environment
  variable turns the feature off, not on.
- **`home_redirect.paths` patterns have no leading slash.** `'/dashboard'` matches nothing;
  `$request->is()` compares against a trimmed path.
- **The home redirect fires on every matching GET, not only after signing in.** A bookmarked
  `/dashboard` goes to the panel too, which is usually the point and occasionally a surprise.
- **This is not Fortify's post-login redirect.** Fortify still sends a user wherever its own
  configuration says; `RedirectPanelHome` catches them when they arrive. Nothing in the package
  edits Fortify's `home`, which is why turning the key off gives the starter kit's screen back
  exactly as it was.
- **A signed-in user who fails a panel's access check gets a 403, never a redirect.** Hiding a
  panel behind a login is not an access control — see [403 responses](authorization-403.md).
- **`login()` on a public panel is a login page nobody needs.** The pages are registered from
  `hasLogin()` alone, so a panel that cleared its auth stack with `authMiddleware([])` can still
  get a `/login` URL.
- **The panel's reset-password page is not what a reset email links to.** Laravel's
  `ResetPassword` notification builds its URL from the application's `password.reset` route;
  pointing it at a panel is a call to `ResetPassword::createUrlUsing()` in your own provider.
- **`firstAccessibleTo()` walks panels in id order, not config order.** Renaming a panel can
  therefore change where users land after signing in.
- **`isAccessibleTo()` runs on every request into a panel and again for the home redirect.** Keep
  `canAccess()` cheap; a query per request per user is a query per page view.

## See also

- [Guest redirect](../configuration/guest-redirect.md), [home redirect](../configuration/home-redirect.md)
- [Middleware registration](../configuration/middleware.md),
  [service provider behaviour](../configuration/service-provider.md),
  [route registration](../configuration/routes.md)
- [Fortify integration](../authentication/fortify.md), [panel login](../authentication/login.md),
  [registration](../authentication/registration.md),
  [password reset](../authentication/password-reset.md),
  [email verification](../authentication/email-verification.md)
- [Two-factor](../authentication/two-factor.md),
  [email code challenge](../authentication/email-code-challenge.md)
- [Panel access rules](../panels/access.md), [middleware and guards](../panels/middleware.md),
  [ids, paths and domains](../panels/ids-paths-domains.md)
- [Request lifecycle](../concepts/request-lifecycle.md), [routing](../concepts/routing.md)
- [403 responses](authorization-403.md), [panel routes that 404](panel-routes-404.md)
- [Common install problems](../getting-started/common-install-problems.md),
  [Laravel Vue starter kit setup](../getting-started/vue-starter-kit.md)
