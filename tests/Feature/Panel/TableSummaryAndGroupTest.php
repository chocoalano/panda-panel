<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use PandaPanel\Actions\Action;
use PandaPanel\Tables\Columns\NumberColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Enums\RecordActionsPosition;
use PandaPanel\Tables\Group;
use PandaPanel\Tables\Summaries\Average;
use PandaPanel\Tables\Summaries\Count;
use PandaPanel\Tables\Summaries\Range;
use PandaPanel\Tables\Summaries\Sum;
use PandaPanel\Tables\TableQuery;
use PandaPanel\Tables\TableSchema;
use Tests\Fixtures\Panel\Relations\Project;
use Tests\Fixtures\Panel\Relations\RelationSchema;
use Tests\Fixtures\Panel\Relations\Task;

beforeEach(function (): void {
    RelationSchema::create();

    $this->apollo = Project::query()->create(['name' => 'Apollo']);
    $this->gemini = Project::query()->create(['name' => 'Gemini']);
});

/**
 * @param  array<string, mixed>  $query
 */
function summaryQuery(TableSchema $schema, array $query = []): TableQuery
{
    $request = Request::create('/', 'GET', $query);

    $request->setLaravelSession(app('session.store'));

    return new TableQuery($schema, $request);
}

/*
 * Summaries
 */

it('computes a summary over the whole filtered result, not the page', function (): void {
    foreach (range(1, 30) as $index) {
        Task::query()->create(['name' => 'Task '.$index, 'project_id' => null]);
    }

    $schema = TableSchema::make()
        ->columns([NumberColumn::make('id')->summarize([Count::make()])])
        ->defaultPerPage(10);

    $query = Task::query();
    $records = summaryQuery($schema)->paginate($query);

    $summaries = $schema->summaries($query, array_values($records->items()));

    // A total that changed when you paged would be a different number wearing
    // the same label.
    expect($records->count())->toBe(10)
        ->and($summaries['id'][0]['raw'])->toBe(30);
});

it('computes a per-page summary from the records on screen', function (): void {
    foreach (range(1, 30) as $index) {
        Task::query()->create(['name' => 'Task '.$index, 'project_id' => null]);
    }

    $schema = TableSchema::make()
        ->columns([NumberColumn::make('id')->summarize([Count::make()->perPage()])])
        ->defaultPerPage(10);

    $query = Task::query();
    $records = summaryQuery($schema)->paginate($query);

    expect($schema->summaries($query, array_values($records->items()))['id'][0]['raw'])
        ->toBe(10);
});

it('summarizes only what the filters left', function (): void {
    $this->apollo->tasks()->create(['name' => 'Alpha']);
    $this->apollo->tasks()->create(['name' => 'Beta']);
    Task::query()->create(['name' => 'Alpine', 'project_id' => null]);

    $schema = TableSchema::make()
        ->columns([
            TextColumn::make('name')->searchable(),
            NumberColumn::make('id')->summarize([Count::make()]),
        ]);

    $query = Task::query();
    $records = summaryQuery($schema, ['search' => 'Alp'])->paginate($query);

    // "Alpha" and "Alpine" match; "Beta" does not.
    expect($schema->summaries($query, array_values($records->items()))['id'][0]['raw'])
        ->toBe(2);
});

it('offers sum, average, count, and range', function (): void {
    Task::query()->create(['name' => 'a', 'project_id' => null]);
    Task::query()->create(['name' => 'b', 'project_id' => null]);
    Task::query()->create(['name' => 'c', 'project_id' => null]);

    $ids = Task::query()->pluck('id');

    $schema = TableSchema::make()->columns([
        NumberColumn::make('id')->summarize([
            Sum::make(),
            Average::make(),
            Count::make(),
            Range::make(),
        ]),
    ]);

    $query = Task::query();
    $records = summaryQuery($schema)->paginate($query);

    $figures = collect($schema->summaries($query, array_values($records->items()))['id'])
        ->keyBy('name');

    expect((int) $figures['sum']['raw'])->toBe((int) $ids->sum())
        ->and((float) $figures['average']['raw'])->toBe((float) $ids->avg())
        ->and($figures['count']['raw'])->toBe(3)
        ->and($figures['range']['value'])->toBe($ids->min().' – '.$ids->max());
});

