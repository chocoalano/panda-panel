<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableQuery;
use PandaPanel\Tables\TableSchema;
use Tests\Fixtures\Panel\Relations\RelationSchema;

beforeEach(function (): void {
    RelationSchema::create();
});

/**
 * @param  array<string, mixed>  $query
 */
function managerState(TableSchema $schema, array $query = [], ?string $sessionKey = null): array
{
    $request = Request::create('/', 'GET', $query);

    $request->setLaravelSession(app('session.store'));

    return (new TableQuery($schema, $request, null, $sessionKey))->state()['columns'];
}

function managedSchema(): TableSchema
{
    return TableSchema::make()->columns([
        TextColumn::make('id')->toggleable(false),
        TextColumn::make('name'),
        TextColumn::make('email'),
        TextColumn::make('secret')->visible(false),
    ]);
}

/*
 * Defaults
 */

it('starts on what the table declared', function (): void {
    expect(managerState(managedSchema()))->toBe([
        'visible' => ['id', 'name', 'email'],
        'order' => ['id', 'name', 'email', 'secret'],
    ]);
});

/*
 * Visibility
 */

it('hides the columns the request asks to hide', function (): void {
    $state = managerState(managedSchema(), [
        'columns' => ['visible' => ['name']],
    ]);

    expect($state['visible'])->toBe(['id', 'name']);
});

it('keeps a column the table refused to make toggleable', function (): void {
    // `id` identifies the record; a table without it is a list of anonymous
    // rows, so it stays however the request asks.
    $state = managerState(managedSchema(), [
        'columns' => ['visible' => ['email']],
    ]);

    expect($state['visible'])->toContain('id');
});

it('drops a column name it does not know', function (): void {
    $state = managerState(managedSchema(), [
        'columns' => ['visible' => ['name', 'password', 'deleted_at']],
    ]);

    expect($state['visible'])->toBe(['id', 'name']);
});

it('can show a column the table hid by default', function (): void {
    $state = managerState(managedSchema(), [
        'columns' => ['visible' => ['secret']],
    ]);

    expect($state['visible'])->toBe(['id', 'secret']);
});

/*
 * Order
 */

it('arranges the columns the request asks for', function (): void {
    $state = managerState(managedSchema(), [
        'columns' => ['order' => ['email', 'name']],
    ]);

    // Anything the arrangement did not mention keeps its declared place, so
    // adding a column to a table does not leave it invisible for everyone
    // who had already arranged the old ones.
    expect($state['order'])->toBe(['email', 'name', 'id', 'secret']);
});

it('ignores a duplicate or unknown name in an arrangement', function (): void {
    $state = managerState(managedSchema(), [
        'columns' => ['order' => ['email', 'email', 'invented', 'name']],
    ]);

    expect($state['order'])->toBe(['email', 'name', 'id', 'secret']);
});

it('reports visible columns in the arranged order', function (): void {
    $state = managerState(managedSchema(), [
        'columns' => ['order' => ['email', 'name', 'id'], 'visible' => ['name', 'email']],
    ]);

    expect($state['visible'])->toBe(['email', 'name', 'id']);
});

/*
 * Persistence
 */

it('remembers the arrangement between visits when asked to', function (): void {
    $schema = managedSchema()->persistColumnsInSession();

    managerState($schema, ['columns' => ['visible' => ['name']]], 'table.users');

    expect(managerState($schema, [], 'table.users')['visible'])->toBe(['id', 'name']);
});

it('remembers nothing without persistence turned on', function (): void {
    $schema = managedSchema();

    managerState($schema, ['columns' => ['visible' => ['name']]], 'table.users');

    expect(managerState($schema, [], 'table.users')['visible'])
        ->toBe(['id', 'name', 'email']);
});

it('lets an empty arrangement clear what was remembered', function (): void {
    $schema = managedSchema()->persistColumnsInSession();

    managerState($schema, ['columns' => ['visible' => ['name']]], 'table.users');

    // The request wins whenever it says anything, so resetting is possible.
    expect(managerState($schema, ['columns' => []], 'table.users')['visible'])
        ->toBe(['id', 'name', 'email']);
});

/*
 * Behaviour sent to the frontend
 */

it('sends the manager\'s behaviour with the definition', function (): void {
    $definition = managedSchema()
        ->reorderableColumns()
        ->deferColumnManager()
        ->columnManagerTrigger('Layout', 'settings')
        ->showColumnManagerReset(false)
        ->toArray();

    expect($definition['columnManager'])->toBe([
        'reorderable' => true,
        'deferred' => true,
        'triggerLabel' => 'Layout',
        'triggerIcon' => 'settings',
        'resetLabel' => 'Reset',
        'showReset' => false,
        'modal' => false,
        // Only the columns a user may actually hide.
        'toggleable' => ['name', 'email', 'secret'],
    ]);
});
