# Composer and Autoloading

What to install on a production machine, which flags matter, and the one place
the panel genuinely depends on Composer's autoloader rather than just being
loaded by it. Reach for this page when writing the install step of a deploy, or
when `panel:cache` finds fewer classes than you expected.

## A minimal working example

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
```

Then, and only then:

```bash
php artisan panel:cache
```

```text
INFO  Panels cached: 2 panels, 1 resources, 5 pages, 4 widgets.
```

That order is the whole point of this page. Everything else is detail.

## The package

```json
"require": {
    "chocoalano/panel": "^1.0"
}
```

| | Value |
| --- | --- |
| Package | `chocoalano/panel` |
| Vendor directory | `vendor/chocoalano/panel` |
| PHP namespace | `PandaPanel\` |
| Service provider | `PandaPanel\PandaPanelServiceProvider` |
| Facade alias | `PandaPanel` → `PandaPanel\Facades\PandaPanel` |

The provider and the alias are declared in the package's `extra.laravel` block,
so Laravel's package discovery registers both. Nothing has to be added to
`bootstrap/providers.php`.

An application installed under the old `panda/panel` name has one line to
change — see [Package name migration](../getting-started/package-name-migration.md).

## What it requires

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

| Requirement | Used for |
| --- | --- |
| `ext-json` | every serialized shape crossing to Vue |
| `ext-zip` | XLSX import and export — an XLSX file is a ZIP container |
| `composer-runtime-api` | `Composer\InstalledVersions`, which reports plugin versions |
| `composer/semver` | `PluginCompatibility` evaluating a plugin's `requiresPanel` constraint |
| `inertiajs/inertia-laravel` | every panel response is an Inertia response |
| `laravel/fortify` | the security settings page and the emailed-code second factor |
| `symfony/finder` | walking discovery paths |

`ext-zip` is deliberately a hard requirement rather than something checked at
the first export. A machine without it fails at `composer install`, which is the
only place that failure is cheap.

Nothing in `src/` reaches for a `require-dev` package, so `--no-dev` is safe on
a production install. The dev block is Pest, Pint, Larastan, Mockery and
Testbench, all of which belong to this repository's own suite.

Supported version ranges — and what CI actually runs — are in the
[compatibility matrix](../getting-started/compatibility.md). Laravel 11 is not
supported and cannot be: every 11.x release is flagged by unpatched advisories,
so `composer update` against it does not resolve.

## The flags, one at a time

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
```

| Flag | Effect on a panel application |
| --- | --- |
| `--no-dev` | skips Pest, Pint, Larastan, Testbench. Nothing the panel runs is in there. |
| `--prefer-dist` | downloads archives rather than cloning. No panel-specific effect; it is faster. |
| `--optimize-autoloader` | dumps a classmap. Every panel class is then found without a filesystem probe. |
| `--no-interaction` | a deploy has nobody to answer a prompt. |

`--no-scripts` is the one to avoid: Laravel's package discovery runs as a
Composer script, and skipping it means the service provider is never registered
— which looks exactly like the package not being installed.

## Where the autoloader is load-bearing

Discovery does not parse files to find out what class they declare. It asks
Composer:

```php
// PandaPanel\Discovery\ClassResolver
foreach (ClassLoader::getRegisteredLoaders() as $loader) {
    foreach ($loader->getPrefixesPsr4() as $namespace => $roots) {
        // …
    }
}
```

A file path under a registered PSR-4 root becomes the class name that root
implies. A path outside every registered root resolves to `null` — which is
correct, because nothing could autoload it anyway.

```php
use PandaPanel\Discovery\ClassResolver;

ClassResolver::forPath(app_path('Panels/Admin/Resources/Users/UserResource.php'));
// 'App\Panels\Admin\Resources\Users\UserResource'

ClassResolver::forPath('/tmp/Orphan.php');
// null
```

| Method | Signature | Returns |
| --- | --- | --- |
| `forPath` | `static forPath(string $path): ?class-string` | the class the path implies, or `null` outside every PSR-4 root |

The prefix map is memoized in a static for the life of the process, and the
longest namespace is matched first so a nested prefix wins over its parent.

Two consequences follow, and both are deployment concerns:

1. **A panel class in a namespace Composer does not know about is invisible.**
   Not an error, not a warning — the file is walked, resolves to `null`, and is
   skipped. If a resource never appears in `panel:cache`'s count, check that its
   directory is under a PSR-4 root in `composer.json`.
2. **`panel:cache` has to run after `composer install`.** The manifest is a list
   of class names produced by that map. Written against the previous
   autoloader, it names classes that have moved.

