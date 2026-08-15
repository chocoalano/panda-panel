<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Plugins\Plugin;
use PandaPanel\Plugins\PluginCompatibility;
use PandaPanel\Plugins\PluginMetadata;
use Tests\Fixtures\Panel\Plugins\RecordingPlugin;
use Tests\Fixtures\Panel\Plugins\ReportingPlugin;
use Tests\Fixtures\Panel\Relations\ProjectRelationResource;

/*
 * Registration
 */

it('configures the panel through the panel\'s own API', function (): void {
    $panel = Panel::make('plug')->path('plug')->plugins([
        ReportingPlugin::make(),
    ]);

    // No second configuration surface: a plugin can do exactly what a panel
    // provider can, which is what stops it doing something a panel cannot.
    expect($panel->getResources())->toContain(ProjectRelationResource::class)
        ->and($panel->getNavigationGroups())->toContain('Reporting');
});

it('is configurable, so one plugin can be two shapes', function (): void {
    $bare = Panel::make('plug-bare')->path('plug-bare')->plugins([
        ReportingPlugin::make()->withResource(false)->group(null),
    ]);

    expect($bare->getResources())->not->toContain(ProjectRelationResource::class)
        ->and($bare->getNavigationGroups())->not->toContain('Reporting');
});

it('takes its id from its class name', function (): void {
    expect(ReportingPlugin::make()->id())->toBe('reporting');
});

it('lets a panel be asked whether it has one', function (): void {
    $panel = Panel::make('plug-ask')->path('plug-ask')->plugins([
        ReportingPlugin::make(),
    ]);

    expect($panel->hasPlugin('reporting'))->toBeTrue()
        ->and($panel->hasPlugin('billing'))->toBeFalse()
        // And how it was configured, so a resource can read its own plugin's
        // settings without the panel repeating them.
        ->and($panel->plugin('reporting'))->toBeInstanceOf(ReportingPlugin::class);
});

it('refuses two plugins claiming one id', function (): void {
    // A panel is asked `hasPlugin('reporting')` to decide what to show, so
    // two answers to one question is caught at registration rather than
    // discovered as a resource that appears twice.
    expect(fn () => Panel::make('plug-dupe')->path('plug-dupe')->plugins([
        ReportingPlugin::make(),
        ReportingPlugin::make(),
    ]))->toThrow(PanelRegistrationException::class, 'claim the id');
});

/*
 * The two phases
 */

it('runs register while the panel is being built, and boot later', function (): void {
    $panel = Panel::make('plug-phase')->path('plug-phase')->plugins([
        ReportingPlugin::make(),
    ]);

    // `register()` has run: the resource is there.
    expect($panel->getResources())->toContain(ProjectRelationResource::class)
        // `boot()` has not: work that needs the container, the user, or a URL
        // must not happen while the panel is still being configured.
        ->and($panel->getCssHooks())->not->toHaveKey('page');

    $panel->boot();

    expect($panel->getCssHooks()['page'] ?? '')->toContain('reporting-page');
});

it('boots plugins before the panel\'s own callback', function (): void {
    RecordingPlugin::reset();

    $panel = Panel::make('plug-order')
        ->path('plug-order')
        ->plugins([new RecordingPlugin])
        ->bootUsing(static function (): void {
            RecordingPlugin::$calls[] = 'panel';
        });

    // Registering happened as the panel was built, before anything booted.
    expect(RecordingPlugin::$calls)->toBe(['register']);

    $panel->boot();

    // The application's own callback is the last word, so it can undo what a
    // plugin did.
    expect(RecordingPlugin::$calls)->toBe(['register', 'boot', 'panel']);
});

/*
 * Publishing
 */

it('publishes a plugin\'s components into the application tree', function (): void {
    $manager = app(PanelManager::class);

    if (! $manager->has('plug-publish')) {
        $manager->register(
            Panel::make('plug-publish')->path('plug-publish')->plugins([
                ReportingPlugin::make(),
            ]),
        );
    }

    $destination = resource_path('js/pages/Panels/Reporting/Gauge.vue');

    File::delete($destination);

    $this->artisan('panel:publish', ['plugin' => 'reporting'])->assertSuccessful();

    // Every component registry is an `import.meta.glob` over
    // `pages/Panels/**`, a build-time allowlist — so a plugin's component has
    // to live there to be reachable at all.
    expect(File::exists($destination))->toBeTrue();

    File::deleteDirectory(resource_path('js/pages/Panels/Reporting'));
});

