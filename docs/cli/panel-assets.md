# `panel:assets`

Reports which published panel files are behind the package, which ones this
application has edited, and which are both — and writes the ones that are safe
to write. Reach for it after every `composer update` of this package.

It covers the published frontend always, and the published translations from
the moment an application has any: see [Translations](#translations).

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

## Translations

An application that ran `vendor:publish --tag=panda-panel-translations` owns
`lang/{locale}/*.php` the same way it owns the frontend, and had the same
problem before this command reached them: the copy was frozen at the release it
was published from, and every sentence the package improved afterwards stopped
there with nothing to say so.

They join the report the moment one of them is on disk, with the same seven
statuses and the same two rules about what may be written:

```text
  out of date ......................................................... 2
  yours ............................................................... 1
  current ........................................................... 361

  lang/id/actions.php ........................................... written
  lang/en/notifications.php ..................................... written

INFO  Wrote 2 file(s).
```

No `npm run build` on that line, because nothing written was a Vue file. A run
that touched both says it.

An application that published nothing has nothing here to report, and that is
the good case rather than a gap: it reads the package's own `lang/` directory,
so it is current by definition and picks up a new locale or a reworded sentence
on `composer update` alone.

**Publish, then record, then reword — in that order.** `vendor:publish` cannot
write `.panel-assets.json`, and a file published and then edited with no record
in between has no common ancestor. It is indistinguishable from a file a later
release added, reads as `new`, and an update writes over it:

```bash
php artisan vendor:publish --tag=panda-panel-translations
php artisan panel:assets --update      # records what you just published
# now reword lang/en/actions.php
```

A run with `--update` writes the manifest even when it had nothing else to
write, which is what makes that second line worth typing on an already-current
tree.

## Signature

```text
panel:assets
    {--update : Write the files that are safe to write}
    {--force : Also overwrite files this application has edited}
    {--reconciled=* : Record these already-merged files as level with the package copy, without changing them}
```

| Option | Default | Effect |
| --- | --- | --- |
| — | — | Report only. Nothing is written. |
| `--update` | off | Writes the `new` and `out of date` files. |
| `--force` | off | Implies writing, and extends it to `CONFLICT` and `yours`. |
| `--reconciled=<path>` | — | Writes no file. Records the named files as level with the package's current copy. Repeatable. |

```bash
php artisan panel:assets                    # report
php artisan panel:assets --update           # write the safe ones
php artisan panel:assets --force            # write those, plus your edits, overwritten
php artisan panel:assets --reconciled=resources/js/panel/tables/DataTable.vue
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
      vendor/chocoalano/panel. Merge the two and re-run with --reconciled=<path> to keep
      your copy, or --force to take the package's:

  resources/js/panel/tables/DataTable.vue
  resources/js/panel/forms/registry.ts
```

Resolving that by guessing is how an upgrade eats somebody's work, so the
command prints the path and stops. The fix is a diff:

```bash
diff -u vendor/chocoalano/panel/resources/js/panel/tables/DataTable.vue \
        resources/js/panel/tables/DataTable.vue
```

Merge upstream's change into your copy by hand, then say so:

```bash
php artisan panel:assets --reconciled=resources/js/panel/tables/DataTable.vue
```

That changes no file. It records that your copy is now level with the package's
current version, which is what turns a `CONFLICT` into `yours` — and only for
the paths you name. If you decided your edit was not worth keeping, take the
package's copy with `--force` instead; do not use it to finish a merge, because
it overwrites the merge.

Only conflicts are listed by path. `current` files are the overwhelming majority
and saying so three hundred times is how a report becomes something nobody
reads.

## `.panel-assets.json`

```json
{
    "_": "Written by php artisan panel:install / panel:assets. Commit this file: it is the record of which version of the panel frontend and translations this application published, and without it an upgrade cannot tell your edits from a stale copy.",
    "files": {
        "lang/en/actions.php": "…",
        "resources/js/panel/components/PanelSidebar.vue": "…",
        "resources/js/panel/icons/registry.ts": "…"
    }
}
```

At the application's root, and it belongs in the repository the way
`composer.lock` does — it is a record of a decision the project made. Under
`bootstrap/cache` it would be regenerated and useless; under `storage` it would
be gitignored and lost on the first deploy.

Each hash is the **package** version that copy is level with — the ancestor —
not the contents of your copy. `\r\n` is normalised to `\n`, so a Windows
checkout does not report every file as edited.

Without the file:

```text
WARN  No .panel-assets.json, so there is no record of what this application published.
      Everything already identical to the package reads as current; anything else reads
      as new. Run --update to write one.
```

It is written by `panel:install`, and rewritten by every `--update` or
`--force` run — including one that wrote no files at all, because recording the
state is half of what an update is for.

A rewrite moves an entry only for a file the run actually reconciled: one it
overwrote from the package, or one `--reconciled` named. A `yours` or `CONFLICT`
file keeps the ancestor it had, so `--update` can be run as often as you like
without moving anything the application owns.

## The API behind it

`PandaPanel\Support\Installer\AssetManifest`:

| Method | Signature |
| --- | --- |
| `path` | `static path(): string` — `base_path('.panel-assets.json')` |
| `exists` | `static exists(): bool` |
| `read` | `static read(): array<string, string>` — recorded hashes, keyed by relative destination |
| `write` | `static write(array $existing = [], ?array $shipped = null): void` |
| `reconcile` | `static reconcile(array $relatives, ?array $shipped = null): list<string>` — records the named files against the package's current copy, returns the ones it recorded |
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
| `map` | `static map(): array<string, string>` — source directory => destination, what `vendor:publish --tag=panda-panel-assets` is given |
| `translations` | `static translations(): array<string, string>` — what `vendor:publish --tag=panda-panel-translations` is given |
| `files` | `static files(): array<string, string>` — absolute destination => absolute source, one entry per file, both maps together |
| `translationFiles` | `static translationFiles(): array<string, string>` — the translations alone, and empty until they are published |
| `relative` | `static relative(string $path): string` |

What is published:

| Package path | Application path | Tracked |
| --- | --- | --- |
| `resources/js/panel` | `FrontendPaths::panel()`, `resources/js/panel` by default | always |
| `resources/js/components` | `resources/js/components` | always |
| `resources/js/composables` | `resources/js/composables` | always |
| `resources/js/lib` | `resources/js/lib` | always |
| `resources/js/pages` | `resources/js/pages` | always |
| `resources/js/types` | `resources/js/types` | always |
| `resources/css/panda-panel.css` | `resources/css/panda-panel.css` | always |
| `lang` | `lang/{locale}` | once published |

`files()` includes a translation from the moment one of them is on disk and
never before it. A `lang/` directory is not the signal — `lang:publish` creates
one, and so does a project that wrote a single sentence of its own — so the
signal is one of the package's own files present at the path it publishes to. An
application that never published is never told about files it did not ask for;
one that did gets them treated exactly like the frontend from then on.

## Exit code

Always `0`, including when there are conflicts. The command ran correctly and
found something a person has to look at; reporting that as a non-zero exit would
break a deploy over a file somebody edited on purpose.

If you want a build to fail on conflicts, read `AssetManifest::compare()` in a
small script of your own.

## Gotchas

- **Nothing is written without `--update` or `--force`.** The bare command is a
  report.
- **Run `npm run build` after an update that wrote frontend files.** The
  command says so on the line that counts them, and stays quiet when everything
  it wrote was a translation — those are PHP and take effect on the next
  request.
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
- **Record a translations publish before you reword it.** `vendor:publish`
  cannot write the manifest, so one `panel:assets --update` between publishing
  and editing is what gives your rewording an ancestor to be compared against.

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
