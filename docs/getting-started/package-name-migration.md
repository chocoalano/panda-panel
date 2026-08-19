# Package name migration

The composer package was renamed from `panda/panel` to `chocoalano/panel`. Nothing else changed:
the PHP namespace, the config file, the publish tags, the route names and the artisan commands are
all exactly what they were. This page is the edit list for an application installed under the old
name, and the one behavioural consequence the rename had.

## The migration

```bash
composer remove panda/panel
composer require chocoalano/panel
php artisan panel:plugins        # confirm every plugin still registers
php artisan test
```

Or edit `composer.json` directly and update:

```json
"require": {
    "chocoalano/panel": "^0.1"
}
```

```bash
composer update chocoalano/panel
```

No published file changes, so there is nothing to re-publish and nothing to rebuild.

## What did not change

| | Value |
| --- | --- |
| PHP namespace | `PandaPanel\` |
| Service provider | `PandaPanel\PandaPanelServiceProvider` |
| Facade alias | `PandaPanel` → `PandaPanel\Facades\PandaPanel` |
| Config file | `config/panda-panel.php` |
| Config keys | `panels`, `register_routes`, `register_web_middleware`, `register_guest_redirect`, `home_redirect`, `load_migrations`, `integrations`, `frontend` |
| Publish tags | `panda-panel`, `panda-panel-config`, `panda-panel-assets`, `panda-panel-migrations`, `panda-panel-stubs` |
| Artisan commands | `panel:install`, `panel:user`, `panel:assets`, `panel:cache`, `panel:clear`, `panel:icons`, `panel:plugins`, `panel:publish`, and the five `make:panel*` generators |
| Route names | `panel.{id}.*` |
| Published paths | `resources/js/{panel,pages,components,composables,lib,types}`, `resources/css/panda-panel.css` |
| The project's name | Panda Panel |

So an application's own code needs no edit at all. No `use` statement changes, no config rename,
no migration, no template change. Composer's `require` key is the whole of it.

## What did change

| | Before | After |
| --- | --- | --- |
| Composer package | `panda/panel` | `chocoalano/panel` |
| Vendor directory | `vendor/panda/panel` | `vendor/chocoalano/panel` |
| npm package name | `@panda/panel` | `@chocoalano/panel` |

The npm name is the development toolchain of this repository. It is `private: true` and has never
been published to npm — the components reach an application through `vendor:publish`, not through
a package install — so unless you had cloned this repository, that name was never something your
project referred to.

The vendor directory matters in exactly one place: diffing a conflicted asset.

```bash
php artisan panel:assets
# CONFLICT  resources/js/panel/tables/DataTable.vue

diff resources/js/panel/tables/DataTable.vue vendor/chocoalano/panel/resources/js/panel/tables/DataTable.vue
```

Anything in your own scripts, CI, `.gitignore` or editor config that spelled out `vendor/panda/panel`
needs the new path.

## The one behavioural consequence

`PandaPanel\Plugins\PluginCompatibility` looks this framework's own version up under the name
composer knows it by:

```php
private const PACKAGE = 'chocoalano/panel';
```

When the rename happened, that constant was left as `panda-panel`. `InstalledVersions::getPrettyVersion()`
throws for a package no installation carries; the class reads a throw as "not installed as a
package" and answers `null`; and a null version **skips every `requiresPanel` constraint there
is**. So the check was still there, was passing unexamined in every installation, and would never
have said no again.

It is fixed, and a test now compares the constant against `name` in `composer.json`, so a future
rename cannot turn the check off the same way:

```php
$reflection = new ReflectionClass(PluginCompatibility::class);

expect($reflection->getConstant('PACKAGE'))
    ->toBe(json_decode(file_get_contents(base_path('composer.json')), true)['name']);
```

**What this means for you:** a plugin whose `requiresPanel` constraint your installation does not
satisfy now says so, by name, when it registers — where it previously registered silently and
failed later with something like `Call to undefined method Panel::whatever()`.

```php
use PandaPanel\Plugins\PluginMetadata;

public function metadata(): PluginMetadata
{
    return new PluginMetadata(
        name: 'Billing',
        package: 'acme/panda-billing',   // the plugin's own package — unaffected by the rename
        requiresPanel: '^1.2',           // a constraint against chocoalano/panel — now checked
    );
}
```

Check what you have installed before upgrading:

```bash
php artisan panel:plugins
php artisan panel:plugins --panel=admin
```

Three cases are still skipped, and all three mean there is no question to answer: a plugin that
declared no constraint, a framework that is not installed as a composer package (this repository's
own test suite), and a branch alias like `dev-main`, which no constraint can be evaluated against.

## If you cannot upgrade yet

Nothing forces the rename on an existing lockfile — an installed `panda/panel` keeps working from
`vendor/` as it always did. What you do not get is any release published after the rename, because
those are published under the new name only. There is no metapackage aliasing the old name to the
new one.

## Notes

- **The repository, and the framework, are still called Panda Panel.** The rename was to the
  composer vendor namespace, not to the project.
- **`config/panda-panel.php` keeps its name.** Renaming it would break every `config('panda-panel.*')`
  call in the package, and there is nothing to gain.
- **Nothing published into `resources/js` refers to the package name**, so `panel:assets` reports
  no change and the frontend needs no rebuild for this.
- **`composer why chocoalano/panel`** is the quickest way to confirm which name a project is
  actually resolving, especially in a repository where both appear in the history.

## See also

- [Installation](installation.md) — installing under the current name
- [Upgrading: package name migration](../upgrading/package-name-migration.md)
- [Upgrading: upgrade guide](../upgrading/upgrade-guide.md),
  [breaking changes](../upgrading/breaking-changes.md)
- [Plugins: compatibility](../plugins/compatibility.md), [metadata](../plugins/metadata.md)
- [CLI: panel:plugins](../cli/panel-plugins.md), [panel:assets](../cli/panel-assets.md)
- [Troubleshooting: Packagist](../troubleshooting/packagist.md)
