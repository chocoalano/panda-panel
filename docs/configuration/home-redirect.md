# Home Redirect

A signed-in user who lands on the starter kit's `/dashboard` is sent to the first panel they can
enter. It is the one thing installing this package changes about a screen the application already
had, and it is two lines of config to reverse. Reach for this page when `/dashboard` is a real
screen you mean to keep, or when you want a different set of paths handed over.

## A minimal working example

```php
// config/panda-panel.php

'home_redirect' => [
    'enabled' => true,

    'paths' => ['dashboard'],
],
```

```php
$this->actingAs($admin)->get('/dashboard')->assertRedirect('/admin');
```

Keep your own dashboard instead:

```php
'home_redirect' => [
    'enabled' => false,

    'paths' => ['dashboard'],
],
```

```php
$this->actingAs($admin)->get('/dashboard')->assertOk();
```

## Why it exists

A Laravel Vue starter kit ships a `/dashboard` route with an empty placeholder page, and points
Fortify's post-login redirect at it. Installing a panel changes neither, so the first screen after
signing in is that placeholder and the panel is somewhere you have to know the URL of. That is the
worst moment in the install, and it is not something the application did wrong.

Nothing is rewritten to fix it. The application keeps its route, its route name and its page
component; a request that reaches it is answered earlier, and that is the whole of it. Turning the
key off gives all three back.

`php artisan panel:install` says out loud that this happened — a redirect nobody was told about is
a bug report.

## The keys

| Key | Type | Default | Behaviour |
| --- | --- | --- | --- |
| `home_redirect.enabled` | `bool` | `true` | Anything other than boolean `true` turns the whole feature off. |
| `home_redirect.paths` | `list<string>` | `['dashboard']` | `Request::is()` patterns. Non-string and empty entries are dropped; an empty list turns it off. |

```php
'paths' => ['dashboard', 'reports/*'],
```

Patterns rather than literals, so an application that keeps a section of starter kit screens can
hand over all of them at once. They are matched against the path without a leading slash, exactly
as `$request->is()` expects.

## `PandaPanel\Support\PanelHomeRedirect`

```php
public static function for(Request $request): ?string
```

Answers the URL to redirect to, or `null` to change nothing. Five conditions produce `null`, and
each is a case worth knowing:

| Condition | Why |
| --- | --- |
| The feature is off, or `paths` is empty | Nothing is taken over. |
| The request path matches none of the patterns | Every other route is left alone. |
| The request is already inside a panel | A panel answers for its own URLs; redirecting one here would loop. |
| No panel admits this user | A guest, or a user every `canAccess()` refuses. |
| The panel's `dashboard` route does not exist | `register_routes` is off, so there is nowhere to send them. |

```php
use Illuminate\Http\Request;
use PandaPanel\Support\PanelHomeRedirect;

$request = Request::create('/dashboard');
$request->setUserResolver(static fn () => $admin);

PanelHomeRedirect::for($request);   // '/admin'
```

The URL is **relative** — `route($route, absolute: false)` — because the destination is a page in
this application and a relative target is what lets an Inertia visit follow it without a full
reload.

Which panel is "first" is `PanelManager::firstAccessibleTo()`. It walks `PanelRegistry::all()`,
which is **sorted by panel id**, and returns the first whose `isAccessibleTo()` answers true. Ids
decide rather than the order of the config file, so the answer is the same on every request rather
than depending on which route happened to run — but it also means renaming a panel can change
where users land.

```php
use PandaPanel\Facades\PandaPanel;

PandaPanel::firstAccessibleTo($user);   // Panel|null
```

## `PandaPanel\Http\Middleware\RedirectPanelHome`

```php
public function handle(Request $request, Closure $next): Response
```

The middleware that calls it, appended to the `web` group by the service provider:

