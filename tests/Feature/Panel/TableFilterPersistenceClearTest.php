<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Http\Request;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Filters\Constraints\TextConstraint;
use PandaPanel\Tables\Filters\QueryBuilderFilter;
use PandaPanel\Tables\Filters\SelectFilter;
use PandaPanel\Tables\TableQuery;
use PandaPanel\Tables\TableSchema;

/*
|--------------------------------------------------------------------------
| Clearing filters a table remembers
|--------------------------------------------------------------------------
|
| A query string has no way to spell an empty array, so "I have cleared every
| filter" is sent as `filters=`. Nearly every Laravel application runs
| `ConvertEmptyStringsToNull`, which rewrites that to null before the table
| sees it — and reading the value then gives the same answer for "the user
| cleared everything" as for "the user said nothing".
|
| With persistence on, those two mean opposite things: one must wipe the
| stored filters, the other must restore them. Reading them the same way meant
| Clear restored what it had just removed, so the filter came straight back and
| the button appeared to do nothing.
|
| The middleware replaces values and leaves keys alone, so the key is still
| there to be asked about. These tests go through the real middleware, because
| the bug only exists on the far side of it.
|
*/

const CLEAR_SESSION_KEY = 'panel.tests.table.tasks';

/**
 * A request as the application actually receives one — after the middleware
 * that caused this.
 */
function transformedRequest(string $uri): Request
{
    $request = Request::create($uri, 'GET');

    (new ConvertEmptyStringsToNull)->handle($request, static fn (): null => null);

    $request->setLaravelSession(app('session.store'));

    return $request;
}

function persistedSchema(): TableSchema
{
    return TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->filters([
            SelectFilter::make('status')->options(['active' => 'Active', 'idle' => 'Idle']),
            SelectFilter::make('kind')->options(['a' => 'A', 'b' => 'B']),
        ])
        ->persistFiltersInSession();
}

function stateFor(TableSchema $schema, string $uri, ?string $key = CLEAR_SESSION_KEY): array
{
    return (new TableQuery($schema, transformedRequest($uri), null, $key))->state();
}

function storedFilters(): mixed
{
    return session()->get(CLEAR_SESSION_KEY.'.filters');
}

beforeEach(function (): void {
    session()->flush();
});

/*
 * T1 — silence still restores
 */

it('restores a persisted filter when the request carries no filter parameter', function (): void {
    stateFor(persistedSchema(), '/t?filters[status]=active');

    expect(storedFilters())->toBe(['status' => 'active']);

    // A plain visit, the way a link or a refresh arrives.
    expect(stateFor(persistedSchema(), '/t')['filters'])->toBe(['status' => 'active']);
});

/*
 * T2–T5 — the finding
 */

it('does not restore persisted filters after the user explicitly clears them', function (): void {
    stateFor(persistedSchema(), '/t?filters[status]=active');

    expect(storedFilters())->toBe(['status' => 'active']);

    // `filters=` — which the middleware has already turned into null.
    $cleared = stateFor(persistedSchema(), '/t?filters=');

    expect($cleared['filters'])->toBe([]);

    // And the session no longer remembers it, so a later plain visit stays
    // clear rather than resurrecting it.
    expect(storedFilters())->toBe([]);
    expect(stateFor(persistedSchema(), '/t')['filters'])->toBe([]);
});

it('tells an explicit empty apart from an absent parameter', function (): void {
    $schema = persistedSchema();

    stateFor($schema, '/t?filters[status]=active');

    // Two requests that look identical once the middleware has run, meaning
    // opposite things.
    expect(stateFor($schema, '/t')['filters'])->toBe(['status' => 'active']);

    session()->flush();
    stateFor($schema, '/t?filters[status]=active');

    expect(stateFor($schema, '/t?filters=')['filters'])->toBe([]);
});

it('clears several filters at once', function (): void {
    stateFor(persistedSchema(), '/t?filters[status]=active&filters[kind]=a');

    expect(storedFilters())->toBe(['status' => 'active', 'kind' => 'a']);

    expect(stateFor(persistedSchema(), '/t?filters=')['filters'])->toBe([]);
    expect(storedFilters())->toBe([]);
});

/*
 * T8 — one filter going away is not a clear-all
 */

