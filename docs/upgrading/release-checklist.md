# Release Checklist

Everything between "main is green" and "a tag exists", in the order the steps have to happen in.
Reach for it before tagging `chocoalano/panel` itself, and as a template when releasing a plugin or
a fork — most of what is here is not specific to this package, but two of the steps are, and both
of those are ones a passing test suite cannot catch.

## A minimal working example

```bash
composer ci                                          # pint --test, phpstan, pest
npm run ci                                           # prettier, eslint, vue-tsc, vite build

git archive HEAD | tar -t | awk -F/ '{print $1}' | sort -u   # what a composer install gets

git tag -a v0.1.3 -m "v0.1.3"
git push origin v0.1.3
```

Between the second and third commands sit the CHANGELOG entry and the upgrade-guide section, which
are the two steps no command performs.

## 1. The verification loop

Two commands run everything CI runs, minus the matrix. They are declared in the repository, so they
cannot drift from what the pipeline does:

```json
"scripts": {
    "test": "vendor/bin/pest",
    "test-coverage": "vendor/bin/pest --coverage",
    "format": "vendor/bin/pint",
    "format-check": "vendor/bin/pint --test",
    "analyse": "vendor/bin/phpstan analyse --memory-limit=1G",
    "ci": ["@format-check", "@analyse", "@test"]
}
```

```json
"scripts": {
    "lint": "eslint resources/js frontend --max-warnings=0",
    "lint:fix": "eslint resources/js frontend --fix",
    "format": "prettier --write resources/js frontend resources/css",
    "format:check": "prettier --check resources/js frontend resources/css",
    "typecheck": "vue-tsc --noEmit -p tsconfig.json",
    "build": "vite build",
    "ci": "npm run format:check && npm run lint && npm run typecheck && npm run build"
}
```

```bash
composer ci
npm run ci
```

Both orderings put the cheapest and most specific failure first: formatting, then lint or static
analysis, then types, then the thing that actually runs. A type error is a better message than the
bundler's version of the same problem.

Individually, while fixing something:

```bash
composer format                       # pint, writing
composer analyse                      # phpstan at the memory limit CI uses
composer test                         # the whole suite
vendor/bin/pest --filter=PluginTest   # one file
npm run lint:fix
npm run typecheck
```

### What CI adds

`.github/workflows/tests.yml` runs the same checks across the combinations a single machine cannot:

| Job | Runs | Matrix | Blocking |
| --- | --- | --- | --- |
| `test` | `vendor/bin/pest` | PHP 8.2/8.3/8.4 × Laravel 12/13 × `prefer-lowest`/`prefer-stable`, less the combination Laravel 13 does not allow — 10 jobs | yes |
| `static-analysis` | `vendor/bin/phpstan analyse` | both ends of the supported range | yes |
| `code-style` | `vendor/bin/pint --test` | one job | yes |
| `frontend` | `format:check`, `lint`, `typecheck`, `build` | Node 20, 22, 24 | yes |
| `frontend-latest` | `typecheck`, `build` against the top of every npm range | one job | no — `continue-on-error` |

Wait for all of them before tagging. `prefer-lowest` is the one that catches a call to a method
added in a minor release the constraint does not require, and it is the half of the matrix a local
`composer ci` never reproduces. The full breakdown is [CI matrix](../testing/ci-matrix.md).

### Four tests that exist to fail at release time

Most of the suite is about the framework's behaviour. Four assertions are about the *package*, and
each one guards a mistake that a release is exactly when you make:

| Test | Pins |
| --- | --- |
| `PluginTest` — "looks up its own version under the name composer knows it by" | `PluginCompatibility::PACKAGE` against `name` in `composer.json`. A rename that misses the constant silently disables every plugin constraint. |
| `AssetUpgradeTest` — "reads every real shipped file as current in this repository" | The publish map against the tree. A file added to `resources/js` and not covered by `PublishedAssets::map()` publishes but is never reported as out of date. |
| `FrontendContractTest` | The host-module list against the imports in the published tree, and the three grid class tables against the PHP clamp. |
| `StylingTest` | `overflow-x-clip` on the content wrapper — `overflow-x-hidden` computes the other axis to `auto`, which captures every `position: sticky` inside it, invisibly. |

None of them can be fixed after a tag exists.

## 2. Write the CHANGELOG entry

