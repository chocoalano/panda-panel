<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Tables\Columns\NumberColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Group;
use PandaPanel\Tables\Summaries\Count;
use PandaPanel\Tables\Summaries\Sum;
use PandaPanel\Tables\TableQuery;
use PandaPanel\Tables\TableSchema;
use Symfony\Component\HttpKernel\Exception\HttpException;
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
function actionQuery(TableSchema $schema, array $query = []): TableQuery
{
    $request = Request::create('/', 'GET', $query);

    $request->setLaravelSession(app('session.store'));

    return new TableQuery($schema, $request);
}

/*
 * Column actions
 */

it('runs an action from a cell, resolved per record', function (): void {
    $task = $this->apollo->tasks()->create(['name' => 'Alpha']);

    $schema = TableSchema::make()->columns([
        TextColumn::make('name')->action(
            Action::make('approve')->action(static fn (Model $record) => $record->touch()),
        ),
    ]);

    // A column action is a record action in every sense that matters, so the
    // endpoint finds it without a second lookup.
    expect($schema->getRecordAction('approve'))->not->toBeNull()
        ->and($schema->toRow($task)['cellMeta']['name']['action']['name'])->toBe('approve');
});

it('leaves a cell plain when the user may not act on it', function (): void {
    $task = $this->apollo->tasks()->create(['name' => 'Alpha']);

    $schema = TableSchema::make()->columns([
        TextColumn::make('name')->action(
            Action::make('approve')->authorize(static fn (): bool => false),
        ),
    ]);

    // Rendered as an ordinary value rather than a button that answers 403.
    expect($schema->toRow($task)['cellMeta'])->toBe([]);
});

/*
 * Bulk authorization and notifications
 */

it('authorizes every record before touching any, whatever the handler', function (): void {
    $one = $this->apollo->tasks()->create(['name' => 'One']);
    $two = $this->apollo->tasks()->create(['name' => 'Two']);

    $touched = 0;

    $action = Action::make('approve')
        ->authorizeEachUsing(
            static fn (Model $record): bool => $record->getAttribute('name') !== 'Two',
        )
        ->bulkAction(static function (Collection $records) use (&$touched): void {
            $touched = $records->count();
        });

    // "All or nothing" has to be decided before the first write, not
    // discovered halfway through.
    expect(fn () => $action->executeBulk(Task::query()->whereKey([
        $one->getKey(),
        $two->getKey(),
    ])->get()))->toThrow(HttpException::class);

    expect($touched)->toBe(0);
});

it('uses the action authorization as the per-record bulk fallback', function (): void {
    $one = $this->apollo->tasks()->create(['name' => 'One']);
    $two = $this->apollo->tasks()->create(['name' => 'Two']);

    $touched = 0;

    $action = Action::make('approve')
        ->authorize(static fn (?Model $record): bool => $record === null
            || $record->getAttribute('name') !== 'Two')
        ->bulkAction(static function (Collection $records) use (&$touched): void {
            $touched = $records->count();
        });

    expect(fn () => $action->executeBulk(Task::query()->whereKey([
        $one->getKey(),
        $two->getKey(),
    ])->get()))->toThrow(HttpException::class);

    expect($touched)->toBe(0);
});

it('counts what a bulk run touched, for a message that says so', function (): void {
    $this->apollo->tasks()->create(['name' => 'One']);
    $this->apollo->tasks()->create(['name' => 'Two']);

    $action = Action::make('approve')
        ->successMessageUsing(static fn (int $count): string => $count.' approved.')
        ->bulkAction(static fn (Collection $records) => null);

    $action->executeBulk(Task::query()->get());

    expect($action->affectedCount())->toBe(2)
        ->and($action->getSuccessMessage())->toBe('2 approved.');
});

it('keeps a plain success message for an action that wants one', function (): void {
    $action = Action::make('approve')->successMessage('Done.');

    expect($action->getSuccessMessage())->toBe('Done.');
});

/*
 * Panel-level action configuration
 */

it('applies the panel\'s house style to every action it builds', function (): void {
    $manager = app(PanelManager::class);

    $panel = $manager->register(
        Panel::make('styled-host')
            ->path('styled-host')
            ->settings(false)
            ->configureActions(static function (Action $action): void {
                $action->icon('check')->variant(ActionVariant::Outline);
            }),
    );

    $manager->setCurrentPanel($panel);

    // Applied as the action is built, so anything the schema then sets wins.
    $default = Action::make('approve');
    $overridden = Action::make('reject')->variant(ActionVariant::Destructive);

    expect($default->getIcon())->toBe('check')
        ->and($default->toArray()['variant'])->toBe('outline')
        ->and($overridden->toArray()['variant'])->toBe('destructive');
});

it('leaves actions alone outside a configured panel', function (): void {
    app(PanelManager::class)->setCurrentPanel(null);

    expect(Action::make('approve')->getIcon())->toBeNull();
});

/*
 * Group summaries
 */

it('summarizes each band over the whole band, not the page slice', function (): void {
    foreach (range(1, 3) as $index) {
        $this->apollo->tasks()->create(['name' => 'A'.$index]);
    }

    $this->gemini->tasks()->create(['name' => 'G1']);

    $group = Group::make('project_id');

    $schema = TableSchema::make()
        ->columns([
            TextColumn::make('name'),
            NumberColumn::make('id')->summarize([Count::make()]),
        ])
        ->groups([$group])
        // `defaultPerPage` is clamped to the declared options, so 2 has to be
        // one of them.
        ->perPageOptions([2, 10])
        ->defaultPerPage(2);

    $query = Task::query();
    $tableQuery = actionQuery($schema, ['group' => 'project_id']);
    $records = $tableQuery->paginate($query);

    $summaries = $schema->groupSummaries(
        $query,
        array_values($records->items()),
        $group,
    );

    // Two of Apollo's three rows are on this page; the band's own total is
    // still three, because a band total that changed when you paged would be
    // a different number wearing the same label.
    expect($records->count())->toBe(2)
        ->and($summaries[(string) $this->apollo->getKey()]['id'][0]['raw'])->toBe(3);
});

it('gives each band its own figures', function (): void {
    $this->apollo->tasks()->create(['name' => 'A1']);
    $this->apollo->tasks()->create(['name' => 'A2']);
    $this->gemini->tasks()->create(['name' => 'G1']);

    $group = Group::make('project_id');

    $schema = TableSchema::make()
        ->columns([
            TextColumn::make('name'),
            NumberColumn::make('id')->summarize([Count::make(), Sum::make()]),
        ])
        ->groups([$group]);

    $query = Task::query();
    $records = actionQuery($schema, ['group' => 'project_id'])->paginate($query);

    $summaries = $schema->groupSummaries($query, array_values($records->items()), $group);

    expect($summaries[(string) $this->apollo->getKey()]['id'][0]['raw'])->toBe(2)
        ->and($summaries[(string) $this->gemini->getKey()]['id'][0]['raw'])->toBe(1);
});

it('produces no band figures for a table with no summaries', function (): void {
    $this->apollo->tasks()->create(['name' => 'A1']);

    $group = Group::make('project_id');

    $schema = TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->groups([$group]);

    $query = Task::query();
    $records = actionQuery($schema, ['group' => 'project_id'])->paginate($query);

    expect($schema->groupSummaries($query, array_values($records->items()), $group))
        ->toBe([]);
});

/*
 * The manager's shell
 */

it('lets the column manager open as a dialog', function (): void {
    $definition = TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->columnManagerInModal()
        ->toArray();

    expect($definition['columnManager']['modal'])->toBeTrue();
});
