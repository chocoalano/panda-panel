# Upgrade Guide

The procedure for moving an installed application onto a newer release of `chocoalano/panel`, in
the order the steps have to happen in. Reach for it after any `composer update` that moves this
package. What each release breaks, and the smallest edit that fixes it, is
[Breaking changes](breaking-changes.md).

## A minimal working example

```bash
composer update chocoalano/panel

php artisan panel:assets            # read the report first
php artisan panel:assets --update   # write only the files you have never edited
php artisan panel:icons             # the icon registry is a published file
npm run build

php artisan migrate
php artisan optimize:clear
php artisan test
```

On an application that has never edited a published file, that is the whole upgrade. Everything
below is what to do when it is not.

## What an upgrade actually touches

Five separate things move, and only the first is composer's:

| What | Moved by | Left alone by `composer update` |
| --- | --- | --- |
| `vendor/chocoalano/panel` | `composer update` | — |
| `resources/js/**`, `resources/css/panda-panel.css` | `panel:assets --update` | yes — they are the application's files |
| `config/panda-panel.php` | nothing, unless you re-publish | yes — new keys still get defaults, see below |
| Database tables | `php artisan migrate` | yes |
| `bootstrap/cache/panels.php`, config/route caches | `optimize:clear`, or `panel:clear` | yes |

The middle three are the reason an upgrade is a procedure rather than one command.

## 1. Update the package

```bash
composer update chocoalano/panel
```

Or the whole tree, if you are upgrading Laravel at the same time:

```bash
composer update
```

`composer update chocoalano/panel` respects the constraint in your `composer.json`. If that
constraint is `^0.1` you will not cross into `0.2` — which is correct, because a `0.x` minor is
allowed to break. To cross deliberately:

```bash
composer require chocoalano/panel:^0.2
```

Read [Breaking changes](breaking-changes.md) before you do, and [Versioning](versioning.md) for
what the number promises.

If composer refuses to resolve, `why-not` names the constraint that is in the way:

```bash
composer why-not chocoalano/panel ^0.2
```

## 2. Reconcile the published frontend

The panel's Vue components were copied into your application at install time, which is what makes
them debuggable and what the build-time component registries require. The cost is that
`composer update` cannot improve a file you now own.

```bash
php artisan panel:assets
```

```text
  out of date ........................................................ 12
  yours ............................................................... 3
  current ........................................................... 284

INFO  Run `php artisan panel:assets --update` to write the safe ones.
```

```bash
php artisan panel:assets --update
```

`--update` writes exactly two categories: files you never had, and files you have never edited.
Anything you changed is left alone. Anything changed on *both* sides is reported by path and never
written — see [Resolving asset conflicts](asset-conflicts.md), which is the one part of an upgrade
a tool must not do on its own.

| Command | Writes |
| --- | --- |
| `php artisan panel:assets` | nothing; a report |
| `php artisan panel:assets --update` | `new` and `out of date` |
| `php artisan panel:assets --force` | those, plus `yours` and `CONFLICT`, overwritten |

**Coming from a version installed before `.panel-assets.json` existed**, there is no record to
compare against. The command says so and carries on: a file already identical to the package's
reads as `current`, and only genuinely different ones are reported. Run `--update` once to write
the manifest, then commit it. If every file is already identical, `--update` writes nothing and no
manifest appears — create one with the installer's checks instead:

```bash
php artisan panel:install --no-panel --no-user --no-interaction
```

That publishes assets, writes `.panel-assets.json` unconditionally, and re-checks the seam around
the frontend — npm dependencies, the host modules, Vite, Inertia, and the layout rule — without
scaffolding a panel or prompting for a user.

## 3. Rebuild the icon registry

```bash
php artisan panel:icons
php artisan panel:icons --check   # for CI: fail instead of writing
```

`resources/js/panel/icons/registry.ts` is a published file rewritten from the icons your panels
declare, and a release that adds a built-in action adds an icon with it. Run it *after*
`panel:assets`, because `--force` would otherwise overwrite the registry with the package's copy.

An icon that is not in the registry warns once, in development, naming the icon and this command.
In production it is simply absent, because that is a build problem rather than a runtime one.