`CHANGELOG.md` follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), which means the
release step is a heading edit: `## [Unreleased]` becomes the version with a date, and a fresh
empty `## [Unreleased]` opens above it.

```markdown
## [Unreleased]

## [0.1.3] - 2026-08-15

### Security

- …

### Added

- …

### Fixed

- …
```

The house conventions inside a section, all of which the existing file demonstrates:

- **A bolded lead that states the user-visible change**, then the reasoning. Naming the failure the
  change replaces is what makes the entry findable later, because the error string is what somebody
  will search for.
- **`Security` first.** An entry nobody scrolls to is an entry nobody reads.
- **Anything needing an edit says so inline**, as `**Breaking:**` or `**Behaviour change:**`, and
  names where the fix is written out.

How the file is read from the other side — including which category a breaking change can turn up
under, which is any of them — is [Changelog](changelog.md).

```bash
grep -n '^### ' CHANGELOG.md               # the categories in this release
grep -n '\*\*Breaking:\|\*\*Behaviour change:' CHANGELOG.md
```

## 3. Write the upgrade-guide section for anything breaking

**The invariant: every entry marked breaking has a matching section in the upgrade guide.** The
changelog says what changed and why; the guide says what breaks and what to type. A release that
adds the first without the second ships a change nobody can act on.

Two files:

| File | What it gets |
| --- | --- |
| [`docs/upgrading/breaking-changes.md`](breaking-changes.md) | A numbered `###` section: **What changed**, **What breaks**, and the smallest edit that fixes it — with the code. |
| [`docs/upgrading/upgrade-guide.md`](upgrade-guide.md) | A row in the version-specific notes table, saying whether the change is silent. |

A section that says "consider" is not finished. If doing nothing leaves the application working,
the change was not breaking and belongs in the changelog alone.

```bash
# Every breaking marker in the changelog should have a home in the guide.
grep -c '\*\*Breaking:\|\*\*Behaviour change:' CHANGELOG.md
grep -c '^### [0-9]' docs/upgrading/breaking-changes.md
```

Two kinds of change need a sentence that is easy to forget:

- **A change to a published file.** `resources/js/**` and `resources/css/panda-panel.css` are the
  application's, so `composer update` does not deliver the fix. The section has to say
  `php artisan panel:assets --update` and `npm run build`, and point at
  [Resolving asset conflicts](asset-conflicts.md) for the case where the reader edited that file.
- **A new config key.** `mergeConfigFrom()` means a published `config/panda-panel.php` from a year
  ago already has the new default, so the section says how to *opt out*, not how to add the key.

## 4. Confirm the dist contains what the installer needs

This is the step no test performs, because the suite runs from the repository — where every file is
present — and an application installs the **archive**, where `.gitattributes` has removed some of
them.

```bash
git archive HEAD | tar -t | awk -F/ '{print $1}' | sort -u
```

```text
LICENSE.md
README.md
composer.json
config
database
resources
src
stubs
```

That is what a `composer require chocoalano/panel` unpacks. Everything else is `export-ignore`d:

```text
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
/package.json       export-ignore
/package-lock.json  export-ignore
/tsconfig.json      export-ignore
/vite.config.ts     export-ignore
/eslint.config.js   export-ignore
/.prettierrc.json   export-ignore
/.prettierignore    export-ignore
```

Three questions to ask of that listing, in order.

**Is everything the runtime reads still there?** The four directories an application depends on are
`src`, `config`, `database` and `resources`, plus `stubs` for the generators — the `make:panel*`
commands read the package's own stubs and fall back to the application's published copies:

```bash
git archive HEAD | tar -t | grep -c '^src/'
git archive HEAD | tar -t | grep '^stubs/'
git archive HEAD | tar -t | grep -c '^resources/js/'
```

**Did this release add a top-level directory?** A new directory is shipped by default, which is the
safe direction. The one that bites is the opposite: a directory added under an existing
`export-ignore`d path, or a new development directory nobody added a rule for, which then ships.

**Does anything in `src/` read a file that is not in the archive?** This is the failure mode worth
naming, because it is silent by construction. `PandaPanel\Support\Installer\FrontendRequirements`
reads the package's own `package.json` for the npm dependency list:

```php
$manifest = dirname(__DIR__, 3).'/package.json';

if (! File::exists($manifest)) {
    return [];
}
```

