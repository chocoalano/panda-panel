# Middleware and Guards

A panel is a Laravel route group, so its middleware stack is an ordinary one. The panel declares a base stack and an authentication stack; the route registrar appends the framework's own resolution middleware after both. This page covers what is applied, in what order, and where to put your own.

## Requiring a signed-in user

```php
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelProvider;

final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->path('admin')
            ->auth();          // 'auth' and 'verified', appended after 'web'
    }
}
```

```php
panel('admin')->getMiddleware();       // ['web', 'auth', 'verified']
panel('admin')->getBaseMiddleware();   // ['web']
panel('admin')->getAuthMiddleware();   // ['auth', 'verified']
```

## The two stacks

| Method | Signature | Default | Behaviour |
| --- | --- | --- | --- |
| `middleware` | `middleware(array $middleware): self` | `['web']` | **Replaces** the base stack. |
| `authMiddleware` | `authMiddleware(array $middleware): self` | `['auth']` | **Replaces** the auth stack. Pass `[]` only for a deliberately public panel. |
| `auth` | `auth(bool $verified = true): self` | — | Merges `auth` (and `verified`) into the auth stack. |

`middleware()` replaces rather than appends, so a panel that sets it must include `web` itself — without a session and CSRF token, no panel page can submit anything:

```php
$panel->middleware(['web', 'throttle:panel']);
```

`auth()` merges, so it can be called alongside an explicit auth stack without discarding it. The
default already contains `auth`; calling `auth()` mainly adds `verified`:

```php
$panel->authMiddleware(['auth:staff'])->auth(verified: false);
// getAuthMiddleware() === ['auth:staff', 'auth']
```

Skip the verified check for a panel that does not require a verified email:

```php
$panel->auth(verified: false);   // ['web', 'auth']
```

Make a panel public only by clearing the auth stack:

```php
$panel->authMiddleware([]);      // ['web']
```

`getMiddleware()` is base plus auth, deduplicated in that order, and is what the route group receives.

## What the registrar adds

After the panel's own stack, every panel route group gets, in order:

| Middleware | Parameter | Role |
| --- | --- | --- |
| `PandaPanel\Http\Middleware\ResolvePanel` | the panel id | Binds the current panel, enforces `isAccessibleTo()`, runs `boot()`. |
| `PandaPanel\Http\Middleware\RequireTwoFactor` | the panel id | Redirects to the security page when the panel demands a second factor. |
| `PandaPanel\Http\Middleware\RequireEmailCode` | the panel id | Holds pages until this session answered an emailed code, for accounts that enabled one. |
| `PandaPanel\Http\Middleware\ResolveTenant` | the panel id | Only for a panel that called `tenant()`. Identifies and binds the tenant. |

`ResolvePanel` runs *after* `auth`, which is what makes `$request->user()` populated when `canAccess()` is evaluated. It receives the panel id as a parameter rather than matching the URL, so two panels sharing a prefix are never ambiguous.

A signed-in user who fails the access check gets **403**. A guest never reaches `ResolvePanel` on an authenticated panel; the auth middleware redirects first.

The full stack on a typical route:

```php
Route::getRoutes()->getByName('panel.admin.dashboard')->gatherMiddleware();
// ['web', 'auth', 'verified', 'PandaPanel\Http\Middleware\ResolvePanel:admin', ...]
```

## Middleware on one page

A standalone page appends middleware to its own route:

```php
use Illuminate\Auth\Middleware\RequirePassword;
use PandaPanel\Pages\Page;

final class BillingSettings extends Page
{
    /** @var list<string> */
    protected static array $middleware = [RequirePassword::class];
}
```

`Page::middleware(): array` is the reader; the registrar calls it and attaches the result to that page's route only when it is not empty.

This is for concerns the route must enforce before the page is constructed — password confirmation, a signed URL — which `canAccess()` cannot express, because `canAccess()` answers yes or no and cannot redirect. The framework's own `SecuritySettings` page uses exactly this.

Resource pages have no equivalent: they are covered by the panel stack and by the resource's policy. Put a route-level concern for a resource on the panel, or express it in the policy.

## Middleware aliases

The service provider registers four aliases so an application can reference the same classes in its own route files:

