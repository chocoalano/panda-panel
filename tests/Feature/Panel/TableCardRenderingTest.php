<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use PandaPanel\Tables\CardLayout;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

/**
 * The card layout, through a real request.
 *
 * `TableCardLayoutTest` covers the schema and the query in isolation — what a
 * slot infers, what the whitelist refuses, what the session remembers. This
 * covers the part that only exists once a page is rendered: that the payload a
 * card renderer needs actually arrives, that switching layout does not lose
 * the rest of the table's state, and that a hidden column does not take the
 * card's heading with it.
 *
 * The example `UsersTable` declares a card face, so these run against the same
 * resource a person would open.
 */
beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create(['name' => 'Ada Lovelace']);

    $this->actingAs($this->admin);
});

/**
 * @return array<string, mixed>
 */
function cardProps(array $query = []): array
{
    $url = '/admin/users'.($query === [] ? '' : '?'.http_build_query($query));

    return test()->get($url)->assertOk()->original->getData()['page']['props'];
}

/*
 * The payload a card renderer needs
 */

it('offers both layouts on a table that declares a card face', function (): void {
    $props = cardProps();

    expect($props['table']['layouts'])->toBe(['table', 'grid'])
        ->and($props['table']['cards'])->not->toBeNull()
        // Opens as a table until somebody asks otherwise.
        ->and($props['state']['layout'])->toBe('table');
});

it('sends the card face as column names the definitions already carry', function (): void {
    $cards = cardProps()['table']['cards'];
    $declared = array_column(cardProps()['table']['columns'], 'name');

    expect($cards['title'])->toBe('name')
        ->and($cards['image'])->toBe('avatar')
        ->and($cards['description'])->toBe('email');

    // Every slot names a column that is really there, or the renderer would
    // resolve it to nothing and draw an empty card face.
    foreach ([$cards['image'], $cards['title'], $cards['description']] as $slot) {
        expect($declared)->toContain($slot);
    }

    foreach ([...$cards['badges'], ...$cards['details']] as $slot) {
        expect($declared)->toContain($slot);
    }
});

it('draws the grid when the request asks for it', function (): void {
    $props = cardProps(['layout' => 'grid']);

    expect($props['state']['layout'])->toBe('grid')
        ->and($props['rows'])->not->toBeEmpty();
});

it('carries a cell for every slot the card face names', function (): void {
    $props = cardProps(['layout' => 'grid']);
    $cards = $props['table']['cards'];
    $cells = $props['rows'][0]['cells'];

    // The renderer reads `row.cells[face.title.name]`. A slot with no cell
    // behind it is a card that draws a placeholder where its heading goes.
    foreach ([$cards['image'], $cards['title'], $cards['description']] as $slot) {
        expect($cells)->toHaveKey($slot);
    }

    foreach ([...$cards['badges'], ...$cards['details']] as $slot) {
        expect($cells)->toHaveKey($slot);
    }
});

/*
 * Switching layout keeps the rest of the table
 */

it('keeps the search, the sort and the filters when the layout changes', function (): void {
    User::factory()->create(['name' => 'Grace Hopper']);

    $props = cardProps([
        'layout' => 'grid',
        'search' => 'Grace',
        'sort' => 'name',
        'direction' => 'asc',
    ]);

    expect($props['state']['layout'])->toBe('grid')
        ->and($props['state']['search'])->toBe('Grace')
        ->and($props['state']['sort'])->toBe('name')
        // The grid narrows to the same records the table would have shown.
        ->and($props['rows'])->toHaveCount(1);
});

it('stays on the page it was on', function (): void {
    User::factory()->count(40)->create();

    $props = cardProps(['layout' => 'grid', 'perPage' => 10, 'page' => 3]);

    // Switching how the same rows are drawn must not throw the reader back to
    // page one — the grid and the table page the same query.
    expect($props['pagination']['page'])->toBe(3)
        ->and($props['state']['layout'])->toBe('grid');
});

