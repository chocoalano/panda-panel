# Admin Panel Example

The administrator panel that ships in [`examples/`](../../examples/), mounted at `/admin` and reachable only by a user whose `is_admin` flag is true. Read this page when you are building the first panel of an application and want a complete, working provider to copy rather than a list of methods to assemble. Every file named here is in the repository and the test suite runs against it.

## A minimal working example

Generate the panel and its directories:

```bash
php artisan make:panel Admin
```

Register the provider — panels are listed by hand, so the panel set is visible in one place:

```php
// config/panda-panel.php

'panels' => [
    App\Panels\Admin\AdminPanelProvider::class,
],
```

That is a working panel at `/admin` with a dashboard, three account settings pages, and nothing else. Sign in and open it.

## The example provider, in full

`examples/app/Panels/Admin/AdminPanelProvider.php`:

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

The rest of this page takes that provider one call at a time.

## Identity

The id is derived from the class name — `AdminPanelProvider` becomes `admin` — and everything else is stated.

```php
public function id(string $id): self
public function name(string $name): self
public function path(string $path): self
public function domain(?string $domain): self
```

```php
$panel
    ->path('admin')        // the URL prefix: /admin
    ->name('Administrator') // what the panel calls itself in the shell
    ->domain('admin.example.com');   // optional; keeps the panel off other hosts
```

Readers are prefixed `get`: `getId()`, `getPath()`, `getName()`, `getDomain()`. Route names are built from the id — `panel.admin.dashboard`, `panel.admin.resources.users.index` — so changing the path never breaks a `Resource::url()`.

## Branding and the shell

```php
public function brandName(string $brandName): self
public function brandLogo(?string $brandLogo, ?string $darkBrandLogo = null): self
public function icon(?string $icon, ?string $darkIcon = null): self
public function favicon(?string $favicon, ?string $darkFavicon = null): self
public function darkMode(bool $darkMode = true): self
public function maxContentWidth(?string $maxContentWidth): self
public function sidebar(
    bool $collapsible = true,
    bool $defaultOpen = true,
    string $variant = 'sidebar',
    string $appearance = 'inset',
): self
```

```php
$panel
    ->brandName((string) config('app.name'))
    ->icon('shield')                       // an icon registry key, never a path
    ->sidebar(appearance: 'sidebar');      // 'inset' (default), 'floating', 'sidebar'
```

`variant: 'header'` swaps the side rail for top navigation; `appearance` styles the rail and is ignored by the header shell. `icon()` takes a key from `resources/js/panel/icons/registry.ts` — run `php artisan panel:icons` after naming a new one, or it renders nothing at all.

## Who may enter

Two questions, and both must agree.

```php
public function auth(bool $verified = true): self
public function canAccess(Closure $callback): self       // Closure(?Authenticatable): bool
public function isAccessibleTo(?Authenticatable $user): bool
```

```php
$panel
    ->auth()   // appends 'auth' and 'verified' to the panel's auth middleware
    ->canAccess(static fn (?Authenticatable $user): bool => $user instanceof User && $user->is_admin);
```

`auth()` is the middleware — a guest is redirected to a login. `canAccess()` is a predicate — an authenticated user who fails it gets **403**, not a redirect, because they are signed in and still not welcome here.

The other half is on the user model. `App\Models\User` in the examples implements `PandaPanel\Contracts\PanelUser`:

```php
public function canAccessPanel(Panel $panel): bool
{
    return $this->hasVerifiedEmail();
}
```

A rule about the *panel* belongs in `canAccess()`; a rule about the *account* belongs on the model, where it applies to every panel at once and cannot be forgotten when a new one is added. A panel that says yes cannot overrule a user model that says no.

## Navigation groups

```php
public function navigationGroups(array $groups): self
```

```php
$panel->navigationGroups([
    'User Management',
    'System',
    'Access' => 'System',   // nests Access under System
]);
```

Groups render in the order declared; a group a class names but the panel never declared is appended alphabetically. Groups accumulate across calls rather than overwrite, so a plugin can contribute one. A backed enum works in place of a string, which is worth reaching for once more than one class names the same group — a mistyped string is a second group that looks like the first.

