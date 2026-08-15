<?php

declare(strict_types=1);

use App\Models\User;
use PandaPanel\Core\PanelManager;
use Tests\Fixtures\Panel\CustomisedCreateUser;
use Tests\Fixtures\Panel\HaltingCreateUser;
use Tests\Fixtures\Panel\HookedCreateUser;
use Tests\Fixtures\Panel\HookedEditUser;
use Tests\Fixtures\Panel\SilentCreateUser;

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());

    // These call the page controllers directly, so they supply the panel
    // context `ResolvePanel` would have set for a routed request.
    app(PanelManager::class)->setCurrentPanel(panel('admin'));

    HookedCreateUser::$calls = [];
    HookedEditUser::$calls = [];
});

/**
 * @return array<string, mixed>
 */
function validUserInput(array $overrides = []): array
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

/*
 * Filling the form
 */

it('fires the fill hooks when the create form is rendered', function (): void {
    (new HookedCreateUser)->render(request());

    expect(HookedCreateUser::$calls)->toBe([
        'beforeFill',
        'mutateFormDataBeforeFill',
        'afterFill',
    ]);
});

it('lets a fill hook change what the form opens with', function (): void {
    $rendered = (new HookedCreateUser)->render(request());

    // The Inertia response renders into the root view, where the page object
    // carries the props the frontend would receive.
    $props = $rendered->toResponse(request())->original->getData()['page']['props'];

    $fields = collect($props['form']['schema'])
        ->flatMap(fn (array $node): array => $node['schema'] ?? [$node]);

    expect($fields->firstWhere('name', 'name')['value'])->toBe('Prefilled');
});

it('fires the fill hooks on the edit form too', function (): void {
    $record = User::factory()->create();

    (new HookedEditUser)->render(request(), (string) $record->getKey());

    expect(HookedEditUser::$calls)->toBe([
        'beforeFill',
        'mutateFormDataBeforeFill',
        'afterFill',
    ]);
});

/*
 * Halting
 */

it('writes nothing when a hook halts', function (): void {
    $before = User::query()->count();

    $response = (new HaltingCreateUser)->handle(request()->merge(validUserInput()));

    expect(User::query()->count())->toBe($before)
        // A decision, not a failure: back where they were, no 500.
        ->and($response->getStatusCode())->toBe(302);
});

/*
 * Overriding the write, the destination, and the notification
 */

it('lets the page perform the write itself', function (): void {
    (new CustomisedCreateUser)->handle(request()->merge(validUserInput()));

    expect(User::query()->where('email', 'ada@example.com')->value('name'))
        ->toBe('Written by the page');
});

it('lets the page choose where to go afterwards', function (): void {
    $response = (new CustomisedCreateUser)->handle(request()->merge(validUserInput()));

    expect($response->getTargetUrl())->toEndWith('/admin/users');
});

it('lets the page choose what is said afterwards', function (): void {
    $response = (new CustomisedCreateUser)->handle(request()->merge(validUserInput()));

    expect($response->getSession()->get('info'))->toBe('Welcome aboard.');
});

it('lets the page say nothing at all', function (): void {
    $response = (new SilentCreateUser)->handle(request()->merge(validUserInput()));

    expect($response->getSession()->get('success'))->toBeNull()
        ->and($response->getSession()->get('info'))->toBeNull();
});

it('says the default thing otherwise', function (): void {
    $response = (new HookedCreateUser)->handle(request()->merge(validUserInput()));

    expect($response->getSession()->get('success'))->toBe('User created.');
});

/*
 * Create another
 */

it('offers to create another by default', function (): void {
    $this->get('/admin/users/create')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canCreateAnother', true));
});

it('returns to the create page when another is asked for', function (): void {
    $response = (new HookedCreateUser)->handle(
        request()->merge(validUserInput(['createAnother' => true])),
    );

    expect($response->getTargetUrl())->toEndWith('/admin/users/create')
        // The record was still created; only the destination differs.
        ->and(User::query()->where('email', 'ada@example.com')->exists())->toBeTrue();
});

it('goes to the record when another is not asked for', function (): void {
    $response = (new HookedCreateUser)->handle(request()->merge(validUserInput()));

    expect($response->getTargetUrl())->toContain('/admin/users/')
        ->and($response->getTargetUrl())->toEndWith('/edit');
});

it('keeps nothing for the next form unless the page asks', function (): void {
    $response = (new HookedCreateUser)->handle(
        request()->merge(validUserInput(['createAnother' => true])),
    );

    expect($response->getSession()->getOldInput())->toBe([]);
});

it('preserves the input for the next form when the page asks', function (): void {
    $response = (new CustomisedCreateUser)->handle(
        request()->merge(validUserInput(['createAnother' => true])),
    );

    $old = $response->getSession()->getOldInput();

    expect($old['name'])->toBe('Ada Lovelace')
        // The flag itself is not part of the form.
        ->and($old)->not->toHaveKey('createAnother');
});
