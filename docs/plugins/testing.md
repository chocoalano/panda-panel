# Testing Plugins

A plugin is a class with two side-effecting methods and a value object, so it
tests without HTTP: build a panel, install the plugin, assert on what the panel
now holds. Reach for this page when writing a plugin's own suite, or when an
application wants to prove that the plugin it installed did what it claims.

The framework's own plugin tests live in `tests/Feature/Panel/PluginTest.php`
with fixtures in `tests/Fixtures/Panel/Plugins/`, and every example here is the
shape those use.

## A minimal working example

```php
<?php

use App\Panels\Plugins\ReportingPlugin;
use App\Panels\Admin\Resources\Reports\ReportResource;
use PandaPanel\Core\Panel;

it('registers its resource', function (): void {
    $panel = Panel::make('test')->path('test')->plugins([
        ReportingPlugin::make(),
    ]);

    expect($panel->getResources())->toContain(ReportResource::class);
});
```

`Panel::make()` builds a panel in memory. No provider, no config entry, no
routes, no database — `plugins()` calls `register()` immediately, and the
assertions read the panel back.

Give each test panel its own id and path. `Panel::make()` does not register with
`PandaPanel\Core\PanelManager`, so ids cannot collide there, but the habit
matters for the tests further down that do register.

## Asserting what `register()` did

Every panel getter is fair game:

```php
use PandaPanel\Core\Panel;

$panel = Panel::make('test')->path('test')->plugins([ReportingPlugin::make()]);

expect($panel->getResources())->toContain(ReportResource::class)
    ->and($panel->getWidgets())->toContain(RevenueChart::class)
    ->and($panel->getPages())->toContain(ReportSettings::class)
    ->and($panel->getNavigationGroups())->toContain('Insights')
    ->and($panel->getResourceDiscoveryPaths())->toContain(__DIR__.'/Resources');
```

| Getter | Returns |
| --- | --- |
| `getResources()` | `list<class-string>` |
| `getResourceConfigurations()` | `list<ResourceConfiguration>` |
| `getPages()` | `list<class-string>` |
| `getWidgets()` | `list<class-string>` |
| `getNavigationGroups()` | `list<string>` |
| `getResourceDiscoveryPaths()`, `getPageDiscoveryPaths()`, `getWidgetDiscoveryPaths()` | `list<string>` |
| `getRenderHooks()` | `array<string, list<array{component: string, data: array, scopes: list<string>}>>` |
| `getCssHooks()` | `array<string, string>` |
| `getAssets()` | `list<string>` |
| `getPlugins()` | `array<string, PanelPlugin>` |

## Asserting the configuration matters

A configurable plugin's real contract is that two configurations produce two
panels. Assert the negative as well as the positive:

```php
it('is configurable, so one plugin can be two shapes', function (): void {
    $bare = Panel::make('bare')->path('bare')->plugins([
        ReportingPlugin::make()->withCharts(false)->group(null),
    ]);

    expect($bare->getWidgets())->not->toContain(RevenueChart::class)
        ->and($bare->getNavigationGroups())->not->toContain('Insights');
});
```

## Asserting the id and the lookup

```php
it('takes its id from its class name', function (): void {
    expect(ReportingPlugin::make()->id())->toBe('reporting');
});

it('lets a panel be asked whether it has one', function (): void {
    $panel = Panel::make('ask')->path('ask')->plugins([ReportingPlugin::make()]);

    expect($panel->hasPlugin('reporting'))->toBeTrue()
        ->and($panel->hasPlugin('billing'))->toBeFalse()
        ->and($panel->plugin('reporting'))->toBeInstanceOf(ReportingPlugin::class)
        ->and(ReportingPlugin::in($panel))->toBeInstanceOf(ReportingPlugin::class)
        ->and(ReportingPlugin::in(null))->toBeNull();
});
```

The id is part of the public contract — applications branch on
`hasPlugin('reporting')` — so pinning it in a test is what stops a rename from
being a silent breaking change.

## Asserting the duplicate-id guard

```php
use PandaPanel\Exceptions\PanelRegistrationException;

it('refuses two plugins claiming one id', function (): void {
    expect(fn () => Panel::make('dupe')->path('dupe')->plugins([
        ReportingPlugin::make(),
        ReportingPlugin::make(),
    ]))->toThrow(PanelRegistrationException::class, 'claim the id');
});
```

## Testing the two phases

`register()` has run by the time `plugins()` returns. `boot()` has not — it
needs an explicit call, which is exactly what makes the split testable:

```php
it('runs register while the panel is being built, and boot later', function (): void {
    $panel = Panel::make('phase')->path('phase')->plugins([ReportingPlugin::make()]);

    expect($panel->getResources())->toContain(ReportResource::class)
        ->and($panel->getCssHooks())->not->toHaveKey('page');

    $panel->boot();

    expect($panel->getCssHooks()['page'] ?? '')->toContain('reporting-page');
});
```

