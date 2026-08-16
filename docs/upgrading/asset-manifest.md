# Asset Manifest

`.panel-assets.json` is the record of what the panel's published frontend looked like **when this
application published it**. It is the third value that lets an upgrade tell a file you edited from
one that has simply fallen behind, and it is what `php artisan panel:assets` reads. Reach for this
page when you need to know what is in that file, what writes it, and how to call the classes behind
it.

## A minimal working example

```bash
php artisan panel:assets            # compare, report, write nothing
php artisan panel:assets --update   # write the files that are safe to write
```

```php
use PandaPanel\Support\Installer\AssetManifest;

AssetManifest::path();      // '/var/www/app/.panel-assets.json'
AssetManifest::exists();    // true after an install
AssetManifest::read();      // ['resources/js/panel/tables/DataTable.vue' => '…', …]
AssetManifest::compare();   // every shipped file, with a status
```

Everything on this page is those four calls and the file they read.

## Why the file exists at all

The panel's frontend is published into the application rather than imported from the package. That
is the right trade — every component registry is a build-time `import.meta.glob` allowlist over the
application's own tree, and a component you cannot read the source of is one you cannot debug — but
it costs the thing every published-asset design costs: **once a file is the application's, a
package update cannot improve it.**

`vendor:publish` cannot help, because it has two settings and both are wrong on an upgrade:

| | Result |
| --- | --- |
| `vendor:publish --tag=panda-panel-assets` | Skips every file that exists. Nothing updates. |
| `vendor:publish --tag=panda-panel-assets --force` | Overwrites everything, including deliberate edits. |

Neither can tell the difference, because "differs from the package's copy" is equally true of a
stale file and an edited one. The missing piece is the one `git merge-base` supplies: a record of
the common ancestor.

## Three hashes, not two

With the hash a file had *when it was published*, three values answer the question exactly:

| On disk | In package | Status | What `--update` does |
| --- | --- | --- | --- |
| = manifest | = manifest | `current` | nothing |
| = manifest | ≠ manifest | `stale` | **overwrite, safely** |
| ≠ manifest | = manifest | `modified` | leave alone |
| ≠ manifest | ≠ manifest | `conflict` | report, never touch |
| absent | present | `deleted` | leave alone |
| not in manifest, differs | present | `new` | **write it** |
| not in manifest, identical | present | `current` | nothing |
| in manifest | not shipped | `removed-upstream` | report as removable |

Only two rows are written automatically, and in both the application demonstrably has no opinion
about the file: one it never had, and one it has never touched. A conflict is reported with its
path and never resolved by guessing — that is a diff for a person to read, and it has its own page:
[Resolving asset conflicts](asset-conflicts.md).

## The file

```json
{
    "_": "Written by php artisan panel:install / panel:assets. Commit this file: it is the record of which version of the panel frontend this application published, and without it an upgrade cannot tell your edits from a stale copy.",
    "files": {
        "resources/css/panda-panel.css": "3f0a…",
        "resources/js/panel/icons/registry.ts": "9c41…",
        "resources/js/panel/tables/DataTable.vue": "b7e2…"
    }
}
```

| | |
| --- | --- |
| Location | `base_path('.panel-assets.json')` — the application's root |
| `_` | A note for whoever opens the file. Ignored on read. |
| `files` | Application-relative destination => content hash, sorted with `ksort()` |
| Hash | `hash('xxh128', …)` of the file's contents, with `\r\n` replaced by `\n` |

**Commit it.** It is a record of a decision the project made, in the same way `composer.lock` is.
Under `bootstrap/cache` it would be regenerated and useless; under `storage` it would be gitignored
and lost on the first deploy.

Line endings are normalised because a checkout on Windows, or an editor configured to write CRLF,
would otherwise report every file as edited — and a report where everything is a conflict is a
report nobody reads.

## `PandaPanel\Support\Installer\AssetManifest`

A `final` class of static methods. Safe to call from tinker, a test, or a deploy script.

