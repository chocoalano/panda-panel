<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Filters\BooleanFilter;
use PandaPanel\Tables\Filters\Constraints\BooleanConstraint;
use PandaPanel\Tables\Filters\Constraints\NumberConstraint;
use PandaPanel\Tables\Filters\Constraints\TextConstraint;
use PandaPanel\Tables\Filters\DateFilter;
use PandaPanel\Tables\Filters\FormFilter;
use PandaPanel\Tables\Filters\QueryBuilderFilter;
use PandaPanel\Tables\Filters\SelectFilter;
use PandaPanel\Tables\Filters\TernaryFilter;
use PandaPanel\Tables\Filters\TrashedFilter;
use PandaPanel\Tables\TableQuery;
use PandaPanel\Tables\TableSchema;
use Tests\Fixtures\Panel\Relations\Project;
use Tests\Fixtures\Panel\Relations\RelationSchema;
use Tests\Fixtures\Panel\Relations\Task;

beforeEach(function (): void {
    RelationSchema::create();
});

/**
 * @param  array<string, mixed>  $query
 */
function filterQuery(TableSchema $schema, array $query = [], ?string $sessionKey = null): TableQuery
{
    $request = Request::create('/', 'GET', $query);

    $request->setLaravelSession(app('session.store'));

    return new TableQuery($schema, $request, null, $sessionKey);
}

/**
 * @param  array<string, mixed>  $query
 * @return list<string>
 */
function filteredNames(TableSchema $schema, array $query = [], ?string $sessionKey = null): array
{
    return collect(filterQuery($schema, $query, $sessionKey)->paginate(Task::query())->items())
        ->pluck('name')
        ->sort()
        ->values()
        ->all();
}

/*
 * Ternary
 */

it('offers three states where the third is an answer', function (): void {
    $project = Project::query()->create(['name' => 'Apollo']);

    $project->tasks()->create(['name' => 'Assigned']);
    Task::query()->create(['name' => 'Orphan', 'project_id' => null]);

    $schema = TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->filters([
            TernaryFilter::make('project_id')->nullable()->labels('Assigned', 'Unassigned'),
        ]);

    expect(filteredNames($schema))->toBe(['Assigned', 'Orphan'])
        ->and(filteredNames($schema, ['filters' => ['project_id' => TernaryFilter::TRUE]]))
        ->toBe(['Assigned'])
        ->and(filteredNames($schema, ['filters' => ['project_id' => TernaryFilter::FALSE]]))
        ->toBe(['Orphan']);
});

it('lets each branch of a ternary own its query', function (): void {
    $project = Project::query()->create(['name' => 'Apollo']);
    $project->tasks()->create(['name' => 'Assigned']);
    Task::query()->create(['name' => 'Orphan', 'project_id' => null]);

    // The two answers need not be inverses of one constraint.
    $schema = TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->filters([
            TernaryFilter::make('has_project')->queries(
                static fn (Builder $query) => $query->whereHas('project'),
                static fn (Builder $query) => $query->whereDoesntHave('project'),
            ),
        ]);

    expect(filteredNames($schema, ['filters' => ['has_project' => TernaryFilter::TRUE]]))
        ->toBe(['Assigned']);
});

it('ignores a ternary value it does not define', function (): void {
    Task::query()->create(['name' => 'One', 'project_id' => null]);

    $schema = TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->filters([TernaryFilter::make('project_id')->nullable()]);

    expect(filteredNames($schema, ['filters' => ['project_id' => 'maybe']]))->toBe(['One']);
});

/*
 * Defaults
 */

it('applies a filter default before the user touches anything', function (): void {
    $project = Project::query()->create(['name' => 'Apollo']);
    $project->tasks()->create(['name' => 'Assigned']);
    Task::query()->create(['name' => 'Orphan', 'project_id' => null]);

    $schema = TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->filters([
            TernaryFilter::make('project_id')->nullable()->default(TernaryFilter::TRUE),
        ]);

    expect(filteredNames($schema))->toBe(['Assigned']);
});

