# Overview

Panda Panel is an admin panel framework for Laravel 12 and 13, built on Inertia 3, Vue 3 and
Tailwind 4. PHP owns registration, routing, authorization, query composition, validation and
metadata; Vue owns rendering; Inertia is the only bridge. Reach for it when an application needs
resource CRUD, tables with server-side search, sorting, filtering and pagination, forms with real
validation, dashboards and standalone pages — and needs more than one such panel, each with its own
path, navigation, middleware and access rule.

## A panel from nothing

```bash
composer require chocoalano/panel
php artisan panel:install
```

`panel:install` publishes the config and the frontend, scaffolds a first panel, writes it into
`config/panda-panel.php`, checks what the frontend still needs, and offers to create an account that
can sign in. What it wrote:

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

A generated panel has no `->canAccess()`, so every signed-in user may enter it. Add the predicate
before the panel holds anything worth guarding:

```php
use Illuminate\Contracts\Auth\Authenticatable;
use App\Models\User;

->canAccess(static fn (?Authenticatable $user): bool => $user instanceof User && $user->is_admin)
```

```php
// config/panda-panel.php
'panels' => [
    App\Panels\Admin\AdminPanelProvider::class,
],
```

Add a resource and it is routed, navigable and authorized without another edit:

```bash
php artisan make:panel-resource User --panel=Admin
npm run build
```

That writes `app/Panels/Admin/Resources/Users/UserResource.php` with its list, create, view and
edit pages, its table and its form. The panel discovers it because the class sits under a declared
discovery path. `/admin/users` now exists.

## The two namespaces

| Namespace | What lives there |
| --- | --- |
| `PandaPanel\*` | the framework: `Panel`, `Resource`, `TableSchema`, `FormSchema`, `Action`, `Widget`, the middleware, the controllers |
| `App\Panels\*` | your panels: providers, resources, pages, widgets, relation managers |

Nothing in your application extends a controller or registers a route for a panel screen. You write
declarations; the framework routes them.

## The panel is the unit

A panel is one addressable admin area. It has an id, a path, an optional domain, a middleware stack,
navigation groups, branding, an access predicate, and its own registries of resources, pages and
widgets. Two panels at `/admin` and `/app` share a session and a user model and nothing else: a
resource registered in one has no route in the other, and `Resource::url()` throws when asked for a
URL in a panel that does not register it.

A panel provider configures exactly one panel:

```php
namespace PandaPanel\Core;

abstract class PanelProvider
{
    abstract public function panel(Panel $panel): Panel;

    public function panelId(): string;   // AdminPanelProvider → 'admin'

    public function build(): Panel;      // panel(Panel::make($this->panelId()))
}
```

`panelId()` derives the id from the class name, so `AdminPanelProvider` is the `admin` panel. Call
`->id('back-office')` inside `panel()` to override it.

Panels are listed in config rather than discovered. Registration order is visible in one place, and
it decides where a signed-in user lands when the request does not name a panel. The classes *inside*
a panel are discovered, because listing every resource by hand is the boilerplate worth removing.

## Reaching a panel at runtime

```php
use PandaPanel\Core\Panel;

panel();          // Panel|null — the panel for this request, null outside one
panel('admin');   // Panel — throws PanelRegistrationException when unknown
```

`panel()` is autoloaded through composer's `files`, so no import is needed. For everything past
"which panel am I in", the facade wraps `PandaPanel\Core\PanelManager`:

```php
use PandaPanel\Facades\PandaPanel;

PandaPanel::all();                             // list<Panel>, sorted by id
PandaPanel::has('admin');                      // bool
PandaPanel::get('admin');                      // Panel, throws when unknown
PandaPanel::currentPanel();                    // Panel|null
PandaPanel::setCurrentPanel(panel('admin'));   // void
PandaPanel::resolveFromRequest($request);      // Panel|null, longest path wins
PandaPanel::firstAccessibleTo($request->user());
PandaPanel::resources($panel);                 // ResourceRegistry
PandaPanel::pages($panel);                     // PageRegistry
PandaPanel::widgets($panel);                   // WidgetRegistry
PandaPanel::navigation($panel);                // NavigationRegistry
```

`register(Panel $panel)` and `registerProvider(string $provider)` exist too, for a package that adds
a panel from its own service provider rather than from application config.

## What a request does

```text
request
  ↓  web middleware        ResetPanelContext clears any previous panel
  ↓  panel route group     panel middleware, then ResolvePanel:{id}
  ↓  PanelContext          the current panel, request-scoped
  ↓  page or resource page authorize → build metadata → serialize
  ↓  Inertia               shared props (panel, navigation) + page props
  ↓  Vue                   PanelLayout → page component → renderers
```

Every panel route points at a real controller, never a closure, so `php artisan route:cache` keeps
working. Route names are `panel.{id}.*`: `panel.admin.dashboard`, `panel.admin.resources.users.index`,
`panel.admin.actions.record`. [Architecture at a Glance](architecture.md) walks the whole path.

## Configuration

