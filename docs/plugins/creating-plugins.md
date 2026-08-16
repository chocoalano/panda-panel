# Creating a Plugin

This page builds one plugin from the smallest thing that works up to a version
shipped as its own composer package. Reach for it when you have decided a
bundle of panel configuration should be reusable; [Plugin Concepts](concepts.md)
is the page that helps you decide.

## The smallest plugin that works

Extend `PandaPanel\Plugins\Plugin` and implement one method:

```php
<?php

declare(strict_types=1);

namespace App\Panels\Plugins;

use App\Panels\Admin\Resources\Reports\ReportResource;
use PandaPanel\Core\Panel;
use PandaPanel\Plugins\Plugin;

final class ReportingPlugin extends Plugin
{
    public function register(Panel $panel): void
    {
        $panel->resources([ReportResource::class]);
    }
}
```

```php
use App\Panels\Plugins\ReportingPlugin;

$panel->plugins([
    new ReportingPlugin,
]);
```

The base class supplies `id()`, `boot()`, `metadata()` and `publishes()`, so
`register()` is the only thing left. Nothing about `make()` is required — it is
a convention because it reads better in a chain, not a framework hook.

## Adding a static constructor

```php
public static function make(): self
{
    return new self;
}
```

```php
$panel->plugins([
    ReportingPlugin::make(),
]);
```

Write one when the plugin has fluent setters, because `ReportingPlugin::make()->withCharts()`
reads better than `(new ReportingPlugin)->withCharts()`. Skip it when it does
not.

## Making it configurable

A plugin that takes no configuration is a class the application could have
written itself. Fluent setters that store state and return `$this`, read back
in `register()`:

```php
<?php

declare(strict_types=1);

namespace App\Panels\Plugins;

use App\Panels\Admin\Resources\Reports\ReportResource;
use App\Panels\Admin\Widgets\RevenueChart;
use PandaPanel\Core\Panel;
use PandaPanel\Plugins\Plugin;

final class ReportingPlugin extends Plugin
{
    private bool $charts = true;

    private ?string $group = 'Insights';

    private string $currency = 'usd';

    public static function make(): self
    {
        return new self;
    }

    public function withCharts(bool $charts = true): self
    {
        $this->charts = $charts;

        return $this;
    }

    public function group(?string $group): self
    {
        $this->group = $group;

        return $this;
    }

    public function currency(string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    /** Read back by the plugin's own resources. */
    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function register(Panel $panel): void
    {
        if ($this->group !== null) {
            $panel->navigationGroups([$this->group]);
        }

        $panel->resources([ReportResource::class]);

        if ($this->charts) {
            $panel->widgets([RevenueChart::class]);
        }
    }
}
```

One plugin, two panels, two shapes, and neither panel ships a class of its own:

```php
$admin->plugins([ReportingPlugin::make()->currency('eur')]);
$app->plugins([ReportingPlugin::make()->withCharts(false)->group(null)]);
```

Configuration that must not be omitted belongs in the constructor rather than
in a setter:

```php
public function __construct(private readonly string $currency) {}

public static function make(string $currency): self
{
    return new self($currency);
}
```

## Reading the configuration back

The plugin's own resources need the settings it was installed with. Two ways,
and the second is usually the one you want:

```php
use PandaPanel\Contracts\PanelPlugin;

$plugin = panel()?->plugin('reporting');   // ?PanelPlugin
```

```php
use App\Panels\Plugins\ReportingPlugin;

$currency = ReportingPlugin::in(panel())?->getCurrency() ?? 'usd';
```

`Plugin::in(?Panel $panel): ?static` matches by class, so the return value is
your own type with no instance check. It answers `null` when the panel does not
have the plugin — including when `panel()` itself is `null` outside a panel
request — which is why the call sites above end in `?? 'usd'`.

## Doing work in `boot()`

`register()` runs while the application is still booting its service providers.
Anything that needs the container, the request, a URL, or the authenticated
user goes in `boot()`:

```php
use PandaPanel\Core\Panel;
use PandaPanel\Enums\RenderHook;

public function boot(Panel $panel): void
{
    // A route name only exists once routes are registered, and the user
    // only exists once the request has been authenticated. Both are true
    // here and neither is true in register().
    $panel->renderHook(
        RenderHook::SidebarEnd,
        'Panels/AcmeReporting/Hooks/ReportShortcuts',
        [
            'url' => route($panel->routeName('resources.reports.index')),
            'name' => auth()->user()?->name,
        ],
    );
}
```

`boot()` runs once per request that reaches the panel, after the access check.
[Register and Boot](lifecycle.md) covers the ordering and the idempotency rule
that comes with running per request.

## Naming the plugin

The base class derives an id and a display name from the class name. Override
either when the derived value is wrong:

```php
use PandaPanel\Plugins\PluginMetadata;

public function id(): string
{
    return 'acme-reporting';
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
```

