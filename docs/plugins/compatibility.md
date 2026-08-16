# Version Compatibility

A plugin can declare which versions of this framework it was built against, and
be refused by name when it is installed on one it does not support. Reach for
this when shipping a plugin as a package: the failure it replaces is
`Call to undefined method Panel::whatever()` somewhere inside a request, naming
the framework rather than the plugin that asked for it.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace Acme\Reporting;

use PandaPanel\Core\Panel;
use PandaPanel\Plugins\Plugin;
use PandaPanel\Plugins\PluginMetadata;

final class ReportingPlugin extends Plugin
{
    public function register(Panel $panel): void
    {
        $panel->resources([Resources\ReportResource::class]);
    }

    public function metadata(): PluginMetadata
    {
        return new PluginMetadata(
            name: 'Acme Reporting',
            package: 'acme/panda-reporting',
            requiresPanel: '^1.2',
        );
    }
}
```

Install it on an application running 1.0.3 and the panel refuses to build:

```text
The [Acme Reporting] plugin ([acme-reporting], registered on the [admin] panel)
requires panda-panel ^1.2, and 1.0.3 is installed. Upgrade the plugin, or pin
this framework to a version it supports.
```

## Where the check happens

`Panel::plugins()` calls `PandaPanel\Plugins\PluginCompatibility::assert()` for
each plugin, after the duplicate-id check and **before** `register()`:

```php
PluginCompatibility::assert($plugin, $this->getId());

$this->plugins[$id] = $plugin;

$plugin->register($this);
```

Registration is the earliest moment the answer is knowable and the last moment
before the plugin starts changing the panel. A refused plugin therefore
registers nothing at all — no half-configured panel to reason about.

The exception is `PandaPanel\Exceptions\PanelRegistrationException`, which
extends `RuntimeException` and is fatal during application boot. That is
deliberate: an incompatible plugin is a developer error, and a panel that
half-works is worse than one that refuses to build.

## `PluginCompatibility::assert()`

```php
namespace PandaPanel\Plugins;

use PandaPanel\Contracts\PanelPlugin;

final class PluginCompatibility
{
    /**
     * @param  string|null  $installed  the framework version to check against,
     *                                  defaulting to the one composer reports
     *
     * @throws \PandaPanel\Exceptions\PanelRegistrationException
     */
    public static function assert(PanelPlugin $plugin, string $panelId, ?string $installed = null): void;
}
```

| Parameter | Type | Default | What it is |
| --- | --- | --- | --- |
| `$plugin` | `PanelPlugin` | required | The plugin, read for `metadata()->requiresPanel`, `metadata()->name` and `id()` |
| `$panelId` | `string` | required | Named in the message, so a multi-panel application knows where the plugin was installed |
| `$installed` | `?string` | `null` | The framework version to check against. `null` means "ask composer" |

The whole method, in order:

1. Read `$plugin->metadata()->requiresPanel`. Return if it is `null`.
2. Resolve `$installed`, falling back to composer. Return if that is `null`.
3. `Semver::satisfies($installed, $constraint)` — return if it passes.
4. Throw, naming the plugin, its id, the panel, the constraint and the
   installed version.

Calling it directly is what tests do, because passing `$installed` is the only
way to exercise the comparison in a checkout that has no version — see
[Testing Plugins](testing.md):

```php
use PandaPanel\Plugins\PluginCompatibility;

