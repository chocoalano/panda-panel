<?php

declare(strict_types=1);

use PandaPanel\Core\PageRegistry;
use PandaPanel\Core\ResourceRegistry;
use PandaPanel\Core\WidgetRegistry;
use PandaPanel\Exceptions\PanelRegistrationException;
use Tests\Fixtures\Panel\DuplicateUsersFixtureResource;
use Tests\Fixtures\Panel\RolesFixtureResource;
use Tests\Fixtures\Panel\SettingsFixturePage;
use Tests\Fixtures\Panel\SettingsFixtureResource;
use Tests\Fixtures\Panel\StatsFixtureWidget;
use Tests\Fixtures\Panel\UsersFixtureResource;

it('looks resources up by slug', function (): void {
    $registry = new ResourceRegistry;
    $registry->register(UsersFixtureResource::class);

    expect($registry->has('users'))->toBeTrue()
        ->and($registry->bySlug('users'))->toBe(UsersFixtureResource::class)
        ->and($registry->bySlug('missing'))->toBeNull()
        ->and($registry->contains(UsersFixtureResource::class))->toBeTrue();
});

it('orders resources deterministically regardless of registration order', function (): void {
    $first = new ResourceRegistry;
    $first->register(UsersFixtureResource::class);
    $first->register(RolesFixtureResource::class);

    $second = new ResourceRegistry;
    $second->register(RolesFixtureResource::class);
    $second->register(UsersFixtureResource::class);

    expect($first->all())->toBe($second->all());
});

it('is idempotent when the same resource is registered twice', function (): void {
    $registry = new ResourceRegistry;
    $registry->register(UsersFixtureResource::class);
    $registry->register(UsersFixtureResource::class);

    expect($registry->count())->toBe(1);
});

it('rejects two resources claiming the same slug', function (): void {
    $registry = new ResourceRegistry;
    $registry->register(UsersFixtureResource::class);

    expect(fn () => $registry->register(DuplicateUsersFixtureResource::class))
        ->toThrow(PanelRegistrationException::class, 'is used by both');
});

it('rejects a page whose slug collides with a resource', function (): void {
    $resources = new ResourceRegistry;
    $resources->register(SettingsFixtureResource::class);

    $pages = new PageRegistry($resources);

    expect(fn () => $pages->register(SettingsFixturePage::class))
        ->toThrow(PanelRegistrationException::class, 'already registered by the resource');
});

it('registers a page when no resource claims its slug', function (): void {
    $pages = new PageRegistry(new ResourceRegistry);
    $pages->register(SettingsFixturePage::class);

    expect($pages->bySlug('settings'))->toBe(SettingsFixturePage::class)
        ->and($pages->count())->toBe(1);
});

it('looks widgets up by id', function (): void {
    $registry = new WidgetRegistry;
    $registry->register(StatsFixtureWidget::class);

    expect($registry->byId('stats-fixture'))->toBe(StatsFixtureWidget::class)
        ->and($registry->has('stats-fixture'))->toBeTrue()
        ->and($registry->count())->toBe(1);
});