## Dashboards

```php
public function dashboard(string $page): self             // class-string<Page>
public function dashboards(array $pages): self            // list<class-string<Page>>
public function getExtraDashboards(): array
```

```php
$panel->dashboards([
    Dashboard::class,          // the panel root, /admin
    AccountsDashboard::class,  // its own route, navigation item, and filters
]);
```

The first is the root; the rest are registered as ordinary pages. `PandaPanel\Pages\Dashboard` draws every widget the panel discovered. `AccountsDashboard` names its own three and adds a page-wide filter:

```php
// examples/app/Panels/Admin/Pages/AccountsDashboard.php

final class AccountsDashboard extends Dashboard
{
    protected static ?string $title = 'Accounts';

    protected static ?string $slug = 'accounts';

    protected static ?string $navigationIcon = 'users';

    protected static string|BackedEnum|null $navigationGroup = 'User Management';

    public function filterSchema(): FormSchema
    {
        return FormSchema::make()->schema([
            Select::make('period')
                ->label('Period')
                ->options(['month' => 'This month', 'quarter' => 'This quarter', 'year' => 'This year'])
                ->default('month'),
        ]);
    }

    /** @return list<class-string<Widget>> */
    public function widgets(): array
    {
        return [UserStats::class, UserGrowth::class, RecentUsers::class];
    }
}
```

Two dashboards rather than one with a dropdown: "how are accounts doing" and "is the system healthy" are read by different people.

## Discovery

```php
public function discoverResources(string ...$paths): self
public function discoverPages(string ...$paths): self
public function discoverWidgets(string ...$paths): self
public function resources(array $resources): self   // class-string|ResourceConfiguration
public function pages(array $pages): self
public function widgets(array $widgets): self
```

```php
$panel
    ->discoverResources(app_path('Panels/Admin/Resources'))
    ->discoverPages(app_path('Panels/Admin/Pages'))
    ->discoverWidgets(app_path('Panels/Admin/Widgets'));
```

Nothing in the example panel is registered by hand. Class names come from Composer's PSR-4 prefixes rather than from parsing files, only concrete classes implementing the expected contract are included, and results are sorted so two machines produce the same manifest. Explicit registration still works and merges with discovery — that is how a panel pulls in a class living outside its tree.

Discovery paths accumulate. Calling `discoverWidgets()` twice adds two directories.

## Panel-wide action defaults

```php
public function configureActions(Closure $callback): self   // Closure(Action): void
public function actionConfigurator(): ?Closure
```

```php
$panel->configureActions(static function (Action $action): void {
    if ($action->getVariant() === ActionVariant::Destructive) {
        $action->requiresConfirmation();
    }
});
```

House style applied as each action is built, so a schema that states its own still wins. It is request-scoped through the current panel rather than a static configurator, which is what lets two panels differ without anything leaking between requests.

## What the panel produces

With the example files in place, `/admin` answers with:

| URL | Route name | Class |
| --- | --- | --- |
| `/admin` | `panel.admin.dashboard` | `PandaPanel\Pages\Dashboard` |
| `/admin/accounts` | `panel.admin.pages.accounts` | `AccountsDashboard` |
| `/admin/settings` | `panel.admin.pages.settings` | `App\Panels\Admin\Pages\Settings` |
| `/admin/users` | `panel.admin.resources.users.index` | `ListUsers` |
| `/admin/users/create` | `.create`, `.store` | `CreateUser` |
| `/admin/users/{record}` | `.view` | `ViewUser` |
| `/admin/users/{record}/edit` | `.edit`, `.update` | `EditUser` |
| `/admin/settings/profile` | `panel.admin.pages.settings-profile` | built-in |
| `/admin/settings/security` | built-in | behind `RequirePassword` |
| `/admin/settings/appearance` | built-in | client-side theme |