it('never overwrites a published file without being told to', function (): void {
    $manager = app(PanelManager::class);

    if (! $manager->has('plug-publish-2')) {
        $manager->register(
            Panel::make('plug-publish-2')->path('plug-publish-2')->plugins([
                ReportingPlugin::make(),
            ]),
        );
    }

    $destination = resource_path('js/pages/Panels/Reporting/Gauge.vue');

    File::ensureDirectoryExists(dirname($destination));
    File::put($destination, '<!-- edited by the application -->');

    $this->artisan('panel:publish', ['plugin' => 'reporting'])->assertSuccessful();

    // A published file the application changed is work, and overwriting it
    // silently is losing it.
    expect(File::get($destination))->toBe('<!-- edited by the application -->');

    File::deleteDirectory(resource_path('js/pages/Panels/Reporting'));
});

/*
 * Metadata and compatibility
 *
 * A panel with four plugins has four sources of resources, pages, widgets and
 * routes. When one misbehaves the questions are "which plugin" and "which
 * version", and a stack trace naming this framework answers neither.
 */

it('names a plugin that never said what it was called', function (): void {
    $plugin = new class extends Plugin
    {
        public function register(Panel $panel): void {}
    };

    // A title-cased id is a reasonable name, and no package means no version
    // — which is right for a plugin living in the application rather than in
    // a package of its own.
    expect($plugin->metadata()->name)->toBeString()
        ->and($plugin->metadata()->package)->toBeNull()
        ->and($plugin->metadata()->version())->toBeNull()
        ->and($plugin->metadata()->requiresPanel)->toBeNull();
});

it('reads a version from composer rather than from what the author typed', function (): void {
    $metadata = new PluginMetadata(name: 'Pest', package: 'pestphp/pest');

    // A real installed package, so the answer is composer's. A hand-written
    // version string is one somebody forgets to change.
    expect($metadata->version())->not->toBeNull()
        ->and($metadata->toArray()['version'])->toBe($metadata->version());
});

it('reports a package composer has never heard of as unknown', function (): void {
    $metadata = new PluginMetadata(name: 'Ghost', package: 'nobody/nothing-like-this');

    // A wrong package name is a documentation bug, not a reason to refuse to
    // boot.
    expect($metadata->version())->toBeNull();
});

it('lets a plugin through when it declares no constraint', function (): void {
    $plugin = new class extends Plugin
    {
        public function register(Panel $panel): void {}
    };

    expect(fn () => Panel::make('unconstrained')->plugins([$plugin]))
        ->not->toThrow(PanelRegistrationException::class);
});

it('lets a plugin through when this framework has no version to check', function (): void {
    // Which is exactly the situation in this repository: composer reports
    // `1.0.0+no-version-set` for a root package that never declared one, and
    // evaluating a constraint against a placeholder would refuse a plugin
    // requiring ^2.0 on a machine running whatever main is.
    $plugin = demandingPlugin('^99.0');

    expect(fn () => Panel::make('demanding')->plugins([$plugin]))
        ->not->toThrow(PanelRegistrationException::class);
});

it('refuses a plugin built against a version that is no longer installed', function (): void {
    // The version is passed in, because the check is only skipped here for
    // the reason above — an installed release has a real one.
    expect(fn () => PluginCompatibility::assert(demandingPlugin('^2.0'), 'admin', '1.4.1'))
        ->toThrow(PanelRegistrationException::class);
});

it('names the plugin, the constraint and what is installed', function (): void {
    try {
        PluginCompatibility::assert(demandingPlugin('^2.0'), 'admin', '1.4.1');
    } catch (PanelRegistrationException $exception) {
        // The whole point: the alternative is `Call to undefined method
        // Panel::whatever()` inside a request, naming this framework rather
        // than the plugin that asked for it.
        expect($exception->getMessage())
            ->toContain('Demanding')
            ->toContain('admin')
            ->toContain('^2.0')
            ->toContain('1.4.1');

        return;
    }

    $this->fail('An incompatible plugin was allowed to register.');
});

it('accepts a plugin whose constraint the installed version satisfies', function (): void {
    expect(fn () => PluginCompatibility::assert(demandingPlugin('^1.2'), 'admin', '1.4.1'))
        ->not->toThrow(PanelRegistrationException::class);
});

/**
 * A plugin that wants a particular framework version.
 */
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

it('lists what is registered, with versions', function (): void {
    $this->artisan('panel:plugins')->assertSuccessful();
});

it('looks up its own version under the name composer knows it by', function (): void {
    $reflection = new ReflectionClass(PluginCompatibility::class);

    // A rename that missed this constant would not fail anything: the lookup
    // would simply never find a version, `installedVersion()` would answer
    // null, and every `requiresPanel` constraint would be silently skipped
    // for good. The check would still be there, and would never say no again.
    expect($reflection->getConstant('PACKAGE'))
        ->toBe(json_decode((string) file_get_contents(base_path('composer.json')), true)['name']);
});