```php
if (! $request->isMethod('GET') || $request->expectsJson()) {
    return $next($request);
}

$target = PanelHomeRedirect::for($request);

return $target === null
    ? $next($request)
    : redirect()->to($target);
```

GET only, and never for a request that wants JSON: a starter kit's dashboard is a screen, and an
application that has hung an API or a form post off the same path keeps it. The response is an
ordinary 302 rather than an Inertia location visit, because the destination is a page in this
application — an Inertia request follows it and renders the panel without a full reload.

It is middleware rather than a competing route because `/dashboard` belongs to the application's
own route file, and a package registering the same URI would be relying on registration order to
win.

## Worked cases

Each of these is a test in the package's suite.

```php
// An admin lands in the admin panel.
$this->actingAs(User::factory()->admin()->create())
    ->get('/dashboard')
    ->assertRedirect('/admin');

// A member fails the admin panel's access check, so they get the next one they can enter.
$this->actingAs(User::factory()->create())
    ->get('/dashboard')
    ->assertRedirect('/app');

// A guest is the application's business: /dashboard is behind `auth`.
$this->get('/dashboard')->assertRedirect(route('login'));

// Every other route is untouched.
$this->actingAs($admin)->get('/')->assertOk();

// A request that wants JSON is untouched.
$this->actingAs($admin)->getJson('/dashboard')->assertOk();
```

Handing over a different section:

```php
use Illuminate\Support\Facades\Config;

Config::set('panda-panel.home_redirect.paths', ['reports/*']);

$this->actingAs($admin)->get('/dashboard')->assertOk();   // no longer taken over
```

A panel mounted on a path this would otherwise take over is ignored rather than looping:

```php
Config::set('panda-panel.home_redirect.paths', ['admin', 'admin/*']);

$this->actingAs($admin)->get('/admin')->assertOk();
```

## Turning it off

Three ways, in decreasing order of preference:

```php
// 1. The config key. Reverses everything, keeps the middleware harmless.
'home_redirect' => ['enabled' => false, 'paths' => ['dashboard']],
```

```php
// 2. Narrow the paths to a section you do want handed over.
'home_redirect' => ['enabled' => true, 'paths' => ['reports/*']],
```

```php
// 3. Drop the middleware entirely, by taking over web middleware registration.
'register_web_middleware' => false,
```

The third also drops `ResetPanelContext`, `ShareFlashToast` and `SharePanelData`, which you then
have to register yourself. See [Middleware Registration](middleware.md).

## Gotchas

- **`enabled` is compared with `!== true`.** A string from an environment variable disables it,
  including `'true'`.
- **Patterns have no leading slash.** `'/dashboard'` matches nothing; `$request->is()` compares
  against a trimmed path.
- **This is not Fortify's post-login redirect.** Fortify still sends a user to whatever
  `HOME`/`LoginResponse` says; this catches them when they arrive. Change Fortify's own redirect
  if you would rather they never touch `/dashboard` at all.
- **A user no panel admits sees the application's own page**, unchanged. There is no error and no
  403 — the redirect does not happen.
- **`register_routes => false` disables this in practice.** With no `panel.{id}.dashboard` route
  there is nowhere to redirect to, and `for()` answers null.
- **The redirect fires on every matching GET, not only after login.** Any later visit to
  `/dashboard` goes to the panel too, which is usually the point and occasionally a surprise for a
  link somebody bookmarked.

## See also

- [config/panda-panel.php](panda-panel.md)
- [Guest Redirect](guest-redirect.md)
- [Middleware Registration](middleware.md)
- [Route Registration](routes.md)
- [Panel Login](../authentication/login.md)
- [Fortify Integration](../authentication/fortify.md)
- [Laravel Vue Starter Kit Setup](../getting-started/vue-starter-kit.md)
- [Running panel:install](../getting-started/installer.md)
- [Multiple Panels](../panels/multi-panel.md)
- [Panel IDs, Paths, and Domains](../panels/ids-paths-domains.md)
