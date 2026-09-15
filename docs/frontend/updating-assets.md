# Updating published assets

The panel's Vue frontend is copied into your application by `vendor:publish` and built by your
Vite, which means every one of those files is yours: in your repository, in your build, and
editable. The cost of that is the whole of this page — once a file is yours, `composer update`
cannot improve it. `php artisan panel:assets` is how you find out which published files have
fallen behind, which ones you changed, and which are both.

## The one command

```bash
php artisan panel:assets            # report only, writes nothing
php artisan panel:assets --update   # write the files that are safe to write
npm run build
```

A bare run is a question. It prints a count per status, lists any conflict by path, and exits 0.
`--update` writes exactly two of those statuses — the files you never had, and the files you have
never touched — and then records what it wrote.

## Why `vendor:publish` cannot do this

`vendor:publish` has two settings and both are wrong on an upgrade. Without `--force` it skips
every file that already exists, so nothing updates. With `--force` it overwrites everything,
including the files you deliberately changed. Neither can tell the difference, because "this file
differs from the package's copy" is equally true of a stale file and an edited one.

## The third hash

The missing value is the one `git merge-base` supplies: what the file looked like **when it was
published**. `PandaPanel\Support\Installer\AssetManifest` records it in `.panel-assets.json` at
your application root, and three hashes turn an ambiguous two-way comparison into an unambiguous
three-way one.

| On disk | In package | Status | `--update` | `--force` |
| --- | --- | --- | --- | --- |
| = manifest | = manifest | `current` | no | no |
| = manifest | ≠ manifest | `stale` | **writes** | writes |
| ≠ manifest | = manifest | `modified` | no | **writes** |
| ≠ manifest | ≠ manifest | `conflict` | no | **writes** |
| absent | present | `deleted` | no | no |
| absent, not in manifest | present | `new` | **writes** | writes |
| present, not in manifest, differs | present | `conflict` | no | writes |
| not in manifest, identical | present | `current` | no | no |
| in manifest | no longer shipped | `removed-upstream` | no | no |

Only the two rows where you demonstrably have no opinion about the file are written by default: a
file you never had, and a file you have never touched. A file changed on both sides is reported
with its path and left exactly as it is, because resolving that by guessing is how an upgrade eats
somebody's work.

The constants are public, so the statuses can be matched on rather than spelled:

```php
use PandaPanel\Support\Installer\AssetManifest;

AssetManifest::NEW;               // 'new'
AssetManifest::CURRENT;           // 'current'
AssetManifest::STALE;             // 'stale'
AssetManifest::MODIFIED;          // 'modified'
AssetManifest::CONFLICT;          // 'conflict'
AssetManifest::DELETED;           // 'deleted'
AssetManifest::REMOVED_UPSTREAM;  // 'removed-upstream'
```

## Reading the report

Each status prints under its own label, and only statuses with a non-zero count appear:

| Status | Label in the report | Colour |
| --- | --- | --- |
| `new` | `new` | green |
| `stale` | `out of date` | yellow |
| `conflict` | `CONFLICT` | red |
| `modified` | `yours` | blue |
| `deleted` | `deleted by you` | gray |
| `removed-upstream` | `no longer shipped` | gray |
| `current` | `current` | gray |

```text
  out of date ........................................ 12
  CONFLICT ............................................ 1
  yours ............................................... 4
  current ........................................... 322
```

Only conflicts are listed individually. `current` files are the overwhelming majority, and saying
so three hundred times is how a report becomes something nobody reads.

The counts are taken before anything is written, so a run with `--update` reports the state it
found rather than the state it left behind.

## `panel:assets`

`PandaPanel\Console\Commands\PanelAssetsCommand`:

```php
protected $signature = 'panel:assets
    {--update : Write the files that are safe to write}
    {--force : Also overwrite files this application has edited}
    {--reconciled=* : Record these already-merged files as level with the package copy, without changing them}';
```

| Option | Effect |
| --- | --- |
| *(none)* | Report only. Writes no files and does not create or touch `.panel-assets.json`. |
| `--update` | Writes `new` and `stale`. Records the manifest afterwards, whether or not it wrote a file. |
| `--force` | Implies writing. Extends `--update` to `modified` and `conflict`, and to nothing else. |
| `--reconciled=<path>` | Records one file you have merged yourself as level with the package copy. Changes no file's contents. Repeatable. |

The exit code is always 0. A conflict is not a failure of the command — it ran correctly and found
something a person has to look at — and a non-zero exit would break a deploy over a file somebody
edited on purpose.

