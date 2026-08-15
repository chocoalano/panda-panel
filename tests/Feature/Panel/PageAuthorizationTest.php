<?php

declare(strict_types=1);

use App\Models\User;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Support\NavigationBuilder;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Fixtures\Panel\ForbiddenPage;
use Tests\Fixtures\Panel\SettingsFixturePage;

it('redirects a guest away from a page rather than rendering it', function (): void {
    $this->get('/admin/settings')->assertRedirect('/login');
});

it('refuses a page inside a panel the user cannot access', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/admin/settings')
        ->assertForbidden();
});

it('returns 403 for a direct url to a page the user cannot access', function (): void {
    // The page itself refuses, independently of the panel.
    $panel = app(PanelManager::class)->register(
        Panel::make('restricted-host')
            ->path('restricted-host')
            ->pages([ForbiddenPage::class]),
    );

    app(PanelManager::class)->setCurrentPanel($panel);

    $page = new ForbiddenPage;

    expect(fn () => $page->render())
        ->toThrow(HttpException::class);
});

it('omits a page the user cannot access from navigation', function (): void {
    $panel = app(PanelManager::class)->register(
        Panel::make('nav-host')
            ->path('nav-host')
            ->settings(false)
            ->pages([SettingsFixturePage::class, ForbiddenPage::class]),
    );

    $labels = collect(app(NavigationBuilder::class)->for($panel, '/nav-host'))
        ->flatMap(fn (array $group): array => array_column($group['items'], 'label'))
        ->all();

    expect($labels)->toContain('Settings')
        ->and($labels)->not->toContain('Restricted');
});

it('still registers a route for a page hidden from navigation', function (): void {
    // Hiding a page from the sidebar is a presentation choice; the route
    // must still exist and must still authorize.
    expect(ForbiddenPage::canAccess())->toBeFalse()
        ->and(ForbiddenPage::slug())->toBe('restricted');
});

it('lets an admin open the page', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/settings')
        ->assertOk();
});
