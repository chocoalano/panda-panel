# Plugin Metadata

`PandaPanel\Plugins\PluginMetadata` is what a plugin says about itself: a name a
person can read, the composer package it ships in, the framework version it
needs, and a URL. Reach for it when shipping a plugin as a package, because the
two questions asked of a misbehaving plugin are always "which one" and "which
version", and neither is answerable from a class name.

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
            url: 'https://github.com/acme/panda-reporting',
        );
    }
}
```

```bash
php artisan panel:plugins
```

```text
+-------+-----------------+-----------------+-----------------------+---------+----------+
| Panel | ID              | Name            | Package               | Version | Requires |
+-------+-----------------+-----------------+-----------------------+---------+----------+
| admin | acme-reporting  | Acme Reporting  | acme/panda-reporting  | 1.4.1   | ^1.2     |
+-------+-----------------+-----------------+-----------------------+---------+----------+
```

## The value object

```php
namespace PandaPanel\Plugins;

final readonly class PluginMetadata
{
    public function __construct(
        public string $name,
        public ?string $package = null,
        public ?string $requiresPanel = null,
        public ?string $url = null,
    ) {}

    public function version(): ?string;

    /** @return array{name: string, package: string|null, version: string|null, requiresPanel: string|null, url: string|null} */
    public function toArray(): array;
}
```

`readonly`, so an instance cannot be changed after it is built. Construct a new
one rather than mutating.

### Constructor parameters

| Parameter | Type | Default | What it is |
| --- | --- | --- | --- |
| `name` | `string` | required | Human-readable, for a report a person reads |
| `package` | `?string` | `null` | The composer package, used for the version lookup |
| `requiresPanel` | `?string` | `null` | A composer-style constraint on this framework, e.g. `^1.2` |
| `url` | `?string` | `null` | Where to read about it |

All four are public promoted properties, so they read directly:

```php
$metadata = $plugin->metadata();

$metadata->name;           // 'Acme Reporting'
$metadata->package;        // 'acme/panda-reporting'
$metadata->requiresPanel;  // '^1.2'
$metadata->url;            // 'https://github.com/acme/panda-reporting'
```

Use named arguments. Positional order is `name`, `package`, `requiresPanel`,
`url`, and the last three are easy to swap by accident.

## `version(): ?string`

The installed version of the package this plugin ships in.

```php
use PandaPanel\Plugins\PluginMetadata;

$metadata = new PluginMetadata(name: 'Pest', package: 'pestphp/pest');

$metadata->version();   // 'v4.7.8' — whatever composer actually installed
```

The version is read from `Composer\InstalledVersions::getPrettyVersion()`
rather than declared by hand. A hand-written version string is a string
somebody forgets to change, and a plugin reporting 1.2.0 while 1.4.1 is
installed is worse than a plugin reporting nothing.

It answers `null` in two cases:

| Case | Why |
| --- | --- |
| `package` is `null` | A plugin that lives in the application rather than a package of its own has no version. That is a normal arrangement — a project's own plugin is versioned by the project. |
| Composer has never heard of `package` | A wrong package name in metadata is a documentation bug, not a reason to refuse to boot. |

```php
use PandaPanel\Plugins\PluginMetadata;

(new PluginMetadata(name: 'Reporting'))->version();                            // null
(new PluginMetadata(name: 'Ghost', package: 'nobody/nothing'))->version();     // null
```

The two look the same from `version()` and are told apart by `panel:plugins`,
which prints `in this application` for the first and `unknown` for the second.

The lookup runs on every call, so it is not something to put in a loop over a
large collection. In practice it is called once per plugin per `panel:plugins`
row.

## `toArray(): array`

The whole thing flattened, with `version()` resolved:

```php
$plugin->metadata()->toArray();
```

```php
[
    'name' => 'Acme Reporting',
    'package' => 'acme/panda-reporting',
    'version' => '1.4.1',
    'requiresPanel' => '^1.2',
    'url' => 'https://github.com/acme/panda-reporting',
]
```

Five keys, always present, any of the last four possibly `null`. Useful for a
support page, a health check, or an issue template:

```php
use PandaPanel\Contracts\PanelPlugin;

$report = array_map(
    static fn (PanelPlugin $plugin): array => $plugin->metadata()->toArray(),
    panel('admin')->getPlugins(),
);
```

That gives an array keyed by plugin id, because `getPlugins()` is.

## The default metadata

`PandaPanel\Plugins\Plugin` supplies metadata for a plugin that never states
any:

```php
public function metadata(): PluginMetadata
{
    return new PluginMetadata(name: Str::headline($this->id()));
}
```

| Class | `id()` | `metadata()->name` | `package` | `version()` | `requiresPanel` |
| --- | --- | --- | --- | --- | --- |
| `ReportingPlugin` | `reporting` | `Reporting` | `null` | `null` | `null` |
| `AcmeBillingPlugin` | `acme-billing` | `Acme Billing` | `null` | `null` | `null` |

A title-cased id is a reasonable name for a plugin that never says one, and no
package means no version — which is correct for a plugin that lives in the
application rather than in a package of its own.

Overriding `id()` changes the default name with it, since the name is derived
from the id rather than from the class.

## When metadata is read

| Caller | Reads |
| --- | --- |
| `Panel::plugins()` → `PluginCompatibility::assert()` | `requiresPanel`, and `name` for the error message |
| `panel:plugins` | `name`, `package`, `version()`, `requiresPanel` |

Nothing reads `url`. It is stored so a support page or an issue template can
print it; the framework itself never follows it.

`metadata()` is called during panel registration, which means it runs on every
application boot, in the console as well as in a request. It must not query,
resolve a route, or read the current user. Building a `PluginMetadata` from
constants, which is all any of these examples do, is the intended shape.

## Notes

- `PluginMetadata` is `final`. Extending it is not an option; a plugin that
  needs to carry more information carries it on the plugin object.
- `package` must be the exact composer package name, as it appears in that
  package's `composer.json` `name` field. A typo is silently reported as
  `unknown` rather than raised.
- The version reported is whatever is *installed*, which for a path repository
  or a git checkout can be a branch alias like `dev-main`. That is a real
  answer and is printed as such.
- `requiresPanel` is documented in full on
  [Version Compatibility](compatibility.md), including the three cases where
  the check is skipped.

## See also

- [Plugin Contract](contract.md)
- [Version Compatibility](compatibility.md)
- [Plugin CLI](cli.md)
- [Creating a Plugin](creating-plugins.md)
- [Testing Plugins](testing.md)
- [panel:plugins](../cli/panel-plugins.md)
- [Versioning](../upgrading/versioning.md)
- [Package Name Migration](../getting-started/package-name-migration.md)
