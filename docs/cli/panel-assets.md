# `panel:assets`

Reports which published panel assets are behind the package, which ones this
application has edited, and which are both — and writes the ones that are safe
to write. Reach for it after every `composer update` of this package.

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

```text
  resources/js/panel/tables/DataTable.vue ....................... written
  …
INFO  Wrote 12 file(s). Run `npm run build`.
```

## The problem it exists for

The panel's frontend is published into the application rather than imported from
the package. That is the right trade — the component registries are build-time
`import.meta.glob` allowlists over the application's own tree, and a component
you cannot read the source of is one you cannot debug — but it costs what every
published-asset design costs: a package update cannot improve a file the
application now owns.

`vendor:publish` alone cannot help, because it has two settings and both are
wrong on an upgrade. Without `--force` it skips every file that exists, so
nothing updates. With `--force` it overwrites everything, including the files
you deliberately changed. Neither can tell the difference: "differs from the
package's copy" is equally true of a stale file and an edited one.

`.panel-assets.json` records what each file looked like *when it was published*.
That third value is the common ancestor, and it turns an ambiguous two-way
comparison into an unambiguous three-way one — the move `git merge-base` makes.

## Signature

```text
panel:assets
    {--update : Write the files that are safe to write}
    {--force : Also overwrite files this application has edited}
```

| Option | Default | Effect |
| --- | --- | --- |
| — | — | Report only. Nothing is written. |
| `--update` | off | Writes the `new` and `out of date` files. |
| `--force` | off | Implies writing, and extends it to `CONFLICT` and `yours`. |

```bash
php artisan panel:assets                    # report
php artisan panel:assets --update           # write the safe ones
php artisan panel:assets --force            # write those, plus your edits, overwritten
```

## The seven statuses

| Status | Label in the report | On disk vs recorded | In package vs recorded | `--update` | `--force` |
| --- | --- | --- | --- | --- | --- |
| `AssetManifest::NEW` | `new` | not recorded | ships | writes | writes |
| `AssetManifest::STALE` | `out of date` | same | differs | writes | writes |
| `AssetManifest::CONFLICT` | `CONFLICT` | differs | differs | no | writes |
| `AssetManifest::MODIFIED` | `yours` | differs | same | no | writes |
| `AssetManifest::DELETED` | `deleted by you` | absent | ships | no | no |
| `AssetManifest::REMOVED_UPSTREAM` | `no longer shipped` | recorded | not shipped | no | no |
| `AssetManifest::CURRENT` | `current` | same | same | no | no |

Only two categories are ever written automatically, and in both the application
demonstrably has no opinion about the file: one it never had, and one it has
never touched.

`--force` deliberately does not extend to `deleted by you` or `no longer
shipped`: a file deleted on purpose stays deleted, and one the package no longer
ships is not resurrected.

## Conflicts

```text
WARN  2 file(s) changed both here and upstream. Neither copy is safe to throw away, so
      nothing was written. Diff each against the package copy under
      vendor/chocoalano/panel, then re-run with --force once you have merged:

  resources/js/panel/tables/DataTable.vue
  resources/js/panel/forms/registry.ts
```

Resolving that by guessing is how an upgrade eats somebody's work, so the
command prints the path and stops. The fix is a diff:

```bash
diff -u vendor/chocoalano/panel/resources/js/panel/tables/DataTable.vue \
        resources/js/panel/tables/DataTable.vue
```

Merge upstream's change into your copy by hand, then either leave it alone — it
is now `yours` and `MODIFIED` — or, if you decided your edit was not worth
keeping, take the package's with `--force`.

Only conflicts are listed by path. `current` files are the overwhelming majority
and saying so three hundred times is how a report becomes something nobody
reads.

## `.panel-assets.json`

```json
{
    "_": "Written by php artisan panel:install / panel:assets. Commit this file: it is the record of which version of the panel frontend this application published, and without it an upgrade cannot tell your edits from a stale copy.",
    "files": {
        "resources/js/panel/components/PanelSidebar.vue": "…",
        "resources/js/panel/icons/registry.ts": "…"
    }
}
```