it('keeps the filters that were sent when only one was dropped', function (): void {
    stateFor(persistedSchema(), '/t?filters[status]=active&filters[kind]=a');

    // The toolbar re-sends what remains rather than an explicit clear.
    $state = stateFor(persistedSchema(), '/t?filters[kind]=a');

    expect($state['filters'])->toBe(['kind' => 'a'])
        ->and(storedFilters())->toBe(['kind' => 'a']);
});

/*
 * T6 — a table that remembers nothing is unaffected
 */

it('clears a non-persistent table exactly as before', function (): void {
    $schema = TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->filters([SelectFilter::make('status')->options(['active' => 'Active'])]);

    expect(stateFor($schema, '/t?filters[status]=active', null)['filters'])
        ->toBe(['status' => 'active']);

    expect(stateFor($schema, '/t?filters=', null)['filters'])->toBe([]);
    expect(stateFor($schema, '/t', null)['filters'])->toBe([]);
});

/*
 * T9 — one table's clear is its own
 */

it('leaves another table\'s remembered filters alone', function (): void {
    $a = 'panel.tests.table.a';
    $b = 'panel.tests.table.b';

    stateFor(persistedSchema(), '/t?filters[status]=active', $a);
    stateFor(persistedSchema(), '/t?filters[kind]=b', $b);

    stateFor(persistedSchema(), '/t?filters=', $a);

    expect(session()->get($a.'.filters'))->toBe([])
        ->and(session()->get($b.'.filters'))->toBe(['kind' => 'b']);
});

/*
 * T11 / T12 — a persisted query builder, and PP-27 untouched
 */

it('clears a persisted query-builder condition', function (): void {
    $schema = TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->filters([
            QueryBuilderFilter::make('conditions')->constraints([TextConstraint::make('name')]),
        ])
        ->persistFiltersInSession();

    stateFor($schema, '/t?filters[conditions][0][column]=name&filters[conditions][0][operator]=starts_with&filters[conditions][0][value]=Al');

    expect(storedFilters())->not->toBe([]);

    expect(stateFor($schema, '/t?filters=')['filters'])->toBe([]);
    expect(storedFilters())->toBe([]);
});

it('does not read a half-written query-builder rule as a clear', function (): void {
    $schema = TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->filters([
            QueryBuilderFilter::make('conditions')->constraints([TextConstraint::make('name')]),
        ])
        ->persistFiltersInSession();

    // The editor sends a complete rule beside one still being written. That
    // is a filter parameter with values in it, not an explicit clear, and it
    // must persist rather than wipe the session. Which of the two rules is
    // executable is the sanitizer's business, covered in `TableFilterTest`.
    $state = stateFor(
        $schema,
        '/t?filters[conditions][0][column]=name&filters[conditions][0][operator]=starts_with'
            .'&filters[conditions][0][value]=Al&filters[conditions][1][column]=name'
            .'&filters[conditions][1][operator]=equals',
    );

    expect($state['filters'])->toHaveKey('conditions')
        ->and(storedFilters())->not->toBe([]);
});

/*
 * T14 — the marker is not a way past validation
 */

it('still discards a filter the table never declared', function (): void {
    $state = stateFor(persistedSchema(), '/t?filters[injected]=x&filters[status]=active');

    expect($state['filters'])->toBe(['status' => 'active']);
});

/*
 * T21–T23 — columns share the same reader, so they share the fix
 */

it('restores persisted columns when the request says nothing about them', function (): void {
    $schema = TableSchema::make()
        ->columns([TextColumn::make('name'), TextColumn::make('status')])
        ->persistColumnsInSession();

    stateFor($schema, '/t?columns[visible][]=name');

    expect(stateFor($schema, '/t')['columns']['visible'])->toBe(['name']);
});

it('clears a persisted column override when the user resets it', function (): void {
    $schema = TableSchema::make()
        ->columns([TextColumn::make('name'), TextColumn::make('status')])
        ->persistColumnsInSession();

    stateFor($schema, '/t?columns[visible][]=name');

    expect(session()->get(CLEAR_SESSION_KEY.'.columns'))->not->toBe([]);

    // Same shape as the filters clear, through the same reader.
    stateFor($schema, '/t?columns=');

    expect(session()->get(CLEAR_SESSION_KEY.'.columns'))->toBe([]);

    // Back to the table's own defaults rather than the stale override.
    expect(stateFor($schema, '/t')['columns']['visible'])->toBe(['name', 'status']);
});
