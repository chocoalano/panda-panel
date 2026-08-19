# Publish Tags

Five `vendor:publish` tags, plus one umbrella tag, are everything this package copies into an
application: the config file, the Vue frontend, the migrations, the generator stubs, and the
translations. Reach for them when you want one of those things on its own — `php artisan
panel:install` runs the same publishes in the right order and does the rest of the install
around them.

## A minimal working example

```bash
composer require chocoalano/panel
php artisan vendor:publish --tag=panda-panel-config
php artisan vendor:publish --tag=panda-panel-assets
npm install && npm run build
```

The first tag writes `config/panda-panel.php`, which is where a panel has to be listed before its
URL answers. The second writes the panel's Vue components into `resources/js`, which is what makes
the build-time component registries able to see them. Nothing else is required to boot a panel.

## The tags

```php
// PandaPanel\PandaPanelServiceProvider::registerPublishing()

$this->publishes([
    $this->packagePath('config/panda-panel.php') => config_path('panda-panel.php'),
], ['panda-panel', 'panda-panel-config']);

$this->publishes([
    $this->packagePath('database/migrations') => database_path('migrations'),
], ['panda-panel', 'panda-panel-migrations']);

$this->publishes([
    $this->packagePath('stubs/panel') => base_path('stubs/panel'),
], 'panda-panel-stubs');

$this->publishes([
    $this->packagePath('lang') => $this->app->langPath('vendor/panda-panel'),
], ['panda-panel', 'panda-panel-translations']);

$this->publishes(PublishedAssets::map(), ['panda-panel', 'panda-panel-assets']);
```

| Tag | Publishes | Destination | In `panda-panel` |
| --- | --- | --- | --- |
| `panda-panel-config` | `config/panda-panel.php` | `config/panda-panel.php` | yes |
| `panda-panel-assets` | the seven frontend sources | `resources/js/**`, `resources/css/panda-panel.css` | yes |
| `panda-panel-migrations` | `database/migrations` | `database/migrations` | yes |
| `panda-panel-stubs` | `stubs/panel` | `stubs/panel` | **no** |
| `panda-panel-translations` | `lang/{en,id}` (nine files each) | `lang/vendor/panda-panel` | yes |
| `panda-panel` | config, migrations, translations and assets together | as above | — |

`registerPublishing()` runs only when `$this->app->runningInConsole()` is true. That is the only
context `vendor:publish` exists in, but it also means a test that boots the application over HTTP
and then asks `ServiceProvider::publishableGroups()` sees nothing.

## `panda-panel-config`

```bash
php artisan vendor:publish --tag=panda-panel-config
```

Writes one file, `config/panda-panel.php`. The provider calls `mergeConfigFrom()` at register time,
so every key already has a value before you publish — publishing matters for one key in particular:

```php
// config/panda-panel.php
'panels' => [
    App\Panels\Admin\AdminPanelProvider::class,
],
```

Panels are listed rather than discovered, and a provider that is not in this list has no routes.
`panel:install` adds the line for you; `make:panel` prints it. Every key is in the
[configuration reference](../configuration/panda-panel.md).

## `panda-panel-assets`

```bash
php artisan vendor:publish --tag=panda-panel-assets
```

The map comes from `PandaPanel\Support\Installer\PublishedAssets::map()`, which the service provider
and `panel:assets` both read — one list, so a directory added in a later release cannot publish
without also being diffed.

| Package source | Application destination |
| --- | --- |
| `resources/js/panel` | `FrontendPaths::panel()` — `resources/js/panel` by default |
| `resources/js/components` | `resources/js/components` |
| `resources/js/composables` | `resources/js/composables` |
| `resources/js/lib` | `resources/js/lib` |
| `resources/js/pages` | `resources/js/pages` |
| `resources/js/types` | `resources/js/types` |
| `resources/css/panda-panel.css` | `resources/css/panda-panel.css` |

```php
use PandaPanel\Support\Installer\PublishedAssets;

PublishedAssets::map();      // array<string, string> — absolute source => absolute destination
PublishedAssets::files();    // array<string, string> — destination => source, one entry per file
PublishedAssets::relative('/app/resources/js/panel/palette.ts'); // 'resources/js/panel/palette.ts'
```

The first destination is configurable, and the map is built per call rather than held in a constant
so that the configured value is read at publish time:

```php
// config/panda-panel.php
'frontend' => [
    'panel_path' => 'js/panel',
    'pages_path' => 'js/pages/Panels',
],
```

Publish the config tag, edit `frontend.panel_path`, then publish the assets tag — in that order.
Publishing the assets first puts the components in the default location and moving them afterwards
is a manual job. `pages_path` is not a publish destination at all: the package creates nothing under
it, and the generators write there as you use them. See
[Frontend Paths](../configuration/frontend-paths.md) and
[Published Asset Structure](../frontend/assets.md).

