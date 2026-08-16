# Releases

How a change on `main` becomes a version somebody can install: what the number means, what goes in `CHANGELOG.md`, and what `.gitattributes` decides ends up inside the archive Composer downloads. That last one is not housekeeping — the installer reads a file from the package at runtime, and the export list has to keep that one file in.

## A minimal working example

```bash
composer validate --strict --no-check-publish
composer run format-check
composer run analyse
composer run test

git add .
git commit -m "Update package"
git push origin main

git tag -a v0.1.3 -m "v0.1.3"
git push origin v0.1.3
```

That is `UPDATE.md` in the repository root, and it is the whole procedure. Packagist picks the tag up from the GitHub webhook; there is nothing to upload.

`composer validate --strict --no-check-publish` is first for a reason: it is the only check that reads `composer.json` as a *manifest* rather than as a dependency list, and a malformed one is a tag nobody can install.

## Versioning

The package is in its `0.x` series — `v0.1.0`, `v0.1.1` and `v0.1.2` are the published tags — and semantic versioning treats `0.x` as the one range where the usual promise does not hold: **a `0.x` minor release is allowed to break things.** Composer knows that and narrows the caret accordingly, so `^0.1` resolves `>=0.1.0 <0.2.0`.

| Bump | When |
| --- | --- |
| Patch — `0.1.2` → `0.1.3` | A fix that needs no edit in any application. |
| Minor — `0.1.x` → `0.2.0` | New features, and any change that needs an edit. Until `1.0.0`, this is where breaking changes go. |
| Major — `0.x` → `1.0.0` | The point at which the caret starts meaning what it means everywhere else. |

After `1.0.0`, a breaking change is a major and nothing else is.

What the number covers is decided by `.gitattributes`: everything `export-ignore`d is absent from the installed package and therefore cannot be part of any promise about it. The full table of what is and is not covered is [Versioning policy](../upgrading/versioning.md); the short version is that `src`, `config`, `database`, `stubs` and `resources` are the shipped surface, and the published Vue files stop being covered the moment they are copied into an application.

There is no `Panel::VERSION` constant. The version comes from Composer:

```php
use Composer\InstalledVersions;

InstalledVersions::getPrettyVersion('chocoalano/panel');   // '0.1.2'
InstalledVersions::isInstalled('chocoalano/panel');        // true
```

A hand-written version string is a string somebody forgets to change, and a package reporting `1.2.0` while `1.4.1` is installed is worse than one reporting nothing.

## The changelog

`CHANGELOG.md` states its own contract in the header:

```markdown
# Changelog

All notable changes to `panda-panel` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]
```

New work is appended under `## [Unreleased]`, in one of the Keep a Changelog sections. All five are in use:

| Section | For |
| --- | --- |
| `### Security` | A vulnerability that was fixed. First, always. |
| `### Added` | A capability that did not exist. |
| `### Changed` | Different behaviour from the same API. |
| `### Fixed` | A defect. |
| `### Removed` | Something that is gone. |

The house style is prose, not a list of nouns. An entry opens with a bold sentence stating the change from the reader's side, then explains what was wrong and why the fix is the fix:

```markdown
- **A schema that cannot mean what it says is now refused, loudly.** Six declaration mistakes were
  silent, and all six produced wrong behaviour rather than no behaviour. `PanelSchemaException`
  covers them, and every message names the offending name and the fix:
```

An entry that needs an edit in an application says **Breaking:** in the same bullet and names the page with the fix:

```markdown
  **Breaking:** these throw where they previously did nothing. An application carrying one of them
  has a bug today and will get an exception at schema-build time after upgrading — which is at boot
  or on first render, so a test suite finds it before a user does.
```

A behaviour change that breaks nothing but surprises somebody says **Behaviour change:** instead. Both phrasings are already in the file; keeping to them means a reader can scan for either.

Every breaking entry also belongs in [`docs/upgrading/breaking-changes.md`](../upgrading/breaking-changes.md) with the smallest fix stated. The changelog says what happened; that page says what to do about it.

### Cutting the section

At release time, rename the heading and open a fresh one:

