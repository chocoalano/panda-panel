<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Enums\SortDirection;
use PandaPanel\Tables\TableQuery;
use PandaPanel\Tables\TableSchema;
use Tests\Fixtures\Panel\Relations\Project;
use Tests\Fixtures\Panel\Relations\RelationSchema;

beforeEach(function (): void {
    RelationSchema::create();
});

/**
 * A fresh request per call, sharing one session store.
 *
 * `request()` is a singleton, so mutating its query bag would leak one call's
 * parameters into the next and make "a second visit carrying nothing" carry
 * everything. The session is shared on purpose: that is the thing under test.
 *
 * @param  array<string, mixed>  $query
 */
function tableQuery(TableSchema $schema, array $query = [], ?string $sessionKey = null): TableQuery
{
    $request = Request::create('/', 'GET', $query);

    $request->setLaravelSession(app('session.store'));

    return new TableQuery($schema, $request, null, $sessionKey);
}

/**
 * @param  array<string, mixed>  $query
 * @return list<string>
 */
function projectNames(TableSchema $schema, array $query = [], ?string $sessionKey = null): array
{
    return collect(tableQuery($schema, $query, $sessionKey)->paginate(Project::query())->items())
        ->pluck('name')
        ->all();
}

function searchableSchema(): TableSchema
{
    return TableSchema::make()->columns([
        TextColumn::make('name')->searchable(),
    ]);
}

/*
 * Term splitting
 */

it('matches every word of a multi-word term', function (): void {
    Project::query()->create(['name' => 'Ada Lovelace']);
    Project::query()->create(['name' => 'Ada Byron']);
    Project::query()->create(['name' => 'Grace Hopper']);

    // Each word must match somewhere, so a term with two words narrows
    // rather than looking for the phrase.
    expect(projectNames(searchableSchema(), ['search' => 'ada lovelace']))
        ->toBe(['Ada Lovelace']);
});

it('finds a record whose words are in different columns', function (): void {
    $apollo = Project::query()->create(['name' => 'Apollo']);
    $apollo->tasks()->create(['name' => 'Lunar']);

    Project::query()->create(['name' => 'Gemini']);

    $schema = TableSchema::make()->columns([
        TextColumn::make('name')->searchable(),
        TextColumn::make('tasks.name')->searchable(),
    ]);

    // The point of splitting: one word matches the project, the other the
    // task, and neither matches both.
    expect(projectNames($schema, ['search' => 'Apollo Lunar']))->toBe(['Apollo']);
});

it('treats the whole term as one when splitting is off', function (): void {
    Project::query()->create(['name' => 'Ada Lovelace']);
    Project::query()->create(['name' => 'Ada Byron']);

    $schema = searchableSchema()->splitSearchTerms(false);

    expect(projectNames($schema, ['search' => 'Ada Lovelace']))->toBe(['Ada Lovelace'])
        // As one string, the words have to appear together and in order.
        ->and(projectNames($schema, ['search' => 'Lovelace Ada']))->toBe([]);
});

/*
 * Individual column search
 */

it('narrows by a column that carries its own box', function (): void {
    Project::query()->create(['name' => 'Apollo']);
    Project::query()->create(['name' => 'Gemini']);

    $schema = TableSchema::make()->columns([
        TextColumn::make('name')->searchable(individually: true),
    ]);

    expect(projectNames($schema, ['columnSearch' => ['name' => 'Apo']]))->toBe(['Apollo']);
});

it('ignores a column search for a column with no box', function (): void {
    Project::query()->create(['name' => 'Apollo']);
    Project::query()->create(['name' => 'Gemini']);

    // Searchable, but not individually: a request naming it searches nothing
    // rather than reaching a column the table never offered.
    $schema = searchableSchema();

    expect(projectNames($schema, ['columnSearch' => ['name' => 'Apo']]))
        ->toBe(['Gemini', 'Apollo']);
});

it('reports only the column searches it accepted', function (): void {
    $schema = TableSchema::make()->columns([
        TextColumn::make('name')->searchable(individually: true),
    ]);

    $state = tableQuery($schema, [
        'columnSearch' => ['name' => 'Apo', 'invented' => 'x', 'blank' => ''],
    ])->state();

    expect($state['columnSearches'])->toBe(['name' => 'Apo']);
});

it('narrows the table-wide search rather than widening it', function (): void {
    Project::query()->create(['name' => 'Apollo One']);
    Project::query()->create(['name' => 'Apollo Two']);

    $schema = TableSchema::make()->columns([
        TextColumn::make('name')->searchable(individually: true),
    ]);

    expect(projectNames($schema, [
        'search' => 'Apollo',
        'columnSearch' => ['name' => 'Two'],
    ]))->toBe(['Apollo Two']);
});

/*
 * Custom sort query
 */

it('lets a column own its ordering', function (): void {
    Project::query()->create(['name' => 'bbb']);
    Project::query()->create(['name' => 'a']);
    Project::query()->create(['name' => 'cc']);

    $schema = TableSchema::make()->columns([
        TextColumn::make('name')->sortUsing(
            // An ordering the schema could not express as a column name.
            static fn (Builder $query, SortDirection $direction) => $query
                ->orderByRaw('length(name) '.$direction->value),
        ),
    ]);

    expect(projectNames($schema, ['sort' => 'name', 'direction' => 'asc']))
        ->toBe(['a', 'cc', 'bbb']);
});

