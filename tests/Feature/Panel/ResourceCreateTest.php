<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

/**
 * @return array<string, mixed>
 */
function newUserPayload(array $overrides = []): array
{
    return [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'verified' => false,
        'is_admin' => false,
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
        ...$overrides,
    ];
}

it('renders the create form', function (): void {
    $this->get('/admin/users/create')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('panel/resources/Create')
            ->where('submitUrl', '/admin/users/create')
            ->has('form.schema', 2)
            ->where('form.schema.0.heading', 'Personal Information')
            ->where('form.schema.1.heading', 'Security')
            ->where('page.heading', 'New User')
        );
});

it('marks the password required on create', function (): void {
    $this->get('/admin/users/create')
        ->assertInertia(function (AssertableInertia $page): void {
            $security = $page->toArray()['props']['form']['schema'][1];

            expect($security['schema'][0]['required'])->toBeTrue()
                ->and($security['schema'][0]['type'])->toBe('password')
                ->and($security['schema'][0]['value'])->toBeNull();
        });
});

it('creates a record from validated input', function (): void {
    $this->post('/admin/users/create', newUserPayload())
        ->assertRedirect();

    $user = User::where('email', 'ada@example.com')->firstOrFail();

    expect($user->name)->toBe('Ada Lovelace')
        ->and($user->is_admin)->toBeFalse()
        ->and($user->email_verified_at)->toBeNull()
        ->and(Hash::check('secret-password', $user->password))->toBeTrue();
});

it('rejects missing required fields', function (): void {
    $this->post('/admin/users/create', ['name' => ''])
        ->assertSessionHasErrors(['name', 'email', 'password']);

    expect(User::count())->toBe(1);
});

it('rejects a duplicate email', function (): void {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->post('/admin/users/create', newUserPayload(['email' => 'taken@example.com']))
        ->assertSessionHasErrors('email');
});

it('rejects a mismatched password confirmation', function (): void {
    $this->post('/admin/users/create', newUserPayload([
        'password_confirmation' => 'something-else',
    ]))->assertSessionHasErrors('password');
});

it('rejects a password below the minimum length', function (): void {
    $this->post('/admin/users/create', newUserPayload([
        'password' => 'short',
        'password_confirmation' => 'short',
    ]))->assertSessionHasErrors('password');
});

it('persists the verified toggle as a timestamp', function (): void {
    $this->post('/admin/users/create', newUserPayload(['verified' => true]));

    expect(User::where('email', 'ada@example.com')->firstOrFail()->email_verified_at)
        ->not->toBeNull();
});

it('discards input that has no field in the schema', function (): void {
    $this->post('/admin/users/create', newUserPayload([
        'remember_token' => 'injected',
    ]));

    expect(User::where('email', 'ada@example.com')->firstOrFail()->remember_token)
        ->not->toBe('injected');
});

it('refuses to create for a user the policy rejects', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get('/admin/users/create')->assertForbidden();
    $this->post('/admin/users/create', newUserPayload())->assertForbidden();

    expect(User::where('email', 'ada@example.com')->exists())->toBeFalse();
});
