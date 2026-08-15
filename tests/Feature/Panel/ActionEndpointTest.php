<?php

declare(strict_types=1);

use App\Models\User;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->target = User::factory()->create();

    $this->actingAs($this->admin);
});

it('deletes a record through the action endpoint', function (): void {
    $this->from('/admin/users')
        ->post('/admin/actions/record', [
            'resource' => 'users',
            'action' => 'delete',
            'record' => $this->target->id,
        ])
        ->assertRedirect('/admin/users');

    expect(User::find($this->target->id))->toBeNull();
});

it('flashes the action success message', function (): void {
    $this->from('/admin/users')->post('/admin/actions/record', [
        'resource' => 'users',
        'action' => 'delete',
        'record' => $this->target->id,
    ]);

    expect(session('success'))->toBe('Record deleted.');
});

it('rejects an action the resource never declared', function (): void {
    $this->post('/admin/actions/record', [
        'resource' => 'users',
        'action' => 'nuke',
        'record' => $this->target->id,
    ])->assertNotFound();

    expect(User::find($this->target->id))->not->toBeNull();
});

it('rejects a resource that is not registered in this panel', function (): void {
    $this->post('/admin/actions/record', [
        'resource' => 'invoices',
        'action' => 'delete',
        'record' => $this->target->id,
    ])->assertNotFound();
});

it('rejects a record key the resource query cannot reach', function (): void {
    $this->post('/admin/actions/record', [
        'resource' => 'users',
        'action' => 'delete',
        'record' => 999_999,
    ])->assertNotFound();
});

it('rejects a non-scalar record key', function (): void {
    $this->post('/admin/actions/record', [
        'resource' => 'users',
        'action' => 'delete',
        'record' => ['a', 'b'],
    ])->assertStatus(422);
});

it('rejects a link action that has no handler', function (): void {
    $this->post('/admin/actions/record', [
        'resource' => 'users',
        'action' => 'edit',
        'record' => $this->target->id,
    ])->assertStatus(400);

    expect(User::find($this->target->id))->not->toBeNull();
});

it('enforces the policy on execution, not on the button being rendered', function (): void {
    $this->actingAs(User::factory()->create());

    $this->post('/admin/actions/record', [
        'resource' => 'users',
        'action' => 'delete',
        'record' => $this->target->id,
    ])->assertForbidden();

    expect(User::find($this->target->id))->not->toBeNull();
});

it('refuses an admin deleting their own account', function (): void {
    $this->post('/admin/actions/record', [
        'resource' => 'users',
        'action' => 'delete',
        'record' => $this->admin->id,
    ])->assertForbidden();

    expect(User::find($this->admin->id))->not->toBeNull();
});

it('hides an action the policy would refuse for that row', function (): void {
    $rows = $this->get('/admin/users')->viewData('page')['props']['rows'];

    $ownRow = collect($rows)->firstWhere('key', $this->admin->id);
    $otherRow = collect($rows)->firstWhere('key', $this->target->id);

    expect(array_column($ownRow['actions'], 'name'))->not->toContain('delete')
        ->and(array_column($otherRow['actions'], 'name'))->toContain('delete');
});

it('serializes a row action without any handler', function (): void {
    $rows = $this->get('/admin/users')->viewData('page')['props']['rows'];
    $action = collect($rows)->firstWhere('key', $this->target->id)['actions'][0];

    expect($action)->toHaveKeys(['name', 'label', 'icon', 'variant', 'type', 'url', 'confirmation'])
        ->and(json_encode($action))->not->toContain('Closure');
});

it('deletes every selected record in bulk', function (): void {
    $others = User::factory()->count(2)->create();

    $this->from('/admin/users')->post('/admin/actions/bulk', [
        'resource' => 'users',
        'action' => 'delete',
        'records' => [$this->target->id, ...$others->pluck('id')->all()],
    ])->assertRedirect('/admin/users');

    expect(User::count())->toBe(1);
});

it('deletes nothing when one selected record is forbidden', function (): void {
    $this->post('/admin/actions/bulk', [
        'resource' => 'users',
        'action' => 'delete',
        // The admin's own record cannot be deleted, so the whole batch must
        // roll back rather than deleting the rest.
        'records' => [$this->target->id, $this->admin->id],
    ])->assertForbidden();

    expect(User::count())->toBe(2);
});

it('rejects a bulk selection containing an unreachable key', function (): void {
    $this->post('/admin/actions/bulk', [
        'resource' => 'users',
        'action' => 'delete',
        'records' => [$this->target->id, 999_999],
    ])->assertNotFound();

    expect(User::find($this->target->id))->not->toBeNull();
});

it('rejects an empty bulk selection', function (): void {
    $this->post('/admin/actions/bulk', [
        'resource' => 'users',
        'action' => 'delete',
        'records' => [],
    ])->assertStatus(302)
        ->assertSessionHasErrors('records');
});

it('enforces the bulk policy for a user without it', function (): void {
    $this->actingAs(User::factory()->create());

    $this->post('/admin/actions/bulk', [
        'resource' => 'users',
        'action' => 'delete',
        'records' => [$this->target->id],
    ])->assertForbidden();

    expect(User::find($this->target->id))->not->toBeNull();
});

it('does not expose the action endpoint to guests', function (): void {
    auth()->logout();

    $this->post('/admin/actions/record', [
        'resource' => 'users',
        'action' => 'delete',
        'record' => $this->target->id,
    ])->assertRedirect('/login');
});