PluginCompatibility::assert($plugin, 'admin', '1.4.1');
```

## Writing the constraint

`requiresPanel` is a composer-style constraint, evaluated by
`composer/semver`'s `Semver::satisfies()`. Anything composer accepts in a
`require` block works here.

| Constraint | Satisfied by |
| --- | --- |
| `^1.2` | 1.2.0 up to but not including 2.0.0 |
| `~1.2.3` | 1.2.3 up to but not including 1.3.0 |
| `>=1.2 <2.0` | the same range as `^1.2`, written out |
| `1.4.*` | any patch of 1.4 |
| `^1.2 \|\| ^2.0` | either major line, for a plugin that supports both |

Declare the constraint you actually need. `^1.2` because the plugin calls a
method added in 1.2 is a fact; `^1.2` copied from another plugin is a guess that
will refuse a working install later.

## The three cases where the check is skipped

All three mean "there is no question to answer".

| Case | Behaviour | Why |
| --- | --- | --- |
| The plugin declared no constraint (`requiresPanel` is `null`) | pass | Most plugins do not, and a plugin that has not thought about compatibility should not be treated as if it had. |
| This framework is not installed as a composer package | pass | A path repository, a git checkout, or this repository's own test suite. There is no version to compare against, and inventing one would fail every plugin. |
| The reported version is a branch alias (`dev-main`) or composer's `no-version-set` placeholder | pass | A constraint cannot be evaluated against a branch, and refusing every plugin on a development checkout would make the framework untestable against its own ecosystem. |

The practical consequence: a plugin developed against a local path repository
never sees its own constraint enforced. The first machine that enforces it is
one that installed a tagged release from packagist. Test the comparison by
passing `$installed` explicitly rather than trusting a local run.

## How the installed version is found

```php
private const PACKAGE = 'chocoalano/panel';

Composer\InstalledVersions::getPrettyVersion(self::PACKAGE);
```

The constant must match `name` in this package's `composer.json` exactly. A
name no installation carries makes the lookup throw, which the class reads as
"not installed as a package" and answers `null` to — and a `null` version skips
every `requiresPanel` constraint there is, silently and for good. The package's
own test suite compares the constant against `composer.json` so a rename cannot
turn the check off again.

If you have renamed the package in a fork, that constant is the thing to change
with it. See [Package Name Migration](../getting-started/package-name-migration.md).

## The error message

```text
The [{name}] plugin ([{id}], registered on the [{panelId}] panel) requires
panda-panel {constraint}, and {installed} is installed. Upgrade the plugin, or
pin this framework to a version it supports.
```

Five facts, and each is there because it is the one a person reading a stack
trace does not have: which plugin by its human name, which plugin by the id
they would grep for, which panel of theirs installed it, what it asked for, and
what they actually have.

`{name}` comes from `metadata()->name`, so a plugin that never declares metadata
is reported by its title-cased id.

## What this does not do

- **It does not replace composer's `require`.** A plugin package should still
  declare `"chocoalano/panel": "^1.2"` in `composer.json`; that is what stops
  the wrong version being installed in the first place. `requiresPanel` is the
  backstop for the cases composer cannot see: a path repository, a
  `--ignore-platform-reqs` install, a plugin vendored into `app/`.
- **It does not check plugin-to-plugin dependencies.** A plugin that needs
  another plugin checks for it itself, in `register()`:

  ```php
  public function register(Panel $panel): void
  {
      if (! $panel->hasPlugin('acme-billing')) {
          throw new RuntimeException('The reporting plugin requires the billing plugin.');
      }
  }
  ```

  Which only works if billing is listed first in `plugins([...])`, since
  `register()` runs in array order.
- **It does not check PHP, Laravel or Vue versions.** Those are composer's job
  and the build's job.
- **It does not check the frontend.** A plugin whose published Vue components
  were written against an older panel shell passes this check and breaks in the
  browser. See [Upgrade Guide](../upgrading/upgrade-guide.md) and
  [Published Asset Structure](../frontend/assets.md).

## Notes

- The check runs on every application boot, including in the console, because
  `plugins()` does. It is a string comparison and a semver evaluation, with no
  filesystem or network access.
- `metadata()` is called by `assert()` before `register()`, so a `metadata()`
  that queries breaks a plugin in the same way a `register()` that queries
  does.
- A plugin can raise its own constraint over time without changing its id.
  Applications branch on `hasPlugin('acme-reporting')`, which is about the
  plugin rather than about the release of it.

## See also

- [Plugin Metadata](metadata.md)
- [Plugin Contract](contract.md)
- [Register and Boot](lifecycle.md)
- [Creating a Plugin](creating-plugins.md)
- [Testing Plugins](testing.md)
- [Versioning](../upgrading/versioning.md)
- [Breaking Changes](../upgrading/breaking-changes.md)
- [Package Name Migration](../getting-started/package-name-migration.md)
- [Exceptions Reference](../api/exceptions.md)
