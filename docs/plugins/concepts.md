# Plugin Concepts

A plugin is a reusable, configurable bundle of panel configuration. It does
exactly what a panel provider can do and nothing more, which is what stops it
from doing something a panel cannot. Reach for one when the same set of
resources, pages, widgets or shell decoration has to appear on more than one
panel, or when that set ships in a package of its own.

## A minimal working example

```php
<?php

namespace App\Panels\Plugins;

use App\Panels\Admin\Resources\Reports\ReportResource;
use PandaPanel\Core\Panel;
use PandaPanel\Plugins\Plugin;

final class ReportingPlugin extends Plugin
{
    public static function make(): self
    {
        return new self;
    }

    public function register(Panel $panel): void
    {
        $panel
            ->navigationGroups(['Insights'])
            ->resources([ReportResource::class]);
    }
}
```

Install it on a panel:

```php
use App\Panels\Plugins\ReportingPlugin;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelProvider;

final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->path('admin')
            ->auth()
            ->plugins([
                ReportingPlugin::make(),
            ]);
    }
}
```

That is a complete plugin. `register()` is the only method without a default.

## There is no second configuration surface

Everything a plugin does, it does through `PandaPanel\Core\Panel`'s own public
API — the same methods a panel provider calls. There is no plugin-only
registry, no plugin-only hook, and no plugin-only metadata channel.

That is a deliberate ceiling rather than an omission. A plugin can register
resources, pages, widgets, discovery paths, navigation groups, render hooks,
CSS hooks, assets, colours, middleware and an action configurator, because a
panel can. It cannot do anything a panel provider could not have written by
hand, which means every effect a plugin has is one an application can read on
`Panel` and reverse. See [Panel API Reference](../panels/api.md) for the full
surface a plugin is writing against.

## The id

`Plugin::id()` defaults to the kebab-cased class basename with a trailing
`Plugin` removed:

| Class | Id |
| --- | --- |
| `ReportingPlugin` | `reporting` |
| `AcmeBillingPlugin` | `acme-billing` |
| `Reporting` | `reporting` |
| `BillingPlugin` | `billing` |

Override it when the class name is not the name you want to be asked about:

```php
public function id(): string
{
    return 'acme-billing';
}
```

The id has to be unique on a panel. Two plugins claiming one id throws
`PandaPanel\Exceptions\PanelRegistrationException` from `plugins()`, before
either `register()` runs:

```
Two plugins claim the id [reporting] in panel [admin]. A plugin id is how a
panel is asked whether it has one, so it has to be unique.
```

The alternative to that throw is `hasPlugin('reporting')` being a question with
two answers, discovered later as a resource that appears twice in the sidebar.

## The three phases

| Phase | When it runs | What belongs there |
| --- | --- | --- |
| `register(Panel $panel)` | inside `Panel::plugins()`, while the panel is being built | resources, pages, widgets, navigation groups, discovery paths, settings |
| `boot(Panel $panel)` | on `Panel::boot()`, per request, after the panel's access check | anything needing the container, the request, the authenticated user, or a URL |
| `publishes()` | never automatically — only when `panel:publish` runs | files this plugin copies into the application |

Getting the first two backwards is the usual plugin bug and it is a quiet one:
a `register()` that queries works perfectly in development and shows up as a
database hit on every request in production, including the ones that never
touch a panel. [Register and Boot](lifecycle.md) has the exact call sites and
the ordering guarantees.

## Asking a panel what it has

A panel keeps its plugins keyed by id, and can be asked about them:

```php
// `panel($id)` throws for an unknown id; its declared return type is still
// nullable, so narrow it once rather than at every call.
$panel = panel('admin');

$panel?->hasPlugin('reporting');   // bool
$panel?->plugin('reporting');      // ?PanelPlugin — the configured instance
$panel?->getPlugins();             // array<string, PanelPlugin>, keyed by id
```

The reverse lookup is on the base class, and is what a resource supplied by a
plugin uses to read the settings it was installed with:

```php
use App\Panels\Plugins\ReportingPlugin;

$currency = ReportingPlugin::in(panel())?->getCurrency() ?? 'usd';
```

