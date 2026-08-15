<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PandaPanel\Support\RelationOperation;
use Tests\Fixtures\Panel\Relations\LabelPolicy;
use Tests\Fixtures\Panel\Relations\LabelsRelationManager;
use Tests\Fixtures\Panel\Relations\Project;
use Tests\Fixtures\Panel\Relations\ProjectRelationResource;
use Tests\Fixtures\Panel\Relations\RelationPanel;
use Tests\Fixtures\Panel\Relations\Task;
use Tests\Fixtures\Panel\Relations\TaskPolicy;
use Tests\Fixtures\Panel\Relations\TasksRelationManager;

beforeEach(function (): void {
    RelationPanel::boot();
    RelationPanel::reset();

    $this->actingAs(User::factory()->admin()->create());

    $this->project = Project::query()->create(['name' => 'Apollo']);
    $this->other = Project::query()->create(['name' => 'Gemini']);
});

/**
 * @return array<string, mixed>
 */
function relationProps(Project $project): array
{
    $response = test()->get(RelationPanel::url('projects/'.$project->getKey()));

    $response->assertOk();

    return $response->viewData('page')['props'];
}

/*
 * The relation is the scope
 */

it('lists only the owner record\'s related records', function (): void {
    $this->project->tasks()->create(['name' => 'Ours']);
    $this->other->tasks()->create(['name' => 'Theirs']);

    $relations = relationProps($this->project)['relations'];
    $tasks = collect($relations)->firstWhere('key', 'tasks');

    expect(collect($tasks['rows'])->pluck('cells.name')->all())->toBe(['Ours']);
});

it('resolves nothing for a related record belonging to another owner', function (): void {
    $theirs = $this->other->tasks()->create(['name' => 'Theirs']);

    expect(TasksRelationManager::resolveRecord($this->project, $theirs->getKey()))->toBeNull()
        ->and(TasksRelationManager::resolveRecord($this->other, $theirs->getKey()))->not->toBeNull();
});

it('refuses to run an action on a related record from another owner', function (): void {
    $theirs = $this->other->tasks()->create(['name' => 'Theirs']);

    $this->post(RelationPanel::url('relations/action'), [
        'resource' => 'projects',
        'record' => $this->project->getKey(),
        'relation' => 'tasks',
        'action' => 'delete',
        'related' => $theirs->getKey(),
    ])->assertNotFound();

    expect(Task::withTrashed()->find($theirs->getKey())->trashed())->toBeFalse();
});

/*
 * Authorization
 */

it('omits a relation the user may not read, and never queries it', function (): void {
    $this->project->tasks()->create(['name' => 'Hidden']);

    TaskPolicy::$viewable = false;

    $taskQueries = 0;

    DB::listen(function (QueryExecuted $query) use (&$taskQueries): void {
        if (str_contains($query->sql, 'fixture_tasks')) {
            $taskQueries++;
        }
    });

    $relations = relationProps($this->project)['relations'];

    // Authorization runs before the query, so a refused manager is absent
    // *and* costs nothing — a manager that queried and then hid its rows
    // would still have read them.
    expect(collect($relations)->pluck('key')->all())->toBe(['labels'])
        ->and($taskQueries)->toBe(0);
});

it('drops the create action when the policy refuses creating', function (): void {
    TaskPolicy::$creatable = false;

    $relations = relationProps($this->project)['relations'];
    $tasks = collect($relations)->firstWhere('key', 'tasks');

    expect(collect($tasks['headerActions'])->pluck('name')->all())->not->toContain('create');
});

it('refuses a create the policy does not allow', function (): void {
    TaskPolicy::$creatable = false;

    $this->post(RelationPanel::url('relations/form').'?'.http_build_query([
        'resource' => 'projects',
        'record' => $this->project->getKey(),
        'relation' => 'tasks',
        'operation' => RelationOperation::Create->value,
    ]), ['name' => 'Nope'])->assertForbidden();

    expect($this->project->tasks()->count())->toBe(0);
});

it('404s on an owner outside the resource query', function (): void {
    // The owner is loaded through `Resource::query()` like every other
    // lookup, so a key that resource cannot reach is a 404 here too rather
    // than a relation served for a record the user could not have opened.
    $this->post(RelationPanel::url('relations/action'), [
        'resource' => 'projects',
        'record' => 99999,
        'relation' => 'tasks',
        'action' => 'delete',
        'related' => 1,
    ])->assertNotFound();
});

/*
 * Writes go through the relation
 */

it('creates a related record through the relation, without a foreign key field', function (): void {
    $this->post(RelationPanel::url('relations/form').'?'.http_build_query([
        'resource' => 'projects',
        'record' => $this->project->getKey(),
        'relation' => 'tasks',
        'operation' => RelationOperation::Create->value,
        // A key the form never declared, to prove it is discarded.
        'project_id' => $this->other->getKey(),
    ]), [
        'name' => 'Written',
        'project_id' => $this->other->getKey(),
    ])->assertRedirect();

    $task = Task::query()->firstWhere('name', 'Written');

    expect($task)->not->toBeNull()
        ->and($task->project_id)->toBe($this->project->getKey());
});

