<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;
use PandaPanel\Core\PanelManager;
use Tests\Fixtures\Panel\HookedCreateUser;
use Tests\Fixtures\Panel\HookedEditUser;
use Tests\Fixtures\Panel\ThrowingCreateUser;

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());

    // These tests call the page controllers directly to observe hook order,
    // so they have to supply the panel context that `ResolvePanel` would
    // normally have set for a routed request.
    app(PanelManager::class)->setCurrentPanel(panel('admin'));

    HookedCreateUser::$calls = [];
    HookedEditUser::$calls = [];
});

it('fires the create hooks in the documented order', function (): void {
    $page = new HookedCreateUser;

    $page->handle(request()->merge([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'verified' => false,
        'is_admin' => false,
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ]));

    expect(HookedCreateUser::$calls)->toBe([
        'beforeValidate',
        'afterValidate',
        'beforeCreate',
        'mutateFormDataBeforeCreate',
        'mutateFormDataBeforeSave',
        'beforeSave',
        'handleRecordCreation',
        'afterCreate',
        'afterSave',
    ]);
});

it('fires the update hooks in the documented order and skips the create ones', function (): void {
    $record = User::factory()->create();
    $page = new HookedEditUser;

    $page->handle(request()->merge([
        'name' => 'Renamed',
        'email' => $record->email,
        'verified' => true,
        'is_admin' => false,
        'password' => '',
        'password_confirmation' => '',
    ]), (string) $record->getKey());

    expect(HookedEditUser::$calls)->toBe([
        'beforeValidate',
        'afterValidate',
        'mutateFormDataBeforeSave',
        'beforeSave',
        'handleRecordUpdate',
        'afterSave',
    ]);
});

it('lets a hook shape the data that is persisted', function (): void {
    $page = new HookedCreateUser;

    $page->handle(request()->merge([
        'name' => 'ada lovelace',
        'email' => 'ada@example.com',
        'verified' => false,
        'is_admin' => false,
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ]));

    // HookedCreateUser::beforeSave upper-cases the name.
    expect(User::where('email', 'ada@example.com')->firstOrFail()->name)
        ->toBe('ADA LOVELACE');
});

it('rolls the write back when an after hook throws', function (): void {
    $page = new ThrowingCreateUser;

    expect(fn (): mixed => $page->handle(request()->merge([
        'name' => 'Never Saved',
        'email' => 'never@example.com',
        'verified' => false,
        'is_admin' => false,
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ])))->toThrow(RuntimeException::class, 'afterSave exploded');

    expect(User::where('email', 'never@example.com')->exists())->toBeFalse();
});

it('gives every hook a working no-op default', function (): void {
    $record = User::factory()->create();

    // The stock pages override nothing, so a full create and update must
    // still succeed with the defaults in place.
    $this->post('/admin/users/create', [
        'name' => 'Plain Create',
        'email' => 'plain@example.com',
        'verified' => false,
        'is_admin' => false,
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ])->assertRedirect();

    $this->put("/admin/users/{$record->id}/edit", [
        'name' => 'Plain Update',
        'email' => $record->email,
        'verified' => false,
        'is_admin' => false,
        'password' => '',
        'password_confirmation' => '',
    ])->assertRedirect();

    expect(User::where('email', 'plain@example.com')->exists())->toBeTrue()
        ->and($record->fresh()->name)->toBe('Plain Update');
});

it('runs an action before hook, the handler, and an after hook in order', function (): void {
    $record = User::factory()->create();
    $calls = [];

    $action = Action::make('archive')
        ->before(function () use (&$calls): void {
            $calls[] = 'before';
        })
        ->action(function (Model $model) use (&$calls): void {
            $calls[] = 'handle';
            $model->delete();
        })
        ->after(function () use (&$calls): void {
            $calls[] = 'after';
        });

    $action->execute($record);

    expect($calls)->toBe(['before', 'handle', 'after'])
        ->and(User::find($record->getKey()))->toBeNull();
});

it('rolls an action back when its after hook throws', function (): void {
    $record = User::factory()->create();

    $action = Action::make('archive')
        ->action(static fn (Model $model) => $model->delete())
        ->after(static function (): void {
            throw new RuntimeException('after exploded');
        });

    expect(fn () => $action->execute($record))
        ->toThrow(RuntimeException::class, 'after exploded');

    // The delete and the hook share one transaction, so the record survives.
    expect(User::find($record->getKey()))->not->toBeNull();
});
