<?php

declare(strict_types=1);

use App\Models\User;

it('renders the users index with every applied feature', function (): void {
    $admin = User::factory()->admin()->create(['name' => 'Ada Lovelace']);
    User::factory()->count(3)->create();

    $this->actingAs($admin);

    $props = $this->get('/admin/users')->assertOk()->viewData('page')['props'];

    expect($props['table']['columns'])->toHaveCount(11)
        ->and($props['summaries'])->toHaveKey('passkeys_count')
        ->and($props['state']['columns']['visible'])->not->toBeEmpty();
});

it('computes the passkey summary over every user, not the page', function (): void {
    $admin = User::factory()->admin()->create();

    User::factory()->count(30)->create();

    $this->actingAs($admin);

    // `passkeys_count` is a generated alias, so summing it is the case that
    // used to fail with "Unknown column 'passkeys_count' in 'field list'".
    $props = $this->get('/admin/users?perPage=10')->assertOk()->viewData('page')['props'];

    $figures = collect($props['summaries']['passkeys_count'])->keyBy('name');

    expect($props['rows'])->toHaveCount(10)
        ->and((int) $figures['sum']['raw'])->toBe(0)
        // Counted over all 31 accounts, not the ten on screen.
        ->and((int) $figures['count']['raw'])->toBe(31);
});

it('renders grouped, filtered, searched and sorted at once', function (): void {
    $admin = User::factory()->admin()->create();
    User::factory()->count(3)->create(['email_verified_at' => null]);

    $this->actingAs($admin);

    $url = '/admin/users?'.http_build_query([
        'group' => 'is_admin',
        'search' => 'example',
        'sort' => 'attention',
        'direction' => 'asc',
        'filters' => ['verified' => 'false'],
        'columnSearch' => ['email' => 'example'],
        'columns' => ['visible' => ['name', 'email'], 'order' => ['email', 'name']],
    ]);

    $props = $this->get($url)->assertOk()->viewData('page')['props'];

    expect($props['state']['group'])->toBe('is_admin')
        ->and($props['state']['filters'])->toHaveKey('verified')
        ->and($props['state']['columnSearches'])->toBe(['email' => 'example'])
        // `avatar` is `toggleable(false)`, so it stays visible however the
        // request asks — and lands in the arranged order like the rest.
        ->and($props['state']['columns']['visible'])->toBe(['email', 'name', 'avatar'])
        ->and($props['groupSummaries'])->not->toBeEmpty()
        ->and(collect($props['rows'])->every(fn (array $row): bool => $row['group'] !== null))->toBeTrue();
});

it('writes a cell inline and refuses a privilege escalation', function (): void {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create(['name' => 'Before']);

    $this->actingAs($admin);

    // An administrator may rename another account.
    $this->post('/admin/actions/cell', [
        'resource' => 'users', 'record' => $member->getKey(),
        'column' => 'name', 'value' => 'After',
    ])->assertRedirect();

    expect($member->fresh()->name)->toBe('After');

    // A member may not make themselves an administrator, even though the
    // policy lets them edit their own account.
    $this->actingAs($member);

    $this->post('/admin/actions/cell', [
        'resource' => 'users', 'record' => $member->getKey(),
        'column' => 'is_admin', 'value' => true,
    ])->assertForbidden();

    expect($member->fresh()->is_admin)->toBeFalse();
});
