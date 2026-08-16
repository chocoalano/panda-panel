# Installing from Packagist

The composer package is `chocoalano/panel`, and what it installs is a *subset* of this repository:
`src`, `config`, `database`, `resources` and `stubs`, plus `composer.json`, the README and the
licence. Everything else is `export-ignore`d and never reaches an application. Reach for this page
when `composer require` refuses, when the package installs and nothing registers, or when
`panel:install` is quieter than it should be.

## The install

```bash
composer require chocoalano/panel
php artisan panel:install
npm install
npm run build
```

Confirm what composer actually resolved before believing anything else:

```bash
composer show chocoalano/panel
composer why chocoalano/panel
```

The report names the version resolved, the source it came from, and the `PandaPanel\ => src/` PSR-4
mapping the whole framework autoloads through. `composer why` names what pulled it in, which is how
a transitive install through somebody else's package is spotted.

## 1. Composer cannot find the package

| What composer says | Cause |
| --- | --- |
| `Could not find a matching version of package chocoalano/panel` | The name is right and the constraint is not satisfiable, or the release is not stable |
| `it could not be found in any version, there may be a typo in the package name` | The name is wrong, or the repository composer is reading does not carry it |
| Nothing found under `panda/panel` | The old name. The package was renamed and there is no metapackage aliasing it |

The vendor is `chocoalano`, not `panda`, and the PHP namespace is `PandaPanel\` regardless — the
two never matched and were never meant to. An application installed under the old name has one line
to change; see [Package name migration](../getting-started/package-name-migration.md).

```bash
composer clear-cache
composer diagnose
```

Stability is the other half of "found but not resolvable". The package declares
`"minimum-stability": "stable"` and `"prefer-stable": true` for its own dependencies, but the
*root* application's `minimum-stability` is what decides whether a pre-release tag is a candidate
for it.

Installing from a git checkout rather than Packagist, which is also how a fork is tested:

```json
{
    "repositories": [
        { "type": "path", "url": "../panda-panel" }
    ],
    "require": {
        "chocoalano/panel": "*"
    }
}
```

A path repository symlinks by default, so the whole repository is visible — including the files a
dist archive would have stripped. That difference is the subject of section 4 and is the reason a
package can work perfectly from a path repository and be missing something from Packagist.

## 2. Composer refuses to resolve the requirements

```json
"require": {
    "php": "^8.2",
    "ext-json": "*",
    "ext-zip": "*",
    "composer-runtime-api": "^2.2",
    "composer/semver": "^3.0",
    "inertiajs/inertia-laravel": "^3.0",
    "laravel/framework": "^12.0|^13.0",
    "laravel/fortify": "^1.37.2",
    "symfony/finder": "^7.0|^8.0"
}
```

| Message names | Cause |
| --- | --- |
| `requires php ^8.2` | PHP below 8.2 |
| `requires ext-zip` | The zip extension is not installed. A hard requirement — an XLSX file is a zip archive, and failing at install is the only place that failure is cheap |
| `laravel/framework[v11.x] … does not match your constraint` | Laravel 11 is not supported and cannot be: every 11.x release is flagged by unpatched advisories |
| PHP 8.2 with Laravel 13 does not resolve | Laravel 13 requires PHP 8.3. PHP 8.2 applications get Laravel 12 |
| `laravel/fortify` conflicts | The floor is `^1.37.2`; the security settings page and the emailed-code factor both read Fortify's feature flags |

Nothing in `src/` reaches for a `require-dev` package, so `--no-dev` is safe. See the
[compatibility matrix](../getting-started/compatibility.md) for what CI actually runs.

## 3. The package installs and nothing is registered

**Symptom.** `vendor/chocoalano/panel` exists, and `php artisan` shows no `panel:*` commands.

**Cause.** Package discovery did not run. It is a composer script, so `--no-scripts` skips it, and
the result looks exactly like the package not being installed.

```json
"extra": {
    "laravel": {
        "providers": ["PandaPanel\\PandaPanelServiceProvider"],
        "aliases": { "PandaPanel": "PandaPanel\\Facades\\PandaPanel" }
    }
}
```

```bash
php artisan package:discover
php artisan about --only=drivers
```

Nothing needs adding to `bootstrap/providers.php`. If discovery is deliberately off in your
application, register the provider there by hand.

## 4. What the dist archive contains

Packagist serves a `git archive` of the tag, and `.gitattributes` decides what is in it. Reproduce
exactly what an application will receive:

```bash
git archive --format=tar HEAD | tar -t | awk -F/ '{print $1}' | sort -u
```

```text
LICENSE.md
README.md
composer.json
config/
database/
resources/
src/
stubs/
```

| In the dist | Why |
| --- | --- |
| `src/` | the framework, including `src/Testing` — the testing helpers are autoloaded through `composer.json`'s `files` |
| `config/panda-panel.php` | merged at register time and published by `--tag=panda-panel-config` |
| `database/migrations` | loaded from the package unless `load_migrations` is false |
| `resources/js`, `resources/css` | published by `--tag=panda-panel-assets` |
| `stubs/panel` | what every generator reads, published by `--tag=panda-panel-stubs` |

`export-ignore`d, and therefore absent: `/docs`, `/tests`, `/examples`, `/frontend`, `/.github`,
`CHANGELOG.md`, `phpstan.neon`, `phpunit.xml`, `pint.json`, `tsconfig.json`, `vite.config.ts`,
`eslint.config.js`, the Prettier configs — and `package.json` with `package-lock.json`, which is
the one to look at closely.

## 5. `panel:install` reports no npm dependencies to install

**Symptom.** `php artisan panel:install` finishes with `Done. Nothing is left to do by hand.`, and
then `npm run build` fails with `Failed to resolve import "reka-ui"`, or the same message naming any
other package the published components import.

**Cause.** The npm dependency list is not restated in PHP. It is read out of *this package's own*
`package.json`, from the installed copy under `vendor/`:

```php
// PandaPanel\Support\Installer\FrontendRequirements::npmPackages()
$manifest = dirname(__DIR__, 3).'/package.json';

