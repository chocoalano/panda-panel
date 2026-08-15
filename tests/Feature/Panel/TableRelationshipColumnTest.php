<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use PandaPanel\Tables\Columns\BooleanColumn;
use PandaPanel\Tables\Columns\NumberColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableQuery;
use PandaPanel\Tables\TableSchema;
use Tests\Fixtures\Panel\Relations\Label;
use Tests\Fixtures\Panel\Relations\Project;
use Tests\Fixtures\Panel\Relations\RelationSchema;
use Tests\Fixtures\Panel\Relations\Task;

beforeEach(function (): void {
    RelationSchema::create();

    $this->apollo = Project::query()->create(['name' => 'Apollo']);
    $this->gemini = Project::query()->create(['name' => 'Gemini']);

    $this->apollo->tasks()->create(['name' => 'Alpha']);
    $this->apollo->tasks()->create(['name' => 'Beta']);
});

function paginateWith(TableSchema $schema, array $query = []): LengthAwarePaginator
{
    $request = request();

    foreach ($query as $key => $value) {
        $request->query->set($key, $value);
    }

    return (new TableQuery($schema, $request))->paginate(Project::query());
}

/*
 * Aggregates
 */

it('counts related records without a query per row', function (): void {
    // Enough rows that a per-record count would be obvious.
    foreach (range(1, 8) as $index) {
        Project::query()->create(['name' => 'Filler '.$index]);
    }

    $schema = TableSchema::make()->columns([
        TextColumn::make('name'),
        NumberColumn::make('tasks_count')->counts('tasks'),
    ]);

    $queries = 0;

    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (str_contains($query->sql, 'fixture_tasks')) {
            $queries++;
        }
    });

    $records = paginateWith($schema);

    $rows = collect($records->items())
        ->mapWithKeys(fn ($record): array => [
            $record->getAttribute('name') => $schema->toRow($record)['cells']['tasks_count'],
        ]);

    expect($rows['Apollo']['raw'])->toBe(2)
        ->and($rows['Gemini']['raw'])->toBe(0)
        // One query for the whole page — the subselect inside the single data
        // query — whatever the row count. That is the point of computing an
        // aggregate in the select rather than reading it per record.
        ->and($queries)->toBe(1)
        ->and($records->total())->toBe(10);
});

it('reports whether any related record exists', function (): void {
    $schema = TableSchema::make()->columns([
        BooleanColumn::make('tasks_exists')->exists('tasks'),
    ]);

    $rows = collect(paginateWith($schema)->items())
        ->mapWithKeys(fn ($record): array => [
            $record->getAttribute('name') => $schema->toRow($record)['cells']['tasks_exists']['value'],
        ]);

    expect($rows['Apollo'])->toBeTrue()
        ->and($rows['Gemini'])->toBeFalse();
});

it('computes sum, avg, min, and max over a relation', function (): void {
    $schema = TableSchema::make()->columns([
        NumberColumn::make('tasks_sum_id')->sum('tasks', 'id'),
        NumberColumn::make('tasks_min_id')->min('tasks', 'id'),
        NumberColumn::make('tasks_max_id')->max('tasks', 'id'),
    ]);

    $apollo = collect(paginateWith($schema)->items())
        ->firstWhere('name', 'Apollo');

    $cells = $schema->toRow($apollo)['cells'];

    $ids = $this->apollo->tasks()->pluck('id');

    expect($cells['tasks_sum_id']['raw'])->toBe((int) $ids->sum())
        ->and($cells['tasks_min_id']['raw'])->toBe((int) $ids->min())
        ->and($cells['tasks_max_id']['raw'])->toBe((int) $ids->max());
});

it('reads the attribute eloquent generated, not one the column invented', function (): void {
    // The name is derived with Eloquent's own rule, so the column reads
    // exactly what the query wrote rather than the two having to agree
    // separately.
    expect(NumberColumn::make('anything')->counts('tasks')->aggregateAttribute())
        ->toBe('tasks_count')
        ->and(NumberColumn::make('anything')->sum('tasks', 'id')->aggregateAttribute())
        ->toBe('tasks_sum_id')
        ->and(NumberColumn::make('anything')->exists('tasks')->aggregateAttribute())
        ->toBe('tasks_exists');
});

