# Defining a Panel

A panel is one admin interface: a URL prefix, a middleware stack, a set of resources, pages and widgets, and the shell that draws them. You define one by writing a `PandaPanel\Core\PanelProvider` subclass and listing it in `config/panda-panel.php`. Everything else — routes, navigation, Inertia props — is derived from that one object.

## A minimal panel

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
            ->discoverResources(app_path('Panels/Admin/Resources'))
            ->discoverPages(app_path('Panels/Admin/Pages'))
            ->discoverWidgets(app_path('Panels/Admin/Widgets'));
    }
}
```

```php
// config/panda-panel.php

'panels' => [
    App\Panels\Admin\AdminPanelProvider::class,
],
```

That is a working panel at `/admin` with a dashboard, the three account settings pages, and whatever lives under the three discovery paths. `php artisan make:panel Admin` writes the same file and creates the directories.

## The provider

`PanelProvider` has three methods and you normally override one.

| Method | Signature | What it does |
| --- | --- | --- |
| `panel()` | `abstract public function panel(Panel $panel): Panel` | Configures the panel. Must return it. |
| `panelId()` | `public function panelId(): string` | The id, derived from the class name. |
| `build()` | `public function build(): Panel` | Calls `panel()` with a `Panel` already seeded with `panelId()`. |

The id comes from the class basename with `PanelProvider` removed and the rest kebab-cased: `AdminPanelProvider` becomes `admin`, `BackOfficePanelProvider` becomes `back-office`. Override `panelId()` to change it, or call `->id()` inside `panel()` — see [Panel IDs, Paths, and Domains](ids-paths-domains.md).

`panel()` runs during provider boot, before request-scoped bindings are warm. Do not resolve services, read the authenticated user, or generate URLs in it. Work that needs any of those belongs in `bootUsing()`, which runs per request:

```php
use Illuminate\Contracts\Auth\Authenticatable;
use PandaPanel\Core\Panel;

$panel->bootUsing(static function (Panel $panel): void {
    // Runs on every request into this panel, after the access check passes.
});
```

Boot callbacks accumulate, and `Panel::boot()` runs the panel's plugins first so an application's own callback can undo what a plugin did.

## Registration is explicit

Panels are listed by hand in `config/panda-panel.php`. The classes *inside* a panel are discovered; the panels themselves are not. Two reasons: the list is the whole set of panels an application has — and which of them a signed-in user is sent to when the request names none follows from that set, since `firstAccessibleTo()` walks panels in id order — and adding a panel should be a deliberate edit rather than a filesystem side effect.

A class name in that list that does not resolve is skipped rather than fatal, because a fatal during boot happens before the route that would have shown the error. `php artisan panel:cache` reports the same list where a mistake is visible.

A panel registered twice under the same id is registered once; a second panel claiming the same id, or the same path on the same domain, throws `PandaPanel\Exceptions\PanelRegistrationException`.

To register a panel without a provider — a test, a package — use `PandaPanel\Core\PanelManager`:

```php
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;

app(PanelManager::class)->register(
    Panel::make('reports')->path('reports')->settings(false),
);
```

`PanelManager::register()` populates the registries but does not register routes. `app(PandaPanel\Routing\PanelRouteRegistrar::class)->register($panel)` does that.

## Registering classes

Discovery paths and explicit lists are merged, and both accumulate rather than overwrite.

```php
use App\Panels\Admin\Resources\Users\UserResource;
use App\Panels\Admin\Widgets\UserStats;
use App\Reports\Pages\MonthlyReport;

$panel
    ->discoverResources(app_path('Panels/Admin/Resources'))
    ->discoverPages(app_path('Panels/Admin/Pages'), app_path('Reports/Pages'))
    ->discoverWidgets(app_path('Panels/Admin/Widgets'))
    ->resources([UserResource::class])
    ->pages([MonthlyReport::class])
    ->widgets([UserStats::class]);
