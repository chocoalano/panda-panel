# Resolving Asset Conflicts

The panel's frontend is published into your application, so every one of those files is yours —
which means a package update cannot silently improve one. `php artisan panel:assets` is how an
upgrade finds out which published files fell behind, which ones you changed, and which are both;
this page is the third case, and what to do about it. Reach for it after
`composer update chocoalano/panel` reports a `CONFLICT`, or whenever an upgrade did not bring a
component you were expecting.

## A minimal working example

```bash
composer update chocoalano/panel

php artisan panel:assets            # what is behind, what is yours, what conflicts
php artisan panel:assets --update   # write only the files you have never edited
npm run build
```

On an application that has never edited a published file, that is the whole of it. Everything
below is what to do when `--update` leaves something behind.

## Why the conflict exists at all

The panel's components are copied into `resources/js` rather than imported from
`vendor/chocoalano/panel`. That is deliberate: every component registry is a build-time
`import.meta.glob` allowlist over the application's own tree, so a component the build never saw
cannot resolve, and a component you cannot read the source of is one you cannot debug. The cost is
the whole of this page — **once a file is the application's, a package update cannot improve it.**

`vendor:publish` cannot settle that, because it has two settings and both are wrong on an upgrade:

| | Result |
| --- | --- |
| `php artisan vendor:publish --tag=panda-panel-assets` | Skips every file that exists. Nothing updates. |
| `php artisan vendor:publish --tag=panda-panel-assets --force` | Overwrites everything, including deliberate edits. |

Neither can tell the two apart, because "differs from the package's copy" is equally true of a
stale file and an edited one.

## The three-way comparison

The missing value is the one `git merge-base` supplies: a record of the common ancestor.
`PandaPanel\Support\Installer\AssetManifest` writes it to `.panel-assets.json` at the application
root — the hash each published file had **when it was published** — and with that third value,
three questions have one answer each.

| Question | Compared | Answer |
| --- | --- | --- |
| Did you change it? | on disk vs. manifest | `edited` |
| Did the package change it? | in package vs. manifest | `updated` |
| Which of the two? | both of the above | the status |

```php
// PandaPanel\Support\Installer\AssetManifest::statusFor(), in essence

$edited  = $onDisk !== $recorded;
$updated = $inPackage !== $recorded;

match (true) {
    $edited && $updated => AssetManifest::CONFLICT,
    $edited             => AssetManifest::MODIFIED,
    $updated            => AssetManifest::STALE,
    default             => AssetManifest::CURRENT,
};
```

Which gives four answers where `vendor:publish` had two:

| On disk | In package | Status | Means | `--update` | `--force` |
| --- | --- | --- | --- | --- | --- |
| = manifest | = manifest | `current` | unchanged both sides | no | no |
| = manifest | ≠ manifest | `stale` | **behind** — never edited here | **writes** | writes |
| ≠ manifest | = manifest | `modified` | **yours** — nothing new upstream | no | writes |
| ≠ manifest | ≠ manifest | `conflict` | **both** changed | no | writes |
| absent | present | `deleted` | you deleted it | no | no |
| not in manifest, differs | present | `new` | new since you published | **writes** | writes |
| not in manifest, identical | present | `current` | published before the manifest existed | no | no |
| in manifest | not shipped | `removed-upstream` | no longer shipped | no | no |

`--update` writes exactly two of those rows, and in both the application demonstrably has no
opinion about the file: one it never had, and one it has never touched. A conflict is reported with
its path and never resolved by guessing — that is a diff for a person to read.

The seven statuses are public constants, so a report can be matched on rather than spelled:

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

The file itself, and the rest of the API, are [Asset manifest](asset-manifest.md).

## What `panel:assets` reports

```bash
php artisan panel:assets
```

```text
  new ................................................................ 3
  out of date ....................................................... 12
  CONFLICT ........................................................... 1
  yours .............................................................. 4
  current .......................................................... 284

  WARN  1 file(s) changed both here and upstream. Neither copy is safe to throw away, so
  nothing was written. Diff each against the package copy under vendor/chocoalano/panel,
  then re-run with --force once you have merged:

  resources/js/panel/tables/DataTable.vue

INFO  Run `php artisan panel:assets --update` to write the safe ones.
```

Three things are worth knowing about that output.

**The labels are not the constants.** Each status prints under a name written for a person reading
a report:

| Status | Printed as | Colour |
| --- | --- | --- |
| `new` | `new` | green |
| `stale` | `out of date` | yellow |
| `conflict` | `CONFLICT` | red |
| `modified` | `yours` | blue |
| `deleted` | `deleted by you` | gray |
| `removed-upstream` | `no longer shipped` | gray |
| `current` | `current` | gray |

A status with a count of zero is not printed at all.