`/package.json` is `export-ignore`d, so in an application installed from the archive that file is
not there, `npmPackages()` answers `[]`, and `panel:install` therefore reports no missing npm
packages — the same output as an application that has them all. An install made with
`--prefer-source`, which clones the repository, does have the file and does report. Whether that
trade is the intended one is a release decision; `git archive HEAD | tar -t` is how you find out it
is a decision at all.

```bash
grep -rn "dirname(__DIR__" src/ | grep -v 'src/Support/Installer/PublishedAssets.php'
```

**Then install it for real.** The archive listing proves what is in the box; only an install proves
the box works:

```json
{
    "repositories": [
        { "type": "path", "url": "../panda-panel", "options": { "symlink": false } }
    ]
}
```

```bash
composer require chocoalano/panel:@dev
php artisan panel:install
php artisan panel:assets
npm install && npm run build
```

`"symlink": false` matters: a symlinked path repository is the repository, so it has `docs/`,
`tests/` and `package.json` and proves nothing about the dist. A copied one is closer, though
`composer archive` and `git archive` remain the only things that apply `export-ignore` exactly.

## 5. Decide the version number

`composer.json` declares no `version` key, deliberately — the tag is the version, and a number in
two places is a number that disagrees with itself. Composer derives it from the tag, and reports
`1.0.0+no-version-set` for a checkout that has none, which is one of the three cases
`PluginCompatibility` treats as "no question to answer".

```bash
git tag                                   # v0.1.0, v0.1.1, v0.1.2
composer show chocoalano/panel --all      # what Packagist knows about
```

The package is in its `0.x` series, where semantic versioning suspends its usual promise:

| Kind of change | While `0.x` | After `1.0.0` |
| --- | --- | --- |
| Breaking | minor — `0.1.2` → `0.2.0` | major |
| New surface, nothing broken | minor | minor |
| Fix only | patch — `0.1.2` → `0.1.3` | patch |

The consequence for a constraint an application wrote is that `^0.1` resolves `>=0.1.0 <0.2.0`, so
a `0.2.0` is opt-in by `composer require chocoalano/panel:^0.2` and nobody is surprised by it.
That, and what the number covers at all, is [Versioning policy](versioning.md).

Three things about the number that are decided at this step and nowhere else:

**Did a dependency range in `composer.json` move?**

```json
"require": {
    "php": "^8.2",
    "laravel/framework": "^12.0|^13.0",
    "inertiajs/inertia-laravel": "^3.0",
    "laravel/fortify": "^1.37.2",
    "symfony/finder": "^7.0|^8.0"
}
```

Widening a range breaks nothing. **Narrowing one is breaking** — an application on the version you
dropped stops resolving — so it takes a minor at `0.x` and a paragraph in
[Compatibility](../getting-started/compatibility.md), plus a matching change to the CI matrix, or
the range and the thing that tests it disagree.

**Did an npm range in `package.json` move?** That file is not installed, but it is the list
`panel:install` and the docs both read, and the components are built by the *application's* Vite
against its own tree. A range change is a compatibility change even though composer will never
notice it.

**Whose plugins does this number refuse?** `requiresPanel` constraints are evaluated against the
version composer reports for `chocoalano/panel`, so a `0.1` → `0.2` bump refuses every plugin that
declared `^0.1` — at boot, which fails every route and every artisan command in the application
until it is resolved. That is the designed behaviour and it is the reason the bump is deliberate:

```php
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Plugins\PluginCompatibility;

// What an application with a ^0.1 plugin will get from 0.2.0.
expect(fn () => PluginCompatibility::assert(new BillingPlugin, 'admin', '0.2.0'))
    ->toThrow(PanelRegistrationException::class);
```

```bash
composer validate --strict     # ./composer.json is valid
```

## 6. Tag, and push the tag

```bash
git tag -a v0.1.3 -m "v0.1.3"
git push origin v0.1.3
```

Packagist reads tags, and the version it publishes is the tag with the leading `v` removed, so
`v0.1.3` becomes `0.1.3`. Nothing in `composer.json` changes.

Once the tag is on Packagist, the number is permanent: a tag can be deleted, but anything that
already resolved it has it in a `composer.lock`. Prefer `0.1.4` to re-tagging `0.1.3`.

## 7. Verify from the outside

