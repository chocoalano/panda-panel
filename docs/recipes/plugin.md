# Plugin

A plugin carried the whole way: one class inside an application, then the same plugin configurable, then shipped as its own composer package with a Vue component it publishes and a version constraint it is refused on. Read this page when a bundle of panel configuration — a resource plus its widgets plus a navigation group — should be installable in more than one panel, or in more than one application.

Everything a plugin does, it does through the panel's own public API. There is no second configuration surface, which is what stops a plugin from being able to do something a panel cannot.

There is no `make:panel-plugin`. A plugin is one class with one required method, and a stub would be longer than the class.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Plugins;

use App\Panels\Admin\Resources\Products\ProductResource;
use PandaPanel\Core\Panel;
use PandaPanel\Plugins\Plugin;

final class CatalogPlugin extends Plugin
{
    public function register(Panel $panel): void
    {
        $panel->resources([ProductResource::class]);
    }
}
```

```php
// app/Panels/Admin/AdminPanelProvider.php

use App\Panels\Plugins\CatalogPlugin;

return $panel
    ->path('admin')
    ->auth()
    ->plugins([
        new CatalogPlugin,
    ]);
```

`PandaPanel\Plugins\Plugin` supplies `id()`, `boot()`, `metadata()` and `publishes()`, so `register()` is the only thing left. The id is derived from the class name — `CatalogPlugin` becomes `catalog`.

## The contract

`PandaPanel\Contracts\PanelPlugin` declares five methods:

```php
interface PanelPlugin
{
    public function id(): string;
    public function register(Panel $panel): void;
    public function boot(Panel $panel): void;
    public function metadata(): PluginMetadata;

    /** @return array<string, string> absolute source path => absolute destination path */
    public function publishes(): array;
}
```

`PandaPanel\Plugins\Plugin` is a convenient base that implements four of them. Nothing in the framework asks for it — every lookup, every hook, and `panel:publish` all go through the contract — so a plugin shipped as its own package should implement the interface directly.

| Base-class default | Value |
| --- | --- |
| `id()` | `Str::kebab(Str::beforeLast(class_basename(static::class), 'Plugin'))` |
| `boot()` | nothing |
| `metadata()` | `new PluginMetadata(name: Str::headline($this->id()))` |
| `publishes()` | `[]` |

## Making it configurable

A plugin that takes no configuration is a class the application could have written itself. Fluent setters that store state and return `$this`, read back in `register()`:

```php
<?php

declare(strict_types=1);

namespace App\Panels\Plugins;

use App\Panels\Admin\Resources\Products\ProductResource;
use App\Panels\Admin\Widgets\LowStock;
use PandaPanel\Core\Panel;
use PandaPanel\Plugins\Plugin;

final class CatalogPlugin extends Plugin
{
    private bool $widgets = true;

    private ?string $group = 'Catalog';

    private string $currency = 'usd';

    public static function make(): self
    {
        return new self;
    }