The frontend is published rather than imported because every component registry is an
`import.meta.glob` allowlist over the application's own tree. A component the build never saw is a
component that cannot resolve. Published files are the application's — in its repository, in its
build, and editable.

Publishing does not build. Run `npm run build` afterwards.

## `panda-panel-migrations`

```bash
php artisan vendor:publish --tag=panda-panel-migrations
php artisan migrate
```

Four files:

| File | What it does |
| --- | --- |
| `2026_08_14_130919_create_notifications_table.php` | Laravel's `notifications` table, which the notification centre reads on every panel request |
| `2026_08_14_143501_add_email_two_factor_to_users_table.php` | `two_factor_email_confirmed_at` on `users` |
| `2026_08_15_120000_create_panel_integrations_table.php` | `panel_integrations` |
| `2026_08_15_140000_add_history_and_signing_to_panel_integrations.php` | delivery history and the signing secret |

These already run from the package — `load_migrations` is `true`, and `loadMigrationsFrom()` points
at the package directory. Publishing is about **ownership**, not about the tables existing. A project
that wants them in its own `database/migrations`, to edit or to keep one directory as the record of
its schema, publishes them and then turns the package's own loading off:

```php
// config/panda-panel.php
'load_migrations' => false,
```

The filenames are preserved. Laravel re-dates a published migration only for paths registered with
`publishesMigrations()`; these are registered with plain `publishes()`, so the copy in
`database/migrations` keeps the name the package shipped. That matters beyond tidiness:
`PandaPanel\Support\PackageSchema::isOwned()` decides whether a rollback may drop a table by asking
whether some *other* migration claims it, and a re-dated copy would read as a rival.

Each `up()` checks before it touches anything, so an application that already has the
`notifications` table or the `users` column is left alone.

## `panda-panel-stubs`

```bash
php artisan vendor:publish --tag=panda-panel-stubs
```

Copies all fourteen generator templates into `stubs/panel`:

| Stub | Read by |
| --- | --- |
| `panel-provider.stub` | `make:panel` |
| `resource.stub`, `resource-table.stub`, `resource-form.stub`, `resource-page.stub` | `make:panel-resource` |
| `page.stub`, `page-component.stub` | `make:panel-page` (the second only with `--component`) |
| `widget-stats.stub`, `widget-table.stub`, `widget-chart.stub`, `widget-custom.stub` | `make:panel-widget`, one per `--type` |
| `widget-component.stub` | `make:panel-widget --type=custom`, which is the only type whose Vue file is not optional |
| `relation-manager.stub`, `relation-page.stub` | `make:panel-relation-manager` (the second only with `--page`) |

`PandaPanel\Console\Commands\PanelGeneratorCommand::stubPath()` resolves the application's copy
first and the package's second:

```php
protected function stubPath(string $name): string
{
    $published = base_path("stubs/panel/{$name}.stub");

    if (File::exists($published)) {
        return $published;
    }

    return dirname(__DIR__, 3)."/stubs/panel/{$name}.stub";
}
```

So publishing a stub is how a project changes what its generators write, and the generators work
the moment the package is installed rather than only after someone remembers to publish. Publish one
stub or all of them — a directory with a single file in it is enough, and the rest fall back.

Placeholders are `{{ token }}`, replaced by `writeStub()` with `str_replace()`. The tokens in use
across the shipped stubs are `base`, `bulkActions`, `class`, `component`, `filters`, `imports`,
`label`, `modelBasename`, `pageClass`, `pageEntries`, `panel`, `path`, `pivotForm`, `plural`,
`recordActions`, `relationship`, `resource` and `softDeletes`. Which ones a given stub receives is
fixed by the generator that reads it — an edited stub may drop a token, but a token the generator
does not pass is never filled in.

`panda-panel-stubs` is deliberately outside the umbrella tag. The stubs are only useful to a project
that intends to change what it scaffolds, and publishing them by accident means later releases can
no longer improve the generated code.

## `panda-panel-translations`

```bash
php artisan vendor:publish --tag=panda-panel-translations
```

Copies `lang/en` and `lang/id` to `lang/vendor/panda-panel`. Laravel reads that directory *before*
the package's own, so a published file overrides the package sentence for sentence — a file with
one key in it changes one string and leaves the rest following the package.

Nothing has to be published for either locale to work. English and Indonesian both ship inside the
package and are registered by `loadTranslationsFrom()` at boot. This tag is for **rewording**, and
publishing a whole file has a real cost: a published `lang/vendor/panda-panel/en/actions.php` stops
following the package, so a key an upgrade adds will not reach your copy and renders as the raw
key until you add it by hand.

Adding a *third* locale needs no publish at all — write `lang/vendor/panda-panel/fr/` directly.
See [Translations](../localization/translations.md).