it('reverses a custom sort with the direction it was given', function (): void {
    Project::query()->create(['name' => 'bbb']);
    Project::query()->create(['name' => 'a']);

    $schema = TableSchema::make()->columns([
        TextColumn::make('name')->sortUsing(
            static fn (Builder $query, SortDirection $direction) => $query
                ->orderByRaw('length(name) '.$direction->value),
        ),
    ]);

    expect(projectNames($schema, ['sort' => 'name', 'direction' => 'desc']))
        ->toBe(['bbb', 'a']);
});

it('makes a column sortable by giving it a sort query', function (): void {
    $column = TextColumn::make('name')->sortUsing(static fn () => null);

    expect($column->isSortable())->toBeTrue()
        ->and($column->hasCustomSort())->toBeTrue();
});

/*
 * Default sort option label
 */

it('names the ordering a table falls back to', function (): void {
    $schema = TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->defaultSort('name', SortDirection::Ascending)
        ->defaultSortOptionLabel('Name, A to Z');

    expect($schema->toArray()['defaultSort'])->toBe([
        'column' => 'name',
        'direction' => 'asc',
        'label' => 'Name, A to Z',
    ]);
});

/*
 * Session persistence
 */

it('remembers a search between visits when asked to', function (): void {
    Project::query()->create(['name' => 'Apollo']);
    Project::query()->create(['name' => 'Gemini']);

    $schema = searchableSchema()->persistSearchInSession();

    expect(projectNames($schema, ['search' => 'Apollo'], 'table.projects'))->toBe(['Apollo']);

    // A second visit carrying nothing gets what the first one asked for.
    expect(projectNames($schema, [], 'table.projects'))->toBe(['Apollo']);
});

it('lets an empty value clear what was remembered', function (): void {
    Project::query()->create(['name' => 'Apollo']);
    Project::query()->create(['name' => 'Gemini']);

    $schema = searchableSchema()->persistSearchInSession();

    projectNames($schema, ['search' => 'Apollo'], 'table.projects');

    // The request wins whenever it says anything at all, including that the
    // value is now empty — otherwise clearing a search would be undone by
    // the thing that remembered it.
    expect(projectNames($schema, ['search' => ''], 'table.projects'))
        ->toBe(['Gemini', 'Apollo']);
});

it('remembers a sort between visits when asked to', function (): void {
    Project::query()->create(['name' => 'Apollo']);
    Project::query()->create(['name' => 'Gemini']);

    $schema = TableSchema::make()
        ->columns([TextColumn::make('name')->sortable()])
        ->persistSortInSession();

    expect(projectNames($schema, ['sort' => 'name', 'direction' => 'desc'], 'table.projects'))
        ->toBe(['Gemini', 'Apollo']);

    expect(projectNames($schema, [], 'table.projects'))->toBe(['Gemini', 'Apollo']);
});

it('validates a remembered sort column like a fresh one', function (): void {
    Project::query()->create(['name' => 'Apollo']);

    $schema = TableSchema::make()
        ->columns([TextColumn::make('name')->sortable()])
        ->persistSortInSession();

    tableQuery($schema, ['sort' => 'name'], 'table.projects')->state();

    // The table no longer offers that column. A stale session must not be
    // able to name one the schema does not have.
    $narrowed = TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->persistSortInSession();

    expect(tableQuery($narrowed, [], 'table.projects')->state()['sort'])->toBeNull();
});

it('remembers nothing without persistence turned on', function (): void {
    Project::query()->create(['name' => 'Apollo']);
    Project::query()->create(['name' => 'Gemini']);

    $schema = searchableSchema();

    projectNames($schema, ['search' => 'Apollo'], 'table.projects');

    expect(projectNames($schema, [], 'table.projects'))->toBe(['Gemini', 'Apollo']);
});

it('remembers nothing for a table with no session key', function (): void {
    Project::query()->create(['name' => 'Apollo']);
    Project::query()->create(['name' => 'Gemini']);

    $schema = searchableSchema()->persistSearchInSession();

    projectNames($schema, ['search' => 'Apollo']);

    expect(projectNames($schema))->toBe(['Gemini', 'Apollo']);
});

it('keeps two tables\' remembered state apart', function (): void {
    Project::query()->create(['name' => 'Apollo']);
    Project::query()->create(['name' => 'Gemini']);

    $schema = searchableSchema()->persistSearchInSession();

    projectNames($schema, ['search' => 'Apollo'], 'table.projects');

    expect(projectNames($schema, [], 'table.other'))->toBe(['Gemini', 'Apollo']);
});

/*
 * Search behaviour sent to the frontend
 */

it('sends the debounce and blur decisions with the definition', function (): void {
    $definition = TableSchema::make()
        ->columns([TextColumn::make('name')->searchable(individually: true)])
        ->searchDebounce(750)
        ->searchOnBlur()
        ->toArray();

    // How expensive a search is, is the server's knowledge, so when to ask
    // is the server's decision.
    expect($definition['searchDebounce'])->toBe(750)
        ->and($definition['searchOnBlur'])->toBeTrue()
        ->and($definition['individualSearchColumns'])->toBe(['name']);
});

it('refuses a negative debounce', function (): void {
    expect(TableSchema::make()->searchDebounce(-10)->toArray()['searchDebounce'])->toBe(0);
});