    public function withWidgets(bool $widgets = true): self
    {
        $this->widgets = $widgets;

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

    /** Read back by this plugin's own resources. */
    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function register(Panel $panel): void
    {
        if ($this->group !== null) {
            $panel->navigationGroups([$this->group]);
        }

        $panel->resources([ProductResource::class]);

        if ($this->widgets) {
            $panel->widgets([LowStock::class]);
        }
    }
}
```

One plugin, two panels, two shapes, and neither panel ships a class of its own:

```php
$admin->plugins([CatalogPlugin::make()->currency('eur')]);
$app->plugins([CatalogPlugin::make()->withWidgets(false)->group(null)]);
```

`make()` is a convention rather than a framework hook — it reads better in a chain. Configuration that must not be omitted belongs in the constructor instead, where it cannot be forgotten:

```php
public function __construct(private readonly string $currency) {}

public static function make(string $currency): self
{
    return new self($currency);
}
```

The plugin object is constructed by the application, not the container. There is no constructor injection; resolve what you need inside `boot()` with `app()`.

## Reading the configuration back

The plugin's own resources need the settings it was installed with. Two ways, and the second is usually the one you want:

```php
use PandaPanel\Contracts\PanelPlugin;

$plugin = panel()?->plugin('catalog');   // ?PanelPlugin
```

```php
use App\Panels\Plugins\CatalogPlugin;

$currency = CatalogPlugin::in(panel())?->getCurrency() ?? 'usd';
```

```php
public static function in(?Panel $panel): ?static
```

`in()` matches by **class**, so the return value is your own type with no instance check. It is found by class rather than by looking up `id()`, because reading `id()` would mean constructing a plugin to ask it — and a plugin whose constructor takes its configuration cannot be constructed without it.

It answers `null` when the panel does not have the plugin, including when `panel()` itself is `null` outside a panel request. A resource shared between two panels and installed in one of them is a normal arrangement, not a mistake, which is why the call sites above end in `?? 'usd'`.

The panel side:

```php
public function plugins(array $plugins): self          // list<PanelPlugin>; calls register() in array order
public function getPlugins(): array                    // array<string, PanelPlugin>, keyed by id
public function hasPlugin(string $id): bool
public function plugin(string $id): ?PanelPlugin
```

Two plugins claiming one id is refused at registration:

```php
Panel::make('admin')->plugins([CatalogPlugin::make(), CatalogPlugin::make()]);
// PandaPanel\Exceptions\PanelRegistrationException: … claim the id …
```

A panel is asked `hasPlugin('catalog')` to decide what to show, and two answers to one question is worth catching at boot rather than discovering as a resource that appears twice.

## `register()` and `boot()`

Three phases, and which one a piece of work belongs in is the single thing plugin authors get wrong.

| Phase | When | What belongs there |
| --- | --- | --- |
| `register()` | while the panel is being configured | resources, pages, widgets, navigation groups, settings, discovery paths |
| `boot()` | after the panel is resolved, per request | anything needing the container, the user, or a URL |
| `publishes()` | never automatically — only `panel:publish` | files this plugin copies into the application |

`register()` runs during the application's boot, for **every** request, including the ones that never touch a panel. Work there that queries, reads the authenticated user, or resolves a route is work every request pays for and most requests waste — and, for the user, work done before there is a user to read.

```php
use PandaPanel\Core\Panel;
use PandaPanel\Enums\RenderHook;

public function boot(Panel $panel): void
{
    // A route name only exists once routes are registered, and the user only
    // once the request has been authenticated. Both are true here and
    // neither is true in register().
    $panel->renderHook(
        RenderHook::SidebarEnd,
        'Panels/Catalog/Hooks/StockShortcut',
        [
            'url' => route($panel->routeName('resources.products.index')),
            'name' => auth()->user()?->name,
        ],
    );

    $panel->cssHooks(['page' => 'catalog-page']);
}
```

```php
public function renderHook(RenderHook $hook, string $component, array $data = [], array $scopes = []): self
public function cssHooks(array $classes): self
public function getRenderHooks(): array
public function getCssHooks(): array
```

`RenderHook` is a closed set — `BodyStart`, `BodyEnd`, `SidebarStart`, `SidebarEnd`, `HeaderStart`, `HeaderEnd`, `PageStart`, `PageEnd` — because a hook registered against a name the shell does not render would silently do nothing. The component is a registry key under `resources/js/pages/Panels/{Panel}/Hooks/`, never markup: nothing renderable crosses the wire here either.

`boot()` runs once per request that reached that panel, **after** the access check, and **before** the panel's own `bootUsing()` callbacks — so an application always gets the last word over a plugin it installed.

Getting the two backwards is the usual plugin bug, and it is a quiet one: a `register()` that queries works perfectly in development and shows up as a database hit on every asset request in production.

Because `boot()` runs per request, it must be idempotent. Anything that appends — a render hook, a navigation group — appends again on the next request within the same process under Octane.

## What a plugin may register

Everything a panel provider can, through the same methods:

```php
public function register(Panel $panel): void
{
    $panel
        ->resources([ProductResource::class])
        ->pages([CatalogSettings::class])
        ->widgets([LowStock::class])
        ->navigationGroups(['Catalog'])
        // Discovery works from a plugin too, which is how a package
        // registers a whole directory. These paths are cached by
        // `panel:cache` like any other.
        ->discoverResources(__DIR__.'/Resources')
        ->assets('resources/css/catalog.css')
        ->cssHooks(['page' => 'catalog-page']);
}
```

`PanelPlugin` is **not** a service provider and is never resolved by the container. Migrations, config files, translations, event listeners and non-panel routes belong in an ordinary Laravel provider shipped alongside it.

## Shipping Vue components

A component in the plugin's own package cannot be resolved. Every component registry in this framework is an `import.meta.glob` over the application's `resources/js/pages/Panels/**`, which is a build-time allowlist by design — a name resolved from anywhere else would be a name the build never saw.

So a plugin publishes its components into that tree, and from then on they are the application's files: in its repository, in its build, and editable. That is a feature rather than a workaround; a component you cannot see the source of is a component you cannot debug.

```php
/**
 * Absolute source path => absolute destination path. A directory copies
 * recursively; a file copies as one file.
 *
 * @return array<string, string>
 */
public function publishes(): array
{
    return [
        // For a plugin living in the application, keep the sources beside
        // the class: app/Panels/Plugins/stubs/StockGauge.vue.
        __DIR__.'/stubs' => resource_path('js/pages/Panels/Catalog'),
    ];
}
```

A packaged plugin points at its package's own `resources/js` instead — the same method, a different left-hand side:

```php
__DIR__.'/../resources/js' => resource_path('js/pages/Panels/AcmeCatalog'),
```

```bash
php artisan panel:publish catalog
npm run build
```

```bash
php artisan panel:publish
    {plugin?}   # only this plugin, by id; omit for every plugin on every panel
    --force     # overwrite files that already exist
```

Without `--force`, a destination that already exists is **skipped and reported**, never overwritten:

```text
  [catalog] .../resources/js/pages/Panels/Catalog/Gauge.vue ... exists, skipped
```

A published file the plugin author changed is a file the application may have changed too, and overwriting one of those is losing work. A source path that does not exist warns by name and the rest still publish.

## Naming it, and versioning it

```php
use PandaPanel\Plugins\PluginMetadata;

public function id(): string
{
    return 'acme-catalog';
}

public function metadata(): PluginMetadata
{
    return new PluginMetadata(
        name: 'Acme Catalog',
        package: 'acme/panda-catalog',
        requiresPanel: '^1.2',
        url: 'https://github.com/acme/panda-catalog',
    );
}
```

```php
final readonly class PluginMetadata
{
    public function __construct(
        public string $name,
        public ?string $package = null,
        public ?string $requiresPanel = null,
        public ?string $url = null,
    ) {}

    public function version(): ?string;   // read from composer, null for a package it has never heard of
    public function toArray(): array;     // name, package, version, requiresPanel, url
}
```

The version is read from composer's own installed-packages data rather than declared by hand. A hand-written version string is a string somebody forgets to change, and a plugin reporting 1.2.0 while 1.4.1 is installed is worse than a plugin reporting nothing. A plugin with no `package` reports no version, which is correct for one that lives in the application rather than in a package of its own.

`id()` should be stable across versions: an application asking `hasPlugin('catalog')` is asking about the plugin, not about the release of it.

```bash
php artisan panel:plugins
php artisan panel:plugins --panel=admin
```

```text
 Panel  ID             Name           Package              Version  Requires
 admin  acme-catalog   Acme Catalog   acme/panda-catalog   1.4.1    ^1.2
```

That table is what a bug report should contain. A panel with four plugins has four sources of resources, pages, widgets and routes, and when one misbehaves the first two questions are always "which plugin" and "which version" — neither answerable from a stack trace naming this framework.

### The compatibility check

`requiresPanel` is a composer-style constraint against **this framework**, checked by `PandaPanel\Plugins\PluginCompatibility` at registration — the earliest moment the answer is knowable and the last moment before the plugin starts changing the panel.

```php
PluginCompatibility::assert(PanelPlugin $plugin, string $panelId, ?string $installed = null): void
```

It throws `PandaPanel\Exceptions\PanelRegistrationException` naming the plugin, the panel, the constraint, and what is installed. The failure it replaces is `Call to undefined method Panel::whatever()`, somewhere inside a request, naming the framework rather than the plugin that asked for it.

The check is **skipped** in three cases, all of which mean there is no question to answer:

- The plugin declared no constraint. Most do not.
- This framework is not installed as a composer package — a path repository, a git checkout, or its own test suite.
- The installed version is a branch alias like `dev-main`, or composer's `1.0.0+no-version-set` placeholder. A constraint cannot be evaluated against a branch.

## Shipping it as a package

Implement the contract directly. A package that extends an application's base class is a package coupled to it.

```php
<?php

declare(strict_types=1);

namespace Acme\Catalog;

use PandaPanel\Contracts\PanelPlugin;
use PandaPanel\Core\Panel;
use PandaPanel\Plugins\PluginMetadata;

final class CatalogPlugin implements PanelPlugin
{
    public static function make(): self
    {
        return new self;
    }

