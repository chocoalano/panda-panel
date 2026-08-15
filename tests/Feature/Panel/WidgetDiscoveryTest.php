<?php

declare(strict_types=1);

use App\Models\User;
use App\Panels\Admin\Widgets\RecentUsers;
use App\Panels\Admin\Widgets\SystemInfo;
use App\Panels\Admin\Widgets\UserGrowth;
use App\Panels\Admin\Widgets\UserStats;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Discovery\PanelDiscoverer;
use PandaPanel\Widgets\StatsWidget;
use PandaPanel\Widgets\Widget;

it('discovers every widget without them being registered by hand', function (): void {
    $panel = app(PanelManager::class)->get('admin');

    expect($panel->getWidgets())->toBe([])
        ->and(app(PanelManager::class)->widgets($panel)->all())->toBe([
            RecentUsers::class,
            SystemInfo::class,
            UserGrowth::class,
            UserStats::class,
        ]);
});

it('renders the discovered widgets on the dashboard', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('widgets', 4));
});

it('ignores the abstract widget base classes', function (): void {
    $panel = Panel::make('abstract-widgets')
        ->path('abstract-widgets')
        ->discoverWidgets(app_path('Panel/Widgets'));

    $found = app(PanelDiscoverer::class)->widgets($panel);

    expect($found)->not->toContain(Widget::class)
        ->and($found)->not->toContain(StatsWidget::class)
        ->and($found)->toBe([]);
});

it('ignores the widget value objects and enums', function (): void {
    // Support/ and Enums/ live under the same tree; neither implements the
    // widget contract, so neither may end up in a manifest.
    $panel = Panel::make('support-scan')
        ->path('support-scan')
        ->discoverWidgets(app_path('Panel/Widgets/Support'));

    expect(app(PanelDiscoverer::class)->widgets($panel))->toBe([]);
});
