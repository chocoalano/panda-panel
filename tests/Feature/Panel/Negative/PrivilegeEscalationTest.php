<?php

declare(strict_types=1);

use App\Models\User;

/**
 * Reaching further than the policy allows.
 *
 * The example policy lets a member read and edit their own record and no
 * other, and lets an administrator do everything except delete themselves.
 * Every test here is a way somebody might try to get past that without ever
 * pressing a button the interface offered — a guessed URL, a hand-written
 * POST, an id swapped in a payload.
 *
 * A panel hides what a user may not do. These assert that hiding is not what
 * enforces it.
 */
beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->member = User::factory()->create();
    $this->other = User::factory()->create();
});

/*
 * Reading somebody else's record
 */

it('refuses a member the view page of another account', function (): void {
    $this->actingAs($this->member)
        ->get("/admin/users/{$this->other->id}")
        ->assertForbidden();
});

it('refuses a member the edit page of another account', function (): void {
    $this->actingAs($this->member)
        ->get("/admin/users/{$this->other->id}/edit")
        ->assertForbidden();
});

it('refuses a member the index even though they may read themselves', function (): void {
    // `view` and `viewAny` are different questions, and answering the second
    // with the first would list every account to everybody.
    $this->actingAs($this->member)
        ->get('/admin/users')
        ->assertForbidden();
});

/*
 * Writing somebody else's record
 */

it('refuses a member writing another account through the edit endpoint', function (): void {
    $this->actingAs($this->member)
        ->put("/admin/users/{$this->other->id}/edit", [
            'name' => 'Renamed by a stranger',
            'email' => $this->other->email,
        ])
        ->assertForbidden();

    expect($this->other->fresh()->name)->not->toBe('Renamed by a stranger');
});

it('refuses a member deleting another account through the action endpoint', function (): void {
    $this->actingAs($this->member)
        ->post('/admin/actions/record', [
            'resource' => 'users',
            'action' => 'delete',
            'record' => $this->other->id,
        ])
        ->assertForbidden();

    expect(User::find($this->other->id))->not->toBeNull();
});

it('refuses a member editing another account through an editable cell', function (): void {
    $this->actingAs($this->member)
        ->post('/admin/actions/cell', [
            'resource' => 'users',
            'column' => 'name',
            'record' => $this->other->id,
            'value' => 'Renamed by a stranger',
        ])
        ->assertForbidden();

    expect($this->other->fresh()->name)->not->toBe('Renamed by a stranger');
});

/*
 * Granting yourself the administrator flag
 *
 * The policy permits a member to edit their own record, which is correct for a
 * display name and catastrophic for a privilege flag. These are the three
 * routes to that flag.
 */

it('does not let a member grant themselves the admin flag through the edit form', function (): void {
    $this->actingAs($this->member)
        ->put("/admin/users/{$this->member->id}/edit", [
            'name' => $this->member->name,
            'email' => $this->member->email,
            'is_admin' => true,
        ]);

    expect($this->member->fresh()->is_admin)->toBeFalse();
});

it('does not let a member grant themselves the admin flag through the toggle column', function (): void {
    // A member is refused at the panel door before the column is consulted, so
    // this is the outer gate holding rather than the cell guard. Both matter,
    // and the test below is the one that exercises the cell guard itself.
    $this->actingAs($this->member)
        ->post('/admin/actions/cell', [
            'resource' => 'users',
            'column' => 'is_admin',
            'record' => $this->member->id,
            'value' => true,
        ]);

    expect($this->member->fresh()->is_admin)->toBeFalse();
});

it('does not let an administrator toggle the flag on their own account', function (): void {
    // The one that reaches the cell guard: an administrator is admitted to the
    // panel and the policy lets them edit themselves, so `isDisabledFor()` is
    // the only thing left. Deleting it makes this test fail, which is how we
    // know the guard is load-bearing rather than decorative.
    //
    // The rule matters in its own right: an administrator removing their own
    // rights is how a panel ends up with nobody who can administer it.
    $this->actingAs($this->admin)
        ->post('/admin/actions/cell', [
            'resource' => 'users',
            'column' => 'is_admin',
            'record' => $this->admin->id,
            'value' => false,
        ]);

    expect($this->admin->fresh()->is_admin)->toBeTrue();
});

it('does not let an administrator delete their own account', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/actions/record', [
            'resource' => 'users',
            'action' => 'delete',
            'record' => $this->admin->id,
        ])
        ->assertForbidden();

    expect(User::find($this->admin->id))->not->toBeNull();
});

/*
 * Actions whose button the user never saw
 */

it('refuses a toolbar action to a user the action does not authorize', function (): void {
    User::factory()->unverified()->create();

    $this->actingAs($this->member)
        ->post('/admin/actions/table', [
            'resource' => 'users',
            'action' => 'purgeUnverified',
        ])
        ->assertForbidden();

    expect(User::query()->whereNull('email_verified_at')->count())->toBe(1);
});

it('refuses a bulk action to a user the action does not authorize', function (): void {
    $unverified = User::factory()->unverified()->create();

    $this->actingAs($this->member)
        ->post('/admin/actions/bulk', [
            'resource' => 'users',
            'action' => 'verify',
            'records' => [$unverified->id],
        ])
        ->assertForbidden();

    expect($unverified->fresh()->email_verified_at)->toBeNull();
});

it('writes nothing when a bulk selection contains one record the user may not touch', function (): void {
    // The selection is authorized per record before any of it is written, so a
    // single refused row is not "most of it went through".
    $mine = User::factory()->unverified()->create();
    $theirs = User::factory()->unverified()->create();

    $this->actingAs($this->member)
        ->post('/admin/actions/bulk', [
            'resource' => 'users',
            'action' => 'delete',
            'records' => [$mine->id, $theirs->id],
        ]);

    expect(User::find($mine->id))->not->toBeNull()
        ->and(User::find($theirs->id))->not->toBeNull();
});

/*
 * Crossing between panels
 */

it('does not expose an admin-panel resource through the app panel', function (): void {
    $this->actingAs($this->admin)
        ->get('/app/users')
        ->assertNotFound();
});

it('does not let the action endpoint of one panel reach a resource of another', function (): void {
    $this->actingAs($this->admin)
        ->post('/app/actions/record', [
            'resource' => 'users',
            'action' => 'delete',
            'record' => $this->other->id,
        ])
        ->assertNotFound();

    expect(User::find($this->other->id))->not->toBeNull();
});

it('refuses a user the panel itself does not admit, whatever the policy says', function (): void {
    // The admin panel's `canAccess` is the outer gate. A member is refused at
    // the door, before any resource is consulted.
    $this->actingAs($this->member)
        ->get('/admin')
        ->assertForbidden();
});

/*
 * Guests
 */

it('sends a guest to sign in rather than answering any panel endpoint', function (): void {
    $this->post('/admin/actions/record', [
        'resource' => 'users',
        'action' => 'delete',
        'record' => $this->other->id,
    ])->assertRedirect();

    expect(User::find($this->other->id))->not->toBeNull();
});

it('refuses a guest the search endpoint', function (): void {
    $this->get('/admin/search?q=abc')->assertRedirect();
});

it('refuses a guest the notification endpoints', function (): void {
    $this->getJson('/admin/notifications')->assertUnauthorized();
});
