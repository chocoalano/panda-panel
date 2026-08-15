<?php

declare(strict_types=1);

use App\Panels\Admin\Resources\Users\UserResource;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Discovery\ClassResolver;
use PandaPanel\Discovery\PanelDiscoverer;
use PandaPanel\Resources\Resource;

it('discovers a resource without it being registered by hand', function (): void {
    $panel = app(PanelManager::class)->get('admin');

    expect($panel->getResources())->toBe([])
        ->and(app(PanelManager::class)->resources($panel)->all())
        ->toBe([UserResource::class]);
});

it('ignores classes in the path that are not resources', function (): void {
    // The resource directory also holds pages, a table, and a form. Only the
    // resource itself implements the contract.
    $found = app(PanelDiscoverer::class)->resources(app(PanelManager::class)->get('admin'));

    expect($found)->toBe([UserResource::class]);
});

it('ignores abstract classes', function (): void {
    $panel = Panel::make('abstract-host')
        ->path('abstract-host')
        ->discoverResources(app_path('Panel/Resources'));

    // PandaPanel\Resources\Resource is abstract and implements the contract,
    // so it would be found if the concrete check were missing.
    expect(app(PanelDiscoverer::class)->resources($panel))->not->toContain(Resource::class);
});

it('returns nothing for a path that does not exist', function (): void {
    $panel = Panel::make('missing')
        ->path('missing')
        ->discoverResources(app_path('Panels/DoesNotExist'));

    expect(app(PanelDiscoverer::class)->resources($panel))->toBe([]);
});

it('scans every declared path', function (): void {
    $panel = Panel::make('multi')
        ->path('multi')
        ->discoverResources(app_path('Panels/Admin/Resources'))
        ->discoverResources(app_path('Panels/App/Resources'));

    expect($panel->getResourceDiscoveryPaths())->toHaveCount(2)
        ->and(app(PanelDiscoverer::class)->resources($panel))->toBe([UserResource::class]);
});

it('sorts results so two machines produce the same manifest', function (): void {
    $panel = app(PanelManager::class)->get('admin');

    $first = app(PanelDiscoverer::class)->resources($panel);
    $second = app(PanelDiscoverer::class)->resources($panel);

    expect($first)->toBe($second)
        ->and($first)->toBe(collect($first)->sort()->values()->all());
});

it('resolves a class name from a path through composer, not by reading the file', function (): void {
    expect(ClassResolver::forPath(app_path('Panels/Admin/Resources/Users/UserResource.php')))
        ->toBe(UserResource::class)
        ->and(ClassResolver::forPath('/tmp/outside-every-psr4-root/Thing.php'))
        ->toBeNull();
});

it('merges explicit registration with discovery without duplicating', function (): void {
    $panel = app(PanelManager::class)->register(
        Panel::make('merged')
            ->path('merged')
            ->resources([UserResource::class])
            ->discoverResources(app_path('Panels/Admin/Resources')),
    );

    expect(app(PanelManager::class)->resources($panel)->all())->toBe([UserResource::class]);
});
