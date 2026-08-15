<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;

it('redirects guests away from protected panels', function (): void {
    $this->get('/admin')->assertRedirect('/login');
    $this->get('/app')->assertRedirect('/login');
});

/**
 * Panels declare `verified`, but the middleware is currently inert
 * application-wide: Fortify enables email verification while the User model
 * does not implement MustVerifyEmail, so `verified` lets everyone through on
 * `/dashboard` too. This asserts the stack a panel actually applies. The
 * moment the model implements the contract, panels start enforcing it with
 * no panel change, and this test should gain a request-level assertion.
 */
it('declares the verified middleware on panel routes', function (): void {
    $middleware = Route::getRoutes()->getByName('panel.app.dashboard')?->gatherMiddleware() ?? [];

    expect($middleware)->toContain('verified');
});

it('denies a non-admin the admin panel with a 403 rather than a redirect', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/admin')
        ->assertForbidden();
});

it('allows an admin into the admin panel', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin')
        ->assertOk();
});

it('allows a normal user into the app panel', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/app')
        ->assertOk();
});

it('allows an admin into the app panel too', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/app')
        ->assertOk();
});

it('keeps is_admin out of mass assignment so registration cannot grant it', function (): void {
    $this->post('/register', [
        'name' => 'Mallory',
        'email' => 'mallory@example.com',
        'password' => 'Password123!Password',
        'password_confirmation' => 'Password123!Password',
        'is_admin' => true,
    ]);

    $user = User::where('email', 'mallory@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->is_admin)->toBeFalse();
});

it('keeps is_admin out of profile updates', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->patch('/settings/profile', [
        'name' => 'Renamed',
        'email' => $user->email,
        'is_admin' => true,
    ]);

    expect($user->fresh()->is_admin)->toBeFalse();
});
