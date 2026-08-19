<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use PandaPanel\Exceptions\PanelSchemaException;
use PandaPanel\Tables\CardLayout;
use PandaPanel\Tables\Columns\BadgeColumn;
use PandaPanel\Tables\Columns\BooleanColumn;
use PandaPanel\Tables\Columns\DateTimeColumn;
use PandaPanel\Tables\Columns\ImageColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Columns\ToggleColumn;
use PandaPanel\Tables\Enums\TableLayout;
use PandaPanel\Tables\TableQuery;
use PandaPanel\Tables\TableSchema;
use Tests\Fixtures\Panel\Relations\RelationSchema;
use Tests\Fixtures\Panel\Relations\Task;

/**
 * Named for the card rather than the table.
 *
 * Pest test files share one global function namespace, and
 * `TableSearchAndSortTest.php` already declares `tableQuery()`. A second
 * declaration is a fatal that takes the whole suite with it, not a failure
 * in this file.
 *
 * @param  array<string, mixed>  $query
 */
beforeEach(fn () => RelationSchema::create());

function cardQuery(TableSchema $schema, array $query = [], ?string $sessionKey = null): TableQuery
{
    $request = Request::create('/', 'GET', $query);

    $request->setLaravelSession(app('session.store'));

    return new TableQuery($schema, $request, null, $sessionKey);
}

/**
 * A table wide enough that inference has choices to make.
 */
function cardSchema(): TableSchema
{
    return TableSchema::make()->columns([
        ImageColumn::make('avatar'),
        TextColumn::make('name'),
        TextColumn::make('email'),
        BadgeColumn::make('status'),
        BooleanColumn::make('verified'),
        TextColumn::make('team'),
        TextColumn::make('plan'),
        DateTimeColumn::make('created_at'),
        TextColumn::make('notes'),
    ]);
}

/*
 * The card face
 */

it('offers only the table layout until a card face is declared', function (): void {
    $definition = cardSchema()->toArray();

    expect($definition['layouts'])->toBe(['table'])
        ->and($definition['cards'])->toBeNull();
});

it('infers a usable card face from the columns alone', function (): void {
    $definition = cardSchema()->cards()->toArray();

    expect($definition['layouts'])->toBe(['table', 'grid'])
        ->and($definition['cards'])->toBe([
            'image' => 'avatar',
            'title' => 'name',
            // Never inferred, however obvious `email` looks.
            'description' => null,
            'badges' => ['status', 'verified'],
            // The four after everything already claimed, and no more.
            'details' => ['email', 'team', 'plan', 'created_at'],
            'columns' => 3,
        ]);
});

it('takes each declared slot as written and infers only the rest', function (): void {
    $definition = cardSchema()
        ->cards(CardLayout::make()->title('email')->description('name'))
        ->toArray();

    expect($definition['cards']['title'])->toBe('email')
        ->and($definition['cards']['description'])->toBe('name')
        // Still inferred, because nobody said otherwise.
        ->and($definition['cards']['image'])->toBe('avatar')
        ->and($definition['cards']['badges'])->toBe(['status', 'verified']);
});

it('never lets an inferred slot reuse a column an explicit one claimed', function (): void {
    $definition = cardSchema()
        ->cards(CardLayout::make()->title('team'))
        ->toArray();

    // `team` is the heading, so it cannot also be a detail row: a card
    // showing the same value twice reads as a bug in the data.
    expect($definition['cards']['title'])->toBe('team')
        ->and($definition['cards']['details'])->not->toContain('team');
});

it('never infers a description, because guessing a subtitle is guessing', function (): void {
    $definition = cardSchema()->cards()->toArray();

    expect($definition['cards']['description'])->toBeNull();

    $declared = cardSchema()
        ->cards(CardLayout::make()->description('email'))
        ->toArray();

    expect($declared['cards']['description'])->toBe('email');
});

it('keeps an editable column out of the card heading', function (): void {
    $definition = TableSchema::make()
        ->columns([
            ToggleColumn::make('active'),
            TextColumn::make('name'),
        ])
        ->cards()
        ->toArray();

    // A `Switch` as the card's heading is not a heading.
    expect($definition['cards']['title'])->toBe('name')
        ->and($definition['cards']['details'])->toContain('active');
});

