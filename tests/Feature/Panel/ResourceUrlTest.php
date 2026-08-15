<?php

declare(strict_types=1);

use App\Models\User;
use App\Panels\Admin\Resources\Users\UserResource;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use PandaPanel\Exceptions\PanelRegistrationException;

it('builds a resource url from the current panel', function (): void {
    $this->actingAs(User::factory()->admin()->create())->get('/admin');

    expect(UserResource::url())->toBe('/admin/users')
        ->and(UserResource::url('index'))->toBe('/admin/users');
});

it('builds a resource url for an explicitly named panel', function (): void {
    expect(UserResource::url(panel: 'admin'))->toBe('/admin/users');
});

it('refuses to guess a panel when there is no current one', function (): void {
    expect(fn (): string => UserResource::url())
        ->toThrow(PanelRegistrationException::class, 'no current panel');
});

it('refuses to build a url in a panel that does not register the resource', function (): void {
    expect(fn (): string => UserResource::url(panel: 'app'))
        ->toThrow(PanelRegistrationException::class, 'is not registered in the panel [app]');
});

it('exposes a predictable route name', function (): void {
    expect(UserResource::routeName('index', 'admin'))->toBe('panel.admin.resources.users.index');
});

it('derives slug and labels from the model', function (): void {
    expect(UserResource::slug())->toBe('users')
        ->and(UserResource::label())->toBe('User')
        ->and(UserResource::pluralLabel())->toBe('Users');
});

it('names a record through the resource', function (): void {
    $user = User::factory()->create(['name' => 'Ada Lovelace']);

    expect(UserResource::recordTitle($user))->toBe('Ada Lovelace');
});

it('resolves a record through the resource query', function (): void {
    $user = User::factory()->create();

    expect(UserResource::resolveRecord($user->getKey())->is($user))->toBeTrue();
});

it('404s for a record the resource query cannot reach', function (): void {
    expect(fn () => UserResource::resolveRecord(999_999))
        ->toThrow(ModelNotFoundException::class);
});