## Resolving a conflict

A conflict means the package changed the file *and* so did you. Both copies are work, so the
command prints the paths and stops:

```text
  1 file(s) changed both here and upstream. Neither copy is safe to throw away, so nothing was
  written. Diff each against the package copy under vendor/chocoalano/panel. Merge the two and
  re-run with --reconciled=<path> to keep your copy, or --force to take the package's:

  resources/js/panel/tables/DataTable.vue
```

The package's copy of any published file is at the same relative path inside the installed
package:

```bash
diff -u \
  resources/js/panel/tables/DataTable.vue \
  vendor/chocoalano/panel/resources/js/panel/tables/DataTable.vue
```

There are two ways out, and they are genuinely different decisions.

**Keep your copy.** Merge the package's changes into your file by hand, then say so:

```bash
php artisan panel:assets --reconciled=resources/js/panel/tables/DataTable.vue
npm run build
```

`--reconciled` changes nothing on disk. It records that your copy is now level with the package's
current version — the ancestor moves to the package copy you merged against, and your merged
content stays exactly as you wrote it. The file reads as `modified` from then on: yours, and up to
date. Repeat the flag for each file, or pass it more than once:

```bash
php artisan panel:assets \
  --reconciled=resources/js/panel/tables/DataTable.vue \
  --reconciled=resources/js/panel/theme/panda-panel.css
```

Only the paths you name are recorded, and a path the package does not publish is refused rather
than ignored. There is deliberately no flag that settles every conflict at once: the state exists
to make somebody read a diff.

**Take the package's copy.** Give up your changes to that file:

```bash
php artisan panel:assets --force
npm run build
```

`--force` overwrites, so it is not a way to finish a merge — a file you merged and then `--force`d
has lost the merge. It is a whole-run switch, so it takes the package's copy of *every* `modified`
and `conflict` file, not just the one you were looking at.

## What `--force` does not do

`--force` extends the writable set to `modified` and `conflict`. It does not touch:

- `deleted` — a file you removed on purpose stays removed.
- `removed-upstream` — a file the package no longer ships is not resurrected, because you may well
  have adopted it in the meantime.
- anything with no source, which is the same set as the row above.

## The manifest file

```php
use PandaPanel\Support\Installer\AssetManifest;

AssetManifest::path();     // /var/www/app/.panel-assets.json
AssetManifest::exists();   // bool
```

```json
{
    "_": "Written by php artisan panel:install / panel:assets. Commit this file: it is the record of which version of the panel frontend this application published, and without it an upgrade cannot tell your edits from a stale copy.",
    "files": {
        "resources/css/panda-panel.css": "…",
        "resources/js/panel/tables/DataTable.vue": "…"
    }
}
```

Keys are application-relative destinations, sorted. Values are a content hash — `xxh128`, with
CRLF normalised to LF, so a Windows checkout or an editor writing CRLF does not report every file
as edited.

**Commit it.** It is a record of a decision your project made, in the same way `composer.lock` is.
Under `bootstrap/cache` it would be regenerated and useless; under `storage` it would be
gitignored and lost on the first deploy.

With no manifest at all the command says so and carries on:

```text
  WARN  No .panel-assets.json, so there is no record of what this application published.
        Everything already identical to the package reads as current; anything else on disk
        reads as a conflict, and anything missing reads as new. Run --update to write one.
```

A manifest that is not valid JSON is treated as absent rather than fatal. The worst outcome is a
report full of conflicts — every file on disk becomes one, because with nothing recorded there is
nothing to compare against — and a conflict is the status that writes nothing. A missing manifest
loses the answers, never the files.

## The API

Both classes are static and safe to call from tinker, a test, or a deploy script.

### `PandaPanel\Support\Installer\AssetManifest`

| Method | Signature | Returns |
| --- | --- | --- |
| `path` | `static path(): string` | `base_path('.panel-assets.json')` |
| `exists` | `static exists(): bool` | whether that file is there |
| `read` | `static read(): array<string, string>` | relative destination => recorded hash, `[]` when absent or unparseable |
| `write` | `static write(array $existing = []): void` | rehashes every shipped file on disk and rewrites the manifest |
| `compare` | `static compare(?array $files = null): array` | relative destination => `array{status, destination, source}` |