| Alias | Class |
| --- | --- |
| `panel` | `ResolvePanel` |
| `panel.two-factor` | `RequireTwoFactor` |
| `panel.email-code` | `RequireEmailCode` |
| `panel.parent` | `ResolveParentRecord` |

```php
Route::get('/reports/export', ExportController::class)->middleware('panel:admin');
```

The registrar names the classes directly rather than going through the aliases, so removing an alias cannot break panel routing.

## The web group

Four pieces of middleware belong to the whole `web` group rather than to a panel, and the package appends them itself:

| Middleware | Why it is not on the panel group |
| --- | --- |
| `ResetPanelContext` | Must run for requests that never reach a panel, so nothing leaks between requests under Octane or between requests in one test. |
| `RedirectPanelHome` | Answers `/dashboard`, which is the application's route, not a panel's. |
| `ShareFlashToast` | Must run for redirects back out of a panel. |
| `SharePanelData` | Shares `panel`, `navigation`, `panels`, `broadcasting`, `search`, `notifications` and `tenancy`. Every value is a closure, so a non-panel request pays for none of them. |

They are appended through the HTTP kernel after it resolves, because `bootstrap/app.php` configures the group in an `afterResolving` hook that would otherwise overwrite anything pushed onto the router. Turn the automatic registration off to place them yourself:

```php
// config/panda-panel.php

'register_web_middleware' => false,
```

```php
// bootstrap/app.php

use PandaPanel\Http\Middleware\ResetPanelContext;
use PandaPanel\Http\Middleware\ShareFlashToast;
use PandaPanel\Http\Middleware\SharePanelData;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->web(append: [
        ResetPanelContext::class,
        ShareFlashToast::class,
        SharePanelData::class,
    ]);
})
```

## Where a guest is sent

With `register_guest_redirect` on — the default — the package points Laravel's guest redirect at `PandaPanel\Support\PanelLoginRedirect`. It is a superset of Laravel's default: a panel with its own login sends guests to that panel's login, everything else still goes to `route('login')`.

```php
// config/panda-panel.php

'register_guest_redirect' => true,
```

To keep your own rule and still support panel logins, turn the flag off and call into the helper:

```php
use PandaPanel\Support\PanelLoginRedirect;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->redirectGuestsTo(
        fn ($request) => PanelLoginRedirect::for($request) ?? route('welcome'),
    );
})
```

A panel's own auth pages are registered *outside* its auth stack — with the base middleware and `ResolvePanel` only — because putting the login page behind `auth` sends somebody who cannot sign in to the page that tells them to sign in.

## Requiring a second factor

```php
$panel->requireTwoFactor();          // requireTwoFactor(bool $required = true): self
panel('admin')->requiresTwoFactor(); // bool
```

`RequireTwoFactor` redirects any page in the panel to the security settings page until the account has a second factor. A confirmed authenticator app, an enabled emailed code, or a registered passkey all count — a panel that demanded TOTP from somebody already using a hardware key would be demanding a downgrade. The security page and the other account pages are exempt, and a panel with no security page (`settings(false)`) lets everything through rather than locking everybody out.

## Notes

- `middleware()` replaces. Calling it without `web` produces routes with no session, and the failure looks like every form being a 419.
- Middleware runs before `canAccess()`, so a rate limiter or an IP allowlist placed on the panel applies to guests too.
- Every panel route points at a controller, never a closure, so `php artisan route:cache` keeps working. Middleware parameters are strings for the same reason.
- Adding middleware to a panel after boot has no effect on already-registered routes.
- `ResolvePanel` with no id parameter falls back to `resolveFromRequest()`, which is what makes the `panel` alias usable outside a panel group.

## See also

- [Panel Access Rules](access.md)
- [Defining a Panel](defining-panels.md)
- [Panel IDs, Paths, and Domains](ids-paths-domains.md)
- [Middleware Configuration](../configuration/middleware.md)
- [Guest Redirect](../configuration/guest-redirect.md)
- [Home Redirect](../configuration/home-redirect.md)
- [Request Lifecycle](../concepts/request-lifecycle.md)
- [Fortify Integration](../authentication/fortify.md)
- [Two-Factor Authentication](../authentication/two-factor.md)
