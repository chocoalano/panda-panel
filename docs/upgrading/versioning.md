# Versioning Policy

What a version number of `chocoalano/panel` means, which parts of the package it covers, and what
to write in a `composer.json` constraint because of it. Reach for this before pinning a constraint,
before declaring `requiresPanel` in a plugin, and whenever you need to know whether a given upgrade
is allowed to break your application.

## A minimal working example

```bash
composer require chocoalano/panel
composer show chocoalano/panel
composer outdated chocoalano/panel
```

`composer show` prints the resolved version, and that string — not a git branch, not a
`CHANGELOG.md` heading — is what everything on this page is about. It is also what the framework
itself reads when a plugin declares a constraint.

## Semantic versioning, and where the project is on it

`CHANGELOG.md` states the contract in its own header:

```text
The format follows Keep a Changelog, and this project adheres to Semantic Versioning.
```

The published tags so far are `v0.1.0`, `v0.1.1` and `v0.1.2`, so the package is in its `0.x`
series. Semantic versioning treats `0.x` as the one range where the usual promise does not hold:
**a `0.x` minor release is allowed to break things.** Composer knows that and adjusts the caret
accordingly.

```bash
git tag                      # in a checkout of the repository
composer show chocoalano/panel | grep versions
```

| Constraint | Resolves to | Breaking changes possible |
| --- | --- | --- |
| `^0.1` | `>=0.1.0 <0.2.0` | within `0.1.x`: no |
| `^0.1.2` | `>=0.1.2 <0.2.0` | within `0.1.x`: no |
| `~0.1.2` | `>=0.1.2 <0.2.0` | same as the caret, at `0.x` |
| `0.1.*` | `>=0.1.0 <0.2.0` | same |
| `>=0.1` | anything newer, including `1.x` | yes, silently |
| `dev-main` | whatever `main` is today | yes, on every `composer update` |

The caret is the right default and it is what `composer require chocoalano/panel` writes. `>=` and
`dev-main` are the two to avoid: the first opts an application into every future breaking change
without saying so, and the second makes `composer update` non-reproducible.

After `1.0.0`, `^1.0` behaves the way the caret does everywhere else — minors and patches only, no
breaking changes. Until then, read [Breaking changes](breaking-changes.md) before every minor bump.

## What a version number covers

`.gitattributes` decides what a release actually contains. Everything marked `export-ignore` is
absent from the installed package, so it cannot be part of any promise about it:

```text
/.github            export-ignore
/docs               export-ignore
/examples           export-ignore
/tests              export-ignore
/frontend           export-ignore
/CHANGELOG.md       export-ignore
/package.json       export-ignore
/vite.config.ts     export-ignore
/phpstan.neon       export-ignore
/pint.json          export-ignore
```

What is left — `src`, `config`, `database`, `stubs`, `resources` — is the shipped surface. Within
it:

| Covered by the version number | Why |
| --- | --- |
| `PandaPanel\*` classes the documentation names | An application calls them directly. |
| Config keys in `config/panda-panel.php` | `panels`, `register_routes`, `register_web_middleware`, `register_guest_redirect`, `home_redirect`, `load_migrations`, `integrations`, `frontend`. |
| Publish tags | `panda-panel`, `panda-panel-config`, `panda-panel-assets`, `panda-panel-migrations`, `panda-panel-stubs`. |
| Artisan command names and their options | `panel:install`, `panel:assets`, `panel:cache`, `panel:clear`, `panel:icons`, `panel:plugins`, `panel:publish`, `panel:user`, and the five `make:panel*` generators. |
| Route names | `panel.{id}.*`. |
| `PandaPanel\Testing\*` and its global helper functions | An application's own suite calls them. |
| The serialized shape a panel shares with Vue | `SharePanelData` props are what a customised frontend reads. |

Not covered, and each for a stated reason:

| Not covered | Why not |
| --- | --- |
| The published Vue and TypeScript files | They are copied into the application at install time and become the application's. A package version says nothing about a file you now own — that is what [`.panel-assets.json`](asset-manifest.md) is for. |
| Anything `export-ignore`d | It is not in the installed package. The frontend toolchain, the test suite, `examples/` and these docs are how the repository is developed. |
| Generator stub *contents* | `stubs/panel` is scaffolding, and a scaffold that never changes is a scaffold that never improves. Publish them with `--tag=panda-panel-stubs` to freeze your own. |
| The npm package | `@chocoalano/panel` is `"private": true` at version `0.0.0` and has never been published. The components reach an application through `vendor:publish`. |

## Dependency ranges

From `composer.json`, and each one is a range rather than a pin because a library that pins is a
library that cannot be installed beside anything else:

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

```json
"minimum-stability": "stable",
"prefer-stable": true
```

`minimum-stability: stable` means a `composer require chocoalano/panel` in an application never
pulls a dev or beta dependency on this package's account.