it('lets the user clear a default once they have touched the filters', function (): void {
    $project = Project::query()->create(['name' => 'Apollo']);
    $project->tasks()->create(['name' => 'Assigned']);
    Task::query()->create(['name' => 'Orphan', 'project_id' => null]);

    $schema = TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->filters([
            TernaryFilter::make('project_id')->nullable()->default(TernaryFilter::TRUE),
            SelectFilter::make('name')->options(['Assigned' => 'Assigned']),
        ]);

    // Once any filter is present the default no longer fills in: an absent
    // filter now means the user removed it, not that they never set one.
    expect(filteredNames($schema, ['filters' => ['name' => 'Assigned']]))->toBe(['Assigned'])
        ->and(filteredNames($schema, ['filters' => ['name' => '']]))
        ->toBe(['Assigned', 'Orphan']);
});

it('reports a default as an active filter', function (): void {
    $schema = TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->filters([
            TernaryFilter::make('project_id')->nullable()->default(TernaryFilter::TRUE),
        ]);

    // A default is a decision the table made, so it shows as active rather
    // than as an empty control.
    expect(filterQuery($schema)->state()['filters'])
        ->toBe(['project_id' => TernaryFilter::TRUE]);
});

/*
 * Indicators
 */

it('says what is narrowing the table in words', function (): void {
    $schema = TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->filters([
            TernaryFilter::make('project_id')->nullable()->labels('Assigned', 'Unassigned'),
        ]);

    $state = filterQuery($schema, ['filters' => ['project_id' => TernaryFilter::TRUE]])->state();

    // `true` is "Assigned"; only the filter knows that.
    expect($state['filterIndicators'])->toBe([
        ['name' => 'project_id', 'label' => 'Project Id: Assigned'],
    ]);
});

/**
 * Every filter type says its value the way its own control said it.
 *
 * The whole reason indicators are built here rather than in Vue is that only
 * a filter knows what its value means — and four of them did not use that
 * knowledge. `describe()` is inherited from `Filter`, which casts a scalar and
 * returns `''` for anything else, so a select chip named its option *key*, a
 * boolean chip read `1`, a trashed chip read `only`, and a date chip read
 * nothing at all after the colon. Each one named a filter while failing to say
 * what it was doing, which is the one job a chip has.
 */
it('says each filter value the way its own control spelled it', function (Closure $filter, mixed $value, string $label): void {
    $schema = TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->filters([$filter()]);

    $name = $filter()->getName();
    $state = filterQuery($schema, ['filters' => [$name => $value]])->state();

    expect($state['filterIndicators'])->toBe([['name' => $name, 'label' => $label]]);
})->with([
    'select names the option, not its key' => [
        fn () => SelectFilter::make('status')->options(['published' => 'Published']),
        'published',
        'Status: Published',
    ],
    'boolean names the label, not the bool' => [
        fn () => BooleanFilter::make('project_id')->nullable()->labels('Assigned', 'Unassigned'),
        '1',
        'Project Id: Assigned',
    ],
    'trashed names the state, not its key' => [
        fn () => TrashedFilter::make('trashed'),
        TrashedFilter::ONLY,
        'Deleted records: Only deleted',
    ],
    'a date range reads as a range' => [
        fn () => DateFilter::make('created_at'),
        ['from' => '2026-01-01', 'to' => '2026-02-01'],
        'Created At: 1 Jan 2026 – 1 Feb 2026',
    ],
    'a one-sided range reads as a bound' => [
        fn () => DateFilter::make('created_at'),
        ['from' => '2026-01-01'],
        'Created At: from 1 Jan 2026',
    ],
    'an upper bound says so' => [
        fn () => DateFilter::make('created_at'),
        ['to' => '2026-02-01'],
        'Created At: until 1 Feb 2026',
    ],
]);

