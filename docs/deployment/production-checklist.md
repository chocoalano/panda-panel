# Production Checklist

Everything that has to happen between a merge and a working panel, in the order
it has to happen. Reach for this page when writing a deploy script for the first
time, or when a deploy left something behind — a resource that vanished from the
sidebar, a button that draws no icon, a toast that never arrives.

## A minimal working example

A complete deploy for an application with panels installed:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader

php artisan migrate --force

npm ci
npm run build

php artisan optimize          # config, routes, events, views — and panel:cache

php artisan queue:restart
```

Five steps and one restart. Everything below is why each one is there, and what
it costs to skip.

## The order, and why it is the order

| Step | Depends on the previous because |
| --- | --- |
| `composer install` | nothing — it is first |
| `migrate --force` | the migrations are the new code's |
| `npm ci && npm run build` | the components published into `resources/js` are the new code's too |
| `optimize` | `panel:cache` resolves file paths through Composer's PSR-4 map, so a manifest written before `composer install` names classes that have moved |
| `queue:restart` | a worker started before the deploy is running the old code, including the old panel manifest |

Two of those orderings are load-bearing and are worth stating twice. **Cache
after Composer, never before.** And **restart the workers last**, because a
worker that restarts in the middle of a deploy comes back holding half of it.

## Step 1: Composer

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
```

`--no-dev` is safe: the package's runtime dependencies are `laravel/framework`,
`inertiajs/inertia-laravel`, `laravel/fortify`, `symfony/finder`,
`composer/semver`, `composer-runtime-api`, and the `json` and `zip` extensions.
Nothing in `src/` reaches for a `require-dev` package.

`ext-zip` is a hard requirement rather than a soft one: XLSX exports and imports
are ZIP containers. A server without it fails at `composer install` rather than
at the first export, which is the correct place to find out.

See [Composer and autoloading](composer.md).

## Step 2: Migrations

```bash
php artisan migrate --force
```

The package ships four migrations and runs them from the package by default:

| Migration | Why the panel needs it |
| --- | --- |
| `create_notifications_table` | the notification bell counts unread rows on every panel request |
| `add_email_two_factor_to_users_table` | the `two_factor_email_confirmed_at` column the emailed-code factor reads |
| `create_panel_integrations_table` | only used by resources that enable integrations |
| `add_history_and_signing_to_panel_integrations` | delivery history and request signing |

All four check before they touch anything, so an application that already has
the table or the column is untouched. `load_migrations` in
`config/panda-panel.php` turns the package's copy off for a project that
published them with `vendor:publish --tag=panda-panel-migrations` and wants to
own them.

`SharePanelData` catches a `QueryException` when counting unread notifications
and shares `0`, so a panel whose migrations have not run yet renders rather than
500s. That is a safety net for a half-finished install, not a reason to skip the
step.

## Step 3: Build the frontend

```bash
npm ci
npm run build
```

The panel's Vue components live in the application's `resources/js`, not in
`vendor`. They are compiled by the application's Vite, into the application's
bundle, alongside its own entrypoints. Nothing in `vendor/chocoalano/panel`
ships a built asset.

That means a deploy that skips the build serves the previous bundle against the
new server metadata — and the failure is a Vue component that does not know
about a field type the PHP now sends. See [Frontend build](frontend-build.md).

## Step 4: Optimize

```bash
php artisan optimize
```

| Included | Command |
| --- | --- |
| Configuration | `config:cache` |
| Routes | `route:cache` |
| Events | `event:cache` |
| Views | `view:cache` |
| **Panels** | **`panel:cache`** |

The panel registers its two commands as `optimize` hooks under the key `panels`:

```php
// PandaPanelServiceProvider::registerCommands()
$this->optimizes(
    optimize: 'panel:cache',
    clear: 'panel:clear',
    key: 'panels',
);
```

So a deploy script that already has `php artisan optimize` needs no new line,
and `php artisan optimize:clear` removes the manifest along with everything
else. Run them individually if you prefer to see each one:

```bash
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan view:cache
php artisan panel:cache
```

```text
INFO  Panels cached: 2 panels, 1 resources, 5 pages, 4 widgets.
```

Each of those has a page: [Config cache](config-cache.md),
[Route cache](route-cache.md), [Panel cache](panel-cache.md).

## Step 5: Restart the long-lived processes

```bash
php artisan queue:restart
```

Anything that stays resident is holding a copy of the code the deploy just
replaced:

| Process | How it picks up a deploy |
| --- | --- |
| `queue:work` | `queue:restart` — the worker finishes its current job and exits, and the supervisor starts a new one |
| Octane | `php artisan octane:reload` |
| Reverb | restart under its supervisor; a broadcaster holds no application code, but its config comes from `.env` |
| Scheduler | nothing — `schedule:run` is a fresh process each minute |

A worker running old code with a new database is the failure that produces
"the export finished but the download 404s". See [Queues](queues.md) and
[Octane](octane.md).

## Environment

Outside its own namespace the panel reads three keys at runtime:
`broadcasting.default`, the `driver` of the connection that names, and
`app.name` — the brand name a panel that never called `brandName()` shows in
its shell. There are no `PANDA_*` environment variables — every setting is a
literal in `config/panda-panel.php`, so `.env` matters only where Laravel's own
does.