```bash
composer show chocoalano/panel        # the version an install now resolves
composer why chocoalano/panel
```

In a scratch application:

```bash
composer create-project laravel/laravel scratch
cd scratch
composer require chocoalano/panel
php artisan panel:install
php artisan panel:user
npm install && npm run build
php artisan serve
```

Then check the three things only a browser answers: the sidebar lists the resources, an icon
renders, and the console is empty.

One thing is worth checking specifically in an installed application, because it cannot be checked
anywhere else:

```php
use Composer\InstalledVersions;

InstalledVersions::getPrettyVersion('chocoalano/panel');   // '0.1.3', not null
```

A `null` there means `PluginCompatibility::PACKAGE` no longer matches `composer.json`, and every
plugin constraint in every installation has been switched off silently. See
[Package name migration](package-name-migration.md), which is what that failure looked like the
first time.

## The whole list

| # | Step | Command |
| --- | --- | --- |
| 1 | PHP checks | `composer ci` |
| 2 | Frontend checks | `npm run ci` |
| 3 | CI green on all combinations | — |
| 4 | `composer.json` still valid | `composer validate --strict` |
| 5 | CHANGELOG: `Unreleased` becomes the version, new `Unreleased` above | — |
| 6 | Breaking entries have a section in [Breaking changes](breaking-changes.md) | — |
| 7 | Version-specific row in [Upgrade guide](upgrade-guide.md) | — |
| 8 | The dist contains what the installer needs | `git archive HEAD \| tar -t` |
| 9 | Install once from a path repository | `composer require chocoalano/panel:@dev` |
| 10 | Choose the number against [Versioning](versioning.md) | — |
| 11 | Tag and push | `git tag -a v0.1.3 -m "v0.1.3" && git push origin v0.1.3` |
| 12 | Verify from a scratch application | `composer require chocoalano/panel` |

## Gotchas

- **A green suite says nothing about the dist.** Tests run from the repository, where `package.json`,
  `docs/` and `frontend/` all exist. `git archive HEAD | tar -t` is the only thing that shows what an
  application actually receives.
- **`composer.json` has no `version` key, on purpose.** The tag is the version. Adding one gives you
  two numbers that will eventually disagree.
- **A changelog heading is not a release.** Three tags exist; the file's entries are all still under
  `## [Unreleased]`. Composer resolves tags, not headings.
- **`CHANGELOG.md` is `export-ignore`d**, so a release note is only readable in the repository or on
  GitHub — never in `vendor/`.
- **`--prefer-source` hides an export mistake.** It clones instead of unpacking, so every
  `export-ignore`d file is present and anything reading one appears to work.
- **A `0.x` minor is allowed to break, and will refuse plugins.** `requiresPanel: '^0.1'` stops
  being satisfied at `0.2.0`, at boot, for the whole application. That is the feature; it is still
  worth knowing you are about to trigger it.
- **Narrowing a dependency range is a breaking change** even though nothing in the code changed.
  Widening one is not.
- **`panel:assets` must read `current` for every file in this repository.** Anything else means the
  publish map and the tree have drifted, and `AssetUpgradeTest` fails for exactly that reason.
- **Re-tagging is worse than a patch release.** Anything that already resolved the old tag has it in
  a lockfile.

## See also

- [Changelog](changelog.md) — the file this checklist edits, and how it is read
- [Breaking changes](breaking-changes.md) — the section a breaking release has to add
- [Upgrade guide](upgrade-guide.md) — the procedure a release puts an application through
- [Versioning policy](versioning.md) — what the number promises and what it covers
- [Package name migration](package-name-migration.md) — the rename, and the check it disabled
- [Asset manifest](asset-manifest.md), [Resolving asset conflicts](asset-conflicts.md)
- [CI matrix](../testing/ci-matrix.md), [Testing setup](../testing/setup.md), [Frontend contract tests](../testing/frontend-contract-tests.md)
- [Compatibility](../getting-started/compatibility.md), [Requirements](../getting-started/requirements.md)
- [`panel:install`](../cli/panel-install.md), [`panel:assets`](../cli/panel-assets.md), [`panel:plugins`](../cli/panel-plugins.md), [publish tags](../cli/publish-tags.md)
- [Plugin compatibility](../plugins/compatibility.md)
- [Frontend build](../deployment/frontend-build.md), [Composer in production](../deployment/composer.md)
