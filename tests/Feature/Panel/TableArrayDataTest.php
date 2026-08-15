<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use PandaPanel\Tables\ArrayTableData;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;
use Tests\Fixtures\Panel\Relations\Reading;

/**
 * @param  array<string, mixed>  $query
 */
function arrayTable(TableSchema $schema, array $query = []): ArrayTableData
{
    return ArrayTableData::make(
        $schema,
        collect([
            ['city' => 'Oslo', 'temperature' => 4],
            ['city' => 'Lisbon', 'temperature' => 19],
            ['city' => 'Cairo', 'temperature' => 33],
        ])->map(static fn (array $row): Reading => Reading::make($row)),
        Request::create('/', 'GET', $query),
    );
}

function readingSchema(): TableSchema
{
    return TableSchema::make()->columns([
        TextColumn::make('city')->searchable()->sortable(),
        TextColumn::make('temperature')->sortable(),
    ]);
}

/**
 * @param  array<string, mixed>  $query
 * @return list<string>
 */
function readingCities(array $query = []): array
{
    $data = arrayTable(readingSchema(), $query);

    return collect($data->rows($data->paginate()))
        ->pluck('cells.city')
        ->all();
}

it('renders records that were never in a database', function (): void {
    // The columns, the serialization, and the row shape are the table
    // builder's own; only where the rows come from differs.
    expect(readingCities())->toBe(['Oslo', 'Lisbon', 'Cairo']);
});

it('searches in memory against the declared columns', function (): void {
    expect(readingCities(['search' => 'os']))->toBe(['Oslo']);
});

it('ignores a search when no column declared itself searchable', function (): void {
    $schema = TableSchema::make()->columns([TextColumn::make('city')]);

    $data = arrayTable($schema, ['search' => 'Oslo']);

    expect($data->paginate()->total())->toBe(3);
});

it('sorts by a column that declared itself sortable', function (): void {
    expect(readingCities(['sort' => 'city', 'direction' => 'asc']))
        ->toBe(['Cairo', 'Lisbon', 'Oslo']);
});

it('ignores a sort column the schema does not declare sortable', function (): void {
    $schema = TableSchema::make()->columns([
        TextColumn::make('city'),
        TextColumn::make('temperature'),
    ]);

    $data = arrayTable($schema, ['sort' => 'city', 'direction' => 'asc']);

    // The schema is the whitelist wherever the rows come from.
    expect(collect($data->rows($data->paginate()))->pluck('cells.city')->all())
        ->toBe(['Oslo', 'Lisbon', 'Cairo'])
        ->and($data->state()['sort'])->toBeNull();
});

it('paginates in memory', function (): void {
    $schema = readingSchema()->perPageOptions([2, 10])->defaultPerPage(2);

    $data = arrayTable($schema, ['page' => '2']);
    $records = $data->paginate();

    expect($records->total())->toBe(3)
        ->and($records->currentPage())->toBe(2)
        ->and($data->pagination($records)['lastPage'])->toBe(2)
        ->and(collect($data->rows($records))->pluck('cells.city')->all())->toBe(['Cairo']);
});

it('reports the state it actually applied', function (): void {
    $state = arrayTable(readingSchema(), ['search' => 'os', 'sort' => 'city'])->state();

    expect($state['search'])->toBe('os')
        ->and($state['sort'])->toBe('city')
        ->and($state['columns']['visible'])->toBe(['city', 'temperature']);
});
