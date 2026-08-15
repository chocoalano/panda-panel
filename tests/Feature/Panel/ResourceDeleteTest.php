<?php

declare(strict_types=1);

use App\Models\User;
use App\Panels\Admin\Resources\Users\UserResource;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->target = User::factory()->create();

    $this->actingAs($this->admin);
});

it('offers a delete action that requires confirmation', function (): void {
    $rows = $this->get('/admin/users')->viewData('page')['props']['rows'];
    $row = collect($rows)->firstWhere('key', $this->target->id);

    $delete = collect($row['actions'])->firstWhere('name', 'delete');

    expect($delete)->not->toBeNull()
        ->and($delete['type'])->toBe('callback')
        ->and($delete['variant'])->toBe('destructive')
        ->and($delete['confirmation'])->toHaveKeys(['heading', 'description', 'button']);
});

it('offers a bulk delete action once bulk actions exist', function (): void {
    $table = $this->get('/admin/users')->viewData('page')['props']['table'];

    expect($table['selectable'])->toBeTrue()
        ->and(array_column($table['bulkActions'], 'name'))->toContain('delete')
        ->and($table['bulkActions'][0]['confirmation'])->not->toBeNull();
});

it('deletes through the policy, not through the button', function (): void {
    expect(UserResource::canDelete($this->target))->toBeTrue();

    $this->from('/admin/users')->post('/admin/actions/record', [
        'resource' => 'users',
        'action' => 'delete',
        'record' => $this->target->id,
    ]);

    expect(User::find($this->target->id))->toBeNull();
});

it('returns the user to the list after deleting', function (): void {
    $this->from('/admin/users?page=2')
        ->post('/admin/actions/record', [
            'resource' => 'users',
            'action' => 'delete',
            'record' => $this->target->id,
        ])
        ->assertRedirect('/admin/users?page=2');
});

it('keeps a bulk delete atomic', function (): void {
    $others = User::factory()->count(3)->create();

    $this->post('/admin/actions/bulk', [
        'resource' => 'users',
        'action' => 'delete',
        // Includes the admin, whom the policy refuses.
        'records' => [...$others->pluck('id')->all(), $this->admin->id],
    ])->assertForbidden();

    expect(User::count())->toBe(5);
});

it('deletes the whole selection when every record is permitted', function (): void {
    $others = User::factory()->count(3)->create();

    $this->from('/admin/users')->post('/admin/actions/bulk', [
        'resource' => 'users',
        'action' => 'delete',
        'records' => $others->pluck('id')->all(),
    ])->assertRedirect('/admin/users');

    expect(User::count())->toBe(2);
});
