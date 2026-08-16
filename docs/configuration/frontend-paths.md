# Frontend Paths

Two configurable paths, both under the application's `resources/` directory: where the panel's Vue
components are published, and where the generators write the components they scaffold. Reach for
this page when a project already has `resources/js` arranged its own way, or when a generated
component is not where you expected it.

## A minimal working example

```php
// config/panda-panel.php

'frontend' => [
    'panel_path' => 'js/panel',
    'pages_path' => 'js/pages/Panels',
],
```

```php
use PandaPanel\Support\FrontendPaths;

FrontendPaths::panel();                    // /app/resources/js/panel
FrontendPaths::pages();                    // /app/resources/js/pages/Panels
FrontendPaths::panel('icons/registry.ts'); // /app/resources/js/panel/icons/registry.ts
```

Those are the defaults. An application that leaves the config file unpublished gets exactly the
same answers, because both defaults are repeated inside `FrontendPaths` rather than only in the
config file.

## `PandaPanel\Support\FrontendPaths`

| Method | Signature | Config key | Default |
| --- | --- | --- | --- |
| `panel` | `static panel(string $path = ''): string` | `panda-panel.frontend.panel_path` | `js/panel` |
| `pages` | `static pages(string $path = ''): string` | `panda-panel.frontend.pages_path` | `js/pages/Panels` |

Both return an **absolute** path, resolved through `resource_path()`, and both take an optional
suffix:

```php
use PandaPanel\Support\FrontendPaths;

FrontendPaths::panel();                                  // …/resources/js/panel
FrontendPaths::panel('widgets/registry.ts');             // …/resources/js/panel/widgets/registry.ts
FrontendPaths::pages();                                  // …/resources/js/pages/Panels
FrontendPaths::pages('Admin/Pages/Reports.vue');         // …/resources/js/pages/Panels/Admin/Pages/Reports.vue
FrontendPaths::pages('/Admin/Widgets/SystemInfo.vue');   // the same — a leading slash is trimmed
```

Resolution is four lines and worth knowing exactly:

1. The config key is read.
2. A value that is not a non-empty string falls back to the default. `null`, `false`, `0` and `''`
   are all "not configured".
3. The base is trimmed of leading and trailing slashes.
4. The suffix, when given, is joined with a single `/` after its own leading slash is trimmed.

There is no third path and no way to move the two independently of `resources/` — the values are
always relative to it, because every component registry in the frontend is an `import.meta.glob`
over the application's own tree and Vite cannot glob outside the project.

## Who reads them

Everything that has to name one of these paths reads it here. A path that could be spelled
differently in the publisher, the generator and the icon command is a path that will be.

| Caller | Which | Used for |
| --- | --- | --- |
| `PandaPanel\Support\Installer\PublishedAssets::map()` | `panel()` | the destination of the package's `resources/js/panel` in `vendor:publish --tag=panda-panel-assets` |
| `MakePanelPageCommand` | `pages("{$panel}/Pages/{$class}.vue")` | where `make:panel-page --component` writes |
| `MakePanelWidgetCommand` | `pages("{$panel}/Widgets/{$class}.vue")` | where `make:panel-widget` writes a custom widget |
| `SyncPanelIconsCommand` | `panel('icons/registry.ts')` | the file `panel:icons` rewrites |

```bash
php artisan make:panel-page Reports --panel=Admin --component
# resources/js/pages/Panels/Admin/Pages/Reports.vue

php artisan make:panel-widget Revenue --panel=Admin --type=custom
# resources/js/pages/Panels/Admin/Widgets/Revenue.vue

php artisan panel:icons
# rewrites resources/js/panel/icons/registry.ts
```

`panel_path` is a publish destination. `pages_path` is not: the package creates nothing under it,
and the generators fill it in as you use them.

## Moving `panel_path`

Publish the config first, edit it, then publish the assets — in that order:

```bash
php artisan vendor:publish --tag=panda-panel-config
# edit frontend.panel_path
php artisan vendor:publish --tag=panda-panel-assets
```

`PublishedAssets::map()` is built per call rather than held in a constant, precisely so the
configured value is read at publish time. Publishing the assets first puts the components in the
default location, and moving them afterwards is a manual job that also has to fix every import
inside them.

There is a second cost, and it is why most projects leave this key alone: the published components
import each other by the literal alias `@/panel/...` — `@/panel/types/form`,
`@/panel/icons/registry`, `@/panel/composables/usePanel`, several hundred times over. `@` resolves
to `resources/js` in a starter kit's `vite.config.ts`, so a `panel_path` of anything other than
`js/panel` publishes the files somewhere their own imports do not point. Moving this key means
rewriting those imports, or adding a Vite alias that maps `@/panel` to wherever you put them.

## Moving `pages_path`

This is the more consequential of the two, because `pages_path` is also the root of the
`import.meta.glob` patterns that resolve every custom component name the server sends — and those
patterns are literal strings inside published files. Changing the config moves where the
generators write. It does not move the globs.

| Registry file (under `panel_path`) | Pattern |
| --- | --- |
| `widgets/registry.ts` | `../../pages/Panels/**/Widgets/*.vue` |
| `hooks/registry.ts` | `../../pages/Panels/**/Hooks/*.vue` |
| `shell/registry.ts` | `../../pages/Panels/**/Shell/*.vue` |
| `tables/registry.ts` | `../../pages/Panels/**/Columns/*.vue` |
| `tables/registryEmptyStates.ts` | `../../pages/Panels/**/EmptyStates/*.vue` |
| `forms/registry.ts` | `../../pages/Panels/**/Fields/*.vue`, `**/Schemas/*.vue`, `**/Entries/*.vue`, `**/Modals/*.vue` |

Moving `pages_path` means editing all of those to match. The patterns are relative rather than
aliased on purpose: Vite's dev server resolves an aliased glob to nothing at all while the
production build resolves it normally, so an aliased pattern means every custom component renders
the fallback in development and works once built.

Inertia's own page resolution is a separate constraint. `@inertiajs/vite` only globs
`resources/js/pages/**`, so a page component that has to be Inertia-resolvable has to live
somewhere under `resources/js/pages` — which is why `pages_path` defaults inside it.

## Gotchas

- **Two edits, not one.** Moving `pages_path` without moving the globs makes every custom
  component resolve to nothing, silently, with a development-only console warning as the only
  clue.
- **The defaults live in two places.** `config/panda-panel.php` and `FrontendPaths` both name
  `js/panel` and `js/pages/Panels`, so an application with no published config still resolves. If
  you change one, change the other only if you are editing the package itself.
- **`FrontendPaths` never creates a directory.** It answers where a file goes; the generators
  create what they need.
- **`vendor:publish` skips existing files.** Changing `panel_path` and republishing writes a
  second copy at the new location and leaves the old one behind. Delete the old directory
  yourself.
- **`panel:assets` compares against the configured destination.** After moving `panel_path`, files
  still sitting at the old location are invisible to it — neither reported nor updated. See
  [Updating Published Assets](../frontend/updating-assets.md).

## See also

- [config/panda-panel.php](panda-panel.md)
- [Published Asset Structure](../frontend/assets.md)
- [Updating Published Assets](../frontend/updating-assets.md)
- [Component Registries](../concepts/component-registries.md)
- [Publish Tags](../cli/publish-tags.md)
- [make:panel-page](../cli/make-panel-page.md)
- [make:panel-widget](../cli/make-panel-widget.md)
- [panel:icons](../cli/panel-icons.md)
- [Directory Structure](../getting-started/directory-structure.md)
