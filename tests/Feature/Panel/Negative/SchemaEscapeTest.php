<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Exceptions\PanelAuthorizationException;
use PandaPanel\Routing\PanelRouteRegistrar;
use Tests\Fixtures\Panel\UnpolicedFixtureResource;
use Tests\Fixtures\Panel\UnpolicedModel;

/**
 * Writing what the form never offered.
 *
 * A panel form is a whitelist: the schema names the fields, and what arrives
 * under any other name is not a field. That property is the only thing between
 * a create form and every column on the model, so it is worth stating as
 * something that must not happen rather than as something that happens to.
 *
 * The columns chosen below are the ones that matter — a password hash, a
 * remember token, a two-factor secret, a verification timestamp. Each is on
 * `users` and none is in the form.
 */
beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();

    $this->actingAs($this->admin);
});

/*
 * Creating
 */

it('persists no attribute the create form never declared', function (): void {
    $this->post('/admin/users/create', [
        'name' => 'Mallory',
        'email' => 'mallory@example.test',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',

        // None of these is a field on the form.
        'remember_token' => 'stolen-token',
        'two_factor_secret' => 'stolen-secret',
        'two_factor_confirmed_at' => now()->toDateTimeString(),
        'two_factor_email_confirmed_at' => now()->toDateTimeString(),
        'id' => 9999,
    ]);

    $user = User::query()->where('email', 'mallory@example.test')->first();

    expect($user)->not->toBeNull()
        ->and($user->remember_token)->not->toBe('stolen-token')
        ->and($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and($user->getAttribute('two_factor_email_confirmed_at'))->toBeNull()
        ->and($user->id)->not->toBe(9999);
});

it('writes the password as a hash rather than as what was sent', function (): void {
    $this->post('/admin/users/create', [
        'name' => 'Mallory',
        'email' => 'mallory@example.test',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    $user = User::query()->where('email', 'mallory@example.test')->firstOrFail();

    expect($user->password)->not->toBe('Password123!')
        ->and(Hash::check('Password123!', $user->password))->toBeTrue();
});

it('refuses a create whose required fields are absent rather than writing a half record', function (): void {
    $before = User::query()->count();

    $this->post('/admin/users/create', ['name' => 'Nameless'])
        ->assertSessionHasErrors();

    expect(User::query()->count())->toBe($before);
});

it('refuses a create that reuses an existing address', function (): void {
    $before = User::query()->count();

    $this->post('/admin/users/create', [
        'name' => 'Impostor',
        'email' => $this->admin->email,
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ])->assertSessionHasErrors('email');

    expect(User::query()->count())->toBe($before);
});

/*
 * Editing
 */

it('persists no attribute the edit form never declared', function (): void {
    $target = User::factory()->create();

    $originalToken = $target->remember_token;

    $this->put("/admin/users/{$target->id}/edit", [
        'name' => 'Renamed',
        'email' => $target->email,

        'remember_token' => 'stolen-token',
        'two_factor_secret' => 'stolen-secret',
        'password_confirmation' => 'irrelevant',
    ]);

    $target->refresh();

    expect($target->name)->toBe('Renamed')
        ->and($target->remember_token)->toBe($originalToken)
        ->and($target->two_factor_secret)->toBeNull();
});

it('does not blank a password that was left empty on edit', function (): void {
    $target = User::factory()->create();

    $before = $target->password;

    $this->put("/admin/users/{$target->id}/edit", [
        'name' => $target->name,
        'email' => $target->email,
        'password' => '',
    ]);

    expect($target->fresh()->password)->toBe($before);
});

it('refuses an edit that would take an address already in use', function (): void {
    $target = User::factory()->create();
    $other = User::factory()->create();

    $this->put("/admin/users/{$target->id}/edit", [
        'name' => $target->name,
        'email' => $other->email,
    ])->assertSessionHasErrors('email');

    expect($target->fresh()->email)->not->toBe($other->email);
});

/*
 * The editable-cell endpoint is a form of its own
 */

it('refuses a cell write to a column that is not editable', function (): void {
    $target = User::factory()->create();

    // `email` is a `TextColumn`, not a `TextInputColumn`. It renders, and it
    // is not somewhere a value may be written.
    $this->post('/admin/actions/cell', [
        'resource' => 'users',
        'column' => 'email',
        'record' => $target->id,
        'value' => 'hijacked@example.test',
    ])->assertStatus(400);

    expect($target->fresh()->email)->not->toBe('hijacked@example.test');
});

it('applies the column rules to a cell write', function (): void {
    $target = User::factory()->create();

    // The `name` column declares `required` and `maxLength(255)`.
    $this->postJson('/admin/actions/cell', [
        'resource' => 'users',
        'column' => 'name',
        'record' => $target->id,
        'value' => '',
    ])->assertStatus(422);

    $this->postJson('/admin/actions/cell', [
        'resource' => 'users',
        'column' => 'name',
        'record' => $target->id,
        'value' => str_repeat('a', 300),
    ])->assertStatus(422);

    expect($target->fresh()->name)->toBe($target->name);
});

/*
 * Strict authorization
 *
 * A model with no policy answers "no" to every ability, which is safe and
 * silent — and silence is the problem. A panel in strict mode says so out
 * loud instead of behaving like a permission that was deliberately withheld.
 */

it('raises rather than silently denying when a policy is missing under strict authorization', function (): void {
    $manager = app(PanelManager::class);

    if (! $manager->has('strict-negative')) {
        $panel = $manager->register(
            Panel::make('strict-negative')
                ->path('strict-negative')
                ->settings(false)
                ->strictAuthorization()
                ->resources([UnpolicedFixtureResource::class]),
        );

        app(PanelRouteRegistrar::class)->register($panel);
    }

    $manager->setCurrentPanel($manager->get('strict-negative'));

    expect(fn (): bool => UnpolicedFixtureResource::canViewAny())
        ->toThrow(PanelAuthorizationException::class);
})->skip(
    fn (): bool => ! class_exists(UnpolicedFixtureResource::class) || ! class_exists(UnpolicedModel::class),
    'The unpoliced fixtures are not present.',
);
