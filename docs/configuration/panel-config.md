# Panel Config

A panel is configured in code, in a `PandaPanel\Core\PanelProvider`, and registered by class name
in `config/panda-panel.php`. This page is about that split — which decisions live in the config
file, which live on the `Panel` object, why the line falls where it does, and how to read a
panel's configuration back at runtime. For the exhaustive list of setters see
[Panel API Reference](../panels/api.md).

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelProvider;

final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->path('admin')
            ->name('Administrator')
            ->auth()
            ->discoverResources(app_path('Panels/Admin/Resources'))
            ->canAccess(static fn (?Authenticatable $user): bool => $user instanceof User && $user->is_admin);
    }
}
```

```php
// config/panda-panel.php

'panels' => [
    App\Panels\Admin\AdminPanelProvider::class,
],
```

```php
panel('admin')->getPath();          // 'admin'
panel('admin')->getMiddleware();    // ['web', 'auth', 'verified']
panel('admin')->routeName('dashboard');   // 'panel.admin.dashboard'
```

`php artisan make:panel Admin` writes the provider; `php artisan panel:install` writes both.

## Two configuration surfaces

| Surface | Holds | Read |
| --- | --- | --- |
| `config/panda-panel.php` | which panels exist, and the registration switches around them | once, during boot |
| `PanelProvider::panel()` | everything about one panel | once per panel, during boot |

The rule is whether the decision has logic in it. `canAccess()` takes a closure, `tenant()` takes
a resolver, `configureActions()` takes a callback — none of those survive `config:cache`, which
serializes with `var_export()`. Path, name, and branding could live in an array, and do not,
because splitting one panel's definition across two files would mean looking in two places to
answer one question.

What is left in the config file is the small set of switches that decide whether the framework
registers anything at all — routes, web middleware, the guest redirect, the migrations — plus the
security bounds on integrations and the two frontend paths. Every one of them is
[in the reference](panda-panel.md).

## `PanelProvider`

```php
abstract public function panel(Panel $panel): Panel;

public function panelId(): string;

public function build(): Panel;
```

| Method | Behaviour |
| --- | --- |
| `panel()` | The one method a provider implements. Receives a `Panel` with its id already set, returns it configured. |
| `panelId()` | The id, derived from the class name: `AdminPanelProvider` → `admin`. Override to choose another. |
| `build()` | `panel(Panel::make($this->panelId()))`. Called by `PanelManager::registerProvider()`. |

```php
use App\Panels\Admin\AdminPanelProvider;

(new AdminPanelProvider)->panelId();          // 'admin'
(new AdminPanelProvider)->build()->getPath(); // 'admin'
```

Override `panelId()` when the class name is not the id you want:

```php
final class BackOfficePanelProvider extends PanelProvider
{
    public function panelId(): string
    {
        return 'admin';
    }

    public function panel(Panel $panel): Panel
    {
        return $panel->path('back-office');
    }
}
```

The id is what route names are built from (`panel.admin.*`) and what `panel('admin')` looks up.
The path is what appears in a URL. They default to the same string and are free to differ.

Keep service resolution out of `panel()`. It runs during provider boot, before the container is
warm for request-scoped bindings, and a panel that resolved the current user there would be
resolving a user who does not exist yet.

## `Panel`

```php
public static function make(?string $id = null): self;
```

```php
use PandaPanel\Core\Panel;

$panel = Panel::make('reports')
    ->path('reports')
    ->auth()
    ->settings(false);

$panel->getId();   // 'reports'
```

`make()` without an id leaves it unset and `getId()` then throws
`PandaPanel\Exceptions\PanelRegistrationException`. Inside a provider the id is already seeded.

Setters keep bare names; readers are prefixed `get` or `has`. PHP cannot overload, and a combined
setter/getter returning `string|static` is exactly the kind of magic this framework avoids.

### What each group defaults to

| Group | Setters | Default |
| --- | --- | --- |
| Identity | `id`, `name`, `path`, `domain` | name is `Str::headline($id)`, path is the id, domain `null` |
| Middleware | `middleware`, `authMiddleware`, `auth` | `['web']` base, `['auth']` auth stack |
| Front door | `login`, `registration`, `passwordReset`, `emailVerification`, `requireTwoFactor` | all off |
| Registration | `resources`, `pages`, `widgets`, `discoverResources`, `discoverPages`, `discoverWidgets` | nothing registered, nothing discovered |
| Built-ins | `settings` | on — profile, security and appearance pages |
| Landing | `dashboard`, `dashboards` | `PandaPanel\Pages\Dashboard` |
| Shell | `sidebar`, `topNavigation`, `sidebarWidth`, `collapsedSidebarWidth`, `navigation`, `topbar`, `breadcrumbs`, `maxContentWidth` | collapsible sidebar, `inset` appearance, `16rem` / `3rem`, all three shell parts on, no max width |
| Branding | `brandName`, `brandLogo`, `icon`, `favicon`, `darkMode`, `colors`, `cssHooks` | brand name is `config('app.name')`, dark mode on |
| Behaviour | `databaseTransactions`, `strictAuthorization`, `unsavedChangesAlerts`, `bootUsing` | transactions on, strict authorization off, alerts on |
| Navigation behaviour | `prefetch`, `fullPageUrls`, `errorNotification`, `hideErrorNotification` | prefetch `'hover'`, no full-page URLs, six default error notifications |
| Search | `globalSearch` | enabled, limit 50, debounce 300ms, `['mod+k']` |
| Notifications | `notifications`, `broadcasting` | both on |
| Extension | `renderHook`, `subNavigationPosition`, `assets`, `plugins`, `configureActions` | none, `SubNavigationPosition::Top` |
| Tenancy | `tenant`, `tenantUrlUsing` | no tenancy |
| Access | `canAccess` | no predicate — anyone the middleware admits |

Discovery paths, navigation groups, assets, render hooks and boot callbacks **accumulate**. Calling
`discoverResources()` twice adds two paths. `middleware()` and `authMiddleware()` **replace**,
which is why a panel that calls `middleware()` must include `web` itself.

## Reading configuration back

```php
panel();          // the panel for this request, or null outside one
panel('admin');   // an explicit panel; throws PanelRegistrationException if unknown
```

```php
use PandaPanel\Facades\PandaPanel;