`PanelDiscoverer` then keeps only classes that are concrete and implement the
expected contract — `ResourceContract`, `PageContract`, `WidgetContract` — so an
abstract base class or a trait living in the same directory is skipped silently
rather than failing the boot.

## After a manifest exists, none of this runs

```php
use PandaPanel\Cache\PanelManifest;

app(PanelManifest::class)->exists();   // true in production
```

With the manifest in place the panel never resolves a path to a class again: no
`Finder` walk, no reflection, no `ClassResolver`. The autoloader is then doing
what it does for every other class in the application and nothing more. That is
why an aggressively optimized autoloader is compatible with the panel — the one
command that needs the PSR-4 map, `panel:cache`, runs in the same process as the
install that produced it.

## Regenerating the autoloader on its own

```bash
composer dump-autoload --optimize
php artisan panel:clear
php artisan panel:cache
```

Adding a class without a `composer install` — a generator run on a server, a
namespace added to `composer.json` — needs the map regenerated and the manifest
rebuilt. Skipping the second half leaves a manifest that predates the class.

## Plugin versions come from Composer

`php artisan panel:plugins` reads Composer's installed-packages data rather
than anything a plugin author remembered to bump:

```php
use Composer\InstalledVersions;

InstalledVersions::getPrettyVersion('acme/panel-audit');   // '1.4.1'
```

```bash
php artisan panel:plugins
php artisan panel:plugins --panel=admin
```

A plugin naming a package Composer has never heard of reports `unknown` rather
than blank, because that is a different problem from a plugin that names no
package at all.

The same data drives `PandaPanel\Plugins\PluginCompatibility`, which refuses a
plugin whose `requiresPanel` constraint the installed framework does not
satisfy:

```php
use PandaPanel\Plugins\PluginCompatibility;

PluginCompatibility::assert($plugin, 'admin');            // throws PanelRegistrationException
PluginCompatibility::assert($plugin, 'admin', '1.2.0');   // check against a version explicitly
```

| Method | Signature | Throws |
| --- | --- | --- |
| `assert` | `static assert(PanelPlugin $plugin, string $panelId, ?string $installed = null): void` | `PandaPanel\Exceptions\PanelRegistrationException` |

The check is skipped when the plugin declared no constraint, when the framework
is not installed as a Composer package (a path repository, a git checkout), or
when the installed version is a branch alias like `dev-main`. A constraint
cannot be evaluated against a branch, and refusing every plugin on a development
checkout would make the framework untestable against its own ecosystem.

Deploying from a path repository therefore turns every `requiresPanel`
constraint off, silently and for good. That is the right answer for a monorepo
and the wrong one for a staging environment that is meant to mirror production.

## `composer.lock`

Commit it. It is the record of what the application installed, the same way
`.panel-assets.json` is the record of what it published — and for the same
reason: a deploy that resolves versions afresh is a deploy that can differ from
the one that was tested.

```bash
composer install    # honours the lock file
composer update     # rewrites it — never in a deploy
```

The package declares `"minimum-stability": "stable"` and `"prefer-stable": true`,
so nothing pre-release is pulled in by accident.

## Gotchas

- **`--no-scripts` skips package discovery.** The provider is never registered,
  the routes never exist, and the symptom is a 404 on every panel URL.
- **A class outside every PSR-4 root is skipped silently.** Discovery is not
  telling you it failed, because as far as the autoloader is concerned there is
  nothing there.
- **`composer install` after `panel:cache` leaves a manifest naming the old
  tree.** The order is install, then cache.
- **`ext-zip` missing means no XLSX**, and the failure arrives at
  `composer install` rather than at the first export. That is deliberate.
- **The version constraint check is off on a path repository.** A plugin
  requiring `^2.0` registers happily against `1.x` in a monorepo.
- **`--no-dev` does not affect the frontend.** The Vue components live in
  `resources/js` and are the application's; Composer never touches them.

## See also

- [Production checklist](production-checklist.md)
- [Panel cache in production](panel-cache.md), [Rollbacks](rollbacks.md)
- [Discovery](../concepts/discovery.md) — what the class map is used for
- [Caching](../concepts/caching.md)
- [Compatibility matrix](../getting-started/compatibility.md), [Requirements](../getting-started/requirements.md)
- [Package name migration](../getting-started/package-name-migration.md)
- [`panel:plugins`](../cli/panel-plugins.md), [Plugin concepts](../plugins/concepts.md)
- [Packagist troubleshooting](../troubleshooting/packagist.md)
