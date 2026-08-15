<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Vite;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use Tests\Fixtures\Panel\RecordingVite;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();

    $this->vite = new RecordingVite;

    app()->instance(Vite::class, $this->vite);
});

it('declares no assets until a panel asks for some', function (): void {
    expect(app(PanelManager::class)->get('admin')->getAssets())->toBe([]);
});

it('accumulates entrypoints rather than replacing them', function (): void {
    $panel = Panel::make('themed')
        ->assets('resources/css/panels/themed.css')
        ->assets('resources/js/panels/themed.ts', 'resources/css/panels/themed.css');

    expect($panel->getAssets())->toBe([
        'resources/css/panels/themed.css',
        'resources/js/panels/themed.ts',
    ]);
});

it('loads the application entrypoints on a panel page as before', function (): void {
    $this->actingAs($this->admin)->get('/admin')->assertOk();

    expect($this->vite->entrypoints)->toBe([
        'resources/css/app.css',
        'resources/js/app.ts',
        'resources/js/pages/panel/Dashboard.vue',
    ]);
});

it('appends the panel entrypoints on that panel', function (): void {
    app(PanelManager::class)->get('admin')->assets('resources/css/panels/admin.css');

    $this->actingAs($this->admin)->get('/admin')->assertOk();

    // A path Vite would have to build; the list is what the panel owns, and
    // the entry must also be added to `vite.config.ts` for it to resolve.
    expect($this->vite->entrypoints)->toContain('resources/css/panels/admin.css')
        // Appended, so the application's own still load first.
        ->and($this->vite->entrypoints[0])->toBe('resources/css/app.css');
});

it('loads them on no other panel', function (): void {
    app(PanelManager::class)->get('admin')->assets('resources/css/panels/admin.css');

    $this->actingAs($this->admin)->get('/app')->assertOk();

    expect($this->vite->entrypoints)->not->toContain('resources/css/panels/admin.css');
});

it('loads them on no starter kit page either', function (): void {
    app(PanelManager::class)->get('admin')->assets('resources/css/panels/admin.css');

    $this->actingAs($this->admin)->get('/')->assertOk();

    expect($this->vite->entrypoints)->not->toContain('resources/css/panels/admin.css');
});

it('never sends the asset list to the frontend', function (): void {
    // Entrypoints are a server concern: the browser gets the tags, not the
    // list that produced them.
    expect(app(PanelManager::class)->get('admin')->toSharedArray())
        ->not->toHaveKey('assets');
});