it('falls back to an editable column when there is nothing else', function (): void {
    $definition = TableSchema::make()
        ->columns([ToggleColumn::make('active')])
        ->cards()
        ->toArray();

    // A card with no heading at all is worse than an odd one.
    expect($definition['cards']['title'])->toBe('active');
});

it('caps the inferred detail rows so a wide table is still a card', function (): void {
    $columns = [TextColumn::make('name')];

    foreach (range(1, 20) as $index) {
        $columns[] = TextColumn::make("field_{$index}");
    }

    $definition = TableSchema::make()->columns($columns)->cards()->toArray();

    // Twenty rows in a card is a table with rounded corners.
    expect($definition['cards']['details'])->toHaveCount(4);
});

it('takes an explicit detail list however long it is', function (): void {
    $definition = cardSchema()
        ->cards(CardLayout::make()->details(['email', 'team', 'plan', 'created_at', 'notes']))
        ->toArray();

    // The cap is for inference. Somebody who wrote five meant five.
    expect($definition['cards']['details'])->toHaveCount(5);
});

it('leaves a slot empty when it is declared empty', function (): void {
    $definition = cardSchema()
        ->cards(CardLayout::make()->image(null)->badges([]))
        ->toArray();

    expect($definition['cards']['image'])->toBeNull()
        ->and($definition['cards']['badges'])->toBe([]);
});

it('keeps a column the table starts hidden off the card', function (): void {
    $definition = TableSchema::make()
        ->columns([
            TextColumn::make('name'),
            TextColumn::make('secret')->visible(false),
        ])
        ->cards()
        ->toArray();

    expect($definition['cards']['details'])->not->toContain('secret');
});

it('clamps the cards per row to the counts the renderer has classes for', function (): void {
    // `panel/lib/grid.ts` has literal classes to four. An interpolated
    // `grid-cols-6` is invisible to Tailwind and the grid would silently
    // collapse to one column.
    expect(cardSchema()->cards(CardLayout::make()->columns(6))->toArray()['cards']['columns'])
        ->toBe(4)
        ->and(cardSchema()->cards(CardLayout::make()->columns(0))->toArray()['cards']['columns'])
        ->toBe(1);
});

it('refuses a card slot naming a column the table does not have', function (): void {
    expect(fn () => cardSchema()->cards(CardLayout::make()->title('author'))->toArray())
        ->toThrow(PanelSchemaException::class, 'author');
});

it('serializes the card face as column names rather than definitions', function (): void {
    $cards = cardSchema()->cards()->toArray()['cards'];

    // The definitions are already in `columns`. Sending them twice would be
    // two places for the same column to disagree with itself.
    foreach (['image', 'title'] as $slot) {
        expect($cards[$slot])->toBeString();
    }

    foreach ([...$cards['badges'], ...$cards['details']] as $name) {
        expect($name)->toBeString();
    }
});

/*
 * The layout whitelist
 */

it('ignores a layout the schema never declared', function (): void {
    $schema = cardSchema()->cards();

    expect(cardQuery($schema, ['layout' => 'kanban'])->state()['layout'])->toBe('table');
});

it('ignores grid on a table with no card face', function (): void {
    expect(cardQuery(cardSchema(), ['layout' => 'grid'])->state()['layout'])->toBe('table');
});

it('draws the grid when the request asks for one the table offers', function (): void {
    $schema = cardSchema()->cards();

    expect(cardQuery($schema, ['layout' => 'grid'])->state()['layout'])->toBe('grid');
});

it('opens in the layout the table declared as its default', function (): void {
    $schema = cardSchema()->cards()->defaultLayout(TableLayout::Grid);

    expect(cardQuery($schema)->state()['layout'])->toBe('grid');
});

it('treats a default of grid as declaring the grid layout', function (): void {
    // Without this, `defaultLayout(Grid)` on its own would be a line of
    // configuration that silently does nothing.
    $schema = cardSchema()->defaultLayout(TableLayout::Grid);

    expect($schema->toArray()['layouts'])->toBe(['table', 'grid'])
        ->and($schema->toArray()['cards'])->not->toBeNull()
        ->and(cardQuery($schema)->state()['layout'])->toBe('grid');
});

it('keeps a reorderable table out of the grid', function (): void {
    // An order arranged by dragging is linear; dragging a card into place in
    // a grid that wraps is a different interaction entirely.
    // `reorderable()` also fixes the sort, so the column has to be real.
    $schema = TableSchema::make()
        ->columns([TextColumn::make('name'), TextColumn::make('position')])
        ->cards()
        ->reorderable('position');

    expect($schema->availableLayouts())->toBe([TableLayout::Table])
        ->and($schema->toArray()['layouts'])->toBe(['table'])
        ->and(cardQuery($schema, ['layout' => 'grid'])->state()['layout'])->toBe('table');
});