`package` is what `php artisan panel:plugins` uses to look the installed
version up from composer, and `requiresPanel` is checked at registration. Both
are covered in [Plugin Metadata](metadata.md) and
[Version Compatibility](compatibility.md).

## Shipping Vue components

A component in the plugin's package cannot be resolved: every component
registry is an `import.meta.glob` over the application's own
`resources/js/pages/Panels/**`. Declare where the files are and where they go:

```php
/**
 * @return array<string, string> absolute source => absolute destination
 */
public function publishes(): array
{
    return [
        __DIR__.'/../resources/js' => resource_path('js/pages/Panels/Reporting'),
    ];
}
```

```bash
php artisan panel:publish reporting
npm run build
```

[Plugin Assets](assets.md) has the full command behaviour and the directory
naming the registries expect.

## Shipping it as a package

A plugin distributed on packagist should implement
`PandaPanel\Contracts\PanelPlugin` directly rather than extend `Plugin`. A
package that extends an application's base class is a package coupled to it,
and nothing in the framework asks for `Plugin` — every lookup, every hook and
`panel:publish` all go through the contract.

```php
<?php

declare(strict_types=1);

namespace Acme\Reporting;

use PandaPanel\Contracts\PanelPlugin;
use PandaPanel\Core\Panel;
use PandaPanel\Plugins\PluginMetadata;

final class ReportingPlugin implements PanelPlugin
{
    public static function make(): self
    {
        return new self;
    }

    public function id(): string
    {
        return 'acme-reporting';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([Resources\ReportResource::class]);
    }

    public function boot(Panel $panel): void
    {
        //
    }

    public function metadata(): PluginMetadata
    {
        return new PluginMetadata(
            name: 'Acme Reporting',
            package: 'acme/panda-reporting',
            requiresPanel: '^1.0',
        );
    }

    /** @return array<string, string> */
    public function publishes(): array
    {
        return [
            __DIR__.'/../resources/js' => resource_path('js/pages/Panels/AcmeReporting'),
        ];
    }
}
```

Implementing the contract means implementing all five methods, including the
two the base class would have defaulted. That is the trade: no coupling, four
more lines.

### The package's own service provider

`PanelPlugin` is not a service provider and is never resolved by the container.
Migrations, config files, translations, event listeners and non-panel routes
belong in an ordinary Laravel provider shipped alongside it:

```php
<?php

declare(strict_types=1);

namespace Acme\Reporting;

use Illuminate\Support\ServiceProvider;

final class ReportingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->mergeConfigFrom(__DIR__.'/../config/reporting.php', 'reporting');
    }
}
```

```json
{
    "name": "acme/panda-reporting",
    "require": {
        "php": "^8.2",
        "chocoalano/panel": "^1.0"
    },
    "autoload": {
        "psr-4": {
            "Acme\\Reporting\\": "src/"
        }
    },
    "extra": {
        "laravel": {
            "providers": [
                "Acme\\Reporting\\ReportingServiceProvider"
            ]
        }
    }
}
```

Composer's package discovery registers the service provider automatically. The
plugin itself is still installed by hand, in a panel provider — an installed
package quietly adding resources and navigation to somebody's admin panel is
not a thing that should happen without a line of application code saying so.

## A suggested layout

```text
packages/reporting/
├── composer.json
├── resources/
│   └── js/
│       └── Widgets/
│           └── RevenueChart.vue
└── src/
    ├── ReportingPlugin.php
    ├── ReportingServiceProvider.php
    ├── Resources/
    │   └── Reports/
    │       ├── ReportResource.php
    │       ├── Forms/ReportForm.php
    │       └── Tables/ReportsTable.php
    └── Widgets/
        └── RevenueChart.php
```

Inside the application, `app/Panels/Plugins/` is a reasonable home for a plugin
that is not going anywhere. There is no generator for either: no
`make:panel-plugin` command exists, because a plugin is one class with one
required method and a stub would be longer than the class.

## Notes

- The plugin object is constructed by the application, not the container. There
  is no constructor injection; resolve what you need inside `boot()` with
  `app()`.
- `register()` is called immediately by `Panel::plugins()`, in array order.
  A plugin can therefore read what an earlier plugin registered, though relying
  on that makes the install order load-bearing.
- Discovery paths work from a plugin, which is how a package registers a whole
  directory of resources: `$panel->discoverResources(__DIR__.'/Resources')`.
  Those paths are cached by `panel:cache` like any other.

## See also

- [Plugin Concepts](concepts.md)
- [Plugin Contract](contract.md)
- [Register and Boot](lifecycle.md)
- [Plugin Metadata](metadata.md)
- [Version Compatibility](compatibility.md)
- [Plugin Assets](assets.md)
- [Testing Plugins](testing.md)
- [Creating Resources](../resources/creating-resources.md)
- [Widgets Overview](../widgets/overview.md)
- [Render Hooks](../panels/render-hooks.md)
- [Discovery](../concepts/discovery.md)
- [Plugin Example](../recipes/plugin.md)
