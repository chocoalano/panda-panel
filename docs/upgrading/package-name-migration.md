# Migrating Package Names

The composer package is `chocoalano/panel`. It was renamed, and an application installed under the
old name needs one edit — the `require` line — plus a check that the rename quietly broke and then
un-broke: `PluginCompatibility::PACKAGE`, the constant this framework looks its own version up
under. Reach for this page when upgrading an installation older than the rename, and when you
maintain a plugin that declares `requiresPanel`.

The install-side view of the same rename, for a project that is not upgrading anything else, is
[Getting started: package name migration](../getting-started/package-name-migration.md).

## A minimal working example

```bash
composer remove panda/panel
composer require chocoalano/panel

php artisan panel:plugins        # every plugin still registers, at which version
php artisan test
```

No published file changes, no namespace changes, no config rename. The `require` line is the whole
of the migration; the rest of this page is what the rename touched around it.

## What changed, and what did not

| | Before | After |
| --- | --- | --- |
| Composer package | `panda/panel` | `chocoalano/panel` |
| Vendor directory | `vendor/panda/panel` | `vendor/chocoalano/panel` |
| npm package name | `@panda/panel` | `@chocoalano/panel` |

Everything an application's own code refers to is unchanged:

| | Value |
| --- | --- |
| PHP namespace | `PandaPanel\` |
| Service provider | `PandaPanel\PandaPanelServiceProvider` |
| Facade alias | `PandaPanel` → `PandaPanel\Facades\PandaPanel` |
| Config file | `config/panda-panel.php` |
| Publish tags | `panda-panel`, `panda-panel-config`, `panda-panel-assets`, `panda-panel-migrations`, `panda-panel-stubs` |
| Artisan commands | `panel:install`, `panel:user`, `panel:assets`, `panel:cache`, `panel:clear`, `panel:icons`, `panel:plugins`, `panel:publish`, and the five `make:panel*` generators |
| Route names | `panel.{id}.*` |
| Published paths | `resources/js/panel`, `resources/js/pages`, `resources/css/panda-panel.css` |
| The project's name | Panda Panel |

So there is no `use` statement to edit, no migration to run, and nothing to re-publish or rebuild.
The npm name is this repository's own toolchain — `"private": true`, version `0.0.0`, never
published to npm, because the components reach an application through `vendor:publish` rather than
through an npm install — so unless you had cloned the repository, that name was never something
your project referred to.

## The require line

Either let composer write it:

```bash
composer remove panda/panel
composer require chocoalano/panel
```

Or edit `composer.json` and update:

```json
"require": {
    "chocoalano/panel": "^0.1"
}
```

```bash
composer update chocoalano/panel
```

`composer require` writes a caret constraint, which is the right default. What the constraint
promises — and why `^0.1` still allows a breaking `0.2` — is [Versioning policy](versioning.md).

**There is no metapackage aliasing the old name to the new one.** An installed `panda/panel` keeps
working out of `vendor/` exactly as it did, because a lockfile is a record of what was installed
rather than a subscription. What it does not get is any release published after the rename: those
exist under the new name only.

Confirm which name a project actually resolves, which is worth doing in a repository where both
appear in the history:

```bash
composer why chocoalano/panel        # which constraint pulled it in
composer show chocoalano/panel       # the resolved version
composer show --installed | grep panel
```

### Paths that spelled out the vendor directory

The vendor directory is the one place the old name can survive an otherwise clean migration.
`vendor/panda/panel` appears in scripts, CI steps, `.gitignore` entries, editor include paths, and
in the one command an upgrade actually needs — diffing a conflicted published asset:

```bash
php artisan panel:assets
# CONFLICT  resources/js/panel/tables/DataTable.vue

diff -u resources/js/panel/tables/DataTable.vue \
        vendor/chocoalano/panel/resources/js/panel/tables/DataTable.vue
```

```bash
grep -rn 'panda/panel' --exclude-dir=vendor --exclude-dir=node_modules .
```

## `PluginCompatibility::PACKAGE`

This is the consequence the rename actually had, and it is worth understanding rather than just
applying, because it is a shape a rename can repeat.

`PandaPanel\Plugins\PluginCompatibility` refuses a plugin built against a version of this framework
that no longer exists. To do that it has to know which version is installed, and it asks composer
under a name written down as a private constant:

```php
/**
 * This package, as composer knows it.
 *
 * Must match `name` in `composer.json` exactly.
 */
