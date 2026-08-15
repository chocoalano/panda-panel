<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->member = User::factory()->create();
});

it('offers an admin every panel they may enter', function (): void {
    $this->actingAs($this->admin)
        ->get('/admin')
        ->assertInertia(function (AssertableInertia $page): void {
            $panels = $page->toArray()['props']['panels'];

            expect(array_column($panels, 'id'))->toBe(['admin', 'app'])
                ->and(array_column($panels, 'url'))->toBe(['/admin', '/app'])
                ->and(array_column($panels, 'current'))->toBe([true, false]);
        });
});

it('marks the panel the user is currently in', function (): void {
    $this->actingAs($this->admin)
        ->get('/app')
        ->assertInertia(function (AssertableInertia $page): void {
            $current = collect($page->toArray()['props']['panels'])
                ->firstWhere('current', true);

            expect($current['id'])->toBe('app');
        });
});

it('never offers a panel the user would be refused', function (): void {
    // The switcher is filtered by the same predicate the route enforces, so
    // a one-panel user gets one entry and the control hides itself.
    $this->actingAs($this->member)
        ->get('/app')
        ->assertInertia(function (AssertableInertia $page): void {
            expect(array_column($page->toArray()['props']['panels'], 'id'))
                ->toBe(['app']);
        });

    $this->actingAs($this->member)->get('/admin')->assertForbidden();
});

it('sends no switcher entries outside a panel', function (): void {
    $this->actingAs($this->admin)
        ->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('panels', []));
});

it('serializes only what the switcher renders', function (): void {
    $this->actingAs($this->admin)
        ->get('/admin')
        ->assertInertia(function (AssertableInertia $page): void {
            $entry = $page->toArray()['props']['panels'][0];

            expect(array_keys($entry))
                ->toBe(['id', 'name', 'brandName', 'path', 'icon', 'url', 'current'])
                ->and($entry['icon'])->toBe('shield')
                ->and($entry['path'])->toBe('/admin')
                ->and($entry['name'])->toBe('Administrator');
        });
});