Both ends of every range are tested. CI resolves with `--prefer-lowest` as well as
`--prefer-stable`, ten jobs in total, because a dependency that only works at its newest version is
a dependency whose constraint is wrong. The full table of what is supported is
[Compatibility](../getting-started/compatibility.md); the jobs themselves are in
[CI matrix](../testing/ci-matrix.md).

`composer-runtime-api` is required because two classes read composer's own installed-package data
at runtime — which is the next section.

## Asking an installation what it has

```bash
composer show chocoalano/panel        # version, source, requires
composer why chocoalano/panel         # which of your constraints pulled it
composer why-not chocoalano/panel ^1  # why a newer one will not resolve
php artisan panel:plugins             # plugins, per panel, with their versions
php artisan about --only=environment  # PHP and Laravel, for a bug report
```

In PHP, the version comes from composer rather than from a constant in this package. A hand-written
version string is a string somebody forgets to change, and a package reporting `1.2.0` while
`1.4.1` is installed is worse than one reporting nothing:

```php
use Composer\InstalledVersions;

InstalledVersions::getPrettyVersion('chocoalano/panel');   // '0.1.2'
InstalledVersions::isInstalled('chocoalano/panel');        // true
```

`getPrettyVersion()` **throws** for a package the installation does not carry. That is not a
detail — it is the exact failure the package rename caused, and it is documented in
[Package name migration](package-name-migration.md).

## Declaring a version requirement from a plugin

A plugin is code that reaches into a panel and adds resources, pages, widgets and routes to it.
When one breaks, the two questions are always *which plugin* and *which version of it*, and neither
is answerable from a class name. `PandaPanel\Plugins\PluginMetadata` is where a plugin answers
both.

```php
use PandaPanel\Plugins\Plugin;
use PandaPanel\Plugins\PluginMetadata;

final class BillingPlugin extends Plugin
{
    public function id(): string
    {
        return 'billing';
    }

    public function metadata(): PluginMetadata
    {
        return new PluginMetadata(
            name: 'Billing',
            package: 'acme/panda-billing',
            requiresPanel: '^0.1',
            url: 'https://github.com/acme/panda-billing',
        );
    }
}
```

`PandaPanel\Plugins\PluginMetadata` — a `final readonly` class:

```php
public function __construct(
    public string $name,
    public ?string $package = null,
    public ?string $requiresPanel = null,
    public ?string $url = null,
) {}
```

| Parameter | Type | Default | Meaning |
| --- | --- | --- | --- |
| `name` | `string` | required | Human-readable, for a report a person reads. |
| `package` | `string\|null` | `null` | The plugin's own composer package, used for the version lookup. |
| `requiresPanel` | `string\|null` | `null` | A composer-style constraint against **this framework**. |
| `url` | `string\|null` | `null` | Where to read about it. |

| Method | Signature | Returns |
| --- | --- | --- |
| `version` | `version(): ?string` | The installed version of `package`, from composer. `null` when `package` is `null`, and `null` when composer has never heard of the name. |
| `toArray` | `toArray(): array{name, package, version, requiresPanel, url}` | What `panel:plugins` prints. |

```php
$metadata = new PluginMetadata(name: 'Billing', package: 'acme/panda-billing');

$metadata->version();   // '2.1.0', or null for a plugin that lives in the application
$metadata->toArray();   // ['name' => 'Billing', 'package' => 'acme/panda-billing', 'version' => '2.1.0', …]
```

A `null` version is a normal answer, not an error: a project's own plugin lives in the application
and is versioned by the application. A wrong package name is reported as unknown rather than
raised, because that is a documentation bug and not a reason to refuse to boot.

## How the constraint is checked

`PandaPanel\Plugins\PluginCompatibility` runs when the plugin registers — the earliest moment the
answer is knowable and the last moment before the plugin starts changing the panel.

```php
public static function assert(
    PanelPlugin $plugin,
    string $panelId,
    ?string $installed = null,
): void
```

| Parameter | Type | Default | Meaning |
| --- | --- | --- | --- |
| `$plugin` | `PandaPanel\Contracts\PanelPlugin` | required | Read for `metadata()->requiresPanel`, `metadata()->name` and `id()`. |
| `$panelId` | `string` | required | Named in the exception, because the same plugin can be on several panels. |
| `$installed` | `string\|null` | `null` | The framework version to check against; defaults to the one composer reports. |

It throws `PandaPanel\Exceptions\PanelRegistrationException` when the constraint is not satisfied,
and returns silently otherwise. Passing `$installed` is how a test exercises the refusal without
reinstalling anything:

```php
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Plugins\PluginCompatibility;

// BillingPlugin declares requiresPanel: '^0.1', which is >=0.1.0 <0.2.0.

PluginCompatibility::assert(new BillingPlugin, 'admin', '0.1.5');   // satisfied — returns

expect(fn () => PluginCompatibility::assert(new BillingPlugin, 'admin', '0.2.0'))
    ->toThrow(PanelRegistrationException::class);
```

