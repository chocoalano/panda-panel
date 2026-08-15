<?php

declare(strict_types=1);

use App\Panels\Admin\AdminPanelProvider;
use App\Panels\App\AppPanelProvider;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Core\PanelRegistry;
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Pages\Dashboard;

it('registers the admin and app panels', function (): void {
    $manager = app(PanelManager::class);

    expect($manager->has('admin'))->toBeTrue()
        ->and($manager->has('app'))->toBeTrue()
        ->and($manager->get('admin')->getPath())->toBe('admin')
        ->and($manager->get('app')->getPath())->toBe('app');
});

it('derives the panel id from the provider class name', function (): void {
    expect((new AdminPanelProvider)->panelId())->toBe('admin')
        ->and((new AppPanelProvider)->panelId())->toBe('app');
});

it('rejects a duplicate panel id', function (): void {
    $registry = new PanelRegistry;
    $registry->register(Panel::make('admin')->path('one'));

    expect(fn () => $registry->register(Panel::make('admin')->path('two')))
        ->toThrow(PanelRegistrationException::class, 'already registered');
});

it('rejects a duplicate panel path on the same domain', function (): void {
    $registry = new PanelRegistry;
    $registry->register(Panel::make('first')->path('admin'));

    expect(fn () => $registry->register(Panel::make('second')->path('admin')))
        ->toThrow(PanelRegistrationException::class, 'already used by the panel [first]');
});

it('allows the same path on different domains', function (): void {
    $registry = new PanelRegistry;
    $registry->register(Panel::make('first')->path('admin')->domain('a.test'));
    $registry->register(Panel::make('second')->path('admin')->domain('b.test'));

    expect($registry->count())->toBe(2);
});

it('throws when a panel is used without an id', function (): void {
    expect(fn (): string => Panel::make()->getId())
        ->toThrow(PanelRegistrationException::class, 'no id');
});

it('throws for an unknown panel id', function (): void {
    expect(fn () => app(PanelManager::class)->get('nope'))
        ->toThrow(PanelRegistrationException::class, 'No panel is registered');
});

it('accumulates discovery paths instead of overwriting them', function (): void {
    $panel = Panel::make('modules')
        ->discoverResources('/one')
        ->discoverResources('/two', '/three')
        ->discoverResources('/one');

    expect($panel->getResourceDiscoveryPaths())->toBe(['/one', '/two', '/three']);
});

it('accumulates navigation groups instead of overwriting them', function (): void {
    $panel = Panel::make('modules')
        ->navigationGroups(['User Management'])
        ->navigationGroups(['System', 'User Management']);

    expect($panel->getNavigationGroups())->toBe(['User Management', 'System']);
});

it('falls back to derived values for optional configuration', function (): void {
    $panel = Panel::make('back-office');

    expect($panel->getName())->toBe('Back Office')
        ->and($panel->getPath())->toBe('back-office')
        ->and($panel->getBrandName())->toBe((string) config('app.name'))
        ->and($panel->getDomain())->toBeNull()
        ->and($panel->getDashboard())->toBe(Dashboard::class);
});

it('appends auth middleware after the base stack', function (): void {
    $panel = Panel::make('admin')->middleware(['web'])->auth();

    expect($panel->getMiddleware())->toBe(['web', 'auth', 'verified'])
        ->and($panel->getBaseMiddleware())->toBe(['web'])
        ->and($panel->getAuthMiddleware())->toBe(['auth', 'verified']);
});

it('can require authentication without email verification', function (): void {
    $panel = Panel::make('admin')->auth(verified: false);

    expect($panel->getMiddleware())->toBe(['web', 'auth']);
});

it('shares only serializable panel configuration', function (): void {
    $shared = app(PanelManager::class)->get('admin')->toSharedArray();

    expect($shared)->toHaveKeys([
        'id', 'name', 'path', 'brandName', 'brandLogo',
        'icon', 'favicon', 'darkMode', 'maxContentWidth',
        'unsavedChangesAlerts', 'prefetch', 'errorNotifications', 'sidebar',
    ])
        // Server-side configuration must not leak to the client.
        ->and($shared)->not->toHaveKeys([
            'databaseTransactions', 'strictAuthorization', 'bootCallbacks',
        ])
        ->and($shared['id'])->toBe('admin')
        ->and($shared['icon'])->toBe('shield')
        ->and(json_encode($shared))->toBeString();

    array_walk_recursive($shared, function (mixed $value): void {
        expect($value)->not->toBeInstanceOf(Closure::class);
    });
});