PandaPanel::all();                        // list<Panel>, sorted by id
PandaPanel::has('admin');                 // bool
PandaPanel::get('admin');                 // Panel
PandaPanel::currentPanel();               // Panel|null
PandaPanel::resolveFromRequest($request); // Panel|null — longest path prefix, honours domain()
PandaPanel::firstAccessibleTo($user);     // Panel|null — the first, by id, that admits them
PandaPanel::resources('admin');           // ResourceRegistry
PandaPanel::pages('admin');               // PageRegistry
PandaPanel::widgets('admin');             // WidgetRegistry
PandaPanel::navigation('admin');          // NavigationRegistry
```

The facade proxies `PandaPanel\Core\PanelManager`, which is a container singleton — inject it
instead when you would rather not use a facade.

Two readers worth knowing by name:

```php
public function isAccessibleTo(?Authenticatable $user): bool;

public function toSharedArray(): array;
```

`isAccessibleTo()` asks two questions and both must agree: the user model's own
`PanelUser::canAccessPanel()` when it implements the contract, and the panel's `canAccess()`
predicate. A panel that says yes cannot overrule a user model that says no.

`toSharedArray()` is what crosses to Vue as the `panel` prop. Only settings the frontend acts on
are in it — transactions, strict authorization, boot callbacks and middleware are server concerns
and stay on the server. See [Server Metadata to Vue](../concepts/metadata-to-vue.md).

## Configuration that differs per environment

`config()` is available inside `panel()`: configuration is loaded before providers boot.

```php
public function panel(Panel $panel): Panel
{
    return $panel
        ->path((string) config('panels.admin_path', 'admin'))
        ->strictAuthorization(app()->environment('local', 'testing'));
}
```

Read from `config()` rather than `env()`. Once `config:cache` has run, `env()` outside a config
file returns null, and a panel whose path came from `env()` would mount somewhere else in
production with no error to say so.

Registering a panel only in some environments is a config-file decision, not a panel one:

```php
// config/panda-panel.php

'panels' => array_values(array_filter([
    App\Panels\Admin\AdminPanelProvider::class,
    env('APP_ENV') === 'local' ? App\Panels\Debug\DebugPanelProvider::class : null,
])),
```

`env()` is legitimate there — it is a config file, which is the one place it is read before
caching.

## Adjusting a panel after boot

`bootUsing()` runs on every request into the panel, in `ResolvePanel`, after the access check:

```php
$panel->bootUsing(function (Panel $panel): void {
    // per-request work; the user is known here
});
```

```php
public function bootUsing(Closure $callback): self;
public function getBootCallbacks(): array;   // list<Closure(Panel): void>
public function boot(): void;
```

Callbacks accumulate rather than replace, and a user refused the panel never triggers them.

A registered panel is a live object, so a test can reconfigure one:

```php
use PandaPanel\Core\PanelManager;

app(PanelManager::class)->get('admin')->requireTwoFactor();
```

That changes behaviour for anything read per request. It does **not** change routes: those were
registered from the panel during boot, and a middleware stack changed afterwards applies to
nothing.

## Registering a panel from code

```php
use PandaPanel\Core\Panel;
use PandaPanel\Facades\PandaPanel;

$panel = PandaPanel::register(
    Panel::make('reports')->path('reports')->settings(false),
);
```

```php
PandaPanel::registerProvider(App\Panels\Admin\AdminPanelProvider::class);
```

Both build the panel's registries immediately. Neither registers routes — the route registrar runs
once during boot, and a panel registered after it has already run has none. Register them
explicitly when you need them, which is what the package's own suite does:

```php
use Illuminate\Support\Facades\Route;
use PandaPanel\Routing\PanelRouteRegistrar;

app(PanelRouteRegistrar::class)->register($panel);

Route::getRoutes()->refreshNameLookups();
```

A panel registered this way is not in `config('panda-panel.panels')`, so it is invisible to
`panel:cache`, which enumerates configured panels.

## Notes

- **`panel()` is called once per panel per process.** Under Octane it is not re-run per request,
  so treat the returned `Panel` as immutable configuration rather than as request state.
- **A duplicate panel id fails the boot.** `PanelRegistrationException` is a `RuntimeException`
  and nothing catches it: better than a half-registered panel.
- **The provider class is never serialized.** `panel:cache` stores resource, page and widget class
  names per panel id, and nothing about the provider.
- **Panel ids decide priority, not the order of the config file.** `PanelRegistry::all()` sorts by
  id, and `firstAccessibleTo()` walks that list — which is what decides where the home redirect
  sends somebody who can enter more than one panel.
- **`getId()` throws when the id was never set.** Only reachable through `Panel::make()` with no
  argument; a provider always seeds it.

## See also

- [config/panda-panel.php](panda-panel.md)
- [Service Provider Behavior](service-provider.md)
- [Route Registration](routes.md)
- [Middleware Registration](middleware.md)
- [Panel API Reference](../panels/api.md)
- [Defining a Panel](../panels/defining-panels.md)
- [Panel Providers](../concepts/panel-providers.md)
- [Panel IDs, Paths, and Domains](../panels/ids-paths-domains.md)
- [Panel Access Rules](../panels/access.md)
- [make:panel](../cli/make-panel.md)