/**
 * The chip's X, from the server's side.
 *
 * Closing the last chip deletes its key and writes `filters=`, which is the
 * only way a query string can spell "filters, and there are none". Every
 * filter type has to drop out of the indicators on that request, or the chip
 * the user just closed comes back on the next render.
 */
it('drops a chip when its filter is closed, whatever the filter type', function (Closure $filter, mixed $value): void {
    $schema = TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->filters([$filter()]);

    $name = $filter()->getName();

    expect(filterQuery($schema, ['filters' => [$name => $value]])->state()['filterIndicators'])
        ->toHaveCount(1);

    expect(filterQuery($schema, ['filters' => ''])->state()['filterIndicators'])
        ->toBe([]);
})->with([
    'select' => [fn () => SelectFilter::make('status')->options(['open' => 'Open']), 'open'],
    'ternary' => [fn () => TernaryFilter::make('project_id')->nullable(), TernaryFilter::TRUE],
    'boolean' => [fn () => BooleanFilter::make('project_id')->nullable(), '1'],
    'trashed' => [fn () => TrashedFilter::make('trashed'), TrashedFilter::ONLY],
    'date' => [fn () => DateFilter::make('created_at'), ['from' => '2026-01-01']],
    'query builder' => [
        fn () => QueryBuilderFilter::make('adv')->constraints([TextConstraint::make('name')]),
        [['column' => 'name', 'operator' => 'contains', 'value' => 'ada']],
    ],
]);

/*
 * Session persistence
 */

it('remembers filters between visits when asked to', function (): void {
    $project = Project::query()->create(['name' => 'Apollo']);
    $project->tasks()->create(['name' => 'Assigned']);
    Task::query()->create(['name' => 'Orphan', 'project_id' => null]);

    $schema = TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->filters([TernaryFilter::make('project_id')->nullable()])
        ->persistFiltersInSession();

    expect(filteredNames($schema, ['filters' => ['project_id' => TernaryFilter::TRUE]], 'table.tasks'))
        ->toBe(['Assigned']);

    expect(filteredNames($schema, [], 'table.tasks'))->toBe(['Assigned']);
});

it('remembers the whole filter map, so removing one sticks', function (): void {
    $project = Project::query()->create(['name' => 'Apollo']);
    $project->tasks()->create(['name' => 'Assigned']);
    Task::query()->create(['name' => 'Orphan', 'project_id' => null]);

    $schema = TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->filters([TernaryFilter::make('project_id')->nullable()])
        ->persistFiltersInSession();

    filteredNames($schema, ['filters' => ['project_id' => TernaryFilter::TRUE]], 'table.tasks');

    // "Which filters are set" is one decision. Remembering them individually
    // would make removing one indistinguishable from never setting it.
    expect(filteredNames($schema, ['filters' => []], 'table.tasks'))
        ->toBe(['Assigned', 'Orphan']);
});

/*
 * Base query modification
 */

it('lets a filter change what the query is, before anything reads it', function (): void {
    $project = Project::query()->create(['name' => 'Apollo']);
    $project->tasks()->create(['name' => 'Live']);

    $gone = $project->tasks()->create(['name' => 'Gone']);
    $gone->delete();

    $schema = TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->filters([
            SelectFilter::make('trashed')
                ->options(['with' => 'With trashed'])
                ->modifyBaseQueryUsing(
                    static fn (Builder $query) => $query->withoutGlobalScopes(),
                ),
        ]);

    expect(filteredNames($schema))->toBe(['Live'])
        ->and(filteredNames($schema, ['filters' => ['trashed' => 'with']]))
        ->toBe(['Gone', 'Live']);
});

/*
 * Form filters
 */