Plus the shared endpoints every panel gets: `panel.admin.search`, `.options`, `.uploads`, `.form-state`, `.export-file`, `.import-file`, `.notifications.*`, and `.actions.record` / `.bulk` / `.table` / `.infolist` / `.form` / `.submit` / `.cell` / `.reorder`.

The discovered classes are:

| File | What it is |
| --- | --- |
| `Resources/Users/UserResource.php` | the users resource — see [User Resource](user-resource.md) |
| `Pages/Settings.php` | a standalone page with its own Vue component |
| `Pages/AccountsDashboard.php` | the second dashboard |
| `Widgets/UserStats.php` | `StatsWidget`, polls every 60 seconds |
| `Widgets/UserGrowth.php` | `ChartWidget`, lazy, with its own filter |
| `Widgets/RecentUsers.php` | `TableWidget` |
| `Widgets/SystemInfo.php` | `CustomWidget` with a Vue component |

## Behaviour worth turning on

None of these is in the example provider, and each is a deliberate decision rather than a default to copy.

```php
public function databaseTransactions(bool $databaseTransactions = true): self   // on by default
public function strictAuthorization(bool $strictAuthorization = true): self     // off by default
public function unsavedChangesAlerts(bool $unsavedChangesAlerts = true): self   // on by default
public function bootUsing(Closure $callback): self                              // Closure(Panel): void
public function settings(bool $settings = true): self
public function notifications(bool $notifications = true): self
public function broadcasting(bool $broadcasting = true): self
public function globalSearch(bool $enabled = true, int $limit = 50, int $debounce = 300, array $keyBindings = ['mod+k']): self
public function assets(string ...$entrypoints): self
public function prefetch(bool|string $prefetch = 'hover'): self
public function subNavigationPosition(SubNavigationPosition $position): self
```

```php
$panel
    ->strictAuthorization()                       // a missing policy throws instead of denying
    ->globalSearch(limit: 30, keyBindings: ['mod+k', 'ctrl+k'])
    ->assets('resources/css/panels/admin.css')    // must also be in vite.config.ts input
    ->bootUsing(static function (Panel $panel): void {
        // Runs per request, after the access check passes.
    });
```

`strictAuthorization()` is the one worth turning on early: it converts a missing policy, or a policy missing the ability being checked, into a `PanelAuthorizationException` rather than a silent denial that reads like a working rule.

## Verifying it

```bash
php artisan route:list --name=panel.admin
php artisan panel:cache          # writes bootstrap/cache/panels.php
php artisan test --compact --filter=AdminPanelExample
```

`tests/Feature/Panel/AdminPanelExampleTest.php` asserts the dashboard renders with four widgets, that navigation is built from the discovered classes in the declared group order, and that the whole user lifecycle — list, create, view, edit, delete through the action endpoint — works for an administrator.

## Gotchas

- **`panel()` runs during provider boot.** Do not resolve services, read the authenticated user, or generate URLs inside it; request-scoped bindings are not warm yet. Anything needing them goes in `bootUsing()`.
- **An icon that is not in the registry renders nothing, silently.** `php artisan panel:icons` rewrites the registry from the source and fails by name on a Lucide icon that does not exist. `--check` fails instead of writing, for CI.
- **`->assets()` is two edits.** The path must also appear in `vite.config.ts`'s `input`, or the page fails with a manifest error.
- **`canAccess()` is a 403, not a redirect.** A signed-in user who fails it is not sent to a login they are already past.
- **A cached manifest means discovery does not run.** After adding a resource, page, or widget in production, run `php artisan panel:cache` again — `optimize` and `optimize:clear` include it.

## See also

- [App Panel Example](app-panel.md) — the second panel, and what keeps the two isolated
- [User Resource](user-resource.md) — the resource this panel discovers
- [Defining a Panel](../panels/defining-panels.md)
- [Panel API Reference](../panels/api.md)
- [Dashboards](../panels/dashboards.md)
- [Panel Access](../panels/access.md)
- [Discovery](../concepts/discovery.md)
- [Caching](../concepts/caching.md)
- [make:panel](../cli/make-panel.md)