```

| Method | Signature | Notes |
| --- | --- | --- |
| `discoverResources` | `discoverResources(string ...$paths): self` | Variadic, accumulates, deduplicated. |
| `discoverPages` | `discoverPages(string ...$paths): self` | Same. |
| `discoverWidgets` | `discoverWidgets(string ...$paths): self` | Same. |
| `resources` | `resources(array $resources): self` | Class strings, or `ResourceConfiguration` objects. |
| `pages` | `pages(array $pages): self` | Class strings. |
| `widgets` | `widgets(array $widgets): self` | Class strings. |

A class registered explicitly *and* found by discovery appears once: the registries are keyed by slug and by widget id. See [Discovery](../concepts/discovery.md) for how class names are derived, and [Per-Panel Configuration](../resources/per-panel-configuration.md) for `ResourceConfiguration`.

## A fuller panel

This is `examples/app/Panels/Admin/AdminPanelProvider.php`, which the test suite runs against.

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin;

use App\Models\User;
use App\Panels\Admin\Pages\AccountsDashboard;
use Illuminate\Contracts\Auth\Authenticatable;
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelProvider;
use PandaPanel\Pages\Dashboard;

final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->path('admin')
            ->name('Administrator')
            ->brandName((string) config('app.name'))
            ->icon('shield')
            ->sidebar(appearance: 'sidebar')
            ->auth()
            ->navigationGroups([
                'User Management',
                'System',
            ])
            ->dashboards([
                Dashboard::class,
                AccountsDashboard::class,
            ])
            ->discoverResources(app_path('Panels/Admin/Resources'))
            ->discoverPages(app_path('Panels/Admin/Pages'))
            ->discoverWidgets(app_path('Panels/Admin/Widgets'))
            ->configureActions(static function (Action $action): void {
                if ($action->getVariant() === ActionVariant::Destructive) {
                    $action->requiresConfirmation();
                }
            })
            ->canAccess(static fn (?Authenticatable $user): bool => $user instanceof User && $user->is_admin);
    }
}
```

## Behaviour switches

```php
$panel
    ->databaseTransactions()      // on by default
    ->strictAuthorization()       // off by default
    ->unsavedChangesAlerts()      // on by default
    ->settings()                  // on by default
    ->broadcasting()              // on by default
    ->notifications()             // on by default
    ->darkMode();                 // on by default
```

| Method | Signature | Default | Effect |
| --- | --- | --- | --- |
| `databaseTransactions` | `databaseTransactions(bool $databaseTransactions = true): self` | `true` | Wraps resource create/update and every action in a transaction. |
| `strictAuthorization` | `strictAuthorization(bool $strictAuthorization = true): self` | `false` | A missing policy throws `PanelAuthorizationException` instead of denying. |
| `unsavedChangesAlerts` | `unsavedChangesAlerts(bool $unsavedChangesAlerts = true): self` | `true` | Warns before leaving a dirty create or edit form. |
| `settings` | `settings(bool $settings = true): self` | `true` | Adds the profile, security and appearance pages. |
| `broadcasting` | `broadcasting(bool $broadcasting = true): self` | `true` | Subscribes the panel to the user's notification channel. |
| `notifications` | `notifications(bool $notifications = true): self` | `true` | Shows the notification bell. |
| `darkMode` | `darkMode(bool $darkMode = true): self` | `true` | Shipped as `panel.darkMode`. |

Transactions resolve most-specific-first: an action's `databaseTransaction(bool)`, then a resource page's `protected static ?bool $hasDatabaseTransactions`, then the panel, then on. `null` at either of the first two levels means "did not decide", which is what lets a page override the panel in either direction. Outside a panel entirely the answer is on.

`configureActions()` applies a default to every action the panel builds, as each one is made, so a schema that states its own still wins:

```php
use Closure;
use PandaPanel\Actions\Action;

$panel->configureActions(static function (Action $action): void {
    $action->icon('circle-alert');
});

$panel->actionConfigurator();   // the Closure, or null
```

## Reaching the current panel

```php
panel();          // the Panel for this request, or null outside one
panel('admin');   // an explicit panel; throws PanelRegistrationException if unknown
```

The same answers come from `PanelManager`:

```php
use PandaPanel\Core\PanelManager;

$manager = app(PanelManager::class);

$manager->currentPanel();      // ?Panel
$manager->hasCurrentPanel();   // bool
$manager->all();               // list<Panel>, sorted by id
$manager->has('admin');        // bool
$manager->get('admin');        // Panel, throws if unknown
```

The current panel is request-scoped state held by `PandaPanel\Support\PanelContext`, cleared at the start of every web request by `ResetPanelContext` — see [Panel Context](../concepts/panel-context.md).

## Notes

- `panel()` on the provider runs once per boot, not per request. A value computed there is computed for every user.
- The panel object is mutable after registration. `app(PanelManager::class)->get('admin')->assets(...)` in a test or a service provider works, but route registration has already happened by then, so anything routing-related (path, domain, pages) is too late to change.
- `getPages()` returns the built-in settings pages first when `settings()` is on. That is why `Panel::make('x')->getPages()` is not empty on a fresh panel.
- Nothing that cannot be serialized crosses to the frontend. `toSharedArray()` is the whole contract; discovery paths, middleware, transactions and boot callbacks stay on the server.

## See also

- [Panel IDs, Paths, and Domains](ids-paths-domains.md)
- [Multi-Panel Applications](multi-panel.md)
- [Middleware and Guards](middleware.md)
- [Panel Access Rules](access.md)
- [Panel API Reference](api.md)
- [Panel Providers](../concepts/panel-providers.md)
- [Discovery](../concepts/discovery.md)
- [make:panel](../cli/make-panel.md)
- [Configuration Reference](../configuration/panda-panel.md)