it('validates a form filter against its own schema', function (): void {
    Task::query()->create(['name' => 'Alpha', 'project_id' => null]);
    Task::query()->create(['name' => 'Beta', 'project_id' => null]);

    $filter = FormFilter::make('search')
        ->form(static fn (FormSchema $schema): FormSchema => $schema->schema([
            TextInput::make('starts')->maxLength(10),
        ]))
        ->query(static function (Builder $query, mixed $data): void {
            $query->where('name', 'like', $data['starts'].'%');
        });

    $schema = TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->filters([$filter]);

    expect(filteredNames($schema, ['filters' => ['search' => ['starts' => 'Al']]]))
        ->toBe(['Alpha']);
});

it('discards a key the form filter never declared', function (): void {
    $filter = FormFilter::make('search')
        ->form(static fn (FormSchema $schema): FormSchema => $schema->schema([
            TextInput::make('starts'),
        ]));

    // The schema is the whitelist, exactly as it is on a resource form.
    expect($filter->sanitize(['starts' => 'Al', 'smuggled' => 'x']))
        ->toBe(['starts' => 'Al']);
});

it('treats an all-blank form filter as nothing to say', function (): void {
    $filter = FormFilter::make('search')
        ->form(static fn (FormSchema $schema): FormSchema => $schema->schema([
            TextInput::make('starts'),
        ]));

    expect($filter->sanitize(['starts' => '']))->toBeNull()
        ->and($filter->sanitize('not-an-array'))->toBeNull();
});

/*
 * Query builder
 */

function queryBuilderSchema(): TableSchema
{
    return TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->filters([
            QueryBuilderFilter::make('conditions')->constraints([
                TextConstraint::make('name'),
                NumberConstraint::make('id'),
                BooleanConstraint::make('project_id'),
            ]),
        ]);
}

it('applies the rules the user composed', function (): void {
    Task::query()->create(['name' => 'Alpha', 'project_id' => null]);
    Task::query()->create(['name' => 'Beta', 'project_id' => null]);

    expect(filteredNames(queryBuilderSchema(), [
        'filters' => [
            'conditions' => [
                ['column' => 'name', 'operator' => 'starts_with', 'value' => 'Al'],
            ],
        ],
    ]))->toBe(['Alpha']);
});

it('ands its rules together', function (): void {
    Task::query()->create(['name' => 'Alpha', 'project_id' => null]);
    Task::query()->create(['name' => 'Alpine', 'project_id' => null]);

    expect(filteredNames(queryBuilderSchema(), [
        'filters' => [
            'conditions' => [
                ['column' => 'name', 'operator' => 'starts_with', 'value' => 'Al'],
                ['column' => 'name', 'operator' => 'ends_with', 'value' => 'ha'],
            ],
        ],
    ]))->toBe(['Alpha']);
});

it('drops a rule naming a column it was not given', function (): void {
    Task::query()->create(['name' => 'Alpha', 'project_id' => null]);

    // The declaration is the whitelist: a column that was not declared does
    // not exist, however the request spells it.
    expect(filteredNames(queryBuilderSchema(), [
        'filters' => [
            'conditions' => [
                ['column' => 'deleted_at', 'operator' => 'is_filled'],
            ],
        ],
    ]))->toBe(['Alpha']);
});

it('drops a rule whose operator that column does not support', function (): void {
    Task::query()->create(['name' => 'Alpha', 'project_id' => null]);

    // `contains` is not a comparison a boolean column offered.
    expect(filteredNames(queryBuilderSchema(), [
        'filters' => [
            'conditions' => [
                ['column' => 'project_id', 'operator' => 'contains', 'value' => 'x'],
            ],
        ],
    ]))->toBe(['Alpha']);
});

it('drops a rule whose value the constraint refuses', function (): void {
    Task::query()->create(['name' => 'Alpha', 'project_id' => null]);

    // A number compared against a word is not a narrower query, it is a
    // meaningless one — refused rather than coerced to zero.
    expect(filteredNames(queryBuilderSchema(), [
        'filters' => [
            'conditions' => [
                ['column' => 'id', 'operator' => 'greater_than', 'value' => 'lots'],
            ],
        ],
    ]))->toBe(['Alpha']);
});

