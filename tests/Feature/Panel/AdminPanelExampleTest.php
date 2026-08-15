<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create(['name' => 'Ada Lovelace']);
});

it('serves the admin dashboard with its own identity', function (): void {
    $this->actingAs($this->admin)
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('panel/Dashboard')
            ->where('panel.id', 'admin')
            ->where('panel.name', 'Administrator')
            ->where('panel.icon', 'shield')
            ->has('widgets', 4)
        );
});

it('builds the admin navigation from discovered classes', function (): void {
    $this->actingAs($this->admin)
        ->get('/admin')
        ->assertInertia(function (AssertableInertia $page): void {
            $groups = collect($page->toArray()['props']['navigation']);

            expect($groups->pluck('label')->all())
                ->toBe(['User Management', 'System', 'Account']);

            expect(array_column($groups->firstWhere('label', 'User Management')['items'], 'label'))
                ->toBe(['Accounts', 'Users']);

            expect(array_column($groups->firstWhere('label', 'System')['items'], 'label'))
                ->toBe(['Settings']);
        });
});

it('runs the whole user lifecycle for an admin', function (): void {
    $this->actingAs($this->admin);

    // List
    $this->get('/admin/users')->assertOk();

    // Create
    $this->post('/admin/users/create', [
        'name' => 'Grace Hopper',
        'email' => 'grace@example.com',
        'verified' => true,
        'is_admin' => false,
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ])->assertRedirect();

    $created = User::where('email', 'grace@example.com')->firstOrFail();

    // View
    $this->get("/admin/users/{$created->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('page.heading', 'Grace Hopper'));

    // Edit
    $this->put("/admin/users/{$created->id}/edit", [
        'name' => 'Rear Admiral Hopper',
        'email' => 'grace@example.com',
        'verified' => true,
        'is_admin' => true,
        'password' => '',
        'password_confirmation' => '',
    ])->assertRedirect();

    expect($created->fresh()->name)->toBe('Rear Admiral Hopper')
        ->and($created->fresh()->is_admin)->toBeTrue();

    // Delete
    $this->from('/admin/users')->post('/admin/actions/record', [
        'resource' => 'users',
        'action' => 'delete',
        'record' => $created->id,
    ])->assertRedirect('/admin/users');

    expect(User::find($created->id))->toBeNull();
});

it('marks the users item active while inside the resource', function (): void {
    $this->actingAs($this->admin)
        ->get('/admin/users/'.$this->admin->id.'/edit')
        ->assertInertia(function (AssertableInertia $page): void {
            $active = collect($page->toArray()['props']['navigation'])
                ->flatMap(fn (array $group): array => $group['items'])
                ->filter(fn (array $item): bool => $item['active'] === true)
                ->pluck('label')
                ->all();

            expect($active)->toBe(['Users']);
        });
});

it('serves the standalone settings page through the same shell', function (): void {
    $this->actingAs($this->admin)
        ->get('/admin/settings')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Panels/Admin/Pages/Settings')
            ->where('panel.id', 'admin')
            ->has('navigation')
            ->has('settings')
        );
});

it('reports dashboard counts that match the seeded data', function (): void {
    User::factory()->count(4)->create();
    User::factory()->unverified()->count(2)->create();

    $this->actingAs($this->admin)
        ->get('/admin')
        ->assertInertia(function (AssertableInertia $page): void {
            $stats = collect($page->toArray()['props']['widgets'])
                ->firstWhere('type', 'stats')['data']['stats'];

            expect(collect($stats)->firstWhere('label', 'Total users')['value'])->toBe(7)
                ->and(collect($stats)->firstWhere('label', 'Verified')['value'])->toBe(5);
        });
});
