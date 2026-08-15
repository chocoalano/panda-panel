<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

it('registers resource routes under the panel that owns them', function (): void {
    expect(Route::has('panel.admin.resources.users.index'))->toBeTrue()
        ->and(route('panel.admin.resources.users.index', absolute: false))->toBe('/admin/users');
});

it('does not register a resource in a panel that never declared it', function (): void {
    expect(Route::has('panel.app.resources.users.index'))->toBeFalse();

    $this->actingAs(User::factory()->create())
        ->get('/app/users')
        ->assertNotFound();
});

it('renders the generic resource index component', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/users')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('panel/resources/Index')
            ->where('resource.slug', 'users')
            ->where('resource.pluralLabel', 'Users')
            ->where('resource.indexUrl', '/admin/users')
            ->where('page.heading', 'Users')
        );
});

it('ships breadcrumbs from the dashboard to the resource', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/users')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('page.breadcrumbs.0.label', 'Dashboard')
            ->where('page.breadcrumbs.0.href', '/admin')
            ->where('page.breadcrumbs.1.label', 'Users')
            ->where('page.breadcrumbs.1.current', true)
            ->where('page.breadcrumbs.1.href', null)
        );
});

it('shows the resource in the navigation of its own panel only', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin')
        ->assertInertia(function (AssertableInertia $page): void {
            $labels = collect($page->toArray()['props']['navigation'])
                ->flatMap(fn (array $group): array => array_column($group['items'], 'label'))
                ->all();

            expect($labels)->toContain('Users');
        });

    $this->actingAs(User::factory()->create())
        ->get('/app')
        ->assertInertia(function (AssertableInertia $page): void {
            $labels = collect($page->toArray()['props']['navigation'])
                ->flatMap(fn (array $group): array => array_column($group['items'], 'label'))
                ->all();

            expect($labels)->not->toContain('Users');
        });
});

it('keeps panel routes cacheable with resources registered', function (): void {
    $this->artisan('route:cache')->assertSuccessful();
    $this->artisan('route:clear')->assertSuccessful();
});

it('serializes a table definition free of closures and internals', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/users')
        ->assertInertia(function (AssertableInertia $page): void {
            $table = $page->toArray()['props']['table'];

            expect(json_encode($table))->toBeString()
                ->and(json_encode($table))->not->toContain('App\\')
                ->and(json_encode($table))->not->toContain('select ')
                ->and($table['columns'])->not->toBeEmpty();

            foreach ($table['columns'] as $column) {
                expect($column)->toHaveKeys([
                    'name', 'label', 'type', 'sortable',
                    'searchable', 'visible', 'toggleable', 'alignment',
                ]);
            }
        });
});