it('edits a related record and leaves the others alone', function (): void {
    $task = $this->project->tasks()->create(['name' => 'Before']);
    $untouched = $this->project->tasks()->create(['name' => 'Untouched']);

    $this->post(RelationPanel::url('relations/form').'?'.http_build_query([
        'resource' => 'projects',
        'record' => $this->project->getKey(),
        'relation' => 'tasks',
        'operation' => RelationOperation::Edit->value,
        'related' => $task->getKey(),
    ]), ['name' => 'After'])->assertRedirect();

    expect($task->fresh()->name)->toBe('After')
        ->and($untouched->fresh()->name)->toBe('Untouched');
});

it('validates a related record against the manager form', function (): void {
    $this->post(RelationPanel::url('relations/form').'?'.http_build_query([
        'resource' => 'projects',
        'record' => $this->project->getKey(),
        'relation' => 'tasks',
        'operation' => RelationOperation::Create->value,
    ]), ['name' => ''])->assertSessionHasErrors('name');

    expect($this->project->tasks()->count())->toBe(0);
});

/*
 * Table state is per relation
 */

it('gives each relation its own slice of the query string', function (): void {
    foreach (range(1, 30) as $index) {
        $this->project->tasks()->create(['name' => sprintf('Task %02d', $index)]);
    }

    $response = $this->get(RelationPanel::url('projects/'.$this->project->getKey()).'?'.http_build_query([
        'relations' => ['tasks' => ['page' => 2, 'sort' => 'name', 'direction' => 'asc']],
    ]));

    $relations = $response->viewData('page')['props']['relations'];
    $tasks = collect($relations)->firstWhere('key', 'tasks');
    $labels = collect($relations)->firstWhere('key', 'labels');

    expect($tasks['pagination']['page'])->toBe(2)
        ->and($tasks['state']['sort'])->toBe('name')
        ->and($tasks['stateKey'])->toBe('relations.tasks')
        // The other table on the same page did not move.
        ->and($labels['pagination']['page'])->toBe(1);
});

it('ignores a sort column the relation table did not declare', function (): void {
    $this->project->tasks()->create(['name' => 'Only']);

    $response = $this->get(RelationPanel::url('projects/'.$this->project->getKey()).'?'.http_build_query([
        'relations' => ['tasks' => ['sort' => 'project_id']],
    ]));

    $tasks = collect($response->viewData('page')['props']['relations'])
        ->firstWhere('key', 'tasks');

    expect($tasks['state']['sort'])->toBeNull();
});

/*
 * Serialization
 */

it('serializes a relation without closures or class names', function (): void {
    $this->project->tasks()->create(['name' => 'Serialized']);

    $relations = relationProps($this->project)['relations'];

    $encoded = json_encode($relations);

    expect($encoded)->not->toContain('Tests\\\\Fixtures')
        ->and($encoded)->not->toContain('App\\\\Panel');
});

it('offers no attach on a relation that has no pivot to attach to', function (): void {
    $relations = relationProps($this->project)['relations'];
    $tasks = collect($relations)->firstWhere('key', 'tasks');
    $labels = collect($relations)->firstWhere('key', 'labels');

    expect(collect($tasks['headerActions'])->pluck('name')->all())->not->toContain('attach')
        ->and(collect($labels['headerActions'])->pluck('name')->all())->toContain('attach');
});

it('names the manager it was asked for and nothing else', function (): void {
    expect(ProjectRelationResource::relationManager('tasks'))->toBe(TasksRelationManager::class)
        ->and(ProjectRelationResource::relationManager('labels'))->toBe(LabelsRelationManager::class)
        ->and(ProjectRelationResource::relationManager('nope'))->toBeNull();
});

it('404s on a relation the resource never declared', function (): void {
    $this->get(RelationPanel::url('relations/form').'?'.http_build_query([
        'resource' => 'projects',
        'record' => $this->project->getKey(),
        'relation' => 'invented',
        'operation' => 'create',
    ]))->assertNotFound();
});

it('404s on an operation the server does not recognise', function (): void {
    $this->get(RelationPanel::url('relations/form').'?'.http_build_query([
        'resource' => 'projects',
        'record' => $this->project->getKey(),
        'relation' => 'tasks',
        'operation' => 'obliterate',
    ]))->assertNotFound();
});

it('hides a label relation the label policy refuses', function (): void {
    LabelPolicy::$viewable = false;

    $relations = relationProps($this->project)['relations'];

    expect(collect($relations)->pluck('key')->all())->toBe(['tasks']);
});