`config/panda-panel.php`, published by `panel:install` or by
`vendor:publish --tag=panda-panel-config`:

| Key | Default | What it decides |
| --- | --- | --- |
| `panels` | `[]` | The panel providers to register, in order. |
| `register_routes` | `true` | Register one route group per panel during boot. |
| `register_web_middleware` | `true` | Append the package's four `web` middleware to the group. |
| `register_guest_redirect` | `true` | Send a guest who opens a panel URL to that panel's own login. |
| `home_redirect.enabled` | `true` | Send a signed-in user landing on the starter kit dashboard into the first panel they can enter. |
| `home_redirect.paths` | `['dashboard']` | The `Request::is()` patterns that redirect. |
| `load_migrations` | `true` | Run the package migrations from the package. |
| `integrations.allowed_hosts` | `[]` | Allowlist for outbound integration requests. Empty means nothing is reachable. |
| `integrations.block_private_networks` | `true` | Refuse hosts resolving into private, loopback or link-local ranges. |
| `integrations.history.enabled` | `true` | Record one row per delivery attempt. |
| `integrations.history.keep_per_integration` | `50` | Hard cap on retained rows per integration. |
| `integrations.history.retention_days` | `30` | Window for retained rows. `0` keeps the cap only. |
| `frontend.panel_path` | `js/panel` | Where `vendor:publish` puts the panel components. |
| `frontend.pages_path` | `js/pages/Panels` | Where the generators scaffold Vue components. |

Everything else — paths, domains, middleware, navigation, branding, access — is configured in code,
because those decisions have logic in them.

## Commands

```bash
php artisan panel:install                                    # publish, scaffold, register, verify
php artisan make:panel Admin --path=back-office
php artisan make:panel-resource User --panel=Admin --soft-deletes
php artisan make:panel-page Reports --panel=Admin --component
php artisan make:panel-widget Revenue --panel=Admin --type=chart
php artisan make:panel-relation-manager posts --panel=Admin --resource=Users
php artisan panel:user --name=Ada --email=ada@example.com --panel=Admin

php artisan panel:cache      # discover once at deploy time
php artisan panel:clear
php artisan panel:icons      # rewrite the icon registry from declared icons
php artisan panel:plugins    # what is installed, on which panel, at which version
php artisan panel:assets     # which published components are behind
php artisan panel:publish    # copy a plugin's assets into the application
```

`panel:cache` and `panel:clear` are registered as `optimize` hooks, so a deploy already running
`php artisan optimize` gets the panel manifest with it.

## Publish tags

```bash
php artisan vendor:publish --tag=panda-panel-config
php artisan vendor:publish --tag=panda-panel-assets
php artisan vendor:publish --tag=panda-panel-migrations
php artisan vendor:publish --tag=panda-panel-stubs
php artisan vendor:publish --tag=panda-panel        # config + migrations + assets
```

The frontend is published rather than imported from the package, because every component registry is
an `import.meta.glob` allowlist over the application's own tree — a component the build never saw
cannot resolve. That makes the panel's Vue files yours: in your repository, in your build, and
editable. `panel:assets` is what keeps them current afterwards.

## Requirements in one line

PHP 8.2+ (8.2 through Laravel 12), Laravel 12 or 13, `inertiajs/inertia-laravel` 3.x, Fortify 1.37+,
Vue 3.5+, Vite 7, Tailwind 4.1+, and a Laravel Vue starter kit — or the eighteen frontend modules
one provides. The full matrix, including what is deliberately unsupported, is in
[Compatibility Matrix](../getting-started/compatibility.md).

## Notes

- A freshly generated resource returns 403 until its model has a policy. The gate is asked and
  answers no. A panel that showed every record because nobody had written a rule yet would be worse.
- Panel providers listed in config that no longer resolve are skipped rather than fataling during
  boot, so a renamed class leaves the application reachable. `panel:cache` reports the same list
  where the mistake is visible.
- A panel registered twice in `panels` is registered once: the registry keys by id, and running
  discovery again would change nothing.
- Two panels may not share an id, or a path/domain pair. Both throw
  `PandaPanel\Exceptions\PanelRegistrationException` at boot rather than letting one route silently
  shadow another.
- `panel()` returns `null` outside a panel — a queued job, a console command, an application route.
  Code that must run inside one asks for it by id.

## See also

- [Why Panda Panel](why-panda-panel.md) — the problems this shape solves
- [Feature Overview](features.md) — everything that exists, by class name
- [Architecture at a Glance](architecture.md) — the request path in detail
- [Inertia and Vue Approach](inertia-vue.md) — what crosses the wire, and what renders it
- [Comparison With Filament Concepts](filament-comparison.md) — what was borrowed, what was not
- [Package Limits and Tradeoffs](tradeoffs.md) — the costs, stated
- [Installation](../getting-started/installation.md) and [Running panel:install](../getting-started/installer.md)
- [Defining Panels](../panels/defining-panels.md) — the full `Panel` API
- [Creating Resources](../resources/creating-resources.md)