```php
use PandaPanel\Support\Installer\AssetManifest;

$report = AssetManifest::compare();

$report['resources/js/panel/tables/DataTable.vue'];
// [
//   'status' => 'stale',
//   'destination' => '/var/www/app/resources/js/panel/tables/DataTable.vue',
//   'source' => '/var/www/app/vendor/chocoalano/panel/resources/js/panel/tables/DataTable.vue',
// ]
```

`compare()` also appends every entry the manifest records that the package no longer ships, with
status `removed-upstream` and a `source` of `null`.

`$files` — destination => source — is there so the statuses can be exercised against a scratch
fixture. This repository is its own test application, so with the real map "on disk" and "in the
package" can never differ and the two cases that matter most would be untestable. Pass nothing in
an application.

`write($existing)` keeps the hashes in `$existing` for files it is not writing now, and hashes
**your** copy rather than the package's. That distinction is the point: the manifest records what
you have, so a file published and immediately edited is recorded as edited. The command calls it
as `AssetManifest::write(AssetManifest::read())` after the copies land, never before.

### `PandaPanel\Support\Installer\PublishedAssets`

| Method | Signature | Returns |
| --- | --- | --- |
| `map` | `static map(): array<string, string>` | absolute **source => destination**, the map given to `publishes()` |
| `files` | `static files(): array<string, string>` | absolute **destination => source**, one entry per file |
| `relative` | `static relative(string $path): string` | that destination as it reads in a report |

```php
use PandaPanel\Support\Installer\PublishedAssets;

count(PublishedAssets::map());     // 7 — the directories and the stylesheet
count(PublishedAssets::files());   // every file inside them

PublishedAssets::relative('/var/www/app/resources/js/panel/tables/DataTable.vue');
// 'resources/js/panel/tables/DataTable.vue'
```

The two maps are inverted relative to each other, deliberately: `map()` is what `vendor:publish`
wants, `files()` is what a comparison wants. The map is built per call rather than held in a
constant because two of its destinations are configurable, and reading config at
class-definition time would freeze whatever it happened to be during package discovery.

## What is in the report, and what is not

The report covers the publish map, plus the translations once an application has published any:

| Package source | Application destination |
| --- | --- |
| `resources/js/panel` | `FrontendPaths::panel()` — `resources/js/panel` by default |
| `resources/js/components` | `resources/js/components` |
| `resources/js/composables` | `resources/js/composables` |
| `resources/js/lib` | `resources/js/lib` |
| `resources/js/pages` | `resources/js/pages` |
| `resources/js/types` | `resources/js/types` |
| `resources/css/panda-panel.css` | `resources/css/panda-panel.css` |
| `lang` | `lang/{locale}` — only once `vendor:publish --tag=panda-panel-translations` has run |

Published strings are yours in exactly the way a published component is, and they used to be yours
*permanently*: a confirmation you reworded stayed at the release you published it from, and every
sentence the package improved afterwards stopped at the vendor directory. They are compared here
now. An application that never published them has nothing in the report and nothing to do — it
reads the package's own `lang/`, so `composer update` is the whole of its upgrade.

Two destinations move with config, which is why `PublishedAssets::map()` is the only place the
map is written down:

```php
// config/panda-panel.php
'frontend' => [
    'panel_path' => 'js/panel',          // PandaPanel\Support\FrontendPaths::panel()
    'pages_path' => 'js/pages/Panels',   // PandaPanel\Support\FrontendPaths::pages()
],
```

```php
use PandaPanel\Support\FrontendPaths;

FrontendPaths::panel();                 // …/resources/js/panel
FrontendPaths::panel('icons/registry.ts');
FrontendPaths::pages();                 // …/resources/js/pages/Panels
FrontendPaths::pages('Admin/Widgets');
```

Not in the report, and never written by `panel:assets`:

- **Your own files under a published directory.** `resources/js/pages/Panels/Admin/Widgets/*.vue`
  lives inside a published destination but is not a file the package ships, so it never appears in
  `files()` and is never compared.
- **`config/panda-panel.php`, the migrations, the generator stubs.** Separate publish tags —
  `panda-panel-config`, `panda-panel-migrations`, `panda-panel-stubs`. Re-publish those yourself
  and diff by hand. The fourth separate tag, `panda-panel-translations`, is the exception: what it
  writes *is* compared, once it has been run.
- **Translations you never published.** No panel translation is tracked until
  that directory exists, and a third locale you added there yourself is a file the package does not
  ship, so it is never compared or written.
- **Wayfinder's output.** `resources/js/routes` and `resources/js/actions` are generated from your
  route table and are not in the map. See [Wayfinder routes](wayfinder.md).
