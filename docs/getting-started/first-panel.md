# Opening your first panel

A panel is one provider class, one line of config, and a URL. This page takes a scaffolded panel
from `make:panel` to a screen you can sign into, and explains every part of the generated provider
so the next panel is a deliberate edit rather than a copy.

## The whole thing

```bash
php artisan make:panel Admin
```

```php
// config/panda-panel.php
'panels' => [
    App\Panels\Admin\AdminPanelProvider::class,
],
```

```bash
php artisan panel:user --panel=admin
php artisan serve
```

Open `/admin`. `panel:install` does all four of those for you; doing them by hand is the same
thing, and is what you will do for the second panel.

## What `make:panel` writes

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
            ->name('Admin')
            ->icon('layout-grid')
            ->auth()
            ->discoverResources(app_path('Panels/Admin/Resources'))
            ->discoverPages(app_path('Panels/Admin/Pages'))
            ->discoverWidgets(app_path('Panels/Admin/Widgets'));
    }
}
```

```text
app/Panels/Admin/
├── AdminPanelProvider.php
├── Pages/.gitkeep
├── Resources/.gitkeep
└── Widgets/.gitkeep
```

The `.gitkeep` files are not decoration: discovery scans those directories, and an empty directory
is not tracked by Git, so without them the provider would point at paths that vanish on clone.

```bash
php artisan make:panel Admin --path=back-office   # a URL prefix that is not the kebab-cased name
php artisan make:panel Admin --force              # overwrite an existing provider
```

## The panel id

```php
public function panelId(): string;   // AdminPanelProvider → 'admin'
public function build(): Panel;      // $this->panel(Panel::make($this->panelId()))
```

`PandaPanel\Core\PanelProvider` derives the id from the class basename: the `PanelProvider` suffix
is dropped and the rest is kebab-cased. The id is what every route name, every middleware
parameter and every `--panel=` option refers to. Call `->id('something-else')` to override it.

Keep service resolution out of `panel()`. It runs during provider boot, before the container is
fully warm for request-scoped bindings.

## The methods in the stub

| Call | Signature | Effect |
| --- | --- | --- |
| `path` | `path(string $path): self` | The URL prefix, with leading and trailing slashes trimmed. Defaults to the panel id when never called. |
| `name` | `name(string $name): self` | The panel's display name. Defaults to `Str::headline($id)`. |
| `icon` | `icon(?string $icon, ?string $darkIcon = null): self` | A Lucide name from the build-time registry. Run `panel:icons` after adding one. |
| `auth` | `auth(bool $verified = true): self` | Appends `auth` and — unless `verified: false` — `verified` to the panel's auth middleware. |
| `discoverResources` | `discoverResources(string ...$paths): self` | Directories to scan for `Resource` subclasses. Accumulates. |
| `discoverPages` | `discoverPages(string ...$paths): self` | Same, for `Page` subclasses. |
| `discoverWidgets` | `discoverWidgets(string ...$paths): self` | Same, for `Widget` subclasses. |

Discovery reads class names from Composer's PSR-4 prefixes rather than by parsing files, includes
only concrete classes implementing the expected contract, and sorts its results so two machines
produce the same manifest. Explicit registration still works and merges with it:

```php
$panel->resources([\App\Panels\Admin\Resources\Users\UserResource::class]);
$panel->pages([\App\Panels\Admin\Pages\Settings::class]);
$panel->widgets([\App\Panels\Admin\Widgets\UserStats::class]);
```

## Filling the panel out

This is the example application's admin panel, and every call in it is one you can add to the
stub:

```php
use App\Models\User;
use App\Panels\Admin\Pages\AccountsDashboard;
use Illuminate\Contracts\Auth\Authenticatable;
use PandaPanel\Pages\Dashboard;

return $panel
    ->path('admin')
    ->name('Administrator')
    ->brandName((string) config('app.name'))
    ->icon('shield')
    ->sidebar(appearance: 'sidebar')
    ->auth()
    ->navigationGroups(['User Management', 'System'])
    ->dashboards([Dashboard::class, AccountsDashboard::class])
    ->discoverResources(app_path('Panels/Admin/Resources'))
    ->discoverPages(app_path('Panels/Admin/Pages'))
    ->discoverWidgets(app_path('Panels/Admin/Widgets'))
    ->canAccess(static fn (?Authenticatable $user): bool => $user instanceof User && $user->is_admin);
