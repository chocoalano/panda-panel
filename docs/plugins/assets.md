# Plugin Assets

A plugin that ships a Vue component has to copy it into the application before
it can be rendered. `publishes()` declares what to copy and where, and
`php artisan panel:publish` does the copying. Reach for this page when a plugin
ships frontend files of any kind — components, stubs, a stylesheet.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace Acme\Reporting;

use PandaPanel\Core\Panel;
use PandaPanel\Plugins\Plugin;

final class ReportingPlugin extends Plugin
{
    public function register(Panel $panel): void
    {
        $panel->widgets([Widgets\RevenueChart::class]);
    }

    /**
     * @return array<string, string> absolute source => absolute destination
     */
    public function publishes(): array
    {
        return [
            __DIR__.'/../resources/js' => resource_path('js/pages/Panels/AcmeReporting'),
        ];
    }
}
```

```bash
php artisan panel:publish acme-reporting
npm run build
```

```text
  [acme-reporting] …/resources/js/pages/Panels/AcmeReporting/Widgets/RevenueChart.vue  published

  INFO  Published 1 file(s).
```

## Why the copy is necessary

Every component registry in this framework is an `import.meta.glob` over the
application's own tree:

```ts
import.meta.glob('../../pages/Panels/**/Widgets/*.vue');
```

That is a build-time allowlist by design — a name resolved from anywhere else
would be a name the build never saw. A component sitting in
`vendor/acme/panda-reporting/resources/js/` is not matched by that glob and
cannot be resolved, no matter what the PHP class calls it.

So a plugin publishes its components into that tree, and from then on they are
the application's files: in its repository, in its build, and editable. That is
a feature rather than a workaround. A component you cannot see the source of is
a component you cannot debug.

[Component Registries](../concepts/component-registries.md) has the full
naming rule.

## `publishes(): array`

```php
/**
 * @return array<string, string> absolute source path => absolute destination path
 */
public function publishes(): array;
```

Keys are sources, values are destinations, both absolute. It is a method rather
than configuration because only the plugin knows where its own files are —
`__DIR__` is the only thing that can answer that for a package.

Either side may be a file or a directory:

```php
public function publishes(): array
{
    return [
        // A directory, copied recursively, relative paths preserved.
        __DIR__.'/../resources/js' => resource_path('js/pages/Panels/AcmeReporting'),

        // A single file.
        __DIR__.'/../resources/css/reporting.css' => resource_path('css/reporting.css'),

        // Anywhere in the application, not only under resources/.
        __DIR__.'/../stubs/report.stub' => base_path('stubs/report.stub'),
    ];
}
```

The default on `PandaPanel\Plugins\Plugin` is `[]`, which is right for most
plugins. A plugin implementing `PandaPanel\Contracts\PanelPlugin` directly has
to write the empty method itself.

`publishes()` is only ever called by `panel:publish`, never during a request, so
it may use `resource_path()`, `base_path()` and friends freely.

## Where the files should go

A component's name is the path below `pages/`, without the extension, and the
registries match `Panels/**/{Kind}/*.vue`. So the destination decides the name:

| Destination | Name declared in PHP |
| --- | --- |
| `resources/js/pages/Panels/AcmeReporting/Widgets/RevenueChart.vue` | `Panels/AcmeReporting/Widgets/RevenueChart` |
| `resources/js/pages/Panels/AcmeReporting/Columns/Sparkline.vue` | `Panels/AcmeReporting/Columns/Sparkline` |
| `resources/js/pages/Panels/AcmeReporting/Hooks/Banner.vue` | `Panels/AcmeReporting/Hooks/Banner` |

| Kind directory | Registry | Declared by |
| --- | --- | --- |
| `Columns/` | tables | `CustomColumn::component()` |
| `Fields/`, `Schemas/`, `Entries/`, `Modals/` | forms | `CustomField::component()`, `CustomComponent::make()`, `CustomEntry::component()`, `Modal::content()` |
| `Widgets/` | widgets | `CustomWidget::$component` |
| `Hooks/` | hooks | `Panel::renderHook()` |
| `Shell/` | shell | `Panel::sidebarComponent()`, `Panel::topbarComponent()` |

Give the plugin its own top-level directory under `Panels/` rather than
publishing into a panel's directory. `Panels/AcmeReporting/` belongs to the
plugin, is obvious in a diff, and does not collide with a panel called
`acme-reporting` that the application might add later. The `**` in the globs
means depth does not matter, only the kind directory directly above the file.

Full Inertia pages for a plugin's `Page` classes are resolved by the
application's own `import.meta.glob('./pages/**/*.vue')` rather than by a panel
registry, so `pages/Panels/AcmeReporting/Pages/Report.vue` works the same way.