`in()` answers `null` for a panel that does not have the plugin rather than
throwing: a resource shared between two panels and installed on one of them is
a normal arrangement, not a mistake. It matches by class rather than by id,
because reading `id()` would mean constructing the plugin to ask it — and a
plugin whose constructor takes its configuration cannot be constructed without
it.

## Configurable, which is the point

A plugin that takes no configuration is a class the application could have
written itself. The value appears when one plugin can be two shapes on two
panels:

```php
use App\Panels\Plugins\ReportingPlugin;

// The admin panel gets everything.
$admin->plugins([
    ReportingPlugin::make()->withCharts()->group('Insights'),
]);

// The customer panel gets the resource and no charts, ungrouped.
$app->plugins([
    ReportingPlugin::make()->withCharts(false)->group(null),
]);
```

Fluent setters that return `$this`, read back in `register()`. Nothing in the
framework requires `make()`; `new ReportingPlugin` works identically, and a
constructor that takes required configuration is a good way to make a setting
non-optional.

## What ships in a plugin

| Thing | How it gets there |
| --- | --- |
| Resources, pages, widgets | `$panel->resources()`, `pages()`, `widgets()` in `register()` |
| Whole directories of them | `$panel->discoverResources()`, `discoverPages()`, `discoverWidgets()` |
| Navigation groups | `$panel->navigationGroups()` |
| Shell decoration | `$panel->renderHook()`, `$panel->cssHooks()` |
| Vite entrypoints | `$panel->assets()` |
| Vue components | `publishes()`, copied by `panel:publish` |
| Migrations, config, routes | the plugin package's own Laravel service provider |

The last row is the one worth stating plainly: `PanelPlugin` is not a service
provider. A plugin that needs migrations, a config file, an event listener or a
route outside the panel ships a normal `Illuminate\Support\ServiceProvider`
alongside it and lets composer's package discovery find that. The plugin object
configures a panel; the service provider configures the application.

## Plugins are registered, never discovered

There is no plugin discovery and no `make:panel-plugin` generator. A plugin is
in a panel because a panel provider named it in `plugins([...])`, which is the
only way it gets there. Resources, pages and widgets are discovered from
directories; plugins are not, because an installed composer package silently
adding routes and navigation to a panel is not a thing that should happen
without a line of application code saying so.

## The commands

```bash
php artisan panel:plugins              # what is installed, on which panel, at which version
php artisan panel:plugins --panel=admin
php artisan panel:publish              # copy plugin assets into the application
php artisan panel:publish reporting
php artisan panel:publish --force
```

Both are covered in [Plugin CLI](cli.md).

## Gotchas

- **`register()` runs on every request, panel or not.** It runs while the
  service provider is booting, so a request for a CSS file pays for it too.
  Nothing there may query, resolve a route, or read the current user.
- **A plugin's Vue component cannot be resolved from its own package.** Every
  component registry is an `import.meta.glob` over the application's
  `resources/js/pages/Panels/**`, which is a build-time allowlist. The plugin
  publishes into that tree instead. See [Plugin Assets](assets.md) and
  [Component Registries](../concepts/component-registries.md).
- **A plugin installed on two panels is normally two instances.** Each panel
  provider calls `plugins()` with its own configured object, so per-panel state
  is naturally isolated. Static state on the plugin class is not, and passing
  one object to both panels shares its state deliberately.
- **`plugin()` returns the contract, not your class.** The declared return type
  is `?PanelPlugin`. Use `YourPlugin::in($panel)` when you want your own type
  back without an instance check.
- **The panel's `bootUsing()` callbacks run after every plugin's `boot()`**, so
  an application always has the last word over a plugin it installed.

## See also

- [Creating a Plugin](creating-plugins.md)
- [Plugin Contract](contract.md)
- [Register and Boot](lifecycle.md)
- [Plugin Metadata](metadata.md)
- [Version Compatibility](compatibility.md)
- [Plugin Assets](assets.md)
- [Plugin CLI](cli.md)
- [Testing Plugins](testing.md)
- [Defining Panels](../panels/defining-panels.md)
- [Panel API Reference](../panels/api.md)
- [Render Hooks](../panels/render-hooks.md)
- [Component Registries](../concepts/component-registries.md)
- [Plugin Example](../recipes/plugin.md)
