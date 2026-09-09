<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Panel\Relations\Project;
use Tests\Fixtures\Panel\Relations\RelationPanel;
use Tests\Fixtures\Panel\Relations\Task;
use Tests\Fixtures\Panel\Relations\TaskPolicy;
use Tests\Fixtures\Panel\Relations\TasksRelationManager;

/*
|--------------------------------------------------------------------------
| Header actions on a relation manager
|--------------------------------------------------------------------------
|
| A relation manager's `table()` returns an ordinary `TableSchema`, so
| `->headerActions([...])` has always been callable on it. The list simply
| never reached the payload: `RelationTable::headerActions()` returned the
| three built-in operations — create, attach, associate — and nothing else.
|
| So a manager could declare a header action and watch nothing happen. It was
| not rendered, and had it been, no resolver would have found it: the relation
| endpoints knew a `record` scope and a `bulk` scope and no third one, and the
| frontend opened a dialog only for an action that already carried a
| server-built `formUrl`, which only the three built-ins do.
|
| A header action is about the relation rather than about a row, so it runs
| through `tableAction()` — the same handler a resource's table action uses —
| and never receives a related record.
|
*/

beforeEach(function (): void {
    RelationPanel::boot();
    RelationPanel::reset();

    TasksRelationManager::$lastHeaderData = [];
    TasksRelationManager::$archivedAll = false;

    $this->actingAs(User::factory()->admin()->create());

    $this->project = Project::query()->create(['name' => 'Apollo']);
    $this->other = Project::query()->create(['name' => 'Gemini']);
    $this->task = $this->project->tasks()->create(['name' => 'Before']);
});

function headerContext(Project $project, string $action = 'addSpecial'): array
{
    return [
        'resource' => 'projects',
        'record' => $project->getKey(),
        'relation' => 'tasks',
        'action' => $action,
        'scope' => 'table',
    ];
}

function headerFormUrl(array $query): string
{
    return RelationPanel::url('relations/action-form').'?'.http_build_query($query);
}

/*
 * Serialization — the list has to arrive before anything can resolve it
 */

it('sends a manager\'s own header actions alongside the built-in operations', function (): void {
    $response = $this->get(RelationPanel::url('projects/'.$this->project->getKey()));

    $relations = $response->viewData('page')['props']['relations'];
    $tasks = collect($relations)->firstWhere('key', 'tasks');
    $names = collect($tasks['headerActions'])->pluck('name')->all();

    // The three operations still first: they are what the relation *is*, and
    // a manager's own actions read as additions to them.
    expect($names)->toContain('create')
        ->and($names)->toContain('addSpecial')
        ->and($names)->toContain('archiveAll');
});

it('marks a header action that carries a schema as a form action', function (): void {
    $response = $this->get(RelationPanel::url('projects/'.$this->project->getKey()));

    $relations = $response->viewData('page')['props']['relations'];
    $tasks = collect($relations)->firstWhere('key', 'tasks');
    $special = collect($tasks['headerActions'])->firstWhere('name', 'addSpecial');

    // `type: form` with a null `formUrl` — the shape the client used to drop
    // on the floor, because it opened a dialog only for a server-built URL.
    expect($special['type'])->toBe('form')
        ->and($special['formUrl'])->toBeNull()
        ->and($special['hasForm'])->toBeTrue();
});

it('omits a header action the user may not run', function (): void {
    TaskPolicy::$canAddSpecial = false;

    $response = $this->get(RelationPanel::url('projects/'.$this->project->getKey()));

    $relations = $response->viewData('page')['props']['relations'];
    $tasks = collect($relations)->firstWhere('key', 'tasks');

    expect(collect($tasks['headerActions'])->pluck('name')->all())
        ->not->toContain('addSpecial');
});

/*
 * T2 — the form resolves
 */

it('describes a header action\'s form, with no related record', function (): void {
    $response = $this->getJson(headerFormUrl(headerContext($this->project)));

    $response->assertOk();

    expect(collect($response->json('form.schema'))->pluck('name')->all())
        ->toBe(['name', 'priority'])
        ->and($response->json('title'))->toBe('Add special');
});

it('sends the header form its own side endpoints', function (): void {
    $response = $this->getJson(headerFormUrl(headerContext($this->project)));

    foreach (['optionsUrl', 'uploadUrl', 'formStateUrl', 'submitUrl'] as $key) {
        expect($response->json($key))
            ->toContain('scope=table')
            ->and($response->json($key))->toContain('action=addSpecial');
    }
});

