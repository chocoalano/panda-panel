<?php

declare(strict_types=1);

use App\Models\User;
use App\Panels\Admin\Resources\Users\UserResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Routing\PanelRouteRegistrar;
use PandaPanel\Tables\Tab;
use PandaPanel\Tables\TableSchema;
use Tests\Fixtures\Panel\Orderable;
use Tests\Fixtures\Panel\OrderablePolicy;
use Tests\Fixtures\Panel\ReorderableUsersResource;
use Tests\Fixtures\Panel\TabbedListUsers;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();

    $this->actingAs($this->admin);

    app(PanelManager::class)->setCurrentPanel(panel('admin'));
});

/**
 * @return array<string, mixed>
 */
function listProps(?string $tab = null): array
{
    $request = request();

    if ($tab !== null) {
        $request->query->set('tab', $tab);
    }

    return (new TabbedListUsers)->render($request)
        ->toResponse($request)->original->getData()['page']['props'];
}

/**
 * A panel of its own for the reorder endpoint: the admin panel is already
 * registered, and registering it twice is an error by design.
 */
function reorderPanelPath(): string
{
    if (! Schema::hasTable('orderables')) {
        Schema::create('orderables', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('position')->default(0);
        });
    }

    $manager = app(PanelManager::class);

    if (! $manager->has('reorder-host')) {
        $panel = $manager->register(
            Panel::make('reorder-host')
                ->path('reorder-host')
                ->settings(false)
                ->resources([ReorderableUsersResource::class]),
        );

        app(PanelRouteRegistrar::class)->register($panel);

        Route::getRoutes()->refreshNameLookups();
    }

    return '/reorder-host/actions/reorder';
}

/*
 * Filter tabs
 */

it('sends no tabs for a page that declares none', function (): void {
    $this->get('/admin/users')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('tabs', []));
});

it('serializes every tab with its label, icon, and badge', function (): void {
    $tabs = listProps()['tabs'];

    expect(array_column($tabs, 'key'))->toBe(['all', 'admins', 'members'])
        // A key becomes a readable label unless one was given.
        ->and(array_column($tabs, 'label'))->toBe(['All', 'Administrators', 'Members'])
        ->and($tabs[1]['icon'])->toBe('shield')
        ->and($tabs[0]['badge'])->toBe(User::query()->count());
});

it('falls back to the first tab when the url names none', function (): void {
    expect(array_column(listProps()['tabs'], 'active'))->toBe([true, false, false]);
});

it('activates the tab the url asks for', function (): void {
    expect(array_column(listProps('members')['tabs'], 'active'))->toBe([false, false, true]);
});

it('ignores a tab the page does not declare', function (): void {
    // Query strings are user input, so an unknown key falls back exactly as
    // an unknown sort column does.
    expect(array_column(listProps('nonsense')['tabs'], 'active'))->toBe([true, false, false]);
});

it('narrows the rows to the active tab', function (): void {
    User::factory()->count(3)->create(['is_admin' => false]);

    $admins = listProps('admins');
    $members = listProps('members');

    expect($admins['pagination']['total'])->toBe(1)
        ->and($members['pagination']['total'])->toBe(3);
});

it('counts through the tab for page widgets too', function (): void {
    User::factory()->count(3)->create(['is_admin' => false]);

    // The widget context is the tab-scoped query, so a widget counts what
    // the user is looking at.
    expect(listProps('members')['pagination']['total'])->toBe(3);
});

it('resolves a closure badge to a scalar and never serializes the closure', function (): void {
    $tabs = listProps()['tabs'];

    expect($tabs[0]['badge'])->toBeInt()
        ->and(json_encode($tabs))->not->toContain('Closure');
});

it('applies the resource query, not a query of its own', function (): void {
    $tab = Tab::make('admins')->query(
        static fn (Builder $query): Builder => $query->where('is_admin', true),
    );

    $applied = $tab->apply(UserResource::query());

    // Still the resource's builder, so its scope and eager loads survive.
    expect($applied->getModel())->toBeInstanceOf(User::class)
        ->and($applied->count())->toBe(1);
});

/*
 * Reordering
 */

it('is not reorderable until a table says so', function (): void {
    expect(TableSchema::make()->isReorderable())->toBeFalse()
        ->and(UserResource::table(TableSchema::make())->toArray()['reorderable'])->toBeFalse();
});

it('sorts by the order column once reordering is on', function (): void {
    $schema = ReorderableUsersResource::table(TableSchema::make());

    expect($schema->getReorderColumn())->toBe('position')
        // An arranged order only means something while the table shows it.
        ->and($schema->getDefaultSortColumn())->toBe('position')
        ->and($schema->getDefaultSortDirection()->value)->toBe('asc');
});

it('writes the arranged order to the declared column', function (): void {
    $endpoint = reorderPanelPath();

    Gate::policy(Orderable::class, OrderablePolicy::class);

    $keys = collect(['first', 'second', 'third'])
        ->map(fn (string $name): int => Orderable::query()->create(['name' => $name])->getKey());

    $this->post($endpoint, [
        'resource' => 'ordered-users',
        'records' => $keys->reverse()->values()->all(),
    ])->assertRedirect();

    // Position in the submitted list is the order that was written, so the
    // reversal makes the last record first.
    expect(Orderable::query()->orderBy('position')->pluck('name')->all())
        ->toBe(['third', 'second', 'first']);
});

it('refuses to reorder a table that is not reorderable', function (): void {
    reorderPanelPath();

    $this->post('/admin/actions/reorder', [
        'resource' => 'users',
        'records' => [$this->admin->getKey()],
    ])->assertStatus(400);
});

it('refuses a key the resource query cannot reach', function (): void {
    $this->post(reorderPanelPath(), [
        'resource' => 'ordered-users',
        'records' => [999999],
    ])->assertNotFound();
});

it('refuses to reorder when the user may not edit every record', function (): void {
    $endpoint = reorderPanelPath();

    $keys = collect(['a', 'b'])
        ->map(fn (string $name): int => Orderable::query()->create(['name' => $name])->getKey());

    // No policy is registered for this model, so the Gate refuses every
    // record, and the arrangement is refused before anything moves.
    $this->post($endpoint, [
        'resource' => 'ordered-users',
        'records' => $keys->all(),
    ])->assertForbidden();
});