    public function id(): string
    {
        return 'acme-catalog';
    }

    public function register(Panel $panel): void
    {
        $panel
            ->navigationGroups(['Catalog'])
            ->discoverResources(__DIR__.'/Resources')
            ->discoverWidgets(__DIR__.'/Widgets');
    }

    public function boot(Panel $panel): void
    {
        //
    }

    public function metadata(): PluginMetadata
    {
        return new PluginMetadata(
            name: 'Acme Catalog',
            package: 'acme/panda-catalog',
            requiresPanel: '^0.1',
        );
    }

    /** @return array<string, string> */
    public function publishes(): array
    {
        return [
            __DIR__.'/../resources/js' => resource_path('js/pages/Panels/AcmeCatalog'),
        ];
    }
}
```

Implementing the contract means implementing all five methods, including the two the base class would have defaulted. That is the trade: no coupling, four more lines.

### The package's own service provider

```php
<?php

declare(strict_types=1);

namespace Acme\Catalog;

use Illuminate\Support\ServiceProvider;

final class CatalogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->mergeConfigFrom(__DIR__.'/../config/catalog.php', 'catalog');
    }
}
```

```json
{
    "name": "acme/panda-catalog",
    "require": {
        "php": "^8.2",
        "chocoalano/panel": "^0.1"
    },
    "autoload": {
        "psr-4": {
            "Acme\\Catalog\\": "src/"
        }
    },
    "extra": {
        "laravel": {
            "providers": [
                "Acme\\Catalog\\CatalogServiceProvider"
            ]
        }
    }
}
```

Composer's package discovery registers the service provider automatically. The plugin itself is still installed by hand, in a panel provider — an installed package quietly adding resources and navigation to somebody's admin panel is not a thing that should happen without a line of application code saying so.

### A suggested layout

```text
packages/catalog/
├── composer.json
├── resources/
│   └── js/
│       └── Widgets/
│           └── StockGauge.vue
└── src/
    ├── CatalogPlugin.php
    ├── CatalogServiceProvider.php
    ├── Resources/
    │   └── Products/
    │       ├── ProductResource.php
    │       ├── Forms/ProductForm.php
    │       └── Tables/ProductsTable.php
    └── Widgets/
        └── StockGauge.php