At the application's root, and it belongs in the repository the way
`composer.lock` does — it is a record of a decision the project made. Under
`bootstrap/cache` it would be regenerated and useless; under `storage` it would
be gitignored and lost on the first deploy.

The hashes are of the **application's** copy, with `\r\n` normalised to `\n`, so
a Windows checkout does not report every file as edited.

Without the file:

```text
WARN  No .panel-assets.json, so there is no record of what this application published.
      Everything already identical to the package reads as current; anything else reads
      as new. Run --update to write one.
```

It is written by `panel:install`, and rewritten after every run that wrote
files — from disk rather than from what was intended, so it records what the
application actually has.

## The API behind it

`PandaPanel\Support\Installer\AssetManifest`:

| Method | Signature |
| --- | --- |
| `path` | `static path(): string` — `base_path('.panel-assets.json')` |
| `exists` | `static exists(): bool` |
| `read` | `static read(): array<string, string>` — recorded hashes, keyed by relative destination |
| `write` | `static write(array $existing = []): void` |
| `compare` | `static compare(?array $files = null): array<string, array{status: string, destination: string, source: string\|null}>` |

```php
use PandaPanel\Support\Installer\AssetManifest;

foreach (AssetManifest::compare() as $relative => $entry) {
    if ($entry['status'] === AssetManifest::CONFLICT) {
        echo $relative.PHP_EOL;
    }
}
```

`PandaPanel\Support\Installer\PublishedAssets` is where the file list comes
from, and both the service provider's `publishes()` call and this command read
it — a second copy would drift the first time a directory was added, and the
symptom would be a file that publishes but is never reported as out of date.

| Method | Signature |
| --- | --- |
| `map` | `static map(): array<string, string>` — source directory => destination, what `vendor:publish` is given |
| `files` | `static files(): array<string, string>` — absolute destination => absolute source, one entry per file |
| `relative` | `static relative(string $path): string` |

What is published:

| Package path | Application path |
| --- | --- |
| `resources/js/panel` | `FrontendPaths::panel()`, `resources/js/panel` by default |
| `resources/js/components` | `resources/js/components` |
| `resources/js/composables` | `resources/js/composables` |
| `resources/js/lib` | `resources/js/lib` |
| `resources/js/pages` | `resources/js/pages` |
| `resources/js/types` | `resources/js/types` |
| `resources/css/panda-panel.css` | `resources/css/panda-panel.css` |

## Exit code

Always `0`, including when there are conflicts. The command ran correctly and
found something a person has to look at; reporting that as a non-zero exit would
break a deploy over a file somebody edited on purpose.

If you want a build to fail on conflicts, read `AssetManifest::compare()` in a
small script of your own.

## Gotchas

- **Nothing is written without `--update` or `--force`.** The bare command is a
  report.
- **Run `npm run build` after an update.** Written files are Vue and TypeScript
  sources; nothing changes in the browser until they are compiled.
- **`--force` overwrites your edits with no backup.** Commit first. There is no
  merge step and no `.orig` file.
- **A file you deleted stays deleted.** That is a decision the command respects,
  and the reason `deleted by you` is never written even with `--force`.
- **Commit `.panel-assets.json`.** Without it, every future upgrade is a choice
  between overwriting your work and updating nothing.
- **`vendor:publish --force` is still the wrong tool after the first install.**
  It cannot tell your files from stale ones; that is this command's entire job.
- **Deleting the manifest is recoverable but lossy.** Everything identical to the
  package reads as `current` and everything else as `new`, so your edits become
  files the next `--update` will overwrite.

## See also

- [panel:install](panel-install.md) — writes the first manifest
- [Publish tags](publish-tags.md)
- [Frontend assets](../concepts/frontend-assets.md), [Assets](../frontend/assets.md)
- [Updating assets](../frontend/updating-assets.md)
- [The asset manifest](../upgrading/asset-manifest.md), [Asset conflicts](../upgrading/asset-conflicts.md)
- [Asset conflicts troubleshooting](../troubleshooting/asset-conflicts.md)
- [Upgrade guide](../upgrading/upgrade-guide.md)
- [Frontend build](../deployment/frontend-build.md)
- [Frontend paths](../configuration/frontend-paths.md)
