# config/panda-panel.php

Every key the package reads, what it decides, and what an invalid value does. This is the
whole configuration file: eight top-level keys, all of them registration-time switches or
security bounds. Everything with logic in it — paths, domains, middleware, navigation,
branding, access — is configured in code on the panel, because a decision with a condition
in it does not belong in an array.

## A minimal working example

```bash
composer require chocoalano/panel
php artisan vendor:publish --tag=panda-panel-config
```

```php
// config/panda-panel.php

return [
    'panels' => [
        App\Panels\Admin\AdminPanelProvider::class,
    ],
];
```

That is the only key an application has to set. `PandaPanel\PandaPanelServiceProvider::register()`
calls `mergeConfigFrom()`, so every other key already has the value shown below whether the file
is published or not — and a provider that is not in `panels` has no routes, which is the single
most common "the install worked but the URL 404s".

`php artisan panel:install` publishes this file and writes that line for you. See
[Publish Tags](../cli/publish-tags.md) and [Running panel:install](../getting-started/installer.md).

## Every key

| Key | Type | Default | Read by |
| --- | --- | --- | --- |
| `panels` | `list<class-string<PanelProvider>>` | `[]` | `PandaPanelServiceProvider::configuredPanels()` |
| `register_routes` | `bool` | `true` | `PandaPanelServiceProvider::registerRoutes()` |
| `register_web_middleware` | `bool` | `true` | `PandaPanelServiceProvider::registerMiddleware()` |
| `register_guest_redirect` | `bool` | `true` | `PandaPanelServiceProvider::registerGuestRedirect()` |
| `home_redirect.enabled` | `bool` | `true` | `PandaPanel\Support\PanelHomeRedirect` |
| `home_redirect.paths` | `list<string>` | `['dashboard']` | `PandaPanel\Support\PanelHomeRedirect` |
| `load_migrations` | `bool` | `true` | `PandaPanelServiceProvider::registerMigrations()` |
| `integrations.allowed_hosts` | `list<string>` | `[]` | `PandaPanel\Integrations\OutboundUrl` |
| `integrations.block_private_networks` | `bool` | `true` | `PandaPanel\Integrations\OutboundUrl` |
| `integrations.history.enabled` | `bool` | `true` | `PandaPanel\Integrations\PanelIntegrationDelivery::enabled()` |
| `integrations.history.keep_per_integration` | `int` | `50` | `PanelIntegrationDelivery::prune()` |
| `integrations.history.retention_days` | `int` | `30` | `PanelIntegrationDelivery::prune()` |
| `frontend.panel_path` | `string` | `'js/panel'` | `PandaPanel\Support\FrontendPaths::panel()` |
| `frontend.pages_path` | `string` | `'js/pages/Panels'` | `PandaPanel\Support\FrontendPaths::pages()` |

Nothing here is bound to `env()`. The package ships no environment variables of its own — see
[Environment Variables](environment.md) for the Laravel configuration a panel does depend on.

## `panels`

```php
'panels' => [
    App\Panels\Admin\AdminPanelProvider::class,
    App\Panels\App\AppPanelProvider::class,
],
```

Panels are listed rather than discovered. Every panel an application has is visible in one place,
and adding one is a deliberate edit rather than a filesystem side effect. The classes *inside* a
panel are discovered; see [Discovery](../concepts/discovery.md).

Five rules the provider and the registry enforce while reading this list:

| Case | What happens |
| --- | --- |
| The value is not an array | Treated as `[]`. No panel is registered. |
| An entry is not a `class-string` subclass of `PandaPanel\Core\PanelProvider` | Skipped silently. |
| The same provider appears twice | Registered once — the registry keys by panel id. |
| Two different providers produce the same panel id | `PanelRegistrationException::duplicatePanelId()`. |
| Two panels share a path *and* a domain | `PanelRegistrationException::duplicatePanelPath()`, because one would silently shadow the other. |

The list is read in the order it is written, but `PanelRegistry::all()` hands panels back **sorted
by id**. That is what makes route registration order stable across runs — and it is also the order
`firstAccessibleTo()` walks, so an `admin` panel is considered before an `app` one regardless of
which line comes first here.

