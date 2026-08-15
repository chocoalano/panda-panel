<?php

declare(strict_types=1);

use App\Panels\Admin\Pages\AccountsDashboard;
use App\Panels\Admin\Pages\Settings;
use App\Panels\Admin\Resources\Users\UserResource;
use Illuminate\Support\Facades\File;
use PandaPanel\Cache\PanelManifest;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Core\PanelRegistry;

afterEach(function (): void {
    File::delete(PanelManifest::path());
});

it('writes a manifest of class names for every panel', function (): void {
    $this->artisan('panel:cache')->assertSuccessful();

    expect(File::exists(PanelManifest::path()))->toBeTrue();

    $manifest = require PanelManifest::path();

    expect($manifest)->toHaveKeys(['admin', 'app'])
        ->and($manifest['admin']['resources'])->toBe([UserResource::class])
        ->and(array_values(array_diff($manifest['admin']['pages'], SETTINGS_PAGES)))->toBe([AccountsDashboard::class, Settings::class])
        ->and($manifest['admin']['widgets'])->toHaveCount(4)
        ->and($manifest['app']['resources'])->toBe([]);
});

it('reports what it cached', function (): void {
    $this->artisan('panel:cache')
        ->expectsOutputToContain('Panels cached')
        ->assertSuccessful();

    $manifest = require PanelManifest::path();

    expect($manifest['admin']['resources'])->toHaveCount(1)
        ->and($manifest['admin']['widgets'])->toHaveCount(4);
});

it('writes a manifest that is plain data', function (): void {
    $this->artisan('panel:cache');

    $contents = File::get(PanelManifest::path());

    expect($contents)->toStartWith('<?php')
        ->and($contents)->toContain('return array (')
        // Only class names may be cached: anything user- or request-specific
        // would be one person's answers served to everybody.
        ->and($contents)->not->toContain('Closure')
        ->and($contents)->not->toContain('function');
});

it('is deterministic across runs', function (): void {
    $this->artisan('panel:cache');
    $first = File::get(PanelManifest::path());

    $this->artisan('panel:cache');
    $second = File::get(PanelManifest::path());

    expect($first)->toBe($second);
});

it('removes the manifest', function (): void {
    $this->artisan('panel:cache');
    $this->artisan('panel:clear')->assertSuccessful();

    expect(File::exists(PanelManifest::path()))->toBeFalse();
});

it('treats clearing an absent manifest as success', function (): void {
    File::delete(PanelManifest::path());

    $this->artisan('panel:clear')->assertSuccessful();
    $this->artisan('panel:clear')->assertSuccessful();
});

it('reads from the manifest instead of scanning the filesystem', function (): void {
    $this->artisan('panel:cache');

    // Point the panel at a directory that does not exist. With a manifest in
    // place the classes must still resolve, which is only possible if
    // discovery did not run.
    $manifest = app(PanelManifest::class);
    $manifest->clear();
    $this->artisan('panel:cache');

    $panel = Panel::make('admin')
        ->path('admin')
        ->discoverResources(app_path('Panels/DoesNotExist'));

    expect($manifest->for($panel)['resources'])->toBe([UserResource::class]);
});

it('falls back to discovery when there is no manifest', function (): void {
    File::delete(PanelManifest::path());

    $manifest = app(PanelManifest::class);
    $manifest->clear();

    $panel = Panel::make('admin')
        ->path('admin')
        ->discoverResources(app_path('Panels/Admin/Resources'));

    expect($manifest->for($panel)['resources'])->toBe([UserResource::class]);
});

it('merges explicit registration into the cached manifest', function (): void {
    $registry = new PanelRegistry;
    $registry->register(
        Panel::make('explicit')
            ->path('explicit')
            ->resources([UserResource::class]),
    );

    $manifest = app(PanelManifest::class)->write($registry);

    expect($manifest['explicit']['resources'])->toBe([UserResource::class]);
});

it('is invoked by artisan optimize', function (): void {
    $this->artisan('optimize')->assertSuccessful();

    expect(File::exists(PanelManifest::path()))->toBeTrue();

    $this->artisan('optimize:clear')->assertSuccessful();

    expect(File::exists(PanelManifest::path()))->toBeFalse();
});

it('serves the same panels whether cached or not', function (): void {
    $uncached = app(PanelManager::class)->resources('admin')->all();

    $this->artisan('panel:cache');

    $manifest = app(PanelManifest::class);
    $cached = $manifest->for(app(PanelManager::class)->get('admin'))['resources'];

    expect($cached)->toBe($uncached);
});
