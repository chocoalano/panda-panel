# Frontend Assets

The panel's Vue frontend is *published into* your application rather than
imported from the package: `vendor:publish` copies it into `resources/js`, and
your application's Vite builds it alongside your own entrypoints. A panel can
also declare extra entrypoints of its own, loaded on that panel's pages and
nowhere else. Reach for this page when setting up the frontend, when upgrading
the package, or when a panel needs its own stylesheet.

## Publishing the frontend

```bash
php artisan panel:install
```

That publishes the config and the frontend, scaffolds a first panel, registers
it, checks the frontend toolchain, and offers to create an account. The
individual steps, if you would rather run them yourself:

```bash
php artisan vendor:publish --tag=panda-panel-config
php artisan vendor:publish --tag=panda-panel-assets
php artisan vendor:publish --tag=panda-panel-migrations
php artisan vendor:publish --tag=panda-panel-stubs
php artisan vendor:publish --tag=panda-panel          # config + migrations + assets

npm install && npm run build
```

| Tag | What it publishes |
| --- | --- |
| `panda-panel-config` | `config/panda-panel.php` |
| `panda-panel-assets` | the Vue frontend and `resources/css/panda-panel.css` |
| `panda-panel-migrations` | the `notifications` table and the two-factor email column |
| `panda-panel-stubs` | the generator stubs, into `stubs/panel` |
| `panda-panel` | config, migrations, and assets together |

## What lands where

`PandaPanel\Support\Installer\PublishedAssets::map()` is the single publish
map, read by both `vendor:publish` and `panel:assets`.

| Package source | Application destination |
| --- | --- |
| `resources/js/panel` | `FrontendPaths::panel()` — `resources/js/panel` |
| `resources/js/components` | `resources/js/components` |
| `resources/js/composables` | `resources/js/composables` |
| `resources/js/lib` | `resources/js/lib` |
| `resources/js/pages` | `resources/js/pages` |
| `resources/js/types` | `resources/js/types` |
| `resources/css/panda-panel.css` | `resources/css/panda-panel.css` |

```php
use PandaPanel\Support\Installer\PublishedAssets;

PublishedAssets::map();     // array<string, string> source => destination
PublishedAssets::files();   // array<string, string> destination => source, per file
```

Two of those destinations are configurable:

```php
// config/panda-panel.php
'frontend' => [
    'panel_path' => 'js/panel',
    'pages_path' => 'js/pages/Panels',
],
```

```php
use PandaPanel\Support\FrontendPaths;

FrontendPaths::panel();                 // /app/resources/js/panel
FrontendPaths::panel('layouts');        // /app/resources/js/panel/layouts
FrontendPaths::pages();                 // /app/resources/js/pages/Panels
FrontendPaths::pages('Admin/Widgets');  // /app/resources/js/pages/Admin/Widgets
```

| Method | Signature | Config key | Default |
| --- | --- | --- | --- |
| `panel` | `static panel(string $path = ''): string` | `panda-panel.frontend.panel_path` | `js/panel` |
| `pages` | `static pages(string $path = ''): string` | `panda-panel.frontend.pages_path` | `js/pages/Panels` |

Both resolve under `resource_path()`. `pages()` is also the root of the
`import.meta.glob` patterns the component registries use, so moving it means
editing those globs too — see
[Component Registries](component-registries.md).

## Why publish rather than import

Every component registry in the frontend is an `import.meta.glob` allowlist
over the *application's own* tree. A component the application's build never
saw is a component that cannot resolve, so the components have to be in the
application's build. Published files are the application's: in its repository,
in its build, and editable.

The cost of that decision is that a package update cannot silently improve a
file the application now owns. `panel:assets` exists to pay it.

## Three frontend locations

The split is not optional, because `@inertiajs/vite` only globs
`resources/js/pages/**`.

| Location | Role | Inertia-resolvable |
| --- | --- | --- |
| `resources/js/panel/**` | layouts, components, renderers, composables, registries, types | no |
| `resources/js/pages/panel/**` | framework-generic pages | yes |
| `resources/js/pages/Panels/{Panel}/**` | application-specific pages and custom components | yes |

Every published panel page declares its own layout, so nothing has to be wired
in `resources/js/app.ts`:

```ts
defineOptions({ layout: PanelLayout });        // PanelBlankLayout for auth pages
```

The one thing an application can get wrong is overwriting that choice:

```ts
page.default.layout = AppLayout;               // replaces the panel shell
page.default.layout ??= AppLayout;             // correct
```

An unconditional assignment puts every panel screen inside the application's
own shell, with the host sidebar and the panel navigation nowhere, at HTTP 200
and with nothing logged. `panel:install` reads `app.ts` and refuses to finish
quietly when it finds one, naming the file and the line.

## Per-panel entrypoints

A panel can declare Vite entrypoints loaded only on its own pages.

```php
$panel->assets('resources/css/panels/admin.css');
```

| Method | Signature | Notes |
| --- | --- | --- |
| `assets` | `assets(string ...$entrypoints): self` | Accumulates; a duplicate is added once |
| `getAssets` | `getAssets(): list<string>` | |