it('ignores a related key handed to a header action', function (): void {
    // A header action has no row. Accepting one would let a request smuggle a
    // record into a context that never resolves one.
    $this->getJson(headerFormUrl([
        ...headerContext($this->project),
        'related' => 999999,
    ]))->assertOk();
});

/*
 * T4 — the data reaches the handler
 */

it('runs a header action with what its form submitted', function (): void {
    $this->post(
        headerFormUrl(headerContext($this->project)),
        ['name' => 'Special', 'priority' => 3],
    )->assertRedirect()->assertSessionHas('success');

    expect(TasksRelationManager::$lastHeaderData)
        ->toBe(['name' => 'Special', 'priority' => 3]);
});

it('discards a key the header schema never declared', function (): void {
    $this->post(
        headerFormUrl(headerContext($this->project)),
        ['name' => 'Special', 'priority' => 3, 'smuggled' => 'nope'],
    )->assertRedirect();

    expect(TasksRelationManager::$lastHeaderData)->not->toHaveKey('smuggled');
});

/*
 * T5 — validation
 */

it('refuses a header action whose required field is empty', function (): void {
    $this->post(
        headerFormUrl(headerContext($this->project)),
        ['name' => ''],
    )->assertSessionHasErrors(['name', 'priority']);

    expect(TasksRelationManager::$lastHeaderData)->toBe([]);
});

/*
 * T9 / T10 — authorization, on both endpoints
 */

it('refuses to describe a header form to somebody who may not run it', function (): void {
    TaskPolicy::$canAddSpecial = false;

    $this->getJson(headerFormUrl(headerContext($this->project)))->assertForbidden();
});

it('refuses a forged header submit', function (): void {
    TaskPolicy::$canAddSpecial = false;

    $this->post(
        headerFormUrl(headerContext($this->project)),
        ['name' => 'Special', 'priority' => 3],
    )->assertForbidden();

    expect(TasksRelationManager::$lastHeaderData)->toBe([]);
});

/*
 * T6 / T7 — unknown action, wrong relation
 */

it('refuses a header action the manager never declared', function (): void {
    $this->getJson(headerFormUrl([
        ...headerContext($this->project),
        'action' => 'nonsense',
    ]))->assertNotFound();
});

it('refuses a row action addressed as a header action', function (): void {
    // `rename` is a record action. The scope decides which whitelist the name
    // is looked up in, so it is not found here rather than found and run
    // without the record it needs.
    $this->getJson(headerFormUrl([
        ...headerContext($this->project),
        'action' => 'rename',
    ]))->assertNotFound();
});

it('refuses a header action through a relation that does not declare it', function (): void {
    $this->getJson(headerFormUrl([
        ...headerContext($this->project),
        'relation' => 'labels',
    ]))->assertNotFound();
});

/*
 * T19 — the form-less header action still runs
 */

it('runs a header action that carries no form', function (): void {
    $this->post(RelationPanel::url('relations/action'), [
        'resource' => 'projects',
        'record' => $this->project->getKey(),
        'relation' => 'tasks',
        'action' => 'archiveAll',
        'scope' => 'table',
    ])->assertRedirect();

    expect(TasksRelationManager::$archivedAll)->toBeTrue();
});

it('refuses to run a header action with a form through the form-less endpoint', function (): void {
    $this->post(RelationPanel::url('relations/action'), [
        'resource' => 'projects',
        'record' => $this->project->getKey(),
        'relation' => 'tasks',
        'action' => 'addSpecial',
        'scope' => 'table',
    ])->assertStatus(400);

    expect(TasksRelationManager::$lastHeaderData)->toBe([]);
});

it('still requires a related record for a row action', function (): void {
    // The `related` rule moved out of the request validator so the header
    // scope could stop demanding one. A row action must still get one.
    $this->post(RelationPanel::url('relations/action'), [
        'resource' => 'projects',
        'record' => $this->project->getKey(),
        'relation' => 'tasks',
        'action' => 'delete',
    ])->assertStatus(422);

    expect(Task::withTrashed()->find($this->task->getKey())->trashed())->toBeFalse();
});

/*
 * File upload on a header form — the field lives on the row action's schema,
 * so this proves the upload resolver follows the scope rather than assuming.
 */

it('refuses a file field that is not on the header action\'s schema', function (): void {
    Storage::fake('public');

    $this->post(
        RelationPanel::url('uploads').'?'.http_build_query(headerContext($this->project)),
        ['field' => 'evidence', 'file' => UploadedFile::fake()->create('x.txt', 1)],
    )->assertNotFound();

    expect(Storage::disk('public')->allFiles())->toBe([]);
});