it('leaves the paginated query unordered by a summary', function (): void {
    Task::query()->create(['name' => 'a', 'project_id' => null]);

    $schema = TableSchema::make()->columns([
        TextColumn::make('name')->sortable(),
        NumberColumn::make('id')->summarize([Sum::make()]),
    ]);

    $query = Task::query();
    $records = summaryQuery($schema, ['sort' => 'name'])->paginate($query);

    // The summary clones and reorders, so it cannot leave an `order by` or a
    // `limit` on the builder the table just paginated.
    $schema->summaries($query, array_values($records->items()));

    expect($records->total())->toBe(1);
});

it('summarizes a relationship aggregate by running it, not just naming it', function (): void {
    $this->apollo->tasks()->create(['name' => 'One']);
    $this->apollo->tasks()->create(['name' => 'Two']);
    $this->gemini->tasks()->create(['name' => 'Three']);

    $schema = TableSchema::make()->columns([
        NumberColumn::make('tasks_count')
            ->counts('tasks')
            ->summarize([Sum::make(), Average::make()]),
    ]);

    $query = Project::query();
    $records = summaryQuery($schema)->paginate($query);

    // `tasks_count` exists only in the SELECT list, so `sum(tasks_count)`
    // against the table is an unknown column — the sum has to happen outside
    // a subquery that produced it. Executing it is the only way to know.
    $figures = collect($schema->summaries($query, array_values($records->items()))['tasks_count'])
        ->keyBy('name');

    expect((int) $figures['sum']['raw'])->toBe(3)
        ->and((float) $figures['average']['raw'])->toBe(1.5);
});

it('summarizes the whole result even after the query was paginated', function (): void {
    foreach (range(1, 30) as $index) {
        Task::query()->create(['name' => 'Task '.$index, 'project_id' => null]);
    }

    $schema = TableSchema::make()
        ->columns([NumberColumn::make('id')->summarize([Count::make()])])
        ->perPageOptions([10, 25])
        ->defaultPerPage(10);

    $query = Task::query();
    $records = summaryQuery($schema)->paginate($query);

    // `paginate()` sets a limit and offset on the builder it was given, so a
    // summary computed from it afterwards would describe one page while
    // claiming to describe the result.
    expect($records->count())->toBe(10)
        ->and($schema->summaries($query, array_values($records->items()))['id'][0]['raw'])
        ->toBe(30);
});

it('ships no summaries for a table that declares none', function (): void {
    $schema = TableSchema::make()->columns([TextColumn::make('name')]);

    expect($schema->hasSummaries())->toBeFalse();
});

/*
 * Grouping
 */

it('bands rows and marks where each band starts', function (): void {
    $this->apollo->tasks()->create(['name' => 'A1']);
    $this->apollo->tasks()->create(['name' => 'A2']);
    $this->gemini->tasks()->create(['name' => 'G1']);

    $schema = TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->groups([Group::make('project_id')->label('Project')]);

    $query = Task::query();
    $tableQuery = summaryQuery($schema, ['group' => 'project_id']);
    $records = $tableQuery->paginate($query);

    $group = $tableQuery->activeGroup();

    $keys = collect($records->items())
        ->map(fn ($record): ?string => $schema->toRow($record, $group)['group']['key'])
        ->all();

    // Rows arrive together because the group sorts before anything else.
    expect($group?->getName())->toBe('project_id')
        ->and($keys)->toBe([
            (string) $this->apollo->getKey(),
            (string) $this->apollo->getKey(),
            (string) $this->gemini->getKey(),
        ]);
});