```

Inside an application, `app/Panels/Plugins/` is a reasonable home for a plugin that is not going anywhere.

## The test

```php
<?php

declare(strict_types=1);

use App\Panels\Admin\Resources\Products\ProductResource;
use App\Panels\Plugins\CatalogPlugin;
use Illuminate\Support\Facades\File;
use PandaPanel\Core\Panel;
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Plugins\PluginCompatibility;
use PandaPanel\Plugins\PluginMetadata;

it('configures the panel through the panel\'s own API', function (): void {
    $panel = Panel::make('plug')->path('plug')->plugins([CatalogPlugin::make()]);

    expect($panel->getResources())->toContain(ProductResource::class)
        ->and($panel->getNavigationGroups())->toContain('Catalog');
});

it('is configurable, so one plugin can be two shapes', function (): void {
    $bare = Panel::make('plug-bare')->path('plug-bare')->plugins([
        CatalogPlugin::make()->withWidgets(false)->group(null),
    ]);

    expect($bare->getNavigationGroups())->not->toContain('Catalog');
});

it('takes its id from its class name and can be looked up by it', function (): void {
    $panel = Panel::make('plug-ask')->path('plug-ask')->plugins([CatalogPlugin::make()]);

    expect(CatalogPlugin::make()->id())->toBe('catalog')
        ->and($panel->hasPlugin('catalog'))->toBeTrue()
        ->and($panel->plugin('catalog'))->toBeInstanceOf(CatalogPlugin::class);
});

it('refuses two plugins claiming one id', function (): void {
    expect(fn () => Panel::make('plug-dupe')->path('plug-dupe')->plugins([
        CatalogPlugin::make(),
        CatalogPlugin::make(),
    ]))->toThrow(PanelRegistrationException::class, 'claim the id');
});