## 4. Rebuild the frontend

```bash
npm install     # only when the compatibility table moved
npm run build
```

Not optional. Every component registry is an `import.meta.glob` evaluated at build time, so a file
that changed on disk is not in the bundle until the build runs. A panel that looks unchanged after
an upgrade is usually a panel that was not rebuilt.

Check the npm ranges against [Compatibility](../getting-started/compatibility.md) when a release
moves them; `panel:install` reads this package's own `package.json` and names anything missing.

## 5. Run the migrations

```bash
php artisan migrate
```

The package's migrations run from the package by default — `load_migrations` is `true` — so a
release that adds a table adds it to `php artisan migrate` with no publish step:

| Migration | Table |
| --- | --- |
| `create_notifications_table` | `notifications` |
| `add_email_two_factor_to_users_table` | `users.two_factor_email_confirmed_at` |
| `create_panel_integrations_table` | `panel_integrations`, `panel_integration_deliveries` |
| `add_history_and_signing_to_panel_integrations` | history and signing columns |

Every one of them checks before it touches anything, so an application that already has a
`notifications` table keeps it.

If you published the migrations into `database/migrations` at install time, you own them: set
`load_migrations` to `false` so the same schema is not applied twice, and re-publish to pick up a
new one.

```bash
php artisan vendor:publish --tag=panda-panel-migrations
```

```php
// config/panda-panel.php
'load_migrations' => false,
```

## 6. Clear the caches

```bash
php artisan optimize:clear
```

The panel manifest is registered alongside the config and route caches, so `optimize` and
`optimize:clear` cover it:

```php
// PandaPanel\PandaPanelServiceProvider

$this->optimizes(optimize: 'panel:cache', clear: 'panel:clear', key: 'panels');
```

| Command | Effect |
| --- | --- |
| `php artisan panel:cache` | Discovers resources, pages and widgets once and writes `bootstrap/cache/panels.php` |
| `php artisan panel:clear` | Removes it, so discovery runs again |
| `php artisan optimize` | Runs `panel:cache` with the framework's other caches |
| `php artisan optimize:clear` | Runs `panel:clear` with the rest |

A stale manifest is the trap this exists for: with one written, discovery never runs, so a resource
added afterwards is simply not in the panel — no route, no navigation entry, no error. Outside
production the manifest records a fingerprint of the discovery paths and boot compares it, so a
stale one says so; in production the manifest is the authority and nothing touches the filesystem.
A deploy that adds a resource runs `panel:cache`.

## 7. Verify

```bash
php artisan panel:plugins   # every plugin still registers, at which version
php artisan panel:assets    # should now read: current, plus anything you own
php artisan test
```

`panel:plugins` is worth running first because a plugin whose `requiresPanel` constraint your new
version does not satisfy throws during boot — which means every artisan command fails, including
this one, with a message naming the plugin and the constraint. That is the intended failure: it
names what to fix.

Then open a panel URL and check three things that only a browser can answer: the sidebar lists the
resources you expect, an icon renders, and the browser console is empty.

## Version-specific notes

### Unreleased

Seven changes need an edit, and two of them are silent — the code keeps running and does the wrong
thing. Each one is written out in full, with the fix, in
[Breaking changes](breaking-changes.md):

| # | Change | Silent |
| --- | --- | --- |
| 1 | Uploads are authorized by the form they belong to, not by `viewAny` | yes |
| 2 | A migration `down()` no longer drops a `notifications` table it did not create | yes |
| 3 | `PanelPlugin::publishes()` moved onto the contract | no — a fatal at registration |
| 4 | The guest redirect is registered by the service provider | no |
| 4a | `/dashboard` redirects a signed-in user into the panel | no |
| 4b | Nine schema mistakes now raise `PanelSchemaException` | no — an exception at schema build |
| 5 | The testing helpers moved into the package | no |
| 6 | `Password::toPasswordRulesString()` is no longer called directly | no |
| 7 | `panel:install` registers the panel and offers to create a user | no |

Two of them are worth checking before you upgrade rather than after:

```bash
# 1. If you published the frontend before the upload change, this file must be updated.
php artisan panel:assets | grep uploadEndpoint

# 4b. Schema refusals surface at schema-build time, which the suite reaches.
php artisan test
```