```

| Call | Signature | Notes |
| --- | --- | --- |
| `brandName` | `brandName(string $brandName): self` | Shown in the shell. Defaults to `config('app.name')`. |
| `navigationGroups` | `navigationGroups(array $groups): self` | Declares group order. Accumulates; undeclared groups follow. |
| `sidebar` | `sidebar(bool $collapsible = true, bool $defaultOpen = true, string $variant = 'sidebar', string $appearance = 'inset'): self` | `variant: 'header'` swaps the rail for top navigation; `appearance` is `inset`, `floating` or `sidebar`. |
| `dashboard` | `dashboard(string $page): self` | Replaces the page at the panel root. |
| `dashboards` | `dashboards(array $pages): self` | The root page plus extra dashboards, each an ordinary `Page`. |
| `canAccess` | `canAccess(Closure $callback): self` | `fn (?Authenticatable $user): bool`. Asked on every request into the panel. |
| `login` | `login(bool $login = true): self` | Gives the panel guest routes of its own, at its own path. Off by default. |
| `settings` | `settings(bool $settings = true): self` | The three account pages. On by default. |

Access is two questions and both must agree: this closure, and `PanelUser::canAccessPanel()` on
the user model if it implements the contract. A signed-in user who is refused gets a **403**,
never a redirect — hiding navigation is not an access control.

## Registering it

```php
// config/panda-panel.php
'panels' => [
    App\Panels\Admin\AdminPanelProvider::class,
    App\Panels\Support\SupportPanelProvider::class,
],
```

Panels are listed rather than discovered, for two reasons: the application declares its panel set in
one place, and adding a panel should be a deliberate edit rather than a filesystem side effect. When
the request does not name a panel, Panda Panel walks panels by id, not by config order. The classes
*inside* a panel are discovered.

`make:panel` prints the line rather than writing it. `panel:install` writes it — see
[Running panel:install](installer.md) for the four outcomes.

Two panels cannot share an id, and cannot share a path on the same domain:

```php
$panel->domain('admin.example.test');   // then two panels may both use path 'admin'
```

Both collisions raise `PandaPanel\Exceptions\PanelRegistrationException` at boot rather than
producing a route that silently shadows another.

## The URLs you now have

Route names are `panel.{id}.*`, so Wayfinder output and server-side URL generation stay
predictable. For a panel with id `admin` at path `admin`:

| URL | Route name | What it is |
| --- | --- | --- |
| `/admin` | `panel.admin.dashboard` | The panel root, rendering the `Dashboard` page |
| `/admin/search` | `panel.admin.search` | Global search, answering JSON |
| `/admin/options` | `panel.admin.options` | Rows a searchable select could not fit in its first page |
| `/admin/uploads` | `panel.admin.uploads` | A form field's upload, stored before the form is submitted |
| `/admin/form-state` | `panel.admin.form-state` | What a live form should look like now. Nothing is validated or written |
| `/admin/exports/{file}` | `panel.admin.export-file` | A finished export, reachable only by the user who produced it |
| `/admin/imports/{file}` | `panel.admin.import-file` | The rows an import could not accept |
| `/admin/notifications` | `panel.admin.notifications.index` | The notification centre, scoped to the signed-in user |
| `/admin/actions/*` | `panel.admin.actions.*` | `record`, `bulk`, `reorder`, `cell`, `table`, `infolist`, `form` (GET) and `submit` (POST to the same path) |
| `/admin/relations/*` | `panel.admin.relations.*` | `form`, `save`, `action`, `bulk` |
| `/admin/settings/profile` | `panel.admin.pages.settings-profile` | Account pages, unless `settings(false)` |
| `/admin/settings/security` | `panel.admin.pages.settings-security` | Behind `RequirePassword` |
| `/admin/settings/appearance` | `panel.admin.pages.settings-appearance` | |
| `/admin/{slug}` | `panel.admin.resources.{slug}.index` | A resource's list page, and `create`, `view`, `edit` beneath it |
| `/admin/{page-slug}` | `panel.admin.pages.{slug}` | A standalone page |
| `/admin/login` | `panel.admin.auth.login` | Only with `->login()`. Registered *outside* the panel's auth middleware |

Every route points at a controller rather than a closure, so `php artisan route:cache` keeps
working.

```bash
php artisan route:list --name=panel.admin
```

## What renders

`/admin` is `PandaPanel\Pages\Dashboard`: an ordinary `Page` whose widgets come from the panel's
widget registry rather than from a list of its own. That means metadata, breadcrumbs, header
actions, authorization and lazy widgets all behave there exactly as on any other page.

The Vue component is `panel/Dashboard`, published to
`resources/js/pages/panel/Dashboard.vue`. Navigation is built per request from the panel's
registries — there is no hardcoded array anywhere — so a panel with nothing discovered yet renders
a shell with the dashboard and the account pages, and nothing else.

## Adding something to it

```bash
php artisan make:panel-resource Product --panel=Admin
php artisan make:panel-page Reports --panel=Admin
php artisan make:panel-widget Revenue --panel=Admin --type=stats
```

Each is discovered by the paths already in the provider; nothing needs registering. A generated
resource **403s until its model has a policy** — the gate is asked and answers no, which is the
intended default. In development the panel logs which model is missing one, naming the
`make:policy` command.

```bash
php artisan make:policy ProductPolicy --model=Product
```

## Notes

- **A 404 on the panel URL almost always means the provider is not in `panels`.** The provider
  existing is not enough; nothing scans for it.
- **A 403 means one of the two access rules said no**, not that a route is missing. Check
  `canAccess()` first, then `canAccessPanel()` on the user model.
- **`path()` defaults to the id.** `Panel::make('admin')` with no `path()` answers at `/admin`.
- **A panel provider whose class no longer exists is skipped, not fatal.** A boot-time fatal would
  take down every route including the one that would have shown the error; `panel:cache` reports
  the same list where a mistake is actually visible.
- **After `panel:cache`, discovery does not run.** A resource added afterwards has no route, no
  navigation entry and no error. The manifest records a fingerprint of the discovery paths and
  warns in development when it is stale; `php artisan panel:clear` is the fix.

## See also

- [Directory structure](directory-structure.md) — where everything the panel generates lives
- [Creating the first user](first-user.md) — an account that can actually sign in
- [Common install problems](common-install-problems.md)
- [Panels: defining panels](../panels/defining-panels.md),
  [ids, paths and domains](../panels/ids-paths-domains.md),
  [access](../panels/access.md), [dashboards](../panels/dashboards.md)
- [Resources: creating resources](../resources/creating-resources.md)
- [Widgets](../widgets/overview.md), [Custom pages](../pages-navigation/custom-pages.md)
- [Concepts: routing](../concepts/routing.md), [discovery](../concepts/discovery.md),
  [caching](../concepts/caching.md)
- [CLI: make:panel](../cli/make-panel.md), [panel:cache](../cli/panel-cache.md)
- [Troubleshooting: panel routes 404](../troubleshooting/panel-routes-404.md)