`Panel::boot()` takes no arguments and returns `void`. In a request it is called
by the `ResolvePanel` middleware; in a test, call it yourself.

### Ordering

Plugins boot before the panel's own `bootUsing()` callbacks. A recording fixture
proves it without reaching into a closure:

```php
<?php

namespace Tests\Fixtures\Plugins;

use PandaPanel\Core\Panel;
use PandaPanel\Plugins\Plugin;

final class RecordingPlugin extends Plugin
{
    /** @var list<string> */
    public static array $calls = [];

    public function register(Panel $panel): void
    {
        self::$calls[] = 'register';
    }

    public function boot(Panel $panel): void
    {
        self::$calls[] = 'boot';
    }

    public static function reset(): void
    {
        self::$calls = [];
    }
}
```

```php
it('boots plugins before the panel\'s own callback', function (): void {
    RecordingPlugin::reset();

    $panel = Panel::make('order')
        ->path('order')
        ->plugins([new RecordingPlugin])
        ->bootUsing(static function (): void {
            RecordingPlugin::$calls[] = 'panel';
        });

    expect(RecordingPlugin::$calls)->toBe(['register']);

    $panel->boot();

    expect(RecordingPlugin::$calls)->toBe(['register', 'boot', 'panel']);
});
```

Static state is what makes the fixture work across the two calls, and
`reset()` at the top of the test is what stops it leaking into the next one.

### Asserting `boot()` is idempotent

`boot()` runs per request, and several panel methods append rather than replace.
A plugin that guards itself should say so in a test:

```php
it('injects its hook once, however many times it boots', function (): void {
    $panel = Panel::make('twice')->path('twice')->plugins([ReportingPlugin::make()]);

    $panel->boot();
    $panel->boot();

    expect($panel->getRenderHooks()['page.start'] ?? [])->toHaveCount(1);
});
```

Render hook keys are the `RenderHook` enum's backing values — `page.start`,
`sidebar.end`, and so on.

## Testing metadata

```php
use PandaPanel\Plugins\PluginMetadata;

it('names itself and its package', function (): void {
    $metadata = ReportingPlugin::make()->metadata();

    expect($metadata->name)->toBe('Acme Reporting')
        ->and($metadata->package)->toBe('acme/panda-reporting')
        ->and($metadata->requiresPanel)->toBe('^1.2');
});

it('reads a version from composer rather than from what the author typed', function (): void {
    $metadata = new PluginMetadata(name: 'Pest', package: 'pestphp/pest');

    expect($metadata->version())->not->toBeNull()
        ->and($metadata->toArray()['version'])->toBe($metadata->version());
});

it('reports a package composer has never heard of as unknown', function (): void {
    expect((new PluginMetadata(name: 'Ghost', package: 'nobody/nothing-like-this'))->version())
        ->toBeNull();
});
```

Do not assert an exact version string for your own package. In the package's own
suite it is not installed as a package at all and `version()` answers `null`;
assert the shape, not the number.

## Testing version compatibility

The constraint check is skipped when the framework has no version to compare
against, which is the situation in every package checkout. So test it by passing
the version in — the third parameter exists for exactly this:

```php
use PandaPanel\Core\Panel;
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Plugins\Plugin;
use PandaPanel\Plugins\PluginCompatibility;
use PandaPanel\Plugins\PluginMetadata;

function demandingPlugin(string $constraint): Plugin
{
    return new class($constraint) extends Plugin
    {
        public function __construct(private readonly string $constraint) {}

        public function register(Panel $panel): void {}

        public function metadata(): PluginMetadata
        {
            return new PluginMetadata(name: 'Demanding', requiresPanel: $this->constraint);
        }
    };
}

it('refuses a plugin built against a version that is no longer installed', function (): void {
    expect(fn () => PluginCompatibility::assert(demandingPlugin('^2.0'), 'admin', '1.4.1'))
        ->toThrow(PanelRegistrationException::class);
});

it('accepts a plugin whose constraint the installed version satisfies', function (): void {
    expect(fn () => PluginCompatibility::assert(demandingPlugin('^1.2'), 'admin', '1.4.1'))
        ->not->toThrow(PanelRegistrationException::class);
});

it('names the plugin, the constraint and what is installed', function (): void {
    try {
        PluginCompatibility::assert(demandingPlugin('^2.0'), 'admin', '1.4.1');
    } catch (PanelRegistrationException $exception) {
        expect($exception->getMessage())
            ->toContain('Demanding')
            ->toContain('admin')
            ->toContain('^2.0')
            ->toContain('1.4.1');

        return;
    }

    $this->fail('An incompatible plugin was allowed to register.');
});
```

The skip cases are worth a test too, because they are the ones that would
silently disable the check:

```php
it('lets a plugin through when it declares no constraint', function (): void {
    $plugin = new class extends Plugin
    {
        public function register(Panel $panel): void {}
    };

    expect(fn () => Panel::make('unconstrained')->plugins([$plugin]))
        ->not->toThrow(PanelRegistrationException::class);
});
```

