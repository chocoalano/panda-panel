# Published Asset Structure

The panel's Vue frontend is copied into your application by `vendor:publish` rather than imported from the package. This page is the map of what lands where: which directories are written, what each one holds, which two of them are configurable, and which files the package keeps to itself. Reach for it after an install, before moving anything under `resources/js`, or when working out whether a file you are looking at is yours or ours.

## A minimal working example

```bash
php artisan panel:install
npm install && npm run build
```

`panel:install` publishes the config tag, then the assets tag, then records what it wrote in `.panel-assets.json`. Afterwards:

```bash
ls resources/js
# components  composables  lib  pages  panel  types
```

Everything in that listing except `pages/Panels` is a file the package shipped. All of it is now the application's: in its repository, in its build, and editable.

## The publish map

`PandaPanel\Support\Installer\PublishedAssets::map()` is the single source of the map. The service provider hands it to `publishes()` and `panel:assets` diffs against it, so there is no second copy to drift.

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

PublishedAssets::map();
// array<string, string> — absolute source => absolute destination

PublishedAssets::files();
// array<string, string> — absolute destination => absolute source, one entry per file

PublishedAssets::relative('/app/resources/js/panel/layouts/PanelLayout.vue');
// 'resources/js/panel/layouts/PanelLayout.vue'
```

| Method | Signature | Returns |
| --- | --- | --- |
| `map` | `static map(): array` | source => destination, directories included |
| `files` | `static files(): array` | destination => source, expanded to individual files |
| `relative` | `static relative(string $path): string` | the path with `base_path()` stripped, for reports |

`files()` expands directories because "is this up to date" is a question about a file: a directory that gained one component and had another edited is neither changed nor unchanged.

## Publish tags

```bash
php artisan vendor:publish --tag=panda-panel-config
php artisan vendor:publish --tag=panda-panel-assets
php artisan vendor:publish --tag=panda-panel-migrations
php artisan vendor:publish --tag=panda-panel-stubs
php artisan vendor:publish --tag=panda-panel
```

| Tag | Publishes |
| --- | --- |
| `panda-panel-config` | `config/panda-panel.php` |
| `panda-panel-assets` | everything in the publish map above |
| `panda-panel-migrations` | the notifications table, the two-factor email column, and the integrations tables |
| `panda-panel-stubs` | the generator stubs into `stubs/panel` |
| `panda-panel` | config, migrations and assets together |

`panda-panel-stubs` is deliberately not part of the umbrella tag: the stubs are only useful to a project that intends to edit what the generators write.

## What each directory holds

### `resources/js/panel/`

The framework's own frontend: layouts, shell components, renderers for tables, forms, infolists, widgets and actions, the composables that read the shared props, the six component registries, and the TypeScript mirrors of every serialized shape. Nothing in here is Inertia-resolvable — it is imported by name, never resolved from a page component string.

See [Vue Component Tree](component-tree.md) for the file-by-file breakdown.

### `resources/js/pages/`

Two things, and the split matters:

| Path | Role |
| --- | --- |
| `pages/panel/**` | the framework's own screens — `Dashboard.vue`, `Page.vue`, `resources/{Index,Create,Edit,View,ManageRelated,Integrations}.vue`, `auth/*`, `settings/*` |
| `pages/Panels/**` | your application's panel components — custom pages, columns, fields, widgets, hooks, shell replacements |

Both are under `pages/` because Inertia resolves a component name against `resources/js/pages`, and every panel screen is an Inertia response. `pages/Panels` is also the root of every `import.meta.glob` the component registries use.

The package publishes `pages/panel/**` and creates nothing under `pages/Panels` — that directory is written by the generators as you use them.

### `resources/js/components/`, `composables/`, `lib/`, `types/`

The starter-kit-shaped files the panel's components import directly:

| Path | Holds |
| --- | --- |
| `components/*.vue` | `AppShell`, `AppContent`, `NavUser`, `InputError`, `ManagePasskeys`, `ManageTwoFactor`, and a few more |
| `components/ui/**` | the shadcn primitives the renderers use — `button`, `table`, `dialog`, `sidebar`, `select`, `sonner`, and the rest |
| `composables/useAppearance.ts` | the light/dark toggle the panel header drives |
| `lib/utils.ts`, `lib/flashToast.ts` | the `cn()` helper and the flash-toast bridge |
| `types/auth.ts`, `types/global.d.ts` | shared types the components reference |

These are the files most likely to collide with what a Laravel Vue starter kit already has. `vendor:publish` never overwrites an existing file unless you pass `--force`, so on a starter kit application the collisions are skipped and the application's own versions win — which is usually what you want.

### `resources/css/panda-panel.css`

The Tailwind v4 stylesheet: the theme mapping, the light and dark palettes, and the panel's few component classes. See [Tailwind Theme](tailwind-theme.md).

## Where the two configurable paths point

```php
use PandaPanel\Support\FrontendPaths;

FrontendPaths::panel();                 // /app/resources/js/panel
FrontendPaths::panel('icons/registry.ts');
// /app/resources/js/panel/icons/registry.ts

FrontendPaths::pages();                 // /app/resources/js/pages/Panels
FrontendPaths::pages('Admin/Widgets/SystemInfo.vue');
// /app/resources/js/pages/Panels/Admin/Widgets/SystemInfo.vue
```

| Method | Signature | Config key | Default |
| --- | --- | --- | --- |
| `panel` | `static panel(string $path = ''): string` | `panda-panel.frontend.panel_path` | `js/panel` |
| `pages` | `static pages(string $path = ''): string` | `panda-panel.frontend.pages_path` | `js/pages/Panels` |

Both resolve through `resource_path()`, so the configured value is relative to `resources/`:

```php
// config/panda-panel.php
'frontend' => [
    'panel_path' => 'js/panel',
    'pages_path' => 'js/pages/Panels',
],
```

Everything that has to name one of these paths reads it here — the publish map, the generators, and `panel:icons`. A path that could be spelled differently in three places is a path that will be.

`pages_path` is the more consequential of the two. It is also the root of the `import.meta.glob` patterns in the component registries, and those patterns are literal strings inside published files. Changing the config moves where the generators write; it does not move the globs. See [Component Registries](../concepts/component-registries.md).

## What the package does not publish

| Path in the package | Why it stays |
| --- | --- |
| `frontend/host/**` | stand-ins for the modules your application supplies — see [Host Modules](host-modules.md) |
| `frontend/entry.ts` | the glob entry for the package's own compile check |
| `vite.config.ts` | the package's build config, not an application's |
| `tsconfig.json`, `eslint.config.js` | the package's own toolchain |
| `resources/views/**` | the Inertia root view is the application's file |

The package's Vite build produces `build/frontend`, and nothing in it is a deliverable. It exists to answer one question type-checking cannot: does every one of these files actually resolve and compile together.

## Recording what was published

`panel:install` writes `.panel-assets.json` at the application root after publishing, and `panel:assets` reads it:

```bash
php artisan panel:assets            # report only
php artisan panel:assets --update   # write the files that are safe to write
php artisan panel:assets --force    # also overwrite files this application edited
```

```php
use PandaPanel\Support\Installer\AssetManifest;

AssetManifest::path();      // /app/.panel-assets.json
AssetManifest::exists();    // bool
AssetManifest::read();      // array<string, string> relative destination => hash
AssetManifest::compare();   // array<string, array{status, destination, source}>
AssetManifest::write(AssetManifest::read());
```

The file records the hash each published file had *at publish time*, which is the common ancestor a two-way diff is missing. Commit it: it is a record of a decision the project made, in the same way `composer.lock` is. [Updating Published Assets](updating-assets.md) covers the states and what `--update` acts on.

## Gotchas

- **`vendor:publish` skips existing files.** Without `--force` nothing already on disk is touched, which is why a second install reports almost nothing. Use `panel:assets` for upgrades rather than `vendor:publish --force`, which cannot tell an edited file from a stale one.
- **Publishing the assets tag writes into `resources/js/components` and `resources/js/types`.** On an application with its own starter kit files this is where a `--force` publish does real damage. Prefer `panel:assets --update`, which only writes files you have never touched.
- **`pages_path` and the registry globs are two separate edits.** Moving one without the other means every custom component resolves to nothing, silently, with a development-only console warning as the only clue.
- **A published component is yours.** `composer update` cannot improve it. That is the price of the `import.meta.glob` allowlist, and `panel:assets` exists to pay it.
- **`panel:install` is safe to re-run.** It publishes, records, scaffolds, checks the frontend, and prints what is still outstanding once at the end. Only `--force` overwrites.
- **Nothing under `frontend/host` reaches your application.** It resolves only inside this repository's own type-check and build. Your application supplies the real modules.

## See also

- [Vue Component Tree](component-tree.md)
- [Inertia Pages](inertia-pages.md)
- [Updating Published Assets](updating-assets.md)
- [Host Modules](host-modules.md)
- [Tailwind Theme](tailwind-theme.md)
- [Frontend Assets](../concepts/frontend-assets.md)
- [Component Registries](../concepts/component-registries.md)
- [Panel Assets](../panels/assets.md)
- [panel:install](../cli/panel-install.md), [panel:assets](../cli/panel-assets.md), [Publish tags](../cli/publish-tags.md)
- [Frontend Paths configuration](../configuration/frontend-paths.md)