it('runs register while the panel is being built, and boot later', function (): void {
    $panel = Panel::make('plug-phase')->path('plug-phase')->plugins([CatalogPlugin::make()]);

    // register() has run: the resource is there.
    expect($panel->getResources())->toContain(ProductResource::class)
        // boot() has not: work needing the container, the user, or a URL must
        // not happen while the panel is still being configured.
        ->and($panel->getCssHooks())->not->toHaveKey('page');

    $panel->boot();

    expect($panel->getCssHooks()['page'] ?? '')->toContain('catalog-page');
});

it('publishes a plugin\'s components into the application tree', function (): void {
    Panel::make('plug-publish')->path('plug-publish')->plugins([CatalogPlugin::make()]);

    $destination = resource_path('js/pages/Panels/Catalog/StockGauge.vue');

    File::delete($destination);

    $this->artisan('panel:publish', ['plugin' => 'catalog'])->assertSuccessful();

    expect(File::exists($destination))->toBeTrue();

    File::deleteDirectory(resource_path('js/pages/Panels/Catalog'));
});

it('never overwrites a published file without being told to', function (): void {
    $destination = resource_path('js/pages/Panels/Catalog/StockGauge.vue');

    File::ensureDirectoryExists(dirname($destination));
    File::put($destination, '<!-- edited by the application -->');

    $this->artisan('panel:publish', ['plugin' => 'catalog'])->assertSuccessful();

    expect(File::get($destination))->toBe('<!-- edited by the application -->');

    File::deleteDirectory(resource_path('js/pages/Panels/Catalog'));
});

it('refuses a plugin built against a version that is no longer installed', function (): void {
    $plugin = new class extends PandaPanel\Plugins\Plugin
    {
        public function register(Panel $panel): void {}

        public function metadata(): PluginMetadata
        {
            return new PluginMetadata(name: 'Demanding', requiresPanel: '^2.0');
        }
    };

    // The version is passed in: the check is skipped when this framework has
    // no real version to compare against, which is the case in a checkout.
    expect(fn () => PluginCompatibility::assert($plugin, 'admin', '1.4.1'))
        ->toThrow(PanelRegistrationException::class);
});
```

`tests/Feature/Panel/PluginTest.php` is the framework's own version of these, against the `ReportingPlugin` and `RecordingPlugin` fixtures.

```bash
php artisan test --compact --filter=Plugin
php artisan panel:plugins
```

## Gotchas

- **`register()` runs on every request.** Not just panel requests — every request the application serves. Nothing in it may query, resolve a route, or read the current user.
- **`boot()` runs per request and must be idempotent.** Anything that appends will append again, which under Octane means twice in one worker's lifetime and then three times.
- **A plugin is registered by hand.** There is no discovery for plugins, deliberately: an installed package should not add resources to somebody's admin panel without a line of application code saying so.
- **Plugins register in array order.** A plugin can therefore read what an earlier one registered, though relying on that makes the install order load-bearing.
- **`in()` returns `null` outside a panel request.** `panel()` is `null` in a console command or a queued job unless a panel was set explicitly.
- **`publishes()` returns absolute paths.** `__DIR__` on the left, `resource_path()` on the right. A relative source is reported as not existing.
- **A published component still needs a build.** `panel:publish` copies the file; `import.meta.glob` only sees it after Vite runs again.
- **`requiresPanel` is silently skipped on a checkout.** It only bites against an installed release, which is the point — but it also means a local test of the constraint has to pass the version in.

## See also

- [Plugin Concepts](../plugins/concepts.md)
- [Creating a Plugin](../plugins/creating-plugins.md)
- [Plugin Contract](../plugins/contract.md)
- [Register and Boot](../plugins/lifecycle.md)
- [Plugin Metadata](../plugins/metadata.md), [Version Compatibility](../plugins/compatibility.md)
- [Plugin Assets](../plugins/assets.md), [Plugin CLI](../plugins/cli.md)
- [Testing Plugins](../plugins/testing.md)
- [Render Hooks](../panels/render-hooks.md)
- [Discovery](../concepts/discovery.md)
- [Product Resource](product-resource.md), [Custom Widget](custom-widget.md)
- [panel:plugins](../cli/panel-plugins.md), [Publish Tags](../cli/publish-tags.md)