| Method | Signature |
| --- | --- |
| `path` | `public static function path(): string` |
| `exists` | `public static function exists(): bool` |
| `read` | `public static function read(): array<string, string>` |
| `write` | `public static function write(array $existing = []): void` |
| `compare` | `public static function compare(?array $files = null): array<string, array{status: string, destination: string, source: string\|null}>` |

### `path()`

```php
use PandaPanel\Support\Installer\AssetManifest;

AssetManifest::path();   // '/var/www/app/.panel-assets.json'
```

Always `base_path('.panel-assets.json')`. It is not configurable: a manifest whose location varied
would be a manifest a tool could not find.

### `exists()`

```php
if (! AssetManifest::exists()) {
    // Nothing published, or published before the manifest existed.
}
```

`panel:assets` uses it for one thing — printing the warning that says there is no record to compare
against.

### `read()`

```php
$recorded = AssetManifest::read();

$recorded['resources/js/panel/tables/DataTable.vue'] ?? null;   // 'b7e2…' or null
count($recorded);                                               // how many files are recorded
```

Returns the recorded hashes keyed by application-relative destination, and `[]` when the file is
absent. **A manifest that cannot be parsed is treated as absent rather than fatal**: the worst
outcome is that every file reads as `new`, which is exactly the state an application that never
published is in.

Non-string keys and non-string values are dropped, so a hand-edited file with a stray number in it
does not poison the comparison.

### `write()`

```php
AssetManifest::write();                        // record every shipped file on disk
AssetManifest::write(AssetManifest::read());   // keep what is already recorded, then record
```

```php
public static function write(array $existing = []): void
```

| Parameter | Type | Default | Meaning |
| --- | --- | --- | --- |
| `$existing` | `array<string, string>` | `[]` | Hashes to keep for files not being written now. |

Two properties are worth stating out loud:

- **It hashes the application's copy, not the package's.** This is a record of what the application
  *has*, so a file published and then immediately edited is recorded as edited. Recording the
  package's hash would claim the application had a pristine copy it never had.
- **A shipped file that is not on disk has its record removed**, so a file you deleted stops being
  reported as `deleted` after the next write.

`$existing` is why `panel:assets` calls it as `write(read())`: entries for files the package no
longer ships are preserved rather than silently dropped.

### `compare()`

```php
use PandaPanel\Support\Installer\AssetManifest;

foreach (AssetManifest::compare() as $relative => $entry) {
    if ($entry['status'] === AssetManifest::CONFLICT) {
        echo $relative.PHP_EOL;
    }
}
```

```php
public static function compare(?array $files = null): array
```

Returns one entry per shipped file, keyed by relative destination and sorted:

```php
AssetManifest::compare()['resources/js/panel/tables/DataTable.vue'];
// [
//   'status' => 'stale',
//   'destination' => '/var/www/app/resources/js/panel/tables/DataTable.vue',
//   'source' => '/var/www/app/vendor/chocoalano/panel/resources/js/panel/tables/DataTable.vue',
// ]
```

| Key | Type | Meaning |
| --- | --- | --- |
| `status` | `string` | One of the seven constants below |
| `destination` | `string` | Absolute path in the application |
| `source` | `string\|null` | Absolute path in the package; `null` for `removed-upstream` |

Every entry the manifest records that the package no longer ships is appended with status
`removed-upstream` and a `source` of `null`. Reported rather than deleted: a file this package
stopped shipping may well have been adopted by the application in the meantime.

**`$files`** — destination => source — exists so the statuses can be exercised against a scratch
fixture. This repository is its own test application, so with the real map "on disk" and "in the
package" can never differ and the two cases that matter most would be untestable:

```php
// tests/Feature/Panel/AssetUpgradeTest.php, in essence
$map = ['/tmp/app/Component.vue' => '/tmp/package/Component.vue'];

expect(AssetManifest::compare($map)['/tmp/app/Component.vue']['status'])
    ->toBe(AssetManifest::STALE);
```

Pass nothing in an application.

## The seven statuses

Public constants, so a status can be matched on rather than spelled:

