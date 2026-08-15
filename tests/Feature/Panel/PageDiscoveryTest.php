<?php

declare(strict_types=1);

use App\Panels\Admin\Pages\AccountsDashboard;
use App\Panels\Admin\Pages\Settings;
use App\Panels\App\Pages\Profile;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Discovery\PanelDiscoverer;
use PandaPanel\Pages\Page;

it('discovers a standalone page without it being registered by hand', function (): void {
    $panel = app(PanelManager::class)->get('admin');

    // Only the framework's built-in settings pages are registered by
    // hand; the panel's own Settings page arrives by discovery.
    expect($panel->getPages())->toBe(SETTINGS_PAGES)
        ->and(app(PanelManager::class)->pages($panel)->all())
        ->toContain(Settings::class);
});

it('registers a route for the discovered page', function (): void {
    expect(route('panel.admin.pages.settings', absolute: false))->toBe('/admin/settings');
});

it('ignores the abstract page base class', function (): void {
    $panel = Panel::make('abstract-pages')
        ->path('abstract-pages')
        ->discoverPages(app_path('Panel/Pages'));

    expect(app(PanelDiscoverer::class)->pages($panel))->not->toContain(Page::class);
});

it('discovers each panel only from its own paths', function (): void {
    $manager = app(PanelManager::class);

    $discovered = fn (array $pages): array => array_values(array_diff($pages, SETTINGS_PAGES));

    expect($discovered($manager->pages('admin')->all()))->toBe([AccountsDashboard::class, Settings::class])
        ->and($discovered($manager->pages('app')->all()))->toBe([Profile::class]);
});