Emitting them is one edit to the application's own Inertia root view,
`resources/views/app.blade.php`. Nothing publishes this for you — the root
view belongs to the application, and a package that rewrote it would be
editing a file the project owns:

```blade
@vite([
    'resources/css/app.css',
    'resources/js/app.ts',
    "resources/js/pages/{$page['component']}.vue",
    ...(panel()?->getAssets() ?? []),
])
```

`panel()` is `null` outside a panel, so the spread contributes nothing on the
starter kit's pages and on other panels. A panel that declares no assets
contributes nothing either, which is why the line is safe to add before any
panel needs it.

**Two edits, deliberately.** The path must also appear in `vite.config.ts`'s
`input`, or Vite has nothing to serve and the page fails with a manifest
error:

```ts
export default defineConfig({
    plugins: [laravel({
        input: [
            'resources/css/app.css',
            'resources/js/app.ts',
            'resources/css/panels/admin.css',
        ],
    })],
});
```

That failure is the right one — a declared asset that was never built is a
mistake, not something to paper over — but it is why this is not a
single-line change.

The list itself never crosses to the frontend. `toSharedArray()` has no
`assets` key: the browser gets the tags, not what produced them.

## Keeping published files up to date

```bash
php artisan panel:assets            # report only
php artisan panel:assets --update   # write the safe ones
php artisan panel:assets --force    # also overwrite files you edited
```

`vendor:publish` alone cannot help on an upgrade, because it has two settings
and both are wrong. Without `--force` it skips every file that exists, so
nothing updates. With `--force` it overwrites everything, including the files
you deliberately changed. Neither can tell the difference: "differs from the
package's copy" is equally true of a stale file and an edited one.

`PandaPanel\Support\Installer\AssetManifest` records what each file looked
like *when it was published*, in `.panel-assets.json` at the application root.
That third value is the common ancestor, and it turns an ambiguous two-way
comparison into an unambiguous three-way one.

| Status | Meaning | `--update` writes |
| --- | --- | --- |
| `new` | The application never published it | yes |
| `stale` | Published, untouched here, changed upstream | yes |
| `current` | Published, untouched, unchanged | no |
| `modified` | Published and then edited here | only with `--force` |
| `conflict` | Edited here *and* changed upstream | only with `--force` |
| `deleted` | Published and then deleted here | never |
| `removed-upstream` | Published once, no longer shipped | never |

```php
use PandaPanel\Support\Installer\AssetManifest;

AssetManifest::path();               // /app/.panel-assets.json
AssetManifest::exists();             // bool
AssetManifest::read();               // array<string, string> destination => hash
AssetManifest::compare(?array $files = null);  // array<string, array{status, destination, source}>
AssetManifest::write(array $existing = []);    // record the current state
```

`.panel-assets.json` belongs in your repository. It is a record of a decision
the project made, in the same way `composer.lock` is: under `bootstrap/cache`
it would be regenerated and useless, under `storage` it would be gitignored
and lost on the first deploy.

A conflict is not a failure of the command — it ran correctly and found
something a person has to look at — so `panel:assets` exits 0 either way. A
non-zero exit would break a deploy over a file somebody edited on purpose.

After writing, rebuild:

```bash
npm run build
```

## The package's own build

`vite.config.ts` in the package repository produces nothing shipped. It exists
to answer the question no amount of type-checking does: does every one of
these files actually resolve and compile together? The entry is generated by
globbing the whole tree, because an entry that named a few components by hand
would compile a few components by hand. Nothing in `build/` is a deliverable.

## Notes

- The panel's CSS is Tailwind v4. Classes a panel provider sets through
  `cssHooks()` are arbitrary strings that are not in any file Tailwind scans —
  use classes that appear elsewhere in the application, or add the provider to
  the content globs.
- Panel colours go into a `style` attribute as CSS custom properties, not into
  classes. That is why `colors()` takes values and `cssHooks()` takes class
  names: a value never becomes a class.
- Tailwind classes must never be built by interpolation. Column spans, badge
  colours, grid columns, and content widths all map through literal records in
  the frontend for this reason.
- `panel:install` is idempotent apart from `--force`; it publishes, checks,
  and reports what is still outstanding once at the end rather than
  interleaving warnings with successes.
- A panel entrypoint is emitted on that panel's pages only. It is not on
  another panel, and not on the starter kit's own pages — `PanelAssetTest`
  asserts all three.

## See also

- [Component Registries](component-registries.md)
- [Server Metadata to Vue](metadata-to-vue.md)
- [Panels](panels.md)
- [Frontend Assets Guide](../frontend/assets.md)
- [Updating Assets](../frontend/updating-assets.md)
- [Tailwind Theme](../frontend/tailwind-theme.md)
- [Panel Assets](../panels/assets.md)
- [panel:assets](../cli/panel-assets.md)
- [panel:install](../cli/panel-install.md)
- [Frontend Build](../deployment/frontend-build.md)