**Only conflicts are listed by path.** `current` files are the overwhelming majority, and saying so
three hundred times is how a report becomes something nobody reads. To see any other status by
name, read the report yourself — [below](#reading-the-report-from-php).

**The exit code is always `0`.** A conflict is not a failure of the command: it ran correctly and
found something a person has to look at, and a non-zero exit would break a deploy over a file
somebody edited on purpose.

### The command

`PandaPanel\Console\Commands\PanelAssetsCommand`:

```php
protected $signature = 'panel:assets
    {--update : Write the files that are safe to write}
    {--force : Also overwrite files this application has edited}';
```

| Invocation | Writes | Rewrites `.panel-assets.json` |
| --- | --- | --- |
| `php artisan panel:assets` | nothing | never |
| `php artisan panel:assets --update` | `new`, `stale` | if it wrote at least one file |
| `php artisan panel:assets --force` | those, plus `modified` and `conflict` | same |

`--force` implies writing — it does not need `--update` beside it — and it extends the writable set
to files you edited and to nothing else. A file you deleted on purpose stays deleted, and one the
package no longer ships is not resurrected.

There is no per-file flag. **The granularity is the whole run**, which is what makes the routes
below worth reading before reaching for `--force`.

## Taking an update onto an edited file

A conflict means both copies contain work. There are three honest outcomes, and the middle one has
an order that matters.

| You want | Do this | The file then reads |
| --- | --- | --- |
| The package's version, your edit gone | `panel:assets --force` | `current` |
| Both — the update *and* your edit | Take the package copy first, record it, then re-apply your edit | `modified` |
| Your version, skip the update | nothing | `conflict`, in every report, until either side moves |

### Route 1 — take the package's copy

The edit is no longer worth keeping, usually because the package has now made the same fix itself.
Diff to confirm that, then overwrite:

```bash
diff -u resources/js/panel/tables/DataTable.vue \
        vendor/chocoalano/panel/resources/js/panel/tables/DataTable.vue

php artisan panel:assets --force
php artisan panel:icons      # rebuild after panel:assets --force rewrote assets
npm run build
```

`--force` writes every `modified` and `conflict` file, not only this one. Check what else is in
that set before running it:

```bash
php artisan panel:assets     # read the `yours` count first
```

### Route 2 — take the update *and* keep your edit

**Take the package's copy first, let the manifest record it, then re-apply your change on top.**
Not the other way around, and the reason is mechanical: the manifest records what is on disk when
it is written, so a hand-merged file recorded as its own baseline reads as `out of date` on the
next comparison — because the package's copy still differs from it — and the next `--update`
overwrites it without a word.

```bash
# 1. Baseline: the package's copy becomes the file on disk.
cp vendor/chocoalano/panel/resources/js/panel/tables/DataTable.vue \
   resources/js/panel/tables/DataTable.vue

# 2. Record that baseline. panel:install writes the manifest unconditionally,
#    publishes nothing over a file that already exists, and scaffolds nothing.
php artisan panel:install --no-panel --no-user --no-interaction

# 3. Re-apply your change to the new file, by hand or from the diff you kept.
git diff HEAD~1 -- resources/js/panel/tables/DataTable.vue

# 4. Confirm.
php artisan panel:assets     # the file now reads `yours`
npm run build
```

After step 3 the recorded hash is the package's copy and the file on disk is yours, which is
exactly `modified` — your edit, nothing new upstream — and `--update` will never touch it again.
When the package next changes that file it becomes a conflict again, which is the correct answer:
you have an edit, and there is something new to fold into it.

Keep the diff before you start, so step 3 is a re-application rather than an act of memory:

```bash
git diff -- resources/js/panel/tables/DataTable.vue > /tmp/datatable.patch
```

### Route 3 — keep yours, skip the update

Do nothing. The file stays exactly as it is, and reports as `CONFLICT` on every run until either
you or the package changes it again. There is no per-file "ignore" and no suppression list: a
standing conflict is a standing statement that this application is carrying a component the package
has moved past, which is true and worth being reminded of.

What it costs is a report that is never clean, so it is worth being sure the edit still earns its
place — [Not having the conflict next time](#not-having-the-conflict-next-time).

### Recording a resolution by hand

`AssetManifest::write()` hashes the **application's** copy, never the package's, so there is no
supported call that says "treat my file as the baseline". When route 2's baseline step is
impractical — a file you rewrote wholesale, for instance — the escape hatch is to write the hash
into `.panel-assets.json` yourself. The hash is `xxh128` over the file's contents with `\r\n`
normalised to `\n`:

```php
use Illuminate\Support\Facades\File;
use PandaPanel\Support\Installer\AssetManifest;

$relative = 'resources/js/panel/tables/DataTable.vue';
$source = base_path('vendor/chocoalano/panel/'.$relative);

$files = AssetManifest::read();
$files[$relative] = hash('xxh128', str_replace("\r\n", "\n", (string) File::get($source)));

ksort($files);

File::put(AssetManifest::path(), json_encode(['files' => $files], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
```

Recording the package's hash for a file you have edited makes it read as `modified` rather than
`conflict`. It is a claim that you have already considered the upstream change, so make it only
when that is true — the next run has no way to tell that claim from a real one.

## Reading the report from PHP

Everything the command prints comes from one call, so any status can be listed by path, asserted in
a test, or turned into a build failure.

```php
use PandaPanel\Support\Installer\AssetManifest;

$report = AssetManifest::compare();

$conflicts = array_keys(array_filter(
    $report,
    static fn (array $entry): bool => $entry['status'] === AssetManifest::CONFLICT,
));

// ['resources/js/panel/tables/DataTable.vue']
```

Each entry carries the two paths a diff needs:

```php
$report['resources/js/panel/tables/DataTable.vue'];
// [
//   'status' => 'conflict',
//   'destination' => '/var/www/app/resources/js/panel/tables/DataTable.vue',
//   'source' => '/var/www/app/vendor/chocoalano/panel/resources/js/panel/tables/DataTable.vue',
// ]
```

Which is enough to diff every conflict in one pass:

```php
use PandaPanel\Support\Installer\AssetManifest;

foreach (AssetManifest::compare() as $relative => $entry) {
    if ($entry['status'] !== AssetManifest::CONFLICT) {
        continue;
    }

    passthru(sprintf('diff -u %s %s', escapeshellarg($entry['destination']), escapeshellarg($entry['source'])));
}
```

To fail a build on conflicts — which the command deliberately will not do for you:

```php
exit($conflicts === [] ? 0 : 1);
```

## Not having the conflict next time

Every conflict starts as an edit to a published file, and some of those edits did not have to be
edits. Four extension points cover most of them, and none of them is a file the package also
writes:

| Instead of editing | Use | Documented in |
| --- | --- | --- |
| A class on a shell element | `Panel::cssHooks(array $classes)` | [CSS hooks](../frontend/css-hooks.md) |
| Markup inserted into the shell | `Panel::renderHook(RenderHook $hook, string $component, array $data = [], array $scopes = [])` | [Render hooks](../panels/render-hooks.md) |
| A column, field or entry component | A registered component of your own | [Custom columns](../frontend/custom-columns.md), [custom fields](../frontend/custom-fields.md) |
| A whole screen | A custom page or widget component | [Custom pages](../frontend/custom-pages.md), [custom widgets](../frontend/custom-widgets.md) |

```php
use PandaPanel\Core\Panel;

$panel->cssHooks([
    'sidebar' => 'bg-slate-950',
    'topbar' => 'border-b-2',
]);
```

A file you never edited is a file `--update` can always write, which is the whole point.

## Gotchas

- **Merging into your copy first is the trap.** Recorded as its own baseline, a hand-merged file
  reads as `out of date` — the package's copy still differs from it — and the next `--update`
  overwrites it silently. Take the package's copy first, record it, then re-apply. That is route 2,
  in that order.
- **`--force` is a whole-run switch.** It writes every `modified` and `conflict` file. Resolve the
  ones you care about individually first, or check the `yours` count before running it.
- **Run `panel:icons` after any `--force`.** `resources/js/panel/icons/registry.ts` is a published
  file that `php artisan panel:icons` generates from the icons your panels declare, so `--force`
  replaces it with the package's copy.
- **`npm run build` is part of the resolution, not an optimisation.** Published components are
  sources; the registries are `import.meta.glob` calls evaluated at build time. A file written on
  disk is not in the bundle until the build runs.
- **The manifest is only rewritten when at least one file was written.** A run that resolves
  nothing leaves the record exactly as it was, which is why route 2 uses `panel:install` — it
  writes the manifest unconditionally.
- **A bare `panel:assets` never writes anything**, including the manifest. Recording hashes as a
  side effect of asking a question would make the next run's answer depend on having asked.
- **Line endings are not an edit.** Hashes normalise `\r\n` to `\n` before hashing, because a report
  where a CRLF checkout makes every file a conflict is a report nobody reads.
- **A conflict never fails the command.** The exit code is `0`. Fail your own build on it with the
  snippet above if you want that.
- **Plugin assets are a different command.** `php artisan panel:publish` copies those, and it skips
  a file that already exists unless given its own `--force`. It has no manifest and no three-way
  comparison.
- **`vendor:publish --tag=panda-panel-assets --force` is still the wrong tool** after the first
  install. It is the two-way comparison this whole mechanism exists to replace.

## See also

- [Asset manifest](asset-manifest.md) — the file, the statuses, and the full `AssetManifest` API
- [Upgrade guide](upgrade-guide.md) — where this step sits in an upgrade
- [Breaking changes](breaking-changes.md), [Versioning policy](versioning.md)
- [`panel:assets`](../cli/panel-assets.md), [`panel:install`](../cli/panel-install.md), [`panel:icons`](../cli/panel-icons.md), [publish tags](../cli/publish-tags.md)
- [Updating published assets](../frontend/updating-assets.md), [Published asset structure](../frontend/assets.md)
- [CSS hooks](../frontend/css-hooks.md), [Render hooks](../panels/render-hooks.md)
- [Frontend assets](../concepts/frontend-assets.md), [Component registries](../concepts/component-registries.md)
- [Frontend build](../deployment/frontend-build.md), [Icon registry](../deployment/icon-registry.md)
- [Troubleshooting: asset conflicts](../troubleshooting/asset-conflicts.md)