it('refuses to open a reorderable table in a grid it does not offer', function (): void {
    $schema = TableSchema::make()
        ->columns([TextColumn::make('name'), TextColumn::make('position')])
        ->cards()
        ->reorderable('position')
        ->defaultLayout(TableLayout::Grid);

    expect(cardQuery($schema)->state()['layout'])->toBe('table');
});

/*
 * Persistence
 */

it('remembers the layout with the column arrangement', function (): void {
    $schema = cardSchema()->cards()->persistColumnsInSession();

    expect(cardQuery($schema, ['layout' => 'grid'], 'table.users')->state()['layout'])->toBe('grid')
        ->and(cardQuery($schema, [], 'table.users')->state()['layout'])->toBe('grid');
});

it('lets the request turn the grid back off after it was remembered', function (): void {
    $schema = cardSchema()->cards()->persistColumnsInSession();

    cardQuery($schema, ['layout' => 'grid'], 'table.users')->state();

    expect(cardQuery($schema, ['layout' => 'table'], 'table.users')->state()['layout'])->toBe('table')
        ->and(cardQuery($schema, [], 'table.users')->state()['layout'])->toBe('table');
});

it('forgets a remembered grid once the card face is removed', function (): void {
    $with = cardSchema()->cards()->persistColumnsInSession();

    cardQuery($with, ['layout' => 'grid'], 'table.users')->state();

    // The session is read through the same whitelist a fresh request is, so
    // a stale value naming a layout the table no longer offers is dropped.
    $without = cardSchema()->persistColumnsInSession();

    expect(cardQuery($without, [], 'table.users')->state()['layout'])->toBe('table');
});

it('remembers nothing when the table does not persist columns', function (): void {
    $schema = cardSchema()->cards();

    cardQuery($schema, ['layout' => 'grid'], 'table.users')->state();

    expect(cardQuery($schema, [], 'table.users')->state()['layout'])->toBe('table');
});

/*
 * The cost of resolving one
 */

it('resolves the card face once however many rows are serialized', function (): void {
    $schema = cardSchema()->cards();
    $record = Task::query()->create(['name' => 'One']);

    foreach (range(1, 25) as $ignored) {
        $schema->toRow($record, null, ['name']);
    }

    // White-box on purpose: this is a cache, and the thing worth asserting is
    // that it exists. `toRow()` runs once per record, and resolving a card
    // face means validating every declared slot and walking the inference
    // rules — a page of twenty-five was doing that twenty-five times for an
    // answer that cannot change between two rows of one page.
    $resolved = new ReflectionProperty(TableSchema::class, 'cardFaceResolved');
    $memo = new ReflectionProperty(TableSchema::class, 'serializedColumns');

    expect($resolved->getValue($schema))->toBeTrue()
        // One entry, because one arrangement was asked for twenty-five times.
        ->and($memo->getValue($schema))->toHaveCount(1);
});

it('re-resolves once the schema it was resolved from changes', function (): void {
    $schema = cardSchema()->cards();
    $record = Task::query()->create(['name' => 'One']);

    expect($schema->toRow($record, null, ['name'])['cells'])->toHaveKey('name');

    // The builder is fluent, so a schema can be reconfigured after something
    // has already asked it for a row. Memoizing without invalidating would
    // answer the second arrangement with the first one's columns.
    $schema->columns([TextColumn::make('project_id')]);

    expect($schema->toRow($record, null, ['project_id'])['cells'])
        ->toHaveKey('project_id')
        ->and($schema->toRow($record, null, ['project_id'])['cells'])
        ->not->toHaveKey('name');
});

it('serializes a different arrangement differently', function (): void {
    $schema = cardSchema()->cards();
    $record = Task::query()->create(['name' => 'One']);

    // Two arrangements in one request is what a relation page with two tables
    // looks like. The memo is keyed by the arrangement, not by the schema.
    $first = $schema->toRow($record, null, ['name'])['cells'];
    $second = $schema->toRow($record, null, ['name', 'status'])['cells'];

    expect($first)->not->toHaveKey('status')
        ->and($second)->toHaveKey('status');
});