it('sorts by an aggregate as an ordinary column', function (): void {
    $schema = TableSchema::make()->columns([
        TextColumn::make('name'),
        NumberColumn::make('tasks_count')->counts('tasks')->sortable(),
    ]);

    $names = collect(paginateWith($schema, ['sort' => 'tasks_count', 'direction' => 'desc'])->items())
        ->pluck('name')
        ->all();

    expect($names)->toBe(['Apollo', 'Gemini']);
});

/*
 * Relationship sorting
 */

it('sorts by a related column without joining', function (): void {
    $this->gemini->brief()->create(['summary' => 'AAA']);
    $this->apollo->brief()->create(['summary' => 'ZZZ']);

    $schema = TableSchema::make()->columns([
        TextColumn::make('name')->sortableByRelation('brief', 'summary'),
    ]);

    $records = paginateWith($schema, ['sort' => 'name', 'direction' => 'asc']);

    // A join against a to-many relation would multiply rows and break both
    // the page size and the total, so this orders by a correlated subquery.
    expect(collect($records->items())->pluck('name')->all())->toBe(['Gemini', 'Apollo'])
        ->and($records->total())->toBe(2);
});

it('ignores a relation sort the column did not declare', function (): void {
    $schema = TableSchema::make()->columns([
        TextColumn::make('name')->sortable(),
    ]);

    $state = (new TableQuery($schema, tap(request(), static function ($request): void {
        $request->query->set('sort', 'brief.summary');
    })))->state();

    expect($state['sort'])->toBeNull();
});

/*
 * Relationship searching
 */

it('searches a related column with whereHas, not a like on a missing column', function (): void {
    $schema = TableSchema::make()->columns([
        TextColumn::make('name')->searchable(),
        TextColumn::make('tasks.name')->label('Task')->searchable(),
    ]);

    // `tasks.name` is not a column of the projects table; matching it as one
    // would be a SQL error rather than an empty result.
    expect($schema->getSearchColumns())->toBe(['name'])
        ->and($schema->getSearchRelations())->toBe(['tasks.name']);

    $names = collect(paginateWith($schema, ['search' => 'Alpha'])->items())
        ->pluck('name')
        ->all();

    expect($names)->toBe(['Apollo']);
});

it('matches either a local column or a related one', function (): void {
    $schema = TableSchema::make()->columns([
        TextColumn::make('name')->searchable(),
        TextColumn::make('tasks.name')->searchable(),
    ]);

    expect(collect(paginateWith($schema, ['search' => 'Gemini'])->items())->pluck('name')->all())
        ->toBe(['Gemini']);
});

it('counts a table with only relation search columns as searchable', function (): void {
    $schema = TableSchema::make()->columns([
        TextColumn::make('tasks.name')->searchable(),
    ]);

    expect($schema->isSearchable())->toBeTrue()
        ->and($schema->toArray()['searchable'])->toBeTrue();
});

it('leaves a table with nothing searchable alone', function (): void {
    $schema = TableSchema::make()->columns([TextColumn::make('name')]);

    expect($schema->isSearchable())->toBeFalse();
});

it('does not widen an existing constraint when searching a relation', function (): void {
    Label::query()->create(['name' => 'Apollo']);
    Task::query()->create(['name' => 'Apollo', 'project_id' => null]);

    $schema = TableSchema::make()->columns([
        TextColumn::make('tasks.name')->searchable(),
    ]);

    $request = request();
    $request->query->set('search', 'Alpha');

    // The search is grouped, so a query already narrowed stays narrowed.
    $records = (new TableQuery($schema, $request))
        ->paginate(Project::query()->where('name', 'Gemini'));

    expect($records->total())->toBe(0);
});