private const PACKAGE = 'chocoalano/panel';
```

When the package was renamed, that constant was left behind. Nothing failed. What happened instead
is a four-step chain in which every step is individually reasonable:

| Step | Behaviour | Why it is reasonable on its own |
| --- | --- | --- |
| 1 | `InstalledVersions::getPrettyVersion('panda-panel')` **throws** | Composer has never heard of that package. |
| 2 | The `catch (Throwable)` answers `null` | "Not installed as a composer package" is a real state — a path repository, a git checkout, this repository's own suite. |
| 3 | A `null` installed version returns early from `assert()` | There is no version to evaluate a constraint against. |
| 4 | The plugin registers | Nothing was refused, so nothing is reported. |

The result is the worst kind of regression: **every `requiresPanel` constraint any plugin declared
was passing unexamined, in every installation, from the rename onwards.** The check was still
there. It would never have said no again.

### What it does now

```php
use PandaPanel\Contracts\PanelPlugin;
use PandaPanel\Exceptions\PanelRegistrationException;

public static function assert(
    PanelPlugin $plugin,
    string $panelId,
    ?string $installed = null,
): void
```

| Parameter | Type | Default | Meaning |
| --- | --- | --- | --- |
| `$plugin` | `PandaPanel\Contracts\PanelPlugin` | required | Read for `metadata()->requiresPanel`, `metadata()->name` and `id()`. |
| `$panelId` | `string` | required | Named in the exception, because one plugin can be on several panels. |
| `$installed` | `string\|null` | `null` | The framework version to check against; defaults to the one composer reports for `PACKAGE`. |

It runs when the plugin registers — the earliest moment the answer is knowable and the last moment
before the plugin starts changing the panel — and throws
`PandaPanel\Exceptions\PanelRegistrationException` when `Composer\Semver\Semver::satisfies()` says
no:

```text
The [Billing] plugin ([billing], registered on the [admin] panel) requires panda-panel ^2.0, and
1.4.1 is installed. Upgrade the plugin, or pin this framework to a version it supports.
```

That message is the whole point of the class. The failure it replaces is
`Call to undefined method Panel::whatever()` somewhere inside a request, naming this framework
rather than the plugin that asked for it, with no way to tell which of four plugins was
responsible or which version of it you are on.

Registration happens during boot, so an unsatisfied constraint fails **every** route and every
artisan command until it is resolved — including `panel:plugins`. That is intended: the exception
names the plugin, the panel, the constraint and the installed version, which is more than a working
command would have told you.

### The three cases it still skips

All three mean there is no question to answer, and treating any of them as a failure would produce
a false refusal:

| Case | Result | Why |
| --- | --- | --- |
| `requiresPanel` is `null` | passes | Most plugins declare no constraint, and one that has not thought about compatibility should not be treated as if it had. |
| The framework is not installed as a composer package | passes | A path repository, a git checkout, or this repository's own suite. There is no version to compare against. |
| The version is `dev-*`, or contains `no-version-set` | passes | A constraint cannot be evaluated against a branch, and `1.0.0+no-version-set` is composer's placeholder for a root package that never declared a version. |

The second case is exactly what the stale constant turned every installation into, which is why it
was invisible: the framework *behaved* like a git checkout everywhere.

### The test that pins it

`tests/Feature/Panel/PluginTest.php` compares the constant against `composer.json`, so a future
rename that misses it fails a test rather than turning the check off:

```php
use PandaPanel\Plugins\PluginCompatibility;

it('looks up its own version under the name composer knows it by', function (): void {
    $reflection = new ReflectionClass(PluginCompatibility::class);

    expect($reflection->getConstant('PACKAGE'))
        ->toBe(json_decode((string) file_get_contents(base_path('composer.json')), true)['name']);
});
```

One assertion, and it is the only thing standing between a rename and a silently disabled check.

## What this means for an application

A plugin whose constraint your installed version does not satisfy now says so by name, where it
previously registered in silence. Ask before upgrading, not after:

```bash
php artisan panel:plugins                # every panel
php artisan panel:plugins --panel=admin  # one panel
```

```text
 +-------+---------+-----------+--------------------+---------+----------+
 | Panel | ID      | Name      | Package            | Version | Requires |
 +-------+---------+-----------+--------------------+---------+----------+
 | admin | billing | Billing   | acme/panda-billing | 2.1.0   | ^0.1     |
 | admin | audit   | Audit Log | in this application| unknown | any      |
 +-------+---------+-----------+--------------------+---------+----------+