An anonymous class extending `Plugin` gets its id from `class_basename()`, which
for an anonymous class is a long generated string. That is fine for a throwaway
fixture and wrong for anything you assert an id on.

## Testing publishing

`panel:publish` walks the panels registered with `PandaPanel\Core\PanelManager`,
so a test panel has to be registered rather than merely built:

```php
use Illuminate\Support\Facades\File;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;

it('publishes its components into the application tree', function (): void {
    $manager = app(PanelManager::class);

    if (! $manager->has('publish-test')) {
        $manager->register(
            Panel::make('publish-test')->path('publish-test')->plugins([
                ReportingPlugin::make(),
            ]),
        );
    }

    $destination = resource_path('js/pages/Panels/Reporting/Widgets/RevenueChart.vue');

    File::delete($destination);

    $this->artisan('panel:publish', ['plugin' => 'reporting'])->assertSuccessful();

    expect(File::exists($destination))->toBeTrue();

    File::deleteDirectory(resource_path('js/pages/Panels/Reporting'));
});
```

Three things that test is doing on purpose:

- **`has()` before `register()`.** Registering the same id twice throws
  `PanelRegistrationException`, and the manager is a container singleton.
- **`File::delete()` first.** The command never overwrites without `--force`, so
  a leftover file from an earlier run would make the assertion pass for the
  wrong reason.
- **`deleteDirectory()` after.** The command writes into the real
  `resources/js`, which is the repository. Tests that publish must clean up.

The skip behaviour deserves its own test, because it is the one protecting
somebody's work:

```php
it('never overwrites a published file without being told to', function (): void {
    $destination = resource_path('js/pages/Panels/Reporting/Widgets/RevenueChart.vue');

    File::ensureDirectoryExists(dirname($destination));
    File::put($destination, '<!-- edited by the application -->');

    $this->artisan('panel:publish', ['plugin' => 'reporting'])->assertSuccessful();

    expect(File::get($destination))->toBe('<!-- edited by the application -->');

    File::deleteDirectory(resource_path('js/pages/Panels/Reporting'));
});
```

## Testing the report command

```php
it('lists what is registered, with versions', function (): void {
    $this->artisan('panel:plugins')->assertSuccessful();
});
```

`panel:plugins` always exits `0`, so the useful assertion is on output rather
than on the status:

```php
$this->artisan('panel:plugins', ['--panel' => 'admin'])
    ->expectsOutputToContain('acme-reporting')
    ->assertSuccessful();
```

## Testing a plugin end to end

The tests above prove the plugin configures a panel. Proving the panel then
serves the plugin's pages needs a registered panel provider and HTTP.

In an **application's** suite, the panel provider is already in
`config/panda-panel.php`, so there is nothing to arrange:

```php
it('serves the plugin\'s resource', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/reports')
        ->assertOk();
});
```

In a **plugin package's** suite, use Orchestra Testbench, ship a panel provider
as a fixture, and point the config at it:

```php
<?php

namespace Acme\Reporting\Tests;

use Acme\Reporting\Tests\Fixtures\TestPanelProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use PandaPanel\PandaPanelServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            \Inertia\ServiceProvider::class,
            PandaPanelServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app->make('config')->set('panda-panel.panels', [
            TestPanelProvider::class,
        ]);
    }
}
```

```php
<?php

namespace Acme\Reporting\Tests\Fixtures;

use Acme\Reporting\ReportingPlugin;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelProvider;

final class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->path('test')
            ->plugins([ReportingPlugin::make()]);
    }
}
```

`panda-panel.panels` is a list of `PanelProvider` class names, read during the
service provider's boot. Setting it in `defineEnvironment()` is early enough;
setting it inside a test body is not, because the panels are already built.

`panda-panel.register_routes` can be set to `false` for a harness that boots
panels without HTTP.

## Notes

- A panel built with `Panel::make()` and never registered has no routes, no
  registries and no navigation. It is the right tool for testing `register()`
  and `boot()`, and the wrong one for testing that a resource is reachable.
- `PanelManager` is a container singleton and panels registered in one test do
  not survive into the next, because Testbench and Laravel's test case rebuild
  the application. Static state on a plugin class does survive; reset it.
- Nothing in the framework resolves a plugin from the container, so there is
  nothing to mock. Construct the plugin, install it, assert.
- `Panel::plugins()` throws for a duplicate id and for a failed `requiresPanel`
  check, and both throw `PanelRegistrationException`. Assert on the message
  fragment, not just the class, to tell them apart.

## See also

- [Plugin Concepts](concepts.md)
- [Creating a Plugin](creating-plugins.md)
- [Plugin Contract](contract.md)
- [Register and Boot](lifecycle.md)
- [Plugin Metadata](metadata.md)
- [Version Compatibility](compatibility.md)
- [Plugin Assets](assets.md)
- [Test Setup](../testing/setup.md)
- [Testing Helpers](../testing/helpers.md)
- [Testing Authorization](../testing/authorization.md)
- [Local Development](../contributing/local-development.md)