## `panda-panel`

```bash
php artisan vendor:publish --tag=panda-panel
```

Config, migrations and assets in one command. Convenient for a scripted install that wants the
migrations owned by the application; otherwise prefer the individual tags, because this one publishes
the migrations you may not want to own.

## Options `vendor:publish` accepts

These belong to Laravel's own `vendor:publish` command, not to this package.

| Option | Effect |
| --- | --- |
| `--tag=*` | One or many tags. Repeat the flag to publish several. |
| `--force` | Overwrite files that already exist. |
| `--existing` | Publish only files already present, overwriting them; skip the rest. |
| `--provider=` | Everything one provider registered, tags included. |
| `--all` | Publish every provider's assets without the interactive picker. |

```bash
php artisan vendor:publish --tag=panda-panel-config --tag=panda-panel-assets
php artisan vendor:publish --tag=panda-panel-assets --force
php artisan vendor:publish --provider="PandaPanel\PandaPanelServiceProvider"
```

`--provider` publishes the union of everything the provider registered — including
`panda-panel-stubs`, which no tag on this package's umbrella reaches. Use it when you genuinely want
all of it.

Without `--force`, an existing file is reported as `SKIPPED` and left exactly as it was. That is why
a second publish on a Laravel Vue starter kit application reports almost nothing: the starter kit's
own `resources/js/components/*` are already there and win.

## What `panel:install` publishes

```bash
php artisan panel:install
php artisan panel:install --force
```

`PandaPanel\Console\Commands\InstallPanelCommand` calls `vendor:publish` three times at most, in this
order:

1. `panda-panel-config`
2. `panda-panel-assets`
3. `panda-panel-migrations`, and only after an interactive confirm that defaults to no

Between steps 2 and 3 it writes `.panel-assets.json`:

```php
AssetManifest::write(AssetManifest::read());
```

`--force` on `panel:install` is passed straight through to each `vendor:publish` call. See
[panel:install](panel-install.md).

## Publishing again, later

The assets tag is the wrong tool for an upgrade, in both of its settings. Without `--force` nothing
updates; with `--force` everything is overwritten, including files the project deliberately changed.
Neither can tell the two apart, because "differs from the package's copy" is equally true of a stale
file and an edited one.

`.panel-assets.json` records the hash each file had *when it was published*, which is the common
ancestor the two-way comparison is missing:

```bash
php artisan panel:assets            # report only, writes nothing
php artisan panel:assets --update   # write the files that are safe to write
php artisan panel:assets --force    # also overwrite files this application edited
```

| On disk | In package | Reported as | `--update` |
| --- | --- | --- | --- |
| unchanged | unchanged | current | — |
| unchanged | changed | out of date | written |
| changed | unchanged | yours | left alone |
| changed | changed | CONFLICT | never written |

Use `vendor:publish --tag=panda-panel-assets` for a first install and `panel:assets` for every
upgrade after it. See [panel:assets](panel-assets.md) and
[Updating Published Assets](../frontend/updating-assets.md).

## Plugin assets are not a tag

A plugin's files are published by this package's own command rather than by a `vendor:publish` tag,
because the map lives on the plugin instance and is only knowable once the panels have booted:

```bash
php artisan panel:publish                # every plugin on every panel
php artisan panel:publish billing        # only this plugin id
php artisan panel:publish billing --force
```

`PandaPanel\Console\Commands\PublishPanelAssetsCommand` walks every registered panel, asks each
plugin for `publishes(): array` — absolute source => absolute destination — and copies file by file.
An existing destination is reported as `exists, skipped` unless `--force` is given. See
[Plugin Assets](../plugins/assets.md).

## The API behind the tags

Three classes decide what publishes, where it lands, and what happened to it afterwards. All of
their methods are static, and all of them are callable from a command, a test or `tinker`.

```php
use PandaPanel\Support\FrontendPaths;
use PandaPanel\Support\Installer\AssetManifest;
use PandaPanel\Support\Installer\PublishedAssets;

PublishedAssets::map();                     // what vendor:publish is given
PublishedAssets::files();                   // the same map, expanded file by file
PublishedAssets::relative(base_path('resources/js/panel/palette.ts'));
// 'resources/js/panel/palette.ts'

FrontendPaths::panel('icons/registry.ts');  // /app/resources/js/panel/icons/registry.ts
FrontendPaths::pages('Admin/Pages/Reports.vue');
// /app/resources/js/pages/Panels/Admin/Pages/Reports.vue

AssetManifest::path();                      // /app/.panel-assets.json
AssetManifest::exists();                    // false until something writes it
AssetManifest::read();                      // ['resources/js/panel/palette.ts' => 'a1b2…', …]
AssetManifest::compare();                   // one entry per shipped file, with a status
AssetManifest::write(AssetManifest::read());
```