it('remembers the layout alongside the column arrangement', function (): void {
    cardProps(['layout' => 'grid']);

    // `UsersTable` persists columns, and the layout rides with them.
    expect(cardProps()['state']['layout'])->toBe('grid');

    cardProps(['layout' => 'table']);

    expect(cardProps()['state']['layout'])->toBe('table');
});

/*
 * The interaction that has no other test
 */

it('keeps the card identity whatever the arrangement asks for', function (): void {
    $props = cardProps([
        'layout' => 'grid',
        // An arrangement naming nothing the card face uses.
        'columns' => ['visible' => ['created_at']],
    ]);

    $cards = $props['table']['cards'];
    $cells = $props['rows'][0]['cells'];

    // Two rules meet here. `columnState()` forces a non-toggleable column back
    // into the arrangement — which is what an identity column normally is, and
    // why the example marks both of these `toggleable(false)`. `toRow()` then
    // keeps the card's image and heading whatever survived that. A card
    // without them would lose its identity rather than one of its rows.
    expect($cells)->toHaveKey($cards['title'])
        ->and($cells)->toHaveKey($cards['image'])
        // The body slots are the card's cells, and they follow the arrangement.
        ->and($cells)->not->toHaveKey('is_admin');
});

it('keeps a card heading its column manager could hide', function (): void {
    // The example cannot express this: both of its identity columns are
    // `toggleable(false)`, so the arrangement can never drop them. A schema
    // that lets its heading be hidden is the case the rule was written for.
    $schema = TableSchema::make()
        ->columns([
            TextColumn::make('id'),
            TextColumn::make('name'),
            TextColumn::make('email'),
        ])
        ->cards(CardLayout::make()->title('name')->details(['email']));

    $cells = $schema->toRow($this->admin, null, ['id'])['cells'];

    expect($cells)->toHaveKey('name')
        ->and($cells)->toHaveKey('id')
        // A detail slot is an ordinary cell and goes with the arrangement.
        ->and($cells)->not->toHaveKey('email');
});

it('does not read or format a column the arrangement hides', function (): void {
    $full = cardProps()['rows'][0]['cells'];
    $trimmed = cardProps(['columns' => ['visible' => ['name']]])['rows'][0]['cells'];

    expect(count($trimmed))->toBeLessThan(count($full))
        ->and($trimmed)->toHaveKey('name');
});

it('does not aggregate a summary for a column the arrangement hides', function (): void {
    $queries = 0;

    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    cardProps();
    $withEverything = $queries;

    $queries = 0;
    cardProps(['columns' => ['visible' => ['name']]]);
    $trimmed = $queries;

    // A figure under a column nobody is looking at is an aggregate query whose
    // result the frontend discards.
    expect($trimmed)->toBeLessThan($withEverything);
});

/*
 * Negative: what the request must not be able to do
 */

it('ignores a layout nobody declared', function (): void {
    foreach (['kanban', 'calendar', '<script>', '../../etc/passwd', ''] as $hostile) {
        expect(cardProps(['layout' => $hostile])['state']['layout'])->toBe('table');
    }
});

it('refuses a layout sent as an array', function (): void {
    // `param()` accepts scalars only, so a bracketed value is not a layout
    // name that happens to be wrong — it is not a name at all.
    expect(cardProps(['layout' => ['grid']])['state']['layout'])->toBe('table');
});

it('never lets a hidden column reach the payload through the card face', function (): void {
    $props = cardProps([
        'layout' => 'grid',
        'columns' => ['visible' => ['name']],
    ]);

    $cards = $props['table']['cards'];
    $cells = $props['rows'][0]['cells'];

    // Only the arrangement plus the card's own two. A badge or detail slot
    // must not smuggle a column the user turned off back into the payload.
    foreach ($cards['badges'] as $slot) {
        expect($cells)->not->toHaveKey($slot);
    }

    expect(array_keys($cells))->toEqualCanonicalizing(
        array_values(array_unique(['name', $cards['image'], $cards['title']])),
    );
});