it('does not let a wildcard in a composed rule match everything', function (): void {
    Task::query()->create(['name' => '100%', 'project_id' => null]);
    Task::query()->create(['name' => 'Alpha', 'project_id' => null]);

    // Escaped, so `%` is not a wildcard. Whether it then matches the literal
    // `100%` depends on the driver's default LIKE escape character, which
    // differs between SQLite and MySQL — so this asserts the guarantee that
    // holds everywhere: a wildcard cannot widen the query to the whole table.
    expect(filteredNames(queryBuilderSchema(), [
        'filters' => [
            'conditions' => [
                ['column' => 'name', 'operator' => 'contains', 'value' => '%'],
            ],
        ],
    ]))->not->toContain('Alpha');
});

it('bounds how many rules one filter can carry', function (): void {
    $filter = QueryBuilderFilter::make('conditions')
        ->constraints([TextConstraint::make('name')])
        ->maxRules(2);

    $rules = array_fill(0, 5, ['column' => 'name', 'operator' => 'is_filled']);

    expect($filter->sanitize($rules))->toHaveCount(2);
});

it('says what a composed rule means in words', function (): void {
    $state = filterQuery(queryBuilderSchema(), [
        'filters' => [
            'conditions' => [
                ['column' => 'name', 'operator' => 'starts_with', 'value' => 'Al'],
            ],
        ],
    ])->state();

    expect($state['filterIndicators'][0]['label'])
        ->toBe('Conditions: Name starts with Al');
});

/*
 * Filters never narrow a record lookup
 */

it('leaves record resolution alone', function (): void {
    $project = Project::query()->create(['name' => 'Apollo']);
    $task = $project->tasks()->create(['name' => 'Assigned']);

    $schema = TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->filters([
            TernaryFilter::make('project_id')
                ->nullable()
                ->default(TernaryFilter::FALSE)
                ->modifyBaseQueryUsing(static fn (Builder $query) => $query->whereNull('project_id')),
        ]);

    // Filtered out of the list by both the default and the base query, and
    // still openable: filters live in `TableQuery::paginate()`, never in the
    // resource query a record page resolves through.
    expect(filteredNames($schema))->toBe([])
        ->and(Task::query()->find($task->getKey()))->not->toBeNull();
});

/*
 * Behaviour sent to the frontend
 */

it('sends the filter bar\'s behaviour with the definition', function (): void {
    $definition = TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->filters([TernaryFilter::make('project_id')])
        ->deferFilters()
        ->filtersTrigger('Refine', 'filter')
        ->filtersApplyLabel('Run')
        ->filtersResetLabel('Start over')
        ->showFiltersResetAction(false)
        ->toArray();

    expect($definition['filterBehaviour'])->toBe([
        'deferred' => true,
        'triggerLabel' => 'Refine',
        'triggerIcon' => 'filter',
        'applyLabel' => 'Run',
        'resetLabel' => 'Start over',
        'showReset' => false,
    ]);
});

it('serializes a filter free of closures', function (): void {
    $schema = TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->filters([
            TernaryFilter::make('project_id')->queries(
                static fn (Builder $query) => $query,
                static fn (Builder $query) => $query,
            ),
            QueryBuilderFilter::make('conditions')->constraints([
                TextConstraint::make('name'),
            ]),
        ]);

    expect(json_encode($schema->toArray()))->not->toContain('Closure');
});

/*
 * Clearing
 *
 * The server's rule is that the request wins whenever it says anything at all,
 * *including* saying a value is now empty, and that absence is the only case
 * that falls back to what the session remembered. That rule is only useful if
 * the client can express "no filters" — and a query string cannot hold an
 * empty array, so it has to be able to say it another way.
 *
 * Built from a real query *string* rather than a PHP array, because that is
 * the only shape a browser can actually produce: `['filters' => []]` is a
 * thing a test can write and a URL cannot.
 */

/**
 * @return list<string>
 */
