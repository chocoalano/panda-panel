# Multi-Panel Applications

More than one panel in one application: an `/admin` for staff and an `/app` for customers, a reporting panel on its own subdomain, a back office that shares a model with the customer-facing side but shows a narrower slice of it. Panels are isolated by construction — each has its own registries, its own routes and its own access rule — so the work is in deciding what they share, not in keeping them apart.

## Two panels

```php
// config/panda-panel.php

'panels' => [
    App\Panels\Admin\AdminPanelProvider::class,
    App\Panels\App\AppPanelProvider::class,
],
```

```php
<?php

declare(strict_types=1);

namespace App\Panels\App;

use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelProvider;

final class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->path('app')
            ->name('Application')
            ->icon('layout-grid')
            ->auth()
            ->discoverResources(app_path('Panels/App/Resources'))
            ->discoverPages(app_path('Panels/App/Pages'))
            ->discoverWidgets(app_path('Panels/App/Widgets'));
    }
}
```

Each panel discovers from its own directory tree. That is the whole isolation mechanism: `app/Panels/Admin/Resources` is Admin's and nothing else's.

## Registration order decides the default panel

The order in `config/panda-panel.php` is the order `PanelManager::firstAccessibleTo()` walks:

```php
use PandaPanel\Core\PanelManager;

app(PanelManager::class)->firstAccessibleTo(request()->user());   // ?Panel
```

Two things use it, and both matter on the first screen after signing in:

- `RedirectPanelHome` sends a signed-in user who lands on `/dashboard` into the first panel they can enter — see [Home Redirect](../configuration/home-redirect.md).
- The starter kit's `/settings/*` addresses redirect into the same panel.

So with the ids `admin` and `app`, id order considers Admin before App: an administrator lands on `/admin` and a plain user — refused Admin — lands on `/app`. Renaming a panel id can reorder that answer, which is why ids are routing policy.

`PanelManager::all()` is sorted by id, not by config order, and both route registration and `firstAccessibleTo()` walk that order so the result is stable across machines.

## What is isolated

Everything a panel registers is scoped to it.

```php
$manager = app(PanelManager::class);

$manager->resources('admin')->all();    // list<class-string>
$manager->pages('app')->all();
$manager->widgets('admin')->all();
$manager->navigation('admin');          // NavigationRegistry
```

- A resource registered in Admin has no route in App. `/app/users` is a 404, not a 403.
- A page slug registered in one panel is not registered in the other.
- The action endpoints exist on every panel, but the resource named in the payload is resolved against *that panel's* registry. Addressing an Admin resource through `/app/actions/record` answers 404 — the resource does not exist there, whatever the session is.
- Widgets are per panel, so a dashboard only ever shows its own.
- `Resource::url(panel: 'app')` throws `PanelRegistrationException` when the resource is not registered in that panel, rather than producing a URL to a route that does not exist.

```php
use App\Panels\Admin\Resources\Users\UserResource;

UserResource::url(panel: 'admin');   // '/admin/users'
UserResource::url(panel: 'app');     // throws: not registered in the panel [app]
```

## Sharing one resource between panels

Registering the same class in two panels is supported, and `PandaPanel\Resources\ResourceConfiguration` is how each panel says what the class means there: a different slug, a different place in the sidebar, a narrower query.

```php
use App\Panels\Admin\Resources\Users\UserResource;
use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Resources\ResourceConfiguration;

$panel->resources([
    ResourceConfiguration::for(UserResource::class)
        ->slug('people')
        ->pluralLabel('People')
        ->navigationLabel('Directory')
        ->navigationGroup('Company')
        ->navigationIcon('building-2')
        ->navigationSort(99)
        ->modifyQueryUsing(
            static fn (Builder $query): Builder => $query->where('is_admin', false),
        ),
]);
```

The same class is now `/admin/users` in one panel and `/directory/people` in the other, and the second panel cannot read an administrator row at all — `modifyQueryUsing()` narrows `Resource::query()`, which every read goes through, so a record outside it is a 404 rather than a filtered row.

Rules worth knowing:

- Configurations are registered before bare classes, so a class configured for a panel is never also registered under its default slug.
- One class may not be registered twice inside a single panel. A panel keys resources by slug, and `Resource::url()` would have no way to say which registration a link meant.
- Route names still use the panel's slug for that resource: `panel.directory.resources.people.index`.

See [Per-Panel Configuration](../resources/per-panel-configuration.md) for the full option list.

## The switcher

Every panel page ships the panels the current user may enter as the `panels` shared prop, built by `SharePanelData`:

```php
[
    'id' => 'admin',
    'name' => 'Administrator',
    'brandName' => 'Acme',
    'path' => '/admin',
    'icon' => 'shield',
    'url' => '/admin',
    'current' => true,
]
```

The list is filtered by `Panel::isAccessibleTo()` — the same predicate the routes enforce — so a panel the user would be refused never appears as somewhere to go. Outside a panel the list is empty. The header control hides itself when the user may enter only one panel, because offering a move to where somebody already is is not a control.

```ts
import { usePanel } from '@/panel/composables/usePanel';

const { panels, canSwitchPanels } = usePanel();   // canSwitchPanels is panels.length > 1
```

See [Panel Switcher](panel-switcher.md).

## Splitting by domain

Panels may share a path when their domains differ, which is how a central panel and a per-tenant panel coexist:

```php
$admin->domain('admin.example.com')->path('/');
$app->domain('{team}.example.com')->path('/');
```

`PanelManager::resolveFromRequest()` skips any panel whose domain does not match the request host, so the two never contend. Registering two panels on the same path *and* the same domain throws at boot.

## Two panels, one user model

Panel access is asked twice and both answers must agree: the panel's own `canAccess()` predicate, and the user model's `PanelUser::canAccessPanel()`. With several panels, the second is usually where the account-level rules live — a suspended account is suspended everywhere, and writing that on the model means it cannot be forgotten when a fourth panel is added.

```php
use PandaPanel\Contracts\PanelUser;
use PandaPanel\Core\Panel;

final class User extends Authenticatable implements PanelUser
{
    public function canAccessPanel(Panel $panel): bool
    {
        return ! $this->suspended;
    }
}
```

See [Panel Access Rules](access.md).

## Notes

- Two panels sharing a build share one frontend bundle. Per-panel styling is `colors()`, `cssHooks()` and `assets()` rather than a second build — see [Branding](branding.md) and [Panel Assets](assets.md).
- The panel manifest holds a section per panel, so `php artisan panel:cache` caches all of them in one file. Adding a panel means re-running it.
- `ResetPanelContext` clears the current panel at the start of every web request, so nothing leaks between two panels inside one Octane worker or one test.
- Settings pages are per panel by default, which is why `/admin/settings/profile` and `/app/settings/profile` both exist and render in their own shells. `settings(false)` removes them from a panel that has no business showing them.

## See also

- [Defining a Panel](defining-panels.md)
- [Panel IDs, Paths, and Domains](ids-paths-domains.md)
- [Panel Switcher](panel-switcher.md)
- [Panel Access Rules](access.md)
- [Settings Pages](settings-pages.md)
- [Per-Panel Configuration](../resources/per-panel-configuration.md)
- [Tenancy Concepts](../tenancy/concepts.md)
- [Panel Context](../concepts/panel-context.md)