- **`resources/js/app.ts`, `vite.config.ts`, `resources/views/app.blade.php`.** Yours entirely.
  Nothing in the package rewrites them, which is also why a starter kit upgrade can quietly
  reintroduce a layout override that `panel:assets` will never mention.

## After a package upgrade

```bash
composer update chocoalano/panel

php artisan panel:assets            # read the report first
php artisan panel:assets --update   # write only what is safe

php artisan panel:icons             # the icon registry is a published file
npm run build
```

That covers the published translations too, if there are any. `--update` takes the ones this
application never edited; a sentence it reworded reads as `yours` and is left alone, and one both
sides changed reads as `CONFLICT` and waits for a person. `--force` takes everything, reworded
strings included.

`npm run build` is not optional. Every component registry is an `import.meta.glob` evaluated at
build time, so a file that changed on disk is not in the bundle until the build runs.

To re-check the seam around the frontend at the same time — npm dependencies, host modules, Vite,
Inertia, the layout rule — run the installer's checks without scaffolding anything:

```bash
php artisan panel:install --no-panel --no-user --no-interaction
```

That also writes `.panel-assets.json` unconditionally, which is the way to create one for an
application that published before the manifest existed.

## Gotchas

- **`--update` writes the manifest even when it wrote no files.** That is what makes it the right
  thing to run straight after a `vendor:publish` by tag: it records what you just got, so the edit
  you make next has a common ancestor. Edit first and that edit reads as `new` on the following
  release, and `--update` overwrites it.
- **A bare `panel:assets` never writes the manifest.** Recording hashes as a side effect of asking
  a question would make the next run's answer depend on having asked.
- **A file you deleted comes back.** `deleted` is never written by `--update`, but the next
  manifest write drops its record, so the run after that reads it as `new` and writes it. If a
  published file must stay gone, remove what imports it too, or expect it back.
- **`removed-upstream` entries are sticky.** The command writes the manifest as
  `write(read())`, which preserves records for files the package no longer ships. Delete the line
  from `.panel-assets.json` if you want the status to stop appearing.
- **`panel:icons` makes a published file yours.** It rewrites
  `resources/js/panel/icons/registry.ts` from the icons your panels declare, so that file reads as
  `modified` from then on, and `--force` will overwrite it with the package's copy. Re-run
  `php artisan panel:icons` after any `--force`.
- **Line endings do not count as an edit.** Hashes are taken with `\r\n` normalised to `\n`,
  because a report where every file is a conflict is a report nobody reads.
- **A file of your own at a name the package ships to is a conflict, not a new file.** The flat
  translation layout makes this ordinary: `lang/en/formats.php` may be yours. Nothing on disk is
  overwritten to find out — resolve it once with `--reconciled` or `--force`. Before v0.5.4 such a
  file read as `new` and an update wrote straight over it.
- **`--force` is a whole-run switch.** There is no way to force one path. `--reconciled` is the
  per-path option, and it keeps your copy rather than replacing it.
- **An update never moves an ancestor it did not earn.** The manifest records which *package*
  version each file is level with, so running `--update` repeatedly leaves `modified` and
  `conflict` files exactly where they were. Before v0.5.2 it recorded your own content instead,
  which made an edit read as `stale` on the next run and overwrote it on the one after.
- **This repository reads as `current` everywhere.** Its published copies *are* the package's
  files, which is why `AssetManifest::compare()` accepts an injected map for testing.

## See also

- [Published asset structure](assets.md)
- [Wayfinder routes](wayfinder.md), [Host modules](host-modules.md)
- [Icons](icons.md), [Tailwind theme](tailwind-theme.md), [CSS hooks](css-hooks.md)
- [Frontend assets](../concepts/frontend-assets.md), [Component registries](../concepts/component-registries.md)
- [`panel:assets`](../cli/panel-assets.md), [`panel:install`](../cli/panel-install.md), [publish tags](../cli/publish-tags.md)
- [Frontend requirements](../getting-started/frontend-requirements.md), [Laravel Vue starter kit setup](../getting-started/vue-starter-kit.md)
- [Asset manifest](../upgrading/asset-manifest.md), [Resolving asset conflicts](../upgrading/asset-conflicts.md), [Upgrade guide](../upgrading/upgrade-guide.md)
- [NPM build](../deployment/frontend-build.md), [Icon registry](../deployment/icon-registry.md)
- [Troubleshooting: asset conflicts](../troubleshooting/asset-conflicts.md)
