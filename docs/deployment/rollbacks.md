# Rollbacks

Rolling a panel application back is rolling back five things, not one: the PHP,
the caches, the schema, the published frontend, and the queue. Four of them are
Laravel's usual problem; the fifth — the panel manifest — has a failure mode
worth knowing before you need it, because it produces a panel that is missing
classes with nothing in the log. Reach for this page before writing a rollback
script, or in the middle of one.

## A minimal working example

Rolling back to the previous release directory:

```bash
ln -sfn /var/www/releases/41 /var/www/current

cd /var/www/releases/41
php artisan optimize:clear      # config, routes, views, events — and panel:clear
php artisan optimize            # rebuild all of them from *this* release
php artisan queue:restart
php artisan octane:reload       # if Octane is running
```

The clear-then-rebuild is the important half. A rollback that only flips a
symlink leaves the newer release's caches in place.

## What has to be undone

| Thing | Rolled back by | If you skip it |
| --- | --- | --- |
| PHP code | the symlink, or `git checkout` | — |
| Config, route, view, event caches | `optimize:clear` then `optimize` | routes and config from the release you rolled back from |
| **The panel manifest** | the same `optimize:clear` / `optimize` | a panel naming classes that no longer exist, or missing classes it should have |
| Published Vue components | they are in the repository, so the code checkout does it | components from the newer release calling PHP that is gone |
| The built bundle | `npm ci && npm run build` in the old release | the same, compiled |
| Schema | `php artisan migrate:rollback`, if it is safe | usually nothing — see below |
| In-flight jobs | `queue:restart`, and possibly `queue:flush` | jobs referencing classes the rollback removed |

## The panel manifest

This is the panel-specific hazard, and it has one cause: **`bootstrap/cache`
shared between releases.**

The manifest is a list of class names produced from one release's code:

```php
return array (
  'panels' => array (
    'admin' => array (
      'resources' => array (
        0 => 'App\\Panels\\Admin\\Resources\\Users\\UserResource',
        1 => 'App\\Panels\\Admin\\Resources\\Invoices\\InvoiceResource',   // added in release 42
      ),
      // …
    ),
  ),
  'fingerprint' => '…',
);
```

Roll back to release 41 with that file still in place and the panel tries to
register a class that is not there. Roll back *without* the file and discovery
runs — slower, and correct.

```bash
php artisan panel:clear     # always safe: a missing manifest is success
php artisan panel:cache     # rebuild from the code that is actually deployed
```

Both are `optimize` hooks, so `optimize:clear` and `optimize` cover them. There
is nothing extra to add to a rollback script that already has those two lines.

The staleness check that would have caught this is development-only:

```php
// PanelManifest::warnIfStale()
if (! app()->hasDebugModeEnabled() && ! app()->environment('local', 'testing')) {
    return;
}
```

So a production rollback gets no warning. Clear and rebuild unconditionally
rather than deciding whether you need to.

| Method | Signature | Use in a rollback |
| --- | --- | --- |
| `clear` | `clear(): bool` | delete the manifest; `true` whenever there is no longer one, including when there never was — which is what makes it safe to call unconditionally |
| `exists` | `exists(): bool` | assert the rebuild happened |
| `write` | `write(PanelRegistry $registry): array` | what `panel:cache` calls |

```php
use PandaPanel\Cache\PanelManifest;

app(PanelManifest::class)->clear();
app(PanelManifest::class)->exists();   // false
```

### Keep `bootstrap/cache` per release

| Directory | Shared between releases? |
| --- | --- |
| `storage` | yes |
| `.env` | yes |
| `bootstrap/cache` | **no** |

Sharing it is what turns a rollback into an incident. Per release, the old
release's own caches are still there and correct, and the rebuild is belt and
braces rather than the only thing standing between you and a broken panel.

## The route cache

A cached route table is compiled from the registries, which come from the
manifest. Rolling back code but not routes gives a route table that still
contains the newer release's resources:

```bash
php artisan route:clear
php artisan route:cache
php artisan route:list --path=admin    # confirm what is actually registered
```

The two halves fail differently and neither is loud:

| State after a rollback | Symptom |
| --- | --- |
| New route cache, old code | a URL resolves to a controller or page class that no longer exists |
| Old route cache, new manifest | the sidebar shows a link that 404s |

## Rolling back the package itself

```bash
composer require chocoalano/panel:0.1.6 --update-with-dependencies
php artisan optimize:clear
php artisan optimize
```

A downgrade changes what the package ships, which changes what `panel:assets`
compares against:

```bash
php artisan panel:assets
```

```text
out of date         7
CONFLICT            1
yours               3
current             286
```

A status with nothing in it is not printed at all, so the shape of that summary
changes from run to run.

Read "out of date" as "different from the version now installed" rather than
"behind". After a downgrade, `--update` writes the **older** package's copies
over files the application never touched, which is what you want — those files
are the ones that must match the PHP now installed.

```bash
php artisan panel:assets --update
npm run build
```

Files the application edited are never written, and files edited on both sides
are reported by path and left exactly as they are. That is the one case a tool
should not resolve on its own.