function namesForQueryString(TableSchema $schema, string $queryString, string $sessionKey): array
{
    $request = Request::create('/?'.$queryString, 'GET');

    $request->setLaravelSession(app('session.store'));

    return collect((new TableQuery($schema, $request, null, $sessionKey))->paginate(Task::query())->items())
        ->pluck('name')
        ->sort()
        ->values()
        ->all();
}

it('clears a remembered filter when the request says filters are empty', function (): void {
    $project = Project::query()->create(['name' => 'Apollo']);

    $project->tasks()->create(['name' => 'Assigned']);
    Task::query()->create(['name' => 'Orphan', 'project_id' => null]);

    $schema = TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->filters([TernaryFilter::make('project_id')->label('Assigned')])
        ->persistFiltersInSession();

    $key = 'panel.admin.table.clearing';

    // Filtered, and remembered.
    expect(namesForQueryString($schema, 'filters[project_id]=1', $key))->toBe(['Assigned']);

    // `filters=` is how a URL says "filters, and there are none". Without it
    // the key is simply absent, which the server correctly reads as "this
    // request is not talking about filters" — and restores the remembered
    // one, putting back the filter the user just cleared.
    expect(namesForQueryString($schema, 'filters=', $key))->toBe(['Assigned', 'Orphan']);
});

it('still restores a remembered filter when the request mentions none', function (): void {
    $project = Project::query()->create(['name' => 'Apollo']);

    $project->tasks()->create(['name' => 'Assigned']);
    Task::query()->create(['name' => 'Orphan', 'project_id' => null]);

    $schema = TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->filters([TernaryFilter::make('project_id')->label('Assigned')])
        ->persistFiltersInSession();

    $key = 'panel.admin.table.restoring';

    expect(namesForQueryString($schema, 'filters[project_id]=1', $key))->toBe(['Assigned']);

    // The other half of the same rule, and the reason the first test needs a
    // sentinel at all: navigating back to the plain list URL keeps the filter,
    // which is the entire point of remembering it.
    expect(namesForQueryString($schema, 'page=1', $key))->toBe(['Assigned']);
});

it('clears a filter default when the request says filters are empty', function (): void {
    $project = Project::query()->create(['name' => 'Apollo']);

    $project->tasks()->create(['name' => 'Assigned']);
    Task::query()->create(['name' => 'Orphan', 'project_id' => null]);

    $schema = TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->filters([TernaryFilter::make('project_id')->label('Assigned')->default('1')]);

    // A default applies to silence, not to an explicit empty — otherwise a
    // default could never be cleared, which is the second way "clear" used to
    // do nothing.
    expect(namesForQueryString($schema, 'page=1', 'panel.admin.table.defaults'))->toBe(['Assigned'])
        ->and(namesForQueryString($schema, 'filters=', 'panel.admin.table.defaults'))
        ->toBe(['Assigned', 'Orphan']);
});

it('clears a remembered search when the request says the search is empty', function (): void {
    $project = Project::query()->create(['name' => 'Apollo']);

    $project->tasks()->create(['name' => 'Assigned']);
    Task::query()->create(['name' => 'Orphan', 'project_id' => null]);

    $schema = TableSchema::make()
        ->columns([TextColumn::make('name')->searchable()])
        ->persistSearchInSession();

    $key = 'panel.admin.table.search-clearing';

    expect(namesForQueryString($schema, 'search=Orphan', $key))->toBe(['Orphan']);

    // The same shape as the filters case: `search=` is the request saying the
    // term is now empty, which has to beat the remembered one.
    expect(namesForQueryString($schema, 'search=', $key))->toBe(['Assigned', 'Orphan']);

    // And silence still restores, which is why saying it out loud matters.
    expect(namesForQueryString($schema, 'search=Orphan', $key))->toBe(['Orphan'])
        ->and(namesForQueryString($schema, 'page=1', $key))->toBe(['Orphan']);
});