```markdown
## [Unreleased]

## [0.1.3] - 2026-08-16
```

Two things to do while you are in there:

- **Merge duplicated section headings.** The current `[Unreleased]` block carries two separate `### Fixed` sections, appended by two batches of work. One section per type per release is what makes the file readable.
- **Order the sections** `Security`, `Added`, `Changed`, `Fixed`, `Removed`, so `Security` is the first thing anybody upgrading sees.

The file carries no link-reference definitions at the bottom and no released version headings yet — every tag so far was cut from the `[Unreleased]` block. Adding a compare link per version is a reasonable improvement; it is not the current format, so do not assume one is there.

`CHANGELOG.md` is `export-ignore`d, so `vendor/chocoalano/panel/CHANGELOG.md` does not exist in an installed application. It is read in the repository.

## What ships

`.gitattributes` decides what a release archive contains. The header states the rule:

```text
# Kept out of the distributed package. `src`, `config`, `database`, `stubs`
# and `resources` are what an application installs; everything else is how
# this repository is developed.
/.github            export-ignore
/.ai                export-ignore
/.claude            export-ignore
/.codex             export-ignore
/docs               export-ignore
/examples           export-ignore
/integration        export-ignore
/tests              export-ignore
/frontend           export-ignore
/.editorconfig      export-ignore
/.gitattributes     export-ignore
/.gitignore         export-ignore
/CHANGELOG.md       export-ignore
/phpstan.neon       export-ignore
/phpunit.xml        export-ignore
/pint.json          export-ignore
/package-lock.json  export-ignore
/tsconfig.json      export-ignore
/vite.config.ts     export-ignore
/eslint.config.js   export-ignore
/.prettierrc.json   export-ignore
/.prettierignore    export-ignore
```

`package.json` is deliberately absent from that list, however much it looks like a development file — the next section is why, and the file carries a comment saying so.

Adding a config file at the repository root means adding a line here, or it ships to every application that installs the package.

Check what a tag would actually contain before pushing it:

```bash
git archive HEAD | tar -t | head -40
git archive HEAD | tar -t | wc -l
```

`git archive` applies `export-ignore` exactly as Composer's dist archive does, so this is the real answer rather than an approximation of it.

## `package.json` has to reach the dist

`PandaPanel\Support\Installer\FrontendRequirements` reads this repository's own `package.json` **at runtime, from inside `vendor/`**, to tell an application which npm packages the published components need:

```php
public static function npmManifestPath(): string
{
    return dirname(__DIR__, 3).'/package.json';
}

public static function npmPackages(): array
{
    $manifest = self::npmManifestPath();

    if (! File::exists($manifest)) {
        return [];
    }

    // ... read `dependencies`, return 'name@range' pairs ...
}
```

That is a deliberate design: the list lives in one file, and `php artisan panel:install` prints it, so the installer and the build cannot disagree. It only works if the file is there.

It was not, once. `/package.json export-ignore` used to be in the list, so in an application installed from a dist archive — which is what `composer require` does by default — `npmPackages()` found no manifest, returned `[]`, and `missingNpmPackages()` returned `[]` with it. `panel:install` then reported no missing npm dependencies, which reads exactly like "everything is installed" and is not. The failure surfaced later as `npm run build` complaining about a module specifier: a true error about the wrong thing, which is the outcome `FrontendRequirements` exists to prevent.

Nothing in the suite caught it, because the suite runs with this repository as the application and the file is right there. So the fix is in three parts, and all three are in place:

- **`.gitattributes` does not export-ignore `package.json`**, and carries a comment saying why, so the next person tidying the list does not add it back.
- **`Negative/DistributionTest` asserts the attribute itself** — `git check-attr export-ignore -- package.json` must answer `unspecified`, and `package-lock.json` must answer `set`. That is the only way this suite can see a packaging fault it is structurally blind to.
- **`FrontendRequirements::hasNpmManifest()` separates the two empty lists.** "Nothing is missing" and "I could not look" were the same `[]`; `panel:install` now reports the second as a packaging fault rather than as good news.

So before tagging:

```bash
git archive HEAD | tar -t | grep package.json    # must print package.json
```

`/package-lock.json` stays ignored — nothing reads it, and an application installs from the ranges rather than from this repository's resolution of them.

The rest of the frontend toolchain — the Vite config, the tsconfig, the lint configs, `frontend/` — genuinely does not ship. See [Frontend toolchain](frontend-toolchain.md).

## The rename lesson

`PandaPanel\Plugins\PluginCompatibility` looks the framework's own version up by name:

```php
private const PACKAGE = 'chocoalano/panel';
```

When the Composer package was renamed, that constant was left as `panda-panel`. `InstalledVersions::getPrettyVersion()` throws for a name the installation does not carry; the class reads a throw as "not installed as a package" and answers `null`; and a null version skips the constraint. So every `requiresPanel` a plugin declared had been passing unexamined, in every installation, since the rename — and the check would never have said no again.

`PluginTest` now compares the constant against `name` in `composer.json`, so it cannot happen the same way twice. The general lesson is the one worth carrying into any release: **a value that is a copy of something in `composer.json` needs a test that compares the two.** There are two of them now — this constant, and the npm dependency list.

Renaming the package again also means the migration path in [Package name migration](../upgrading/package-name-migration.md), which exists because a rename is not something Composer can follow on its own.

## Before a tag

```bash
composer validate --strict --no-check-publish    # ./composer.json is valid
composer ci                                      # pint --test, phpstan, pest
npm run ci                                       # prettier, eslint, vue-tsc, vite build

git archive HEAD | tar -t | grep package.json    # must print package.json
git archive HEAD | tar -t | grep 'docs/'         # must print nothing
```

The icon registry has no command to run here, because there is no `artisan` binary in this repository — `IconRegistryTest` is what checks it, and it is part of `composer ci`. In an application the equivalent is `php artisan panel:icons --check`.

Then the changelog section, then the tag:

```bash
git tag -a v0.1.3 -m "v0.1.3"
git push origin v0.1.3
```

An annotated tag (`-a`) rather than a lightweight one, so the tag carries a date and an author. Composer reads the tag name; a `v` prefix is stripped, so `v0.1.3` and `0.1.3` install identically and the `v` is the convention here.

## Notes

- **A tag is the release.** There is no build step, no artefact to upload, and no `dist` directory in this repository. Packagist reads the tag.
- **`composer.lock` is not committed**, so there is nothing to update at release time. CI resolves the declared ranges fresh on every run.
- **`minimum-stability` is `stable` and `prefer-stable` is `true`.** A `composer require chocoalano/panel` in an application never pulls a dev or beta dependency on this package's account.
- **A `0.x` minor may break.** Until `1.0.0`, the caret is what protects an application, and `>=` or `dev-main` in a constraint is what removes that protection.
- **The published frontend is versioned separately.** A `composer update` moves `vendor/chocoalano/panel` and does not move `resources/js/panel`, because those files became the application's when they were published. `.panel-assets.json` and `php artisan panel:assets` are that second story — see [Asset manifest](../upgrading/asset-manifest.md).
- **`UPDATE.md` is gitignored.** It is a working note rather than a tracked document, which is why the procedure is restated here.
- **Do not delete or move an ADR at release time.** A superseded record keeps its file and gains a status; see [Architecture decisions](architecture-decisions.md).

## See also

- [Versioning policy](../upgrading/versioning.md) — what a number covers, constraint by constraint
- [Release checklist](../upgrading/release-checklist.md)
- [Breaking changes](../upgrading/breaking-changes.md) — where a breaking entry goes besides the changelog
- [Changelog](../upgrading/changelog.md) — how the release notes are organised for a reader
- [Package name migration](../upgrading/package-name-migration.md)
- [Asset manifest](../upgrading/asset-manifest.md) — how the published frontend is versioned
- [Frontend toolchain](frontend-toolchain.md) — the rest of what does not ship, and why
- [Pull requests](pull-requests.md) — what lands on `main` before any of this
- [Packagist troubleshooting](../troubleshooting/packagist.md)
