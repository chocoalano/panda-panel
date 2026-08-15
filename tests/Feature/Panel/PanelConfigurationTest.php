<?php

declare(strict_types=1);

use App\Models\User;
use App\Panels\Admin\Resources\Users\UserResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PandaPanel\Actions\Action;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Exceptions\PanelAuthorizationException;
use PandaPanel\Support\DatabaseTransaction;
use Tests\Fixtures\Panel\UnpolicedFixtureResource;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
});

/*
 * bootUsing()
 */

it('runs boot callbacks on a request into the panel', function (): void {
    $ran = 0;

    $panel = app(PanelManager::class)->get('admin');
    $panel->bootUsing(function () use (&$ran): void {
        $ran++;
    });

    $this->actingAs($this->admin)->get('/admin')->assertOk();

    expect($ran)->toBe(1);
});

it('accumulates boot callbacks rather than replacing them', function (): void {
    $order = [];

    $panel = Panel::make('booted')
        ->bootUsing(function () use (&$order): void {
            $order[] = 'first';
        })
        ->bootUsing(function () use (&$order): void {
            $order[] = 'second';
        });

    expect($panel->getBootCallbacks())->toHaveCount(2);

    $panel->boot();

    expect($order)->toBe(['first', 'second']);
});

it('never boots a panel the user may not enter', function (): void {
    $ran = false;

    app(PanelManager::class)->get('admin')->bootUsing(function () use (&$ran): void {
        $ran = true;
    });

    // The access check runs first, so a refused request costs nothing.
    $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();

    expect($ran)->toBeFalse();
});

it('passes the panel to its boot callback', function (): void {
    $seen = null;

    $panel = Panel::make('self-aware')->bootUsing(function (Panel $panel) use (&$seen): void {
        $seen = $panel->getId();
    });

    $panel->boot();

    expect($seen)->toBe('self-aware');
});

/*
 * databaseTransactions()
 */

it('wraps writes in a transaction by default', function (): void {
    expect(app(PanelManager::class)->get('admin')->hasDatabaseTransactions())->toBeTrue()
        ->and(DatabaseTransaction::enabled(null))->toBeTrue();
});

it('lets a panel turn transactions off, and a page or action override that', function (): void {
    $panel = app(PanelManager::class)->register(
        Panel::make('no-transactions')->path('no-transactions')->databaseTransactions(false)->settings(false),
    );

    app(PanelManager::class)->setCurrentPanel($panel);

    expect(DatabaseTransaction::enabled(null))->toBeFalse()
        // The more specific setting wins in both directions.
        ->and(DatabaseTransaction::enabled(true))->toBeTrue()
        ->and(DatabaseTransaction::enabled(false))->toBeFalse();
});

it('actually opens a transaction only when enabled', function (): void {
    // Relative to the baseline: the test suite already holds a transaction
    // of its own for the database rollback.
    $baseline = DB::transactionLevel();

    $inside = DatabaseTransaction::run(true, static fn (): int => DB::transactionLevel());
    $outside = DatabaseTransaction::run(false, static fn (): int => DB::transactionLevel());

    expect($inside)->toBe($baseline + 1)
        ->and($outside)->toBe($baseline);
});

it('returns the callback result either way', function (): void {
    expect(DatabaseTransaction::run(true, static fn (): string => 'wrapped'))->toBe('wrapped')
        ->and(DatabaseTransaction::run(false, static fn (): string => 'bare'))->toBe('bare');
});

it('defaults to a transaction outside a panel entirely', function (): void {
    app(PanelManager::class)->setCurrentPanel(null);

    expect(DatabaseTransaction::enabled(null))->toBeTrue();
});

it('records an action-level transaction preference', function (): void {
    expect(Action::make('sync')->hasDatabaseTransaction())->toBeNull()
        ->and(Action::make('sync')->databaseTransaction(false)->hasDatabaseTransaction())->toBeFalse()
        ->and(Action::make('sync')->databaseTransaction()->hasDatabaseTransaction())->toBeTrue();
});

it('runs an action handler without a transaction when the action opts out', function (): void {
    $baseline = DB::transactionLevel();
    $optedOut = null;
    $default = null;

    Action::make('probe')
        ->databaseTransaction(false)
        ->action(function () use (&$optedOut): void {
            $optedOut = DB::transactionLevel();
        })
        ->execute($this->admin);

    Action::make('probe')
        ->action(function () use (&$default): void {
            $default = DB::transactionLevel();
        })
        ->execute($this->admin);

    expect($optedOut)->toBe($baseline)
        ->and($default)->toBe($baseline + 1);
});

it('still rolls an action back when a hook throws', function (): void {
    $user = User::factory()->create(['name' => 'Original']);

    $action = Action::make('rename')
        ->action(static fn (Model $record) => $record->update(['name' => 'Renamed']))
        ->after(static function (): void {
            throw new RuntimeException('after failed');
        });

    expect(fn () => $action->execute($user))->toThrow(RuntimeException::class);

    expect($user->fresh()->name)->toBe('Original');
});

/*
 * strictAuthorization()
 */

it('is lenient by default, so a missing policy is simply a denial', function (): void {
    expect(app(PanelManager::class)->get('admin')->hasStrictAuthorization())->toBeFalse()
        ->and(UnpolicedFixtureResource::canViewAny())->toBeFalse();
});

it('names the missing policy instead of denying silently in strict mode', function (): void {
    $panel = app(PanelManager::class)->register(
        Panel::make('strict')->path('strict')->strictAuthorization()->settings(false),
    );

    app(PanelManager::class)->setCurrentPanel($panel);

    expect(fn () => UnpolicedFixtureResource::canViewAny())
        ->toThrow(PanelAuthorizationException::class, 'No policy is registered');
});

it('leaves a properly policed resource alone in strict mode', function (): void {
    $panel = app(PanelManager::class)->register(
        Panel::make('strict-ok')->path('strict-ok')->strictAuthorization()->settings(false),
    );

    app(PanelManager::class)->setCurrentPanel($panel);

    $this->actingAs($this->admin);

    expect(UserResource::canViewAny())->toBeTrue()
        ->and(UserResource::canCreate())->toBeTrue();
});

/*
 * unsavedChangesAlerts()
 */

it('tells the frontend whether to warn about unsaved changes', function (): void {
    expect(app(PanelManager::class)->get('admin')->toSharedArray()['unsavedChangesAlerts'])->toBeTrue()
        ->and(Panel::make('quiet')->unsavedChangesAlerts(false)->toSharedArray()['unsavedChangesAlerts'])
        ->toBeFalse();
});