| Method | Signature | Returns |
| --- | --- | --- |
| `PublishedAssets::map` | `static map(): array` | `array<string, string>` — absolute source => absolute destination |
| `PublishedAssets::files` | `static files(): array` | `array<string, string>` — destination => source, one entry per file |
| `PublishedAssets::relative` | `static relative(string $path): string` | the path with `base_path()` stripped |
| `FrontendPaths::panel` | `static panel(string $path = ''): string` | absolute path, `panda-panel.frontend.panel_path`, default `js/panel` |
| `FrontendPaths::pages` | `static pages(string $path = ''): string` | absolute path, `panda-panel.frontend.pages_path`, default `js/pages/Panels` |
| `AssetManifest::path` | `static path(): string` | `base_path('.panel-assets.json')` |
| `AssetManifest::exists` | `static exists(): bool` | whether that file is there |
| `AssetManifest::read` | `static read(): array` | relative destination => hash; `[]` when absent or unparseable |
| `AssetManifest::write` | `static write(array $existing = []): void` | rehashes every shipped file that is on disk, drops the ones that are not, and keeps any other hashes passed in |
| `AssetManifest::compare` | `static compare(?array $files = null): array` | relative => `array{status, destination, source}`; pass a map to compare something other than the real one |

`compare()` returns one of seven statuses, each a constant on `AssetManifest`:

| Constant | Value | Meaning |
| --- | --- | --- |
| `AssetManifest::NEW` | `new` | not in the manifest and different from ours |
| `AssetManifest::CURRENT` | `current` | unchanged on both sides |
| `AssetManifest::STALE` | `stale` | untouched here, changed upstream |
| `AssetManifest::MODIFIED` | `modified` | edited here, unchanged upstream |
| `AssetManifest::CONFLICT` | `conflict` | edited here *and* changed upstream |
| `AssetManifest::DELETED` | `deleted` | published, then deleted here |
| `AssetManifest::REMOVED_UPSTREAM` | `removed-upstream` | published once, no longer shipped |

Hashes are `xxh128` over the file with CRLF normalised to LF, so a Windows checkout does not report
every file as edited.

## What is never published

| In the package | Why it stays |
| --- | --- |
| `frontend/host/**` | stand-ins for modules the application supplies — see [Host Modules](../frontend/host-modules.md) |
| `frontend/entry.ts`, `vite.config.ts`, `tsconfig.json`, `eslint.config.js` | the package's own build and toolchain |
| `src/**` | the framework itself, which is imported rather than copied |
| Blade views | none are published; the Inertia root view is the application's file, and `examples/resources/views/app.blade.php` is there to be read rather than copied |

## Gotchas

- **The stubs are not in the umbrella tag.** `--tag=panda-panel` gives you config, migrations and
  assets. Publishing the stubs is a separate, deliberate command.
- **`vendor:publish` does not write `.panel-assets.json`.** Only `panel:install` and a
  `panel:assets --update`/`--force` run that actually wrote a file do. After a manual assets publish
  the manifest is absent, every identical file reads as `current`, and `--update` therefore writes
  nothing and records nothing. Run `panel:install` on a fresh application, or call
  `PandaPanel\Support\Installer\AssetManifest::write()` yourself, to get the first record.
- **Commit `.panel-assets.json`.** It is a record of a decision the project made, the same way
  `composer.lock` is. Without it an upgrade cannot tell your edits from a stale copy.
- **`--force` on the assets tag is where real damage happens.** It writes over
  `resources/js/components` and `resources/js/types`, which on a starter kit application are files
  the project owns. Prefer `panel:assets --update`.
- **Publishing the migrations without turning `load_migrations` off** leaves the same migration
  registered from two places. The `up()` methods guard themselves, so this is not fatal, but the
  intent of publishing is ownership and half of it is the config change.
- **`frontend.panel_path` is read when the map is built.** Change the config before publishing the
  assets, not after.
- **Publishing does not build.** Every published file is Vue or CSS. Run `npm run build`.

## See also

- [panel:install](panel-install.md)
- [panel:assets](panel-assets.md)
- [make:panel](make-panel.md), [make:panel-resource](make-panel-resource.md),
  [make:panel-page](make-panel-page.md), [make:panel-widget](make-panel-widget.md),
  [make:panel-relation-manager](make-panel-relation-manager.md)
- [Installation](../getting-started/installation.md)
- [Published Asset Structure](../frontend/assets.md)
- [Updating Published Assets](../frontend/updating-assets.md)
- [Frontend Paths](../configuration/frontend-paths.md)
- [Migration Loading](../configuration/migrations.md)
- [Configuration Reference](../configuration/panda-panel.md)
- [Plugin Assets](../plugins/assets.md), [Plugin CLI](../plugins/cli.md)
- [Translations](../localization/translations.md)