if (! File::exists($manifest)) {
    return [];
}
```

A missing file is an empty list, not an error — and an empty list means `missingNpmPackages()`
also answers `[]`, so the installer has nothing to report. The check does not fail; it goes quiet.

**Confirm it in the installed copy, not in a checkout:**

```bash
ls vendor/chocoalano/panel/package.json
```

```php
use PandaPanel\Support\Installer\FrontendRequirements;

FrontendRequirements::npmPackages();
// [] when package.json did not reach the dist
// ['@inertiajs/vue3@^3.0.0', '@internationalized/date@^3.12.0', …] when it did
```

**Fix, on the package side.** `/package.json` must not be `export-ignore`d, even though the rest
of the frontend toolchain is. The lockfile, the Vite config, the tsconfig and the lint configs are
all development-only and correctly stripped; `package.json` is read at runtime by the installer and
has to travel.

```diff
  # .gitattributes
- /package.json      export-ignore
  /package-lock.json export-ignore
```

```bash
git archive --format=tar HEAD | tar -t | grep '^package.json'
```

No output means the archive does not carry it, and an installed copy will not either. Nothing in
the test suite catches this: the suite runs from a git checkout, where `package.json` is present
whatever `.gitattributes` says.

**Workarounds for an application on a release that shipped without it.** Either install from source
so the whole repository lands in `vendor/`:

```bash
composer require chocoalano/panel --prefer-source
```

Or install the list by hand. It is the `dependencies` block of the package's `package.json`, and it
is reproduced in full in [Vite build errors](vite.md#failure-2-a-missing-npm-dependency) and
[Frontend requirements](../getting-started/frontend-requirements.md):

```bash
npm install \
  @inertiajs/vue3@^3.0.0 @internationalized/date@^3.12.0 @laravel/echo-vue@^2.4.0 \
  @laravel/passkeys@^0.4.0 @lucide/vue@^1.31.0 @tailwindcss/vite@^4.1.0 \
  @tanstack/vue-table@^9.0.0 @vueuse/core@^14.0.0 class-variance-authority@^0.7.0 \
  clsx@^2.1.0 reka-ui@^2.0.0 tailwind-merge@^3.0.0 tailwindcss@^4.1.0 \
  tw-animate-css@^1.2.0 vue@^3.5.0 vue-input-otp@^0.4.0 vue-sonner@^2.0.0
npm run build
```

Node must be `>=20.19`, which is the package's declared engine range.

### The two methods behind all of this

| Method | Signature | Reads |
| --- | --- | --- |
| `npmPackages` | `static npmPackages(): list<string>` | the **package's** `package.json`, `dependencies` only, as `name@range`, sorted |
| `missingNpmPackages` | `static missingNpmPackages(): list<string>` | the **application's** `package.json`, `dependencies` and `devDependencies`, and filters |

```php
use PandaPanel\Support\Installer\FrontendRequirements;

FrontendRequirements::missingNpmPackages();
// ['@lucide/vue@^1.31.0', 'reka-ui@^2.0.0']
```

`missingNpmPackages()` reads the application's `package.json` rather than `node_modules`, because
what matters is whether the project *declared* the dependency: a transitive copy on disk today is
one somebody else's upgrade removes tomorrow. An application with no `package.json` at all is
reported as missing everything.

## 6. `vendor:publish` finds no assets to publish

**Cause.** The publish map is built from `PandaPanel\Support\Installer\PublishedAssets::map()`,
which points at directories inside the installed package. A dist that did not carry
`resources/js` publishes nothing, and so does a `--tag` that does not exist.

```bash
php artisan vendor:publish --tag=panda-panel-assets
ls vendor/chocoalano/panel/resources/js/panel | head
```

```php
use PandaPanel\Support\Installer\PublishedAssets;