The skip is deliberate. A class name that no longer resolves would fatal during boot, before any
route — including the one that would have shown you the error. Skipping leaves the application
reachable, and `php artisan panel:cache` prints how many panels it cached, so a skipped one shows
up there as a count that is one short.

```php
use PandaPanel\Facades\PandaPanel;

PandaPanel::all();          // list<Panel>, sorted by id
PandaPanel::has('admin');   // bool
PandaPanel::get('admin');   // Panel, throws PanelRegistrationException when not registered
```

## `register_routes`

```php
'register_routes' => true,
```

One route group per panel, registered during boot with the path, domain and middleware each panel
declares. Set it to `false` when the application registers them itself — a test harness that boots
panels without HTTP, for example. Registries are still built, so `PandaPanel::resources('admin')`
still answers. [Route Registration](routes.md).

## `register_web_middleware`

```php
'register_web_middleware' => true,
```

Appends four middleware to the whole `web` group: `ResetPanelContext`, `RedirectPanelHome`,
`ShareFlashToast`, `SharePanelData`. Set it to `false` to place them yourself in
`bootstrap/app.php`, which is the right call when you need them at a specific position in the
stack. The four middleware *aliases* are registered either way.
[Middleware Registration](middleware.md).

## `register_guest_redirect`

```php
'register_guest_redirect' => true,
```

Sends a guest who opens a panel URL to *that panel's* own login when the panel has one, and to
the application's `login` route otherwise — which is what Laravel does by default, so turning
this on adds a case rather than replacing one. Set it to `false` if your `bootstrap/app.php`
calls `redirectGuestsTo()` itself; yours would otherwise be overwritten.
[Guest Redirect](guest-redirect.md).

## `home_redirect`

```php
'home_redirect' => [
    'enabled' => true,

    'paths' => ['dashboard'],
],
```

A signed-in user who lands on one of these paths is sent to the first panel they can enter.
Nothing is rewritten to do it: the application keeps its route, its route name, and its page
component. The paths are `Request::is()` patterns, so `'reports/*'` hands over a whole section.
A path a panel is itself mounted on is ignored, because redirecting one to itself is a loop.

Entries that are not non-empty strings are filtered out. An empty list, or `enabled` set to
anything other than boolean `true`, turns the feature off entirely. [Home Redirect](home-redirect.md).

## `load_migrations`

```php
'load_migrations' => true,
```

Runs the package's migrations from the package. A panel cannot render without the `notifications`
table, so an install that had to remember a publish step would 500 on its first page. Every one of
them checks before it touches anything, so an application that already has the table or the column
is untouched.

Turn this off to own them yourself:

```bash
php artisan vendor:publish --tag=panda-panel-migrations
```

[Migration Loading](migrations.md).

## `integrations`

The screen where an administrator configures outbound HTTP fired on a resource's writes. The
server issues those requests, which makes it a server-side request forgery surface by
construction — the destination is typed into a form rather than written in code. Two gates, and a
URL has to pass both.

```php
'integrations' => [

    'allowed_hosts' => [
        // 'api.example.com',
        // '*.partner.io',
    ],

    'block_private_networks' => true,

    'history' => [
        'enabled' => true,
        'keep_per_integration' => 50,
        'retention_days' => 30,
    ],

],
```

| Key | Behaviour |
| --- | --- |
| `allowed_hosts` | `Str::is()` patterns matched against the URL's host, case-insensitively. Empty means nothing is reachable — deny by default. Non-string and empty entries are dropped. |
| `block_private_networks` | Refuses any host that cannot be resolved to a public A/AAAA address, or that resolves into private, loopback, carrier-grade NAT or link-local ranges. Anything other than boolean `true` disables it. |
| `history.enabled` | Whether a row is written per delivery attempt. |
| `history.keep_per_integration` | Hard cap on retained rows per integration. Floored at `1` via `max(1, (int) …)`. |
| `history.retention_days` | Window for retained rows. Floored at `0`, and `0` keeps the cap and nothing else. |

