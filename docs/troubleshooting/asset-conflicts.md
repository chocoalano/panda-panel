# Asset conflicts

`php artisan panel:assets` reports a published frontend file as `CONFLICT` when it changed both in
your application and in the package. Nothing is written for those files, and this page is how to
resolve one. Reach for it after `composer update chocoalano/panel`, or whenever an upgrade seems
not to have brought a component you were expecting.

## Start here

```bash
php artisan panel:assets
```

```text
  new ................................................................ 3
  out of date ....................................................... 12
  yours .............................................................. 2
  CONFLICT ........................................................... 1
  current .......................................................... 291

  WARN  1 file(s) changed both here and upstream. Neither copy is safe to throw away, so
  nothing was written. Diff each against the package copy under vendor/chocoalano/panel,
  then re-run with --force once you have merged:

  resources/js/panel/tables/DataTable.vue
```

Resolve it:

```bash
diff resources/js/panel/tables/DataTable.vue \
     vendor/chocoalano/panel/resources/js/panel/tables/DataTable.vue

# merge by hand into your copy, then:
php artisan panel:assets --force
npm run build
```

## Why a conflict exists at all

The panel's frontend is published into your application rather than imported from the package.
That is deliberate — every component registry is a build-time `import.meta.glob` allowlist over the
application's own tree, so a component the build never saw cannot resolve — and it costs the thing
every published-asset design costs: **once a file is yours, a package update cannot improve it.**

`vendor:publish` cannot help, because it has two settings and both are wrong on an upgrade.
Without `--force` it skips every file that exists, so nothing updates. With `--force` it overwrites
everything, including the files you deliberately changed. Neither can tell the two apart: "differs
from the package's copy" is equally true of a stale file and an edited one.

`.panel-assets.json` supplies the missing third value — the hash each file had *when it was
published* — which turns an ambiguous two-way comparison into an unambiguous three-way one, the
same move `git merge-base` makes.

## The seven statuses

`PandaPanel\Support\Installer\AssetManifest` declares one constant per outcome. The command's
labels are in the second column.

| Constant | Value | Printed as | On disk | In package | `--update` writes it |
| --- | --- | --- | --- | --- | --- |
| `AssetManifest::NEW` | `new` | `new` | absent, or never published | present | yes |
| `AssetManifest::CURRENT` | `current` | `current` | = manifest | = manifest | no |
| `AssetManifest::STALE` | `stale` | `out of date` | = manifest | ≠ manifest | yes |
| `AssetManifest::MODIFIED` | `modified` | `yours` | ≠ manifest | = manifest | only with `--force` |
| `AssetManifest::CONFLICT` | `conflict` | `CONFLICT` | ≠ manifest | ≠ manifest | only with `--force` |
| `AssetManifest::DELETED` | `deleted` | `deleted by you` | absent | present | never |
| `AssetManifest::REMOVED_UPSTREAM` | `removed-upstream` | `no longer shipped` | anything | absent | never |

Two of them are written automatically, and in both your application demonstrably has no opinion
about the file: one it never had, and one it has never touched.

## The command

```bash
php artisan panel:assets            # report only
php artisan panel:assets --update   # write the new and out-of-date files
php artisan panel:assets --force    # also overwrite yours and conflicted ones
```

| Option | Effect |
| --- | --- |
| *(none)* | Prints the summary, lists conflicts by path, writes nothing |
| `--update` | Writes `new` and `stale` files, then rewrites `.panel-assets.json` from disk |
| `--force` | Everything `--update` does, plus `modified` and `conflict` |

`--force` extends to files you edited and to nothing else. A file you deleted on purpose stays
deleted, and one the package no longer ships is not resurrected.

The command exits `0` even with conflicts. A conflict is not a failure of the command — it ran
correctly and found something a person has to look at — and breaking a deploy over a file somebody
edited on purpose would be wrong.

## Reading the manifest yourself

Everything the command does is available on `AssetManifest`, so a conflict can be inspected from
tinker or asserted in a test.

```php
use PandaPanel\Support\Installer\AssetManifest;

AssetManifest::path();     // '/var/www/app/.panel-assets.json'
AssetManifest::exists();   // bool
```

| Method | Signature | Returns |
| --- | --- | --- |
| `path` | `static path(): string` | `base_path('.panel-assets.json')` |
| `exists` | `static exists(): bool` | whether that file is there |
| `read` | `static read(): array` | `array<string, string>` — application-relative path => hash |
| `write` | `static write(array $existing = []): void` | records what is on disk now |
| `compare` | `static compare(?array $files = null): array` | `array<string, array{status: string, destination: string, source: string\|null}>` |

```php
use PandaPanel\Support\Installer\AssetManifest;

$conflicts = array_keys(array_filter(
    AssetManifest::compare(),
    static fn (array $entry): bool => $entry['status'] === AssetManifest::CONFLICT,
));

// ['resources/js/panel/tables/DataTable.vue']
```

`compare()` returns every shipped file keyed by its application-relative destination, plus anything
recorded in the manifest that the package no longer ships. The optional `$files` argument is a
`destination => source` map and exists so the four states can be tested against a scratch fixture:
this repository is its own test application, so with the real map "on disk" and "in the package"
can never differ.