PublishedAssets::map();        // absolute package source => absolute application destination
PublishedAssets::files();      // absolute destination => absolute source, per file
```

| Tag | Publishes |
| --- | --- |
| `panda-panel-config` | `config/panda-panel.php` |
| `panda-panel-assets` | the seven frontend sources |
| `panda-panel-migrations` | `database/migrations` |
| `panda-panel-stubs` | `stubs/panel` |
| `panda-panel` | config, migrations and assets together — **not** the stubs |

The second most common cause is not the archive at all: `vendor:publish` skips any file that
already exists. After the first install, use [`panel:assets`](../cli/panel-assets.md), which knows
what you edited because `.panel-assets.json` records what each file looked like when it was
published.

## 7. Which version is installed, and what depends on it

The framework's own version is read from Composer's installed-packages data, under the name
composer knows it by:

```php
use Composer\InstalledVersions;

InstalledVersions::getPrettyVersion('chocoalano/panel');   // '1.0.0'
```

```bash
php artisan panel:plugins
php artisan panel:plugins --panel=admin
```

`PandaPanel\Plugins\PluginCompatibility` uses the same lookup to evaluate a plugin's
`requiresPanel` constraint, and a test compares its `PACKAGE` constant against `name` in
`composer.json` so a future rename cannot silently turn the check off:

```php
$reflection = new ReflectionClass(PluginCompatibility::class);

expect($reflection->getConstant('PACKAGE'))
    ->toBe(json_decode(file_get_contents(base_path('composer.json')), true)['name']);
```

Three cases skip the constraint check entirely, and all three mean there is no question to answer:
a plugin that declared no constraint, a framework not installed as a composer package (a path
repository, a git checkout), and a branch alias like `dev-main`, which no constraint can be
evaluated against.

## 8. Installing on a deploy

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan optimize
npm ci && npm run build
```

| Flag | Effect here |
| --- | --- |
| `--no-dev` | skips Pest, Pint, Larastan, Mockery, Testbench — none of which `src/` touches |
| `--prefer-dist` | downloads the archive rather than cloning, which is what makes section 4 matter |
| `--optimize-autoloader` | dumps a classmap, so discovery resolves without a filesystem probe |
| `--no-scripts` | **avoid** — package discovery is a composer script |

`composer install` first, then `panel:cache`: discovery resolves file paths through Composer's
PSR-4 map, and a manifest written against an older autoloader names classes that have moved.

## Gotchas

- **`--prefer-dist` and `--prefer-source` install different trees.** Dist honours `export-ignore`;
  source is a clone and carries everything. A bug that only reproduces on one of them is almost
  always an archive question.
- **`composer.json` in the dist still declares `autoload-dev` for `tests/` and `examples/`**,
  which are not in the archive. That is harmless — `autoload-dev` applies to the root package only,
  never to a dependency.
- **The repository and the framework are still called Panda Panel.** The rename was to the composer
  vendor namespace; `config/panda-panel.php`, `PandaPanel\`, the publish tags and the route names
  are all unchanged.
- **`CHANGELOG.md` is not in the dist.** Read it in the repository or on the release page; an
  installed copy has no changelog to check.
- **`docs/` is not in the dist either.** These pages live in the repository.
- **The npm package `@chocoalano/panel` is `private: true` and has never been published.** It is
  this repository's toolchain; the components reach an application through `vendor:publish`.
- **`package-lock.json` is deliberately not shipped.** This repository's lockfile pins its own
  toolchain; an application installs from the version *ranges* instead.
- **A path repository turns every plugin `requiresPanel` constraint off**, silently. That is right
  for a monorepo and wrong for a staging environment meant to mirror production.

## See also

- [Installation](../getting-started/installation.md), [requirements](../getting-started/requirements.md),
  [compatibility matrix](../getting-started/compatibility.md)
- [Package name migration](../getting-started/package-name-migration.md),
  [upgrade guide](../upgrading/upgrade-guide.md), [versioning](../upgrading/versioning.md)
- [Composer and autoloading](../deployment/composer.md),
  [production checklist](../deployment/production-checklist.md)
- [Frontend requirements](../getting-started/frontend-requirements.md),
  [running `panel:install`](../getting-started/installer.md)
- [`panel:install`](../cli/panel-install.md), [`panel:assets`](../cli/panel-assets.md),
  [publish tags](../cli/publish-tags.md), [`panel:plugins`](../cli/panel-plugins.md)
- [Plugin compatibility](../plugins/compatibility.md)
- [Vite build errors](vite.md), [missing host modules](host-modules.md), [Tailwind 4](tailwind.md)
- [Common install problems](../getting-started/common-install-problems.md)