```

```php
protected $signature = 'panel:plugins {--panel= : Only this panel}';
```

Six columns, and three of them have a written-out answer for the absent case rather than a blank:
`in this application` when the metadata names no package, `unknown` when it names one composer has
never heard of — two different problems that should not look the same — and `any` when the plugin
declares no `requiresPanel`. A plugin with no package reports `unknown` for its version, which is
the normal answer for a plugin that lives in the application and is versioned by it. With no
plugins registered anywhere, the command prints `No plugins are registered.` and exits `0`.

When something is refused, there are three fixes and no fourth: update the plugin, relax its
constraint if the plugin is yours, or take it off the panel.

## What this means for a plugin author

`requiresPanel` is a composer-style constraint evaluated against **`chocoalano/panel`**, so it is a
version of this framework and never of your own package:

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
            package: 'acme/panda-billing',   // your package — unaffected by the rename
            requiresPanel: '^0.1',           // a constraint against chocoalano/panel
            url: 'https://github.com/acme/panda-billing',
        );
    }
}
```

```php
public function __construct(
    public string $name,
    public ?string $package = null,
    public ?string $requiresPanel = null,
    public ?string $url = null,
) {}
```

Your own `composer.json` needs the new name in `require` too, or your plugin resolves nothing:

```json
"require": {
    "chocoalano/panel": "^0.1"
}
```

Two things to test, because neither is exercised by installing your plugin in a checkout:

```php
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Plugins\PluginCompatibility;

// Passing $installed is how the refusal is tested at all: in a checkout the
// framework has no composer version, so the check is skipped and every
// constraint passes.
PluginCompatibility::assert(new BillingPlugin, 'admin', '0.1.5');   // satisfied — returns

expect(fn () => PluginCompatibility::assert(new BillingPlugin, 'admin', '0.2.0'))
    ->toThrow(PanelRegistrationException::class);
```

## If you rename a package of your own

The rule the rename produced is short, and it applies to any package that reads its own version at
runtime:

1. `composer.json` `name` is the only source of truth for the package's name.
2. Anything that spells that name a second time — a constant, a config default, a docs snippet —
   is a copy, and copies drift.
3. Where a second copy is unavoidable, pin it with a test that compares it to `composer.json`.
4. Prefer a lookup that fails **loudly** to one that degrades to "unknown". This class degrades on
   purpose, for three good reasons, and the cost of that decision was this bug.

```bash
grep -rn 'chocoalano/panel' src/ config/ composer.json
```

## Verifying the migration

```bash
composer show chocoalano/panel        # resolved version and source
composer why chocoalano/panel         # what required it
php artisan panel:plugins             # every plugin registers, with versions
php artisan panel:assets              # unchanged — no published file mentions the package name
php artisan about --only=environment  # PHP and Laravel, for a bug report
php artisan test
```

`panel:assets` reporting no change is the expected result: nothing published into `resources/js`
refers to the composer package, so the frontend needs no re-publish and no rebuild for the rename.

## Notes

- **The project is still called Panda Panel.** The rename was to the composer vendor namespace. The
  exception message even says `requires panda-panel ^2.0`, because that is the project's name
  rather than the package coordinate.
- **`config/panda-panel.php` keeps its name.** Renaming it would break every `config('panda-panel.*')`
  call in the package and gain nothing.
- **An unsatisfied constraint stops the application from booting.** Registration is boot-time, so
  every route and every artisan command fails — including `panel:plugins`. Read the exception; it
  names the plugin.
- **A checkout will not reproduce the refusal.** `dev-main` and `1.0.0+no-version-set` both skip the
  check, so a constraint that refuses in an application passes in a development checkout. Pass
  `$installed` explicitly to test it.
- **A plugin with no `package` reports no version.** That is the normal answer for a plugin that
  lives in the application, not an error.
- **`getPrettyVersion()` throws, it does not return null.** That is why the failure was silent, and
  why `PandaPanel\Plugins\PluginCompatibility` wraps it in a `try`.
- **Nothing forces the upgrade.** An old lockfile keeps working; it stops receiving releases.

## See also

- [Getting started: package name migration](../getting-started/package-name-migration.md) — the same rename, from the install side
- [Upgrade guide](upgrade-guide.md) — the procedure the require-line change fits into
- [Breaking changes](breaking-changes.md) — §5, the constraint check being restored
- [Versioning policy](versioning.md) — what the constraint you write actually promises
- [Changelog](changelog.md), [Release checklist](release-checklist.md)
- [Plugin compatibility](../plugins/compatibility.md), [Plugin metadata](../plugins/metadata.md), [Plugin contract](../plugins/contract.md)
- [`panel:plugins`](../cli/panel-plugins.md), [`panel:assets`](../cli/panel-assets.md)
- [Resolving asset conflicts](asset-conflicts.md)
- [Troubleshooting: Packagist install errors](../troubleshooting/packagist.md)
