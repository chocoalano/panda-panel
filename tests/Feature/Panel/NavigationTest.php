<?php

declare(strict_types=1);

use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Support\NavigationBuilder;
use Tests\Fixtures\Panel\DashboardFixturePage;
use Tests\Fixtures\Panel\ForbiddenFixturePage;
use Tests\Fixtures\Panel\ForbiddenFixtureResource;
use Tests\Fixtures\Panel\HiddenFixtureResource;
use Tests\Fixtures\Panel\RolesFixtureResource;
use Tests\Fixtures\Panel\SettingsFixturePage;
use Tests\Fixtures\Panel\UsersFixtureResource;

/**
 * Builds a panel wired to the navigation fixtures, then returns its
 * serialized navigation for the given path.
 *
 * @return list<array<string, mixed>>
 */
function fixtureNavigation(string $currentPath = '/back-office'): array
{
    $manager = app(PanelManager::class);

    $panel = Panel::make('back-office')
        ->path('back-office')
        // A fixture panel registers no routes, so the built-in settings
        // pages are off: their navigation items would look up a route that
        // was never registered.
        ->settings(false)
        ->navigationGroups(['User Management', 'System'])
        ->resources([
            UsersFixtureResource::class,
            RolesFixtureResource::class,
            ForbiddenFixtureResource::class,
            HiddenFixtureResource::class,
        ])
        ->pages([
            DashboardFixturePage::class,
            SettingsFixturePage::class,
            ForbiddenFixturePage::class,
        ]);

    $manager->register($panel);

    return app(NavigationBuilder::class)->for($panel, $currentPath);
}

it('groups navigation in the order the panel declared', function (): void {
    $groups = fixtureNavigation();

    expect(array_column($groups, 'label'))->toBe([null, 'User Management', 'System']);
});

it('sorts items inside a group by sort then label', function (): void {
    $groups = fixtureNavigation();
    $userManagement = collect($groups)->firstWhere('label', 'User Management');

    expect(array_column($userManagement['items'], 'label'))->toBe(['Users', 'Roles']);
});

it('omits resources the user is not allowed to view', function (): void {
    $labels = collect(fixtureNavigation())
        ->flatMap(fn (array $group): array => array_column($group['items'], 'label'))
        ->all();

    expect($labels)->not->toContain('Secrets');
});

it('omits resources that opt out of navigation but keeps them registered', function (): void {
    $labels = collect(fixtureNavigation())
        ->flatMap(fn (array $group): array => array_column($group['items'], 'label'))
        ->all();

    expect($labels)->not->toContain('Audit Logs')
        ->and(app(PanelManager::class)->resources('back-office')->has('audit-logs'))->toBeTrue();
});

it('drops a group left empty by authorization', function (): void {
    expect(array_column(fixtureNavigation(), 'label'))->not->toContain('Finance');
});

it('resolves closure badges to scalars without serializing the closure', function (): void {
    $groups = fixtureNavigation();
    $users = collect($groups)->firstWhere('label', 'User Management')['items'][0];

    expect($users['badge'])->toBe(7)
        ->and(json_encode($groups))->toBeString();
});

it('marks the exact match active', function (): void {
    $groups = fixtureNavigation('/back-office/users');

    $active = collect($groups)
        ->flatMap(fn (array $group): array => $group['items'])
        ->filter(fn (array $item): bool => $item['active'] === true)
        ->pluck('label')
        ->all();

    expect($active)->toBe(['Users']);
});

it('keeps the resource active on a nested record path', function (): void {
    $groups = fixtureNavigation('/back-office/users/3/edit');

    $active = collect($groups)
        ->flatMap(fn (array $group): array => $group['items'])
        ->filter(fn (array $item): bool => $item['active'] === true)
        ->pluck('label')
        ->all();

    expect($active)->toBe(['Users']);
});

it('prefers an exact match over a shorter prefix match', function (): void {
    $groups = fixtureNavigation('/back-office');

    $active = collect($groups)
        ->flatMap(fn (array $group): array => $group['items'])
        ->filter(fn (array $item): bool => $item['active'] === true)
        ->pluck('label')
        ->all();

    expect($active)->toBe(['Overview']);
});

it('marks nothing active on an unrelated path', function (): void {
    $groups = fixtureNavigation('/somewhere-else');

    $active = collect($groups)
        ->flatMap(fn (array $group): array => $group['items'])
        ->filter(fn (array $item): bool => $item['active'] === true)
        ->all();

    expect($active)->toBe([]);
});

it('serializes every navigation field the frontend types expect', function (): void {
    $item = collect(fixtureNavigation())
        ->firstWhere('label', 'User Management')['items'][0];

    expect($item)->toHaveKeys(['label', 'href', 'icon', 'badge', 'active', 'sort', 'fullPage', 'children'])
        ->and($item['href'])->toBe('/back-office/users')
        ->and($item['icon'])->toBe('users')
        ->and($item['children'])->toBe([]);
});

it('returns no navigation for a panel with nothing registered', function (): void {
    $panel = app(PanelManager::class)->register(Panel::make('empty')->path('empty')->settings(false));

    expect(app(NavigationBuilder::class)->for($panel, '/empty'))->toBe([]);
});