it('applies the default group before anybody chooses one', function (): void {
    $schema = TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->groups([Group::make('project_id')])
        ->defaultGroup('project_id');

    expect(summaryQuery($schema)->activeGroup()?->getName())->toBe('project_id');
});

it('lets an explicit empty value turn grouping off', function (): void {
    $schema = TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->groups([Group::make('project_id')])
        ->defaultGroup('project_id');

    // Otherwise a table with a default group could never be ungrouped.
    expect(summaryQuery($schema, ['group' => ''])->activeGroup())->toBeNull();
});

it('ignores a group the table did not declare', function (): void {
    $schema = TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->groups([Group::make('project_id')]);

    expect(summaryQuery($schema, ['group' => 'deleted_at'])->activeGroup())->toBeNull();
});

it('resolves a group title on the server', function (): void {
    $task = $this->apollo->tasks()->create(['name' => 'A1']);

    $group = Group::make('project_id')
        ->titleUsing(fn (): string => 'Project '.$this->apollo->name)
        ->descriptionUsing(static fn (): string => 'Two tasks');

    $schema = TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->groups([$group]);

    // A key becomes a name without the frontend knowing how.
    expect($schema->toRow($task, $group)['group'])->toBe([
        'key' => (string) $this->apollo->getKey(),
        'title' => 'Project Apollo',
        'description' => 'Two tasks',
    ]);
});

it('carries no group on a row when the table is not grouped', function (): void {
    $task = Task::query()->create(['name' => 'A1', 'project_id' => null]);

    $schema = TableSchema::make()->columns([TextColumn::make('name')]);

    expect($schema->toRow($task)['group'])->toBeNull();
});

/*
 * Table-level actions and the empty state
 */

it('serializes header, toolbar, and empty-state actions separately', function (): void {
    $definition = TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->headerActions([Action::make('export')->tableAction(static fn () => null)])
        ->toolbarActions([Action::make('refresh')->tableAction(static fn () => null)])
        ->emptyStateActions([Action::make('seed')->tableAction(static fn () => null)])
        ->recordActionsPosition(RecordActionsPosition::BeforeColumns)
        ->recordActionsLabel('Do')
        ->emptyStateComponent('Panels/Admin/EmptyStates/NoOrders')
        ->toArray();

    expect(collect($definition['headerActions'])->pluck('name')->all())->toBe(['export'])
        ->and(collect($definition['toolbarActions'])->pluck('name')->all())->toBe(['refresh'])
        ->and(collect($definition['emptyState']['actions'])->pluck('name')->all())->toBe(['seed'])
        ->and($definition['emptyState']['component'])->toBe('Panels/Admin/EmptyStates/NoOrders')
        ->and($definition['recordActions'])->toBe([
            'position' => 'before_columns',
            'label' => 'Do',
        ]);
});

it('finds a table action wherever it was declared', function (): void {
    $schema = TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->headerActions([Action::make('export')])
        ->emptyStateActions([Action::make('seed')]);

    // The endpoint that runs them does not care which bar an action was
    // rendered in, only that the table declared it somewhere.
    expect($schema->getTableAction('export'))->not->toBeNull()
        ->and($schema->getTableAction('seed'))->not->toBeNull()
        ->and($schema->getTableAction('invented'))->toBeNull();
});

it('hides a table action the user may not run', function (): void {
    $definition = TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->headerActions([
            Action::make('export')->authorize(static fn (): bool => false),
            Action::make('refresh'),
        ])
        ->toArray();

    // Resolved with no record, the way a bulk action's authorization is asked
    // before anything is selected.
    expect(collect($definition['headerActions'])->pluck('name')->all())->toBe(['refresh']);
});

it('runs a table action without a record', function (): void {
    $ran = false;

    $action = Action::make('export')->tableAction(static function () use (&$ran): void {
        $ran = true;
    });

    expect($action->isTableExecutable())->toBeTrue();

    $action->executeWithoutRecord();

    expect($ran)->toBeTrue();
});