## `panel:publish`

```bash
php artisan panel:publish [plugin] [--force]
```

| Argument / option | Type | Default | Effect |
| --- | --- | --- | --- |
| `plugin` | optional argument | all plugins | Publish only the plugin with this **id**, not class name |
| `--force` | flag | off | Overwrite destination files that already exist |

```bash
php artisan panel:publish                     # every plugin on every panel
php artisan panel:publish acme-reporting      # one plugin
php artisan panel:publish acme-reporting --force
```

What the command does, in order:

1. Walks every registered panel, and every plugin on it.
2. Skips plugins whose `id()` does not match the argument, when one was given.
3. Calls `publishes()` through the `PanelPlugin` contract — so a plugin shipped
   as its own package publishes like any other.
4. For each source: warns and continues if it does not exist, copies
   recursively if it is a directory, copies once if it is a file.
5. Creates missing destination directories.
6. Prints one line per file, and a count at the end.

### Nothing is overwritten without `--force`

```text
  [acme-reporting] …/pages/Panels/AcmeReporting/Widgets/RevenueChart.vue  exists, skipped
```

A published file the application has since edited is work, and silently
replacing it is losing it. The skip is reported rather than silent, so an
upgrade that ships a changed component is visible in the output.

When every file already exists, nothing is written and the command says so:

```text
  INFO  Nothing to publish.
```

That message means "no files were written", not "this plugin publishes
nothing" — the per-file `exists, skipped` lines above it are where the
difference shows.

### A missing source is a warning, not a failure

```text
  [acme-reporting] /path/that/does/not/exist  does not exist.
```

The command continues to the next entry and still exits successfully. A wrong
path in `publishes()` is a plugin bug that should not stop an application from
publishing its other plugins.

## Rebuild afterwards

```bash
npm run build      # or npm run dev
```

`import.meta.glob` is evaluated at build time, so a newly published component is
not in the bundle until then. The dev server picks up new files; a production
bundle built before the file existed does not contain it, and the component
renders its fallback with a development-only console warning.

## Vite entrypoints

A plugin that ships a stylesheet or a script of its own can add it to the
panel's entrypoints:

```php
public function register(Panel $panel): void
{
    $panel->assets('resources/css/reporting.css');
}
```

`Panel::assets(string ...$entrypoints)` accumulates, so a plugin adds to the
panel's own rather than replacing them. The paths must also appear in
`vite.config.ts`'s `input`, or Vite has nothing to serve and the page fails with
a manifest error — which is why this is a deliberate pair of edits rather than
one. That second edit is the application's, not the plugin's, and belongs in the
plugin's installation instructions. See [Panel Assets](../panels/assets.md).

## Gotchas

- **A plugin installed on two panels is visited twice.** `panel:publish` loops
  panels, then plugins. The first pass writes the files; the second reports
  them as `exists, skipped`. Harmless, and worth recognising in the output.
- **`panel:assets` does not track plugin files.** That command and
  `.panel-assets.json` cover the framework's own published frontend only. A
  plugin's published files are compared against nothing, so an upgraded plugin
  cannot tell you its component changed. `panel:publish --force` after checking
  the diff yourself is the whole upgrade path.
- **The argument is the id, not the class or the package.** A plugin whose
  `id()` is overridden publishes under the overridden id.
- **Publishing needs the plugin registered on a panel.** The command reads
  panels, not composer. A plugin that is installed but not listed in any
  panel's `plugins([...])` publishes nothing.
- **Only direct children of a kind directory are registered.**
  `Widgets/Charts/Revenue.vue` is copied by the command and matched by no
  registry, because the globs end in `*.vue`.
- **Deleted upstream files are not removed.** Publishing only ever copies. A
  component a plugin has dropped stays in the application until somebody
  deletes it.

## See also

- [Plugin Concepts](concepts.md)
- [Creating a Plugin](creating-plugins.md)
- [Plugin Contract](contract.md)
- [Plugin CLI](cli.md)
- [Testing Plugins](testing.md)
- [Component Registries](../concepts/component-registries.md)
- [Published Asset Structure](../frontend/assets.md)
- [Custom Widgets](../frontend/custom-widgets.md)
- [Custom Columns](../frontend/custom-columns.md)
- [Render Hooks](../panels/render-hooks.md)
- [Panel Assets](../panels/assets.md)
- [panel:assets](../cli/panel-assets.md)