| Variable | Matters because |
| --- | --- |
| `APP_ENV`, `APP_DEBUG` | the staleness warning and the missing-policy notice are development-only, keyed on `hasDebugModeEnabled()` or `environment('local', 'testing')`. The frontend's unknown-icon warning is gated on `import.meta.env.DEV` instead, so neither variable reaches it |
| `APP_URL` | queued jobs build notification URLs; the package's own use `absolute: false` for exactly this reason |
| `QUEUE_CONNECTION` | `sync` in production means an export runs inside the request that asked for it |
| `BROADCAST_CONNECTION` | `null` or `log` makes `BroadcastSupport::isConfigured()` false, and the panel silently stops sending a channel |
| `VITE_*` | compiled into the bundle at build time, so changing one means rebuilding, not restarting |

## What the panel needs that Laravel will not check for you

Four things, none of which produces an error when it is missing.

### An up-to-date icon registry

`resources/js/panel/icons/registry.ts` is a build-time allowlist. A name that is
not in it renders nothing at all, with no error in production.

```bash
php artisan panel:icons --check
```

Run it in CI rather than in the deploy: it compares a tracked file against what
it would write, and a deploy is the wrong place to discover a tracked file is
wrong. See [Icon registry](icon-registry.md).

### Published assets that are not years behind

```bash
php artisan panel:assets
```

Reports which published components are out of date, which the application has
edited, and which are both. It never fails a build — a conflict is something a
person has to look at, not a broken deploy. See
[Updating assets](../frontend/updating-assets.md).

### At least one account that can enter a panel

```bash
php artisan panel:user --name=Ada --email=ada@example.com --panel=admin
```

The command always reports whether the account it created can reach a panel;
`--panel` chooses which one, and without it the report is for the first
registered panel. The question it asks is `Panel::isAccessibleTo()` — your user
model's `canAccessPanel()` and the panel's own `canAccess()`, both of which have
to agree — and the warning names whichever of the two said no, which a fresh
account usually makes one of them do.

### A record of what is installed

```bash
php artisan panel:plugins
```

```text
+-------+---------+--------------+---------------------+---------+----------+
| Panel | ID      | Name         | Package             | Version | Requires |
+-------+---------+--------------+---------------------+---------+----------+
| admin | audit   | Audit Log    | acme/panel-audit    | 1.4.1   | any      |
+-------+---------+--------------+---------------------+---------+----------+
```

Versions come from Composer's own installed-packages data rather than from
anything a plugin author remembered to bump.

## Verifying a deploy

```bash
php artisan route:list --path=admin      # the panel's routes registered
php artisan panel:plugins                # what is installed, at which version
php artisan queue:monitor default:100    # the queue is being drained
```

From `tinker`, the three questions that between them explain most "it looks
installed but does not work" reports:

```php
use PandaPanel\Cache\PanelManifest;
use PandaPanel\Core\PanelManager;
use PandaPanel\Support\BroadcastSupport;

app(PanelManifest::class)->exists();                             // is the manifest there
app(PanelManager::class)->resources('admin')->all();             // what the panel actually holds
BroadcastSupport::isConfigured();                                // can anything reach a browser
```

## What is deliberately never cached

| Cached | Recomputed every request |
| --- | --- |
| Resource, page and widget class names, per panel | Authorization results |
| The discovery fingerprint | Navigation visibility and active state |
| | Badge values |
| | Record data, table rows, widget data |

Every one of those depends on the current user or the current URL. Caching them
would serve one person's answers to everybody, which is a security failure
rather than a stale screen. There is no configuration that turns it on.

## The whole thing, as a script

```bash
#!/usr/bin/env bash
set -euo pipefail

php artisan down --render="errors::503"

composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction

php artisan migrate --force

npm ci
npm run build

php artisan optimize

php artisan queue:restart

php artisan up
```

Under Octane, add `php artisan octane:reload` after `optimize` and before `up`.

## Gotchas

- **`optimize` after `composer install`, always.** Discovery resolves file paths
  through Composer's PSR-4 map. A manifest written against the old autoloader
  names classes that are no longer where it says.
- **A deploy that copies code but not `bootstrap/cache/`** leaves the previous
  release's manifest in place. Everything added since is invisible: no route, no
  navigation entry, no error. The staleness warning that catches this in
  development is deliberately off in production.
- **`APP_DEBUG=true` in production does more than leak stack traces here.** It
  turns on the manifest staleness check, which costs a `stat` per PHP file under
  every discovery path, on every boot.
- **`npm run build` is not optional after a package upgrade.** New server
  metadata against an old bundle is a renderer that has never heard of the shape
  it was handed.
- **`QUEUE_CONNECTION=sync` hides every queue problem** until the first export
  large enough to cross `Exporter::queueAfter()` times out a web request.
- **Nothing here writes compiled assets to `public/`.** But `FileUpload`
  defaults to the `public` disk, and its preview URL is built from
  `Storage::disk('public')->url()` — so any panel with a file field needs
  `storage:link`, on every release directory. See [Storage setup](storage.md).

## See also

- [Composer and autoloading](composer.md)
- [Panel cache in production](panel-cache.md), [Config cache](config-cache.md), [Route cache](route-cache.md)
- [Frontend build](frontend-build.md), [Icon registry](icon-registry.md)
- [Queues](queues.md), [Broadcasting server](broadcasting.md), [Octane](octane.md)
- [Storage setup](storage.md), [Monitoring](monitoring.md), [Rollbacks](rollbacks.md)
- [Caching](../concepts/caching.md) — what the manifest holds and what it never will
- [`panel:cache`](../cli/panel-cache.md), [`panel:clear`](../cli/panel-clear.md), [`panel:assets`](../cli/panel-assets.md)
- [Compatibility matrix](../getting-started/compatibility.md)
