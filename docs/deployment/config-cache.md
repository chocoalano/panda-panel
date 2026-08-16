# Config Cache

`php artisan config:cache` compiles every configuration file into one PHP array
and stops the rest from being read. The panel is compatible with it without any
special handling — nothing in the package calls `env()` outside a config file,
and `config/panda-panel.php` holds no closures. This page is what the panel
reads, when it reads it, and the two ways a cached config can still surprise you.

## A minimal working example

```bash
php artisan config:cache
```

Or, as part of the whole set:

```bash
php artisan optimize          # config, routes, events, views — and panel:cache
```

Verify the panel still resolves after caching:

```bash
php artisan config:show panda-panel
php artisan route:list --path=admin
```

## What the panel reads from config

Two namespaces, and that is the whole surface.

### `panda-panel.*`

| Key | Default | Read by | When |
| --- | --- | --- | --- |
| `panels` | `[]` | `PandaPanelServiceProvider::configuredPanels()` | boot |
| `register_routes` | `true` | `registerRoutes()` | boot |
| `register_web_middleware` | `true` | `registerMiddleware()` | boot |
| `register_guest_redirect` | `true` | `registerGuestRedirect()` | boot |
| `load_migrations` | `true` | `registerMigrations()` | boot |
| `home_redirect.enabled` | `true` | `PandaPanel\Support\PanelHomeRedirect` | per request |
| `home_redirect.paths` | `['dashboard']` | same | per request |
| `integrations.allowed_hosts` | `[]` | `PandaPanel\Integrations\OutboundUrl` | on save, and before each request |
| `integrations.block_private_networks` | `true` | same | same |
| `integrations.history.enabled` | `true` | `PanelIntegrationDelivery::enabled()` | after each delivery |
| `integrations.history.keep_per_integration` | `50` | `PanelIntegrationDelivery::prune()` | after each delivery |
| `integrations.history.retention_days` | `30` | same | same |
| `frontend.panel_path` | `'js/panel'` | `PandaPanel\Support\FrontendPaths::panel()` | publishing, generators, `panel:icons` |
| `frontend.pages_path` | `'js/pages/Panels'` | `FrontendPaths::pages()` | same |

### Laravel's own

| Key | Read by | For |
| --- | --- | --- |
| `broadcasting.default` | `PandaPanel\Support\BroadcastSupport::isConfigured()` | whether a channel is shared with the frontend at all |
| `broadcasting.connections.{default}.driver` | same | `null` and `log` count as no broadcaster |
| `app.name` | `PandaPanel\Core\Panel::getBrandName()` | the brand name a panel that never called `brandName()` shows in its shell |

Nothing else. There are no `PANDA_*` environment variables, because every
setting is a literal in the config file rather than an `env()` call.

## Why the panel is safe to cache

Three properties, each of which is a common reason a package breaks under
`config:cache`.

**No closures in the config file.** `config:cache` `var_export`s the merged
array; a closure anywhere in it throws `LogicException: Your configuration files
are not serializable`. `config/panda-panel.php` is scalars, arrays of strings,
and `::class` constants — which are resolved to strings when the file is parsed,
not held as references.

```php
// config/panda-panel.php
'panels' => [
    App\Panels\Admin\AdminPanelProvider::class,   // a string by the time it is cached
],
```

**No `env()` outside config.** A cached config never re-reads `.env`, so a
package calling `env()` from a service returns `null` in production and works
perfectly in development. The package calls it nowhere: every read goes through
`config()` or `Config::get()`.

**Every nested read site carries its own default.** A published config that
predates a key still works:

```php
Config::get('panda-panel.integrations.history.keep_per_integration', 50);
Config::get('panda-panel.integrations.block_private_networks', true);
config('panda-panel.home_redirect', []);
```

That matters because nesting is where `mergeConfigFrom` stops helping — see the
next section. The four top-level boot switches are read without a default
(`get('panda-panel.register_routes') !== true` and its three siblings), and they
do not need one: a top-level key missing from a published file is supplied by
the merge.

Between them, that is what lets an application upgrade the package without
re-publishing the config file.

## The shallow-merge gotcha

The provider merges the package's defaults under the application's:

```php
// PandaPanelServiceProvider::register()
$this->mergeConfigFrom($this->packagePath('config/panda-panel.php'), 'panda-panel');
```

Laravel's `mergeConfigFrom` is a **top-level** `array_merge`. A published
`config/panda-panel.php` that defines `integrations` replaces the package's
`integrations` entirely — it does not merge into it key by key. Drop
`history.retention_days` from a published file and the merged config has no such
key at all.

That is survivable here only because the read sites default. It is still worth
knowing, because it is why a published config is a file you maintain rather than
one that quietly gains new keys on upgrade.