```php
AssetManifest::NEW;               // 'new'
AssetManifest::CURRENT;           // 'current'
AssetManifest::STALE;             // 'stale'
AssetManifest::MODIFIED;          // 'modified'
AssetManifest::CONFLICT;          // 'conflict'
AssetManifest::DELETED;           // 'deleted'
AssetManifest::REMOVED_UPSTREAM;  // 'removed-upstream'
```

| Constant | Value | Meaning | Label in the report | `--update` | `--force` |
| --- | --- | --- | --- | --- | --- |
| `NEW` | `new` | A file the application never published | `new`, green | writes | writes |
| `CURRENT` | `current` | Published, untouched, unchanged upstream | `current`, gray | no | no |
| `STALE` | `stale` | Published, untouched, changed upstream | `out of date`, yellow | writes | writes |
| `MODIFIED` | `modified` | Published and then edited here | `yours`, blue | no | writes |
| `CONFLICT` | `conflict` | Edited here *and* changed upstream | `CONFLICT`, red | no | writes |
| `DELETED` | `deleted` | Published and then deleted here | `deleted by you`, gray | no | no |
| `REMOVED_UPSTREAM` | `removed-upstream` | Recorded once, no longer shipped | `no longer shipped`, gray | no | no |

`--force` extends the writable set to `MODIFIED` and `CONFLICT` and to nothing else: a file deleted
on purpose stays deleted, and one the package no longer ships is not resurrected.

## What is in the manifest

Exactly the publish map, one entry per file.
`PandaPanel\Support\Installer\PublishedAssets` is the only place that map is written down — the
service provider's `publishes()` call and `panel:assets` both read it, because two copies would
drift the first time a directory was added, and the symptom would be a file that publishes but is
never reported as out of date.

| Method | Signature | Returns |
| --- | --- | --- |
| `map` | `public static function map(): array<string, string>` | absolute **source => destination**, what `vendor:publish` is given |
| `files` | `public static function files(): array<string, string>` | absolute **destination => source**, one entry per file |
| `relative` | `public static function relative(string $path): string` | that destination as it reads in a report |

```php
use PandaPanel\Support\Installer\PublishedAssets;

count(PublishedAssets::map());     // 7 — six directories and one stylesheet
count(PublishedAssets::files());   // every file inside them

PublishedAssets::relative('/var/www/app/resources/js/panel/tables/DataTable.vue');
// 'resources/js/panel/tables/DataTable.vue'
```

The two maps are inverted relative to each other, deliberately: `map()` is what `vendor:publish`
wants, `files()` is what a comparison wants. `files()` is files rather than directories because "is
this up to date" is a question about a file — a directory that gained one component and had another
edited is neither changed nor unchanged.

| Package source | Application destination |
| --- | --- |
| `resources/js/panel` | `PandaPanel\Support\FrontendPaths::panel()` — `resources/js/panel` by default |
| `resources/js/components` | `resources/js/components` |
| `resources/js/composables` | `resources/js/composables` |
| `resources/js/lib` | `resources/js/lib` |
| `resources/js/pages` | `resources/js/pages` |
| `resources/js/types` | `resources/js/types` |
| `resources/css/panda-panel.css` | `resources/css/panda-panel.css` |

The map is built per call rather than held in a constant because two destinations are configurable,
and reading config at class-definition time would freeze whatever it happened to be during package
discovery:

```php
// config/panda-panel.php
'frontend' => [
    'panel_path' => 'js/panel',
    'pages_path' => 'js/pages/Panels',
],
```

Not in the manifest, and therefore never compared or written: your own files under a published
directory, `config/panda-panel.php`, the migrations, the generator stubs (separate publish tags),
Wayfinder's generated `resources/js/routes` and `resources/js/actions`, and `app.ts`,
`vite.config.ts` and `resources/views/app.blade.php`, which are yours entirely.

## Who writes it, and when