The constraint is evaluated by `Composer\Semver\Semver::satisfies()`, so it accepts everything a
`composer.json` constraint accepts: `^0.1`, `>=0.1.2 <0.3`, `0.1.*`, `^1.0 || ^2.0`.

### The three cases it skips

All three mean there is no question to answer, and each would produce a false refusal if it were
treated as one:

| Case | Result | Why |
| --- | --- | --- |
| `requiresPanel` is `null` | pass | Most plugins declare no constraint, and one that has not thought about compatibility should not be treated as if it had. |
| The framework is not installed as a composer package | pass | A path repository, a git checkout, or this repository's own suite. There is no version to compare against. |
| The version is `dev-*` or contains `no-version-set` | pass | A constraint cannot be evaluated against a branch, and refusing every plugin on a development checkout would make the framework untestable against its own ecosystem. |

The package name the lookup uses is a private constant, and it has to match `composer.json`
exactly:

```php
private const PACKAGE = 'chocoalano/panel';
```

A name no installation carries makes `getPrettyVersion()` throw, which the class reads as "not
installed as a package" and answers `null` to — and a `null` version skips every constraint there
is, silently and for good. That happened once, at the package rename, and `PluginTest` now compares
the constant against `name` in `composer.json` so it cannot happen the same way twice.

## The frontend has its own versions

The panel's components are Vue SFCs built by the *application's* Vite, against the application's
own dependency tree. `package.json` in this repository declares the ranges those components are
written for, and it is the single source of truth — `php artisan panel:install` reads that same
file to tell an application what to install, so the two cannot disagree.

```json
"engines": { "node": ">=20.19" },
"dependencies": {
    "@inertiajs/vue3": "^3.0.0",
    "@lucide/vue": "^1.31.0",
    "@tanstack/vue-table": "^9.0.0",
    "reka-ui": "^2.0.0",
    "tailwindcss": "^4.1.0",
    "vue": "^3.5.0"
}
```

None of it ships. `package.json`, `package-lock.json`, the Vite config, the tsconfig and the lint
configs are all `export-ignore`d, so an application installs from the *ranges* rather than from
this repository's lockfile.

```bash
npm ls vue tailwindcss @inertiajs/vue3 reka-ui --depth=0
```

## Published assets are versioned separately

A `composer update` moves `vendor/chocoalano/panel`. It does not move `resources/js/panel`, because
those files are the application's from the moment they were published. Their version is recorded in
`.panel-assets.json` — the hash of every file *as it was published* — and reconciled by
`panel:assets`:

```bash
composer update chocoalano/panel
php artisan panel:assets            # what is behind, what you changed, what conflicts
php artisan panel:assets --update   # write only the files you have never touched
npm run build
```

That is why the package version and the frontend version are two different questions in an
installed application. [The asset manifest](asset-manifest.md) is the whole of the second one.

## Notes

- **A `0.x` minor may break.** Until `1.0.0`, read [Breaking changes](breaking-changes.md) before
  bumping the minor. The caret protects you from it by default; `>=` does not.
- **`CHANGELOG.md` is not in the installed package.** It is `export-ignore`d, so
  `vendor/chocoalano/panel/CHANGELOG.md` does not exist. Read it in the repository — see
  [Changelog](changelog.md).
- **There is no `Panel::VERSION` constant.** The version comes from composer, deliberately. A
  constant is a value somebody forgets to bump.
- **A plugin's `requiresPanel` is unenforced on a git checkout.** `dev-main` and
  `1.0.0+no-version-set` both skip the check, so a constraint that would refuse in an application
  passes in this repository's own suite. Pass `$installed` explicitly to test the refusal.
- **`composer.lock` is the record that matters in an application.** Commit it. The ranges in
  `composer.json` say what is allowed; the lock says what is running.
- **The Laravel floor is a security floor, not a taste one.** Laravel 11 is not supported and
  cannot be — every 11.x release is flagged by unpatched advisories and composer refuses to resolve
  against it. See [Compatibility](../getting-started/compatibility.md#why-not-laravel-11).

## See also

- [Upgrade guide](upgrade-guide.md) — the procedure for moving between versions
- [Breaking changes](breaking-changes.md) — what each one breaks, and the smallest fix
- [Changelog](changelog.md) — how the release notes are organised
- [Release checklist](release-checklist.md) — what happens before a tag exists
- [Asset manifest](asset-manifest.md) — how the published frontend is versioned
- [Package name migration](package-name-migration.md) — the rename, and what it did to this check
- [Compatibility](../getting-started/compatibility.md), [Requirements](../getting-started/requirements.md)
- [Plugin compatibility](../plugins/compatibility.md), [Plugin metadata](../plugins/metadata.md)
- [CI matrix](../testing/ci-matrix.md)
- [`panel:plugins`](../cli/panel-plugins.md)