Two consequences for a deploy:

- **Publishing the config is optional.** Without it, `mergeConfigFrom` supplies
  every default and `config:cache` bakes them in. `mergeConfigFrom` is skipped
  when configuration is already cached, but `config:cache` itself boots the
  application first — so the merged values are what gets written.
- **After upgrading the package, diff your published config against
  `vendor/chocoalano/panel/config/panda-panel.php`.** New keys do not appear on
  their own.

## Registering panels with a cached config

`panels` is a list of provider class names, in order, and the order decides which
panel a user is sent to when the request does not name one.

```php
// config/panda-panel.php
'panels' => [
    App\Panels\Admin\AdminPanelProvider::class,
    App\Panels\App\AppPanelProvider::class,
],
```

A class name that no longer resolves is skipped rather than fatal:

```php
foreach ($configured as $provider) {
    if (is_string($provider) && is_subclass_of($provider, PanelProvider::class)) {
        $panels[] = $provider;
    }
}
```

A fatal here would happen before any route exists, including the one that would
have shown the error. Skipping leaves the application reachable — and
`panel:cache` reports the same list where the mistake is actually visible, in a
count that is one lower than expected.

`PandaPanel\Support\Installer\PanelRegistrar` is what `panel:install` uses to add
an entry:

```php
use PandaPanel\Support\Installer\PanelRegistrar;

PanelRegistrar::register(App\Panels\Admin\AdminPanelProvider::class);
// static register(string $provider, ?string $path = null): string
```

| Return | Meaning |
| --- | --- |
| `PanelRegistrar::REGISTERED` | added to the `panels` list |
| `PanelRegistrar::ALREADY_PRESENT` | it was already there |
| `PanelRegistrar::NO_CONFIG` | `config/panda-panel.php` has not been published |
| `PanelRegistrar::UNRECOGNISED` | the `panels` block was reshaped, so the file was left alone |

It edits a file, so it belongs to installation rather than to a deploy. On a
machine with a cached config the edit has no effect until the cache is rebuilt.

## Turning the boot-time switches off

All four are read once, during boot, and are the reason an application can host
the package without accepting its defaults:

```php
// config/panda-panel.php
'register_routes' => false,             // register the panel route groups yourself
'register_web_middleware' => false,     // place ResetPanelContext / ShareFlashToast yourself
'register_guest_redirect' => false,     // you call redirectGuestsTo() in bootstrap/app.php
'load_migrations' => false,             // you published the migrations and own them
```

Each has a page: [Routes](../configuration/routes.md),
[Middleware](../configuration/middleware.md),
[Guest redirect](../configuration/guest-redirect.md),
[Migrations](../configuration/migrations.md).

`register_web_middleware => false` is the one with a production consequence
worth naming. `ResetPanelContext` clears the resolved panel at the start of every
web request; without it, and without registering it yourself, panel state
survives from one request to the next inside a long-lived worker. See
[Octane](octane.md).

## Clearing it

```bash
php artisan config:clear
php artisan optimize:clear     # config, routes, views, events — and panel:clear
```

A rollback that leaves a cached config from the release it rolled back from is
the same class of problem as a shared `bootstrap/cache`. See
[Rollbacks](rollbacks.md).

## Gotchas

- **`config:cache` freezes `.env`.** That is Laravel's behaviour, not the
  panel's, and it applies to `BROADCAST_CONNECTION` and `QUEUE_CONNECTION` like
  everything else: change one, re-cache.
- **A published config does not gain new keys on upgrade.** Diff it after every
  package update.
- **`mergeConfigFrom` merges only the top level.** Replacing `integrations`
  replaces all of it.
- **Panel providers named in `panels` that do not resolve are skipped
  silently.** The count printed by `panel:cache` is where that shows up.
- **`config:cache` is not `panel:cache`.** Two separate caches, both included in
  `optimize`. See [Panel cache](panel-cache.md).
- **Nothing panel-related is stored in the config cache.** The manifest is its
  own file, and no resolved metadata is cached anywhere.

## See also

- [Production checklist](production-checklist.md)
- [Panel cache](panel-cache.md), [Route cache](route-cache.md), [Rollbacks](rollbacks.md)
- [Configuration reference](../configuration/panda-panel.md)
- [Routes](../configuration/routes.md), [Middleware](../configuration/middleware.md), [Guest redirect](../configuration/guest-redirect.md), [Home redirect](../configuration/home-redirect.md), [Migrations](../configuration/migrations.md), [Frontend paths](../configuration/frontend-paths.md)
- [Environment variables](../configuration/environment.md)
- [Caching](../concepts/caching.md)