## Upgrading in a deploy pipeline

`panel:assets --update` **writes source files**, so it belongs in development and in a commit, not
in a deploy. A deploy that ran it would write files into a build the next deploy discards, and the
compiled bundle would not contain them anyway.

A deploy of an already-upgraded application:

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
npm ci && npm run build
php artisan optimize        # includes panel:cache
```

What is worth adding to CI instead:

```bash
php artisan panel:assets    # exits 0 always; read the counts
php artisan panel:icons --check
```

`panel:assets` never fails a build, deliberately — breaking a deploy over a file somebody edited on
purpose would be wrong. To fail on conflicts, read the report yourself:

```php
use PandaPanel\Support\Installer\AssetManifest;

$conflicts = array_keys(array_filter(
    AssetManifest::compare(),
    static fn (array $entry): bool => $entry['status'] === AssetManifest::CONFLICT,
));

exit($conflicts === [] ? 0 : 1);
```

## Rolling back

```bash
composer require chocoalano/panel:0.1.1
php artisan optimize:clear
npm run build
```

Three things do not come back on their own:

- **Published assets.** They are your files; restore them from git.
  `git checkout <ref> -- resources/js resources/css/panda-panel.css`, then
  `git checkout <ref> -- .panel-assets.json` so the manifest matches what is on disk again.
- **Migrations.** `php artisan migrate:rollback` runs the older `down()`. A `notifications` table
  the package did not create is now deliberately left standing — the package establishes ownership
  before dropping anything, and leaves the table alone whenever the answer is not a clear yes.
- **Config keys you published.** A key removed upstream stays in your published file, where it is
  ignored.

Roll back the frontend and the package together. A published tree from a newer release against an
older PHP side is the one combination nothing tests.

## Gotchas

- **`npm run build` is part of the upgrade, not an optimisation.** Published Vue files are sources.
  Nothing about them reaches a browser until the build runs.
- **Config is merged, so a new key already has its default.** `mergeConfigFrom()` runs at register
  time, which means a `config/panda-panel.php` published a year ago does not opt you out of a new
  key's default behaviour. To see a new key in your own file, diff it:
  `diff -u config/panda-panel.php vendor/chocoalano/panel/config/panda-panel.php`.
- **`vendor:publish --tag=panda-panel-config --force` overwrites your config.** There is no merge.
  Diff first.
- **`vendor:publish --force` is the wrong tool for the frontend after the first install.** It
  cannot tell your files from stale ones. That is `panel:assets`'s entire job.
- **Run `panel:icons` after `panel:assets --force`, not before.** `--force` writes the package's
  copy of `icons/registry.ts` over the one your panels generated.
- **A cached config file hides a config change.** `php artisan config:clear` — or `optimize:clear`,
  which covers it and the panel manifest together.
- **A plugin can stop the whole application from booting.** An unsatisfied `requiresPanel` throws
  during registration, so every route and every artisan command fails until the plugin is updated
  or removed.
- **Upgrading Laravel and this package at once makes a failure ambiguous.** Do them in two commits.

## See also

- [Breaking changes](breaking-changes.md) — every change that needs an edit, with the edit
- [Versioning policy](versioning.md) — what a version number promises
- [Asset manifest](asset-manifest.md), [Resolving asset conflicts](asset-conflicts.md)
- [Changelog](changelog.md), [Release checklist](release-checklist.md)
- [Package name migration](package-name-migration.md)
- [`panel:assets`](../cli/panel-assets.md), [`panel:install`](../cli/panel-install.md), [`panel:icons`](../cli/panel-icons.md), [`panel:cache`](../cli/panel-cache.md), [`panel:clear`](../cli/panel-clear.md), [`panel:plugins`](../cli/panel-plugins.md)
- [Publish tags](../cli/publish-tags.md), [Migrations](../configuration/migrations.md)
- [Updating published assets](../frontend/updating-assets.md)
- [Production checklist](../deployment/production-checklist.md), [Frontend build](../deployment/frontend-build.md), [Rollbacks](../deployment/rollbacks.md)
- [Compatibility](../getting-started/compatibility.md)
