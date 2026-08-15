<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->target = User::factory()->create([
        'name' => 'Grace Hopper',
        'email' => 'grace@example.com',
        'password' => Hash::make('original-password'),
    ]);

    $this->actingAs($this->admin);
});

/**
 * @return array<string, mixed>
 */
function editUserPayload(array $overrides = []): array
{
    return [
        'name' => 'Grace Hopper',
        'email' => 'grace@example.com',
        'verified' => true,
        'is_admin' => false,
        'password' => '',
        'password_confirmation' => '',
        ...$overrides,
    ];
}

it('renders the edit form populated from the record', function (): void {
    $this->get("/admin/users/{$this->target->id}/edit")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $personal = $page->toArray()['props']['form']['schema'][0]['schema'];

            expect($personal[0]['value'])->toBe('Grace Hopper')
                ->and($personal[1]['value'])->toBe('grace@example.com')
                ->and($personal[2]['value'])->toBeTrue();
        });
});

it('never sends a password value back to the browser', function (): void {
    $this->get("/admin/users/{$this->target->id}/edit")
        ->assertInertia(function (AssertableInertia $page): void {
            $security = $page->toArray()['props']['form']['schema'][1]['schema'];

            expect($security[0]['value'])->toBeNull()
                ->and($security[0]['required'])->toBeFalse();
        });
});

it('updates validated fields', function (): void {
    $this->put("/admin/users/{$this->target->id}/edit", editUserPayload([
        'name' => 'Rear Admiral Hopper',
    ]))->assertRedirect();

    expect($this->target->fresh()->name)->toBe('Rear Admiral Hopper');
});

it('does not overwrite the password when the field is left blank', function (): void {
    $this->put("/admin/users/{$this->target->id}/edit", editUserPayload());

    expect(Hash::check('original-password', $this->target->fresh()->password))->toBeTrue();
});

it('changes the password when one is supplied', function (): void {
    $this->put("/admin/users/{$this->target->id}/edit", editUserPayload([
        'password' => 'a-new-password',
        'password_confirmation' => 'a-new-password',
    ]))->assertRedirect();

    expect(Hash::check('a-new-password', $this->target->fresh()->password))->toBeTrue();
});

it('still validates a supplied password', function (): void {
    $this->put("/admin/users/{$this->target->id}/edit", editUserPayload([
        'password' => 'short',
        'password_confirmation' => 'short',
    ]))->assertSessionHasErrors('password');

    expect(Hash::check('original-password', $this->target->fresh()->password))->toBeTrue();
});

it('allows saving without changing the email', function (): void {
    $this->put("/admin/users/{$this->target->id}/edit", editUserPayload())
        ->assertSessionHasNoErrors();
});

it('rejects an email already taken by another record', function (): void {
    $this->put("/admin/users/{$this->target->id}/edit", editUserPayload([
        'email' => $this->admin->email,
    ]))->assertSessionHasErrors('email');
});

it('keeps the original verification timestamp when the toggle stays on', function (): void {
    $verifiedAt = $this->target->email_verified_at;

    $this->travel(1)->days();

    $this->put("/admin/users/{$this->target->id}/edit", editUserPayload());

    expect($this->target->fresh()->email_verified_at->toIso8601String())
        ->toBe($verifiedAt->toIso8601String());
});

it('clears the verification timestamp when the toggle is turned off', function (): void {
    $this->put("/admin/users/{$this->target->id}/edit", editUserPayload(['verified' => false]));

    expect($this->target->fresh()->email_verified_at)->toBeNull();
});

it('404s for a record outside the resource query', function (): void {
    $this->get('/admin/users/999999/edit')->assertNotFound();
    $this->put('/admin/users/999999/edit', editUserPayload())->assertNotFound();
});

it('refuses to update for a user the policy rejects', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get("/admin/users/{$this->target->id}/edit")->assertForbidden();
    $this->put("/admin/users/{$this->target->id}/edit", editUserPayload(['name' => 'Hacked']))
        ->assertForbidden();

    expect($this->target->fresh()->name)->toBe('Grace Hopper');
});

/**
 * Every label an infolist renders, at any depth.
 *
 * @param  array<int, array<string, mixed>>  $components
 * @return list<string>
 */
function infolistLabels(array $components): array
{
    $labels = [];

    foreach ($components as $component) {
        if (($component['component'] ?? null) === 'entry') {
            $labels[] = $component['label'];
        }

        foreach (['schema', 'tabs'] as $key) {
            if (isset($component[$key]) && is_array($component[$key])) {
                $labels = [...$labels, ...infolistLabels($component[$key])];
            }
        }
    }

    return $labels;
}

it('renders the read-only view page from the infolist', function (): void {
    $this->get("/admin/users/{$this->target->id}")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $props = $page->toArray()['props'];

            // Collected however deeply the layout nests them: what matters
            // is which values the page shows, not which box they sit in.
            $labels = infolistLabels($props['infolist']['schema']);

            expect($props['page']['heading'])->toBe('Grace Hopper')
                ->and($labels)->toContain('Name', 'Email')
                // The password is absent rather than filtered: an infolist
                // that never reads it cannot leak it.
                ->and($labels)->not->toContain('Password')
                ->and(json_encode($props))->not->toContain('$2y$')
                // The form-derived fallback stands down once an infolist
                // exists.
                ->and($props['entries'])->toBe([]);
        });
});