`*.partner.io` covers a subdomain without naming each one, and deliberately does not cover
`partner.io.attacker.test` — the pattern is anchored at both ends. `block_private_networks`
covers `0.0.0.0/8`, `10.0.0.0/8`, `127.0.0.0/8`, `169.254.0.0/16`, `172.16.0.0/12`,
`192.168.0.0/16`, `100.64.0.0/10`, `::1/128`, `fc00::/7` and `fe80::/10`; `169.254.169.254` is
the one that matters most, being the unauthenticated cloud metadata endpoint that hands out IAM
credentials. IPv4-mapped IPv6 literals are normalized back to IPv4 before the same range checks run.
It is checked when an integration is saved and again immediately before each
request, because a name approved last week can resolve elsewhere today. Integration deliveries do
not follow HTTP redirects; a redirected target must pass the allowlist as its own saved URL.

Leave the second on. It is what makes relaxing the first survivable.

```php
use PandaPanel\Integrations\OutboundUrl;

OutboundUrl::isAllowed('https://api.example.com/hooks');   // bool
OutboundUrl::rejection('http://169.254.169.254/latest');   // the sentence shown to the user, or null
```

Both bounds on history are applied immediately after each delivery rather than by anything you
have to schedule. Bodies are stored truncated; headers are never stored at all, because they hold
the API keys these requests carry.

Integrations are off for every resource until one opts in with
`integrations()->isEnabled(true)`, so an application that configures none of this has nothing to
turn off. See [Resource API](../resources/api.md).

## `frontend`

```php
'frontend' => [
    'panel_path' => 'js/panel',
    'pages_path' => 'js/pages/Panels',
],
```

Where `vendor:publish --tag=panda-panel-assets` puts the panel's Vue components, and where the
generators write the components they scaffold. Both are relative to `resources/`. A value that is
not a non-empty string falls back to the default. [Frontend Paths](frontend-paths.md).

## Reading a key back

```php
config('panda-panel.register_routes');            // true
config('panda-panel.home_redirect.paths');        // ['dashboard']
config('panda-panel.integrations.allowed_hosts'); // []
```

Overriding one in a test is an ordinary `Config::set()`, and takes effect for anything read per
request — the home redirect, the integration gates, the frontend paths. The four registration
switches are read once during boot, so changing them after the application has booted changes
nothing.

```php
use Illuminate\Support\Facades\Config;

Config::set('panda-panel.home_redirect.enabled', false);

$this->actingAs($admin)->get('/dashboard')->assertOk();
```

## Gotchas

- **The booleans are compared with `=== true`.** `register_routes`, `register_web_middleware`,
  `register_guest_redirect`, `load_migrations`, `home_redirect.enabled` and
  `integrations.block_private_networks` all check for boolean `true` rather than truthiness. A
  string `'true'` from an environment variable disables the feature.
- **`mergeConfigFrom()` is a shallow `array_merge`.** A published file that declares
  `'integrations' => ['allowed_hosts' => [...]]` replaces the package's whole `integrations`
  array, so `history` is gone from config. The reading code passes its own defaults to
  `Config::get()`, so behaviour is unchanged — but the file no longer documents what is in force.
  Publish the whole file rather than a fragment of it.
- **`config:cache` runs `var_export()` over the result.** Every value here is a scalar, an array
  or a class-name string, which is exactly why `panels` holds strings rather than instances. Do
  not put a closure in this file. See [Config Cache](../deployment/config-cache.md).
- **Caching config does not freeze the package defaults out.** `config:cache` boots a fresh
  application before serializing, so `mergeConfigFrom()` has already run and an unpublished
  config is captured with its defaults intact.
- **A panel added to `panels` still needs its classes discovered.** In production that means
  `php artisan panel:cache` after the edit, or the new panel's resources are invisible with no
  error to say so. See [Caching](../concepts/caching.md).
- **This file is not where a panel is configured.** Path, domain, middleware, branding, access,
  navigation and search all live on the `Panel` object. [Panel Config](panel-config.md).

## See also

- [Panel Config](panel-config.md)
- [Route Registration](routes.md)
- [Middleware Registration](middleware.md)
- [Guest Redirect](guest-redirect.md)
- [Home Redirect](home-redirect.md)
- [Migration Loading](migrations.md)
- [Frontend Paths](frontend-paths.md)
- [Environment Variables](environment.md)
- [Service Provider Behavior](service-provider.md)
- [Publish Tags](../cli/publish-tags.md)
- [Panel Providers](../concepts/panel-providers.md)