`.panel-assets.json` records the hash of each file *as it was published*. Keep it
committed: without it, `panel:assets` has no common ancestor and everything not
byte-identical to the package reads as new.

## Migrations

The package's four migrations are additive and guarded — each checks before it
touches anything:

| Migration | Rolling it back |
| --- | --- |
| `create_notifications_table` | do not, unless the application also stops using panel notifications; the bell reads it on every panel request |
| `add_email_two_factor_to_users_table` | safe to leave; a column nothing reads costs nothing |
| `create_panel_integrations_table` | safe to leave |
| `add_history_and_signing_to_panel_integrations` | safe to leave |

Leaving them applied while rolling code back is almost always right. A schema
that is ahead of the code is invisible; a schema that is behind it is a fatal
query.

`SharePanelData` catches a `QueryException` when counting unread notifications
and shares `0`, so a panel whose `notifications` table is genuinely missing
renders rather than 500s. That is a safety net, not permission to drop the table.

Rolling back the application's own migrations is the application's decision, and
it is the one part of a rollback that can lose data:

```bash
php artisan migrate:rollback --step=1 --force
```

## The queue

Jobs dispatched by the newer release can be sitting in the queue when the
rollback lands. The panel's own jobs carry class names as strings:

```php
RunPanelExport::dispatch(
    $exporter,    // class-string<Exporter>
    $resource,    // class-string<Resource>
    // …
);
```

A job naming an exporter or a resource the rollback removed fails when the
worker tries to resolve it. That is a failed job rather than a corrupted one —
`RunPanelExport` writes a file and nothing else, and `RunPanelImport` runs once
by design — but it is a user waiting for a notification that will not come.

```bash
php artisan queue:restart        # always
php artisan queue:failed         # see what did not survive
php artisan queue:flush          # only if the failures are all from the rolled-back release
```

Draining the queue before flipping back, where the deploy window allows it, is
the cleanest version of this.

## The frontend

The published components live in the repository, so a code rollback rolls them
back. Two things do not follow automatically:

- **The built bundle.** Rebuild in the release you rolled back to, or restore the
  build artifact that was shipped with it. Serving release 42's bundle against
  release 41's PHP is the same mismatch as skipping a build on the way forward.
- **`VITE_*` values.** They are compiled in, so a rollback that also reverts an
  environment change needs the rebuild to pick it up.

The icon registry is a tracked file and is covered by the code rollback. It does
not need regenerating, and running `panel:icons` during a rollback would write a
file nobody will commit.

## Rolling back a panel rename

Renaming a panel changes its id, and the id is in three places that all move
together:

| Derived from the id | Example |
| --- | --- |
| Route names | `panel.admin.dashboard` |
| The manifest key | `'admin' => [...]` |
| Generated Wayfinder modules | compiled into the bundle |

So a rename and its rollback are both "clear everything, rebuild everything".
A manifest keyed by an id no panel claims any more is not an error — that panel
simply falls back to discovery — but a route name that no longer exists is a
`RouteNotFoundException` from every `route()` call that used it.

## A rollback script

```bash
#!/usr/bin/env bash
set -euo pipefail

PREVIOUS=/var/www/releases/41

php artisan down --render="errors::503"

ln -sfn "$PREVIOUS" /var/www/current
cd "$PREVIOUS"

php artisan optimize:clear
php artisan optimize

npm ci
npm run build

php artisan queue:restart
php artisan octane:reload || true

php artisan up
```

`optimize:clear` before `optimize` rather than relying on the second to overwrite
the first: `panel:clear` is idempotent and cheap, and the pair states the intent.

## Gotchas

- **A shared `bootstrap/cache` is the whole problem.** Everything else on this
  page is manageable; that one is silent.
- **No warning in production.** The fingerprint check that catches a stale
  manifest is gated to development.
- **`panel:clear` is safe to run twice**, and safe on a machine that never
  cached — a missing manifest is success, so a rollback script can call it
  unconditionally.
- **`panel:assets` after a downgrade reports "out of date" for files that are
  ahead**, not behind. The label is about difference, not direction.
- **Do not roll back `create_notifications_table`.** The notification bell reads
  it on every panel request.
- **In-flight jobs outlive the rollback.** Restart the workers and look at
  `queue:failed`.
- **The bundle is not in the manifest.** Rebuilding the frontend is a separate
  step from every artisan command on this page.

## See also

- [Production checklist](production-checklist.md), [Panel cache](panel-cache.md)
- [Config cache](config-cache.md), [Route cache](route-cache.md), [Frontend build](frontend-build.md)
- [Queues](queues.md), [Octane](octane.md), [Monitoring](monitoring.md)
- [Asset manifest](../upgrading/asset-manifest.md), [Asset conflicts](../upgrading/asset-conflicts.md)
- [Upgrade guide](../upgrading/upgrade-guide.md), [Versioning](../upgrading/versioning.md)
- [`panel:clear`](../cli/panel-clear.md), [`panel:cache`](../cli/panel-cache.md), [`panel:assets`](../cli/panel-assets.md)
- [Panel routes 404](../troubleshooting/panel-routes-404.md)