| Command | Writes the manifest |
| --- | --- |
| `php artisan panel:install` | Always, right after publishing the config and the assets |
| `php artisan panel:assets` | Never — a bare run is a question |
| `php artisan panel:assets --update` | Only if it wrote at least one file |
| `php artisan panel:assets --force` | Same |

```php
// PandaPanel\Console\Commands\PanelAssetsCommand::handle()

AssetManifest::write(AssetManifest::read());
```

It is written **after** the copies land and re-read from disk, because the manifest records what
the application has rather than what the command intended to write.

Recording hashes as a side effect of asking a question would make the next run's answer depend on
having asked, which is why a bare `panel:assets` writes nothing.

## Creating one for an application that installed before it existed

There is no manifest, so the first run has no record to compare against. The command says so and
carries on:

```text
WARN  No .panel-assets.json, so there is no record of what this application published.
      Everything already identical to the package reads as current; anything else reads as new.
      Run --update to write one.
```

That fallback is deliberate: an unrecorded file that already matches the package reads as `current`
rather than `new`, so an application with 340 identical files is not sent off to overwrite files
that are already right.

```bash
php artisan panel:assets --update    # writes the manifest, if it wrote at least one file
```

If everything was already identical, nothing is written and no manifest appears. Create one
unconditionally with the installer's checks instead:

```bash
php artisan panel:install --no-panel --no-user --no-interaction
```

Then commit the file.

## Using it in your own script

```php
use PandaPanel\Support\Installer\AssetManifest;

$report = AssetManifest::compare();

$counts = array_count_values(array_column($report, 'status'));

printf(
    "%d out of date, %d yours, %d conflicts\n",
    $counts[AssetManifest::STALE] ?? 0,
    $counts[AssetManifest::MODIFIED] ?? 0,
    $counts[AssetManifest::CONFLICT] ?? 0,
);
```

`panel:assets` always exits `0`, including with conflicts, because breaking a deploy over a file
somebody edited on purpose would be wrong. If you want a build to fail on them, that is the script
above plus an `exit`.

## Gotchas

- **Commit `.panel-assets.json`.** Without it, every future upgrade is a choice between overwriting
  your work and updating nothing.
- **Deleting it is recoverable but lossy.** Everything identical to the package reads as `current`
  and everything else as `new` — so your edits become files the next `--update` will overwrite.
- **The hash is of your copy, not the package's.** Publishing and then editing in the same sitting
  records the edited file, which is the correct answer and occasionally a surprising one.
- **`panel:icons` makes a published file yours.** It rewrites
  `resources/js/panel/icons/registry.ts` from the icons your panels declare, so that file reads as
  `modified` from then on. Re-run `php artisan panel:icons` after any `--force`.
- **A file you deleted comes back eventually.** `deleted` is never written by `--update`, but the
  next manifest write drops its record, so the run after that reads it as `new` and writes it.
- **`removed-upstream` entries are sticky.** The command preserves them with `write(read())`.
  Delete the line from the JSON if you want the status to stop appearing.
- **This repository always reads as `current`.** Its published copies *are* the package's files,
  which is exactly why `compare()` accepts an injected map.
- **Line endings are not an edit.** Hashes normalise `\r\n` to `\n` before hashing.

## See also

- [Resolving asset conflicts](asset-conflicts.md) — the one case this file cannot settle on its own
- [Upgrade guide](upgrade-guide.md), [Breaking changes](breaking-changes.md)
- [Versioning policy](versioning.md) — why the frontend has a version of its own
- [`panel:assets`](../cli/panel-assets.md), [`panel:install`](../cli/panel-install.md), [Publish tags](../cli/publish-tags.md)
- [Updating published assets](../frontend/updating-assets.md), [Published asset structure](../frontend/assets.md)
- [Frontend paths](../configuration/frontend-paths.md), [Service provider](../configuration/service-provider.md)
- [Frontend assets](../concepts/frontend-assets.md), [Component registries](../concepts/component-registries.md)
- [Frontend build](../deployment/frontend-build.md)
- [Troubleshooting: asset conflicts](../troubleshooting/asset-conflicts.md)