```php
use Illuminate\Support\Facades\File;
use PandaPanel\Support\Installer\AssetManifest;

File::put('/tmp/pkg/Component.vue', 'version two');
File::put('/tmp/app/Component.vue', 'version one, with our change');

AssetManifest::compare(['/tmp/app/Component.vue' => '/tmp/pkg/Component.vue']);
// ['/tmp/app/Component.vue' => ['status' => 'conflict', …]]
```

`write()` hashes the **application's** copy, not the package's. That distinction is the whole point:
a file that was published and then immediately edited is recorded as edited, and recording the
package's hash would claim your application had a pristine copy it never had. The `$existing`
argument carries forward hashes for files not being written now.

## What counts as a published file

The publish map and the diff report read one list, so they cannot drift.

```php
use PandaPanel\Support\Installer\PublishedAssets;

PublishedAssets::map();
// absolute package source => absolute application destination, per directory

PublishedAssets::files();
// absolute destination => absolute source, per file

PublishedAssets::relative('/var/www/app/resources/js/panel/tables/DataTable.vue');
// 'resources/js/panel/tables/DataTable.vue'
```

| Destination | Source in the package |
| --- | --- |
| `resources/js/panel` (`FrontendPaths::panel()`) | `resources/js/panel` |
| `resources/js/components` | `resources/js/components` |
| `resources/js/composables` | `resources/js/composables` |
| `resources/js/lib` | `resources/js/lib` |
| `resources/js/pages` | `resources/js/pages` |
| `resources/js/types` | `resources/js/types` |
| `resources/css/panda-panel.css` | `resources/css/panda-panel.css` |

`map()` is built per call rather than held in a constant, because two of the destinations are
configurable through `panda-panel.frontend.*` and reading config at class-definition time would
freeze whatever it happened to be during package discovery.

## "No .panel-assets.json"

```text
  WARN  No .panel-assets.json, so there is no record of what this application published.
  Everything already identical to the package reads as current; anything else reads as new.
  Run --update to write one.
```

This is what an application installed before the manifest existed looks like, or one where the file
was never committed. Write one and commit it:

```bash
php artisan panel:assets --update
git add .panel-assets.json
git commit -m "Record the published panel frontend"
```

Without a recorded hash, `compare()` falls back to a two-way answer: a file identical to the
package's reads as `current`, and anything else reads as `new`. That is safe but coarse — your
edits are indistinguishable from a stale copy until a manifest exists.

## Merging a conflict

There is no automatic resolution and there will not be one. Both copies contain work.

1. **Diff.** The package's copy is at `vendor/chocoalano/panel/` under the same relative path.
2. **Decide what your edit was for.** A conflict is usually one of three things: a styling change
   you can re-apply on top of the new file, a bug fix the package has now made itself, or a
   behaviour change that should be a `cssHooks()` class or a replacement component instead of an
   edit.
3. **Merge into your copy**, keeping your change and the upstream one.
4. **`php artisan panel:assets --force`** — but only after merging. `--force` overwrites your file
   with the package's, so run it when the merge is done and you want the package's copy to become
   the new baseline; otherwise leave the file alone and record it by editing nothing.
5. **`npm run build`.** The registries are evaluated at build time.

A three-way merge with git, if the file was committed at each publish:

```bash
git log --oneline -- resources/js/panel/tables/DataTable.vue
git diff HEAD~1 -- resources/js/panel/tables/DataTable.vue
```

## Notes

- **Hashes are `xxh128` over content with `\r\n` normalised to `\n`.** A Windows checkout, or an
  editor writing CRLF, would otherwise report every file as edited — and a report where everything
  is a conflict is a report nobody reads.
- **Commit `.panel-assets.json`.** It is the record of which version of the frontend this
  application published, in the same way `composer.lock` records what it installed. Under
  `bootstrap/cache` it would be regenerated and useless; under `storage` it would be gitignored and
  lost on the first deploy.
- **A manifest that cannot be parsed is treated as absent**, not fatal. The worst outcome is that
  every file reads as `new`, which is the state an application that never published is in.
- **`panel:assets` rewrites the manifest from disk after writing**, not from what it intended to
  write, so a file that failed to copy is not recorded as current.
- **`--update` does not run `npm run build`.** The command says so when it writes anything; a
  published component is not in the bundle until the build has seen it.
- **A `deleted by you` row stays deleted.** If you removed a component on purpose, nothing brings
  it back — including `--force`.
- **`no longer shipped` is reported, never removed.** A file this package stopped shipping may well
  have been adopted by your application in the meantime.
- **Plugin assets are a different command.** `panel:publish` handles those, and it never overwrites
  an existing file without `--force`.

## See also

- [Asset manifest](../upgrading/asset-manifest.md), [asset conflicts on upgrade](../upgrading/asset-conflicts.md)
- [`panel:assets`](../cli/panel-assets.md), [publish tags](../cli/publish-tags.md)
- [Updating published assets](../frontend/updating-assets.md), [published asset structure](../frontend/assets.md)
- [Upgrade guide](../upgrading/upgrade-guide.md)
- [Common install problems](../getting-started/common-install-problems.md)
- [Vite build errors](vite.md), [Tailwind](tailwind.md), [host modules](host-modules.md)
