<?php

declare(strict_types=1);

use App\Models\User;
use App\Panels\Admin\Resources\Users\UserResource;

it('returns 403 on a direct resource URL for a user without the policy', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/admin/users')
        ->assertForbidden();
});

it('allows an admin through', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/users')
        ->assertOk();
});

it('redirects a guest rather than exposing the resource', function (): void {
    $this->get('/admin/users')->assertRedirect('/login');
});

it('delegates every resource ability to the policy', function (): void {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();

    $this->actingAs($admin);

    expect(UserResource::canViewAny())->toBeTrue()
        ->and(UserResource::canCreate())->toBeTrue()
        ->and(UserResource::canView($member))->toBeTrue()
        ->and(UserResource::canEdit($member))->toBeTrue()
        ->and(UserResource::canDelete($member))->toBeTrue()
        ->and(UserResource::canDeleteAny())->toBeTrue();

    $this->actingAs($member);

    expect(UserResource::canViewAny())->toBeFalse()
        ->and(UserResource::canCreate())->toBeFalse()
        ->and(UserResource::canDelete($admin))->toBeFalse();
});

it('does not let an admin delete their own account through the panel', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    expect(UserResource::canDelete($admin))->toBeFalse();
});

it('lets a member view and edit their own record but not others', function (): void {
    $member = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($member);

    expect(UserResource::canView($member))->toBeTrue()
        ->and(UserResource::canEdit($member))->toBeTrue()
        ->and(UserResource::canView($other))->toBeFalse();
});

it('hides the resource from navigation when the user cannot view it', function (): void {
    $this->actingAs(User::factory()->create());

    expect(UserResource::canViewAny())->toBeFalse();
});
