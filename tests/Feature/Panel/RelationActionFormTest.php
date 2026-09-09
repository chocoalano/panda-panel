<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PandaPanel\Support\FrontendContract;
use Tests\Fixtures\Panel\Relations\Project;
use Tests\Fixtures\Panel\Relations\RelationPanel;
use Tests\Fixtures\Panel\Relations\Task;
use Tests\Fixtures\Panel\Relations\TaskPolicy;
use Tests\Fixtures\Panel\Relations\TasksRelationManager;
use Tests\Fixtures\Panel\Tenancy\Document;
use Tests\Fixtures\Panel\Tenancy\Revision;
use Tests\Fixtures\Panel\Tenancy\TenancyPanel;
use Tests\Fixtures\Panel\Tenancy\TenantUser;
use Tests\Fixtures\Panel\Tenancy\Workspace;

/*
|--------------------------------------------------------------------------
| A relation manager's actions can carry forms
|--------------------------------------------------------------------------
|
| The contract was broken on both sides and neither half worked without the
| other.
|
| The frontend asked the panel's action-form endpoint for the schema. That
| endpoint resolves actions out of the *resource's* table, where a relation
| manager's actions have never been, so every one of them answered 404 and the
| dialog never opened.
|
| The backend ran relation actions as `$action->execute($related)` — no second
| argument. So even reaching the handler, it was reached with an empty array:
| a form that had been filled in submitted nothing at all.
|
| These are about the endpoint that closes both: it resolves the action inside
| the relation that declared it, authorizes it there, and passes the validated
| values through.
|
*/

beforeEach(function (): void {
    RelationPanel::boot();
    RelationPanel::reset();

    $this->actingAs(User::factory()->admin()->create());

    $this->project = Project::query()->create(['name' => 'Apollo']);
    $this->other = Project::query()->create(['name' => 'Gemini']);
    $this->task = $this->project->tasks()->create(['name' => 'Before']);

    TasksRelationManager::$lastRowData = [];
});

function actionFormUrl(array $query): string
{
    return RelationPanel::url('relations/action-form').'?'.http_build_query($query);
}

function renameContext(Project $project, Task $task): array
{
    return [
        'resource' => 'projects',
        'record' => $project->getKey(),
        'relation' => 'tasks',
        'action' => 'rename',
        'scope' => 'record',
        'related' => $task->getKey(),
    ];
}

/*
 * Form resolution
 */

it('describes the form an action on a relation table declared', function (): void {
    $response = $this->getJson(actionFormUrl(renameContext($this->project, $this->task)));

    $response->assertOk();

    $names = collect($response->json('form.schema'))->pluck('name')->all();

    expect($names)->toBe(['name', 'reason', 'evidence'])
        ->and($response->json('title'))->toBe('Rename');
});

it('sends every side endpoint the form needs, each carrying the action context', function (): void {
    $response = $this->getJson(actionFormUrl(renameContext($this->project, $this->task)));

    // All three, and all three naming this relation and this action: the
    // options and state endpoints used to be absent here entirely, which is
    // what made `live()` and dependent selects inert inside a relation
    // action's dialog.
    foreach (['optionsUrl', 'uploadUrl', 'formStateUrl', 'submitUrl'] as $key) {
        expect($response->json($key))
            ->toContain('relation=tasks')
            ->and($response->json($key))->toContain('record='.$this->project->getKey());
    }

    expect($response->json('optionsUrl'))->toContain('action=rename');
});

it('refuses to describe an action the relation never declared', function (): void {
    $this->getJson(actionFormUrl([
        ...renameContext($this->project, $this->task),
        'action' => 'nonsense',
    ]))->assertNotFound();
});

it('refuses to describe a form for an action that has none', function (): void {
    $this->getJson(actionFormUrl([
        ...renameContext($this->project, $this->task),
        'action' => 'delete',
    ]))->assertStatus(400);
});

/*
 * Data transport — the second half of the defect
 */

it('runs the action with what the form submitted', function (): void {
    $this->post(
        RelationPanel::url('relations/action-form').'?'.http_build_query(
            renameContext($this->project, $this->task),
        ),
        ['name' => 'After', 'reason' => 'Because'],
    )->assertRedirect();

    expect($this->task->fresh()->name)->toBe('After');
});

it('discards a key the action schema never declared', function (): void {
    $this->post(
        RelationPanel::url('relations/action-form').'?'.http_build_query(
            renameContext($this->project, $this->task),
        ),
        ['name' => 'After', 'reason' => 'Because', 'project_id' => $this->other->getKey()],
    )->assertRedirect();

    // The handler only writes `name`, and the schema only dehydrates what it
    // declared — so an extra key reaches neither.
    expect($this->task->fresh()->project_id)->toBe($this->project->getKey());
});

it('runs a bulk relation action with its form data, over the selection', function (): void {
    $second = $this->project->tasks()->create(['name' => 'Second']);

    $this->post(
        RelationPanel::url('relations/action-form').'?'.http_build_query([
            'resource' => 'projects',
            'record' => $this->project->getKey(),
            'relation' => 'tasks',
            'action' => 'bulkRename',
            'scope' => 'bulk',
        ]),
        ['name' => 'Renamed', 'records' => [$this->task->getKey(), $second->getKey()]],
    )->assertRedirect();

    expect($this->task->fresh()->name)->toBe('Renamed')
        ->and($second->fresh()->name)->toBe('Renamed');
});

/*
 * Validation
 */

it('refuses to run when a required field is empty, and writes nothing', function (): void {
    $this->post(
        RelationPanel::url('relations/action-form').'?'.http_build_query(
            renameContext($this->project, $this->task),
        ),
        ['name' => ''],
    )->assertSessionHasErrors(['name', 'reason']);

    expect($this->task->fresh()->name)->toBe('Before');
});

/*
 * Authorization — asked twice, because opening a dialog and performing an
 * operation are two permissions in time.
 */

it('refuses to describe the form to somebody who may not run the action', function (): void {
    TaskPolicy::$canRename = false;

    $this->getJson(actionFormUrl(renameContext($this->project, $this->task)))
        ->assertForbidden();
});

it('refuses a forged submit for an action the user may not run', function (): void {
    TaskPolicy::$canRename = false;

    $this->post(
        RelationPanel::url('relations/action-form').'?'.http_build_query(
            renameContext($this->project, $this->task),
        ),
        ['name' => 'After', 'reason' => 'Because'],
    )->assertForbidden();

    expect($this->task->fresh()->name)->toBe('Before');
});

it('refuses when the relation itself may not be read', function (): void {
    // Two questions, and this is the first: whether the relation may be
    // opened at all. Being allowed to run an action is no substitute — an
    // action on a manager the user cannot read is an action on something they
    // are not supposed to know exists.
    TaskPolicy::$viewable = false;

    $this->getJson(actionFormUrl(renameContext($this->project, $this->task)))
        ->assertForbidden();
});

/*
 * Scope — the related record is resolved through the relation, never globally
 */

it('resolves nothing for a related record belonging to another owner', function (): void {
    $theirs = $this->other->tasks()->create(['name' => 'Theirs']);

    $this->getJson(actionFormUrl([
        ...renameContext($this->project, $this->task),
        'related' => $theirs->getKey(),
    ]))->assertNotFound();

    $this->post(
        RelationPanel::url('relations/action-form').'?'.http_build_query([
            ...renameContext($this->project, $this->task),
            'related' => $theirs->getKey(),
        ]),
        ['name' => 'After', 'reason' => 'Because'],
    )->assertNotFound();

    expect($theirs->fresh()->name)->toBe('Theirs');
});

it('refuses a relation the resource never declared', function (): void {
    $this->getJson(actionFormUrl([
        ...renameContext($this->project, $this->task),
        'relation' => 'nonsense',
    ]))->assertNotFound();
});

it('refuses an owner record that is not there', function (): void {
    $this->getJson(actionFormUrl([
        ...renameContext($this->project, $this->task),
        'record' => 99999,
    ]))->assertNotFound();
});

/*
 * The plain action endpoint no longer swallows a form
 */

it('refuses to run a form-carrying action through the endpoint that carries no data', function (): void {
    $this->post(RelationPanel::url('relations/action'), [
        'resource' => 'projects',
        'record' => $this->project->getKey(),
        'relation' => 'tasks',
        'action' => 'rename',
        'related' => $this->task->getKey(),
    ])->assertStatus(400);

    // The point of refusing: running it here would have called the handler
    // with an empty array, which is a write nobody described.
    expect($this->task->fresh()->name)->toBe('Before');
});

it('still runs a relation action that has no form', function (): void {
    $this->post(RelationPanel::url('relations/action'), [
        'resource' => 'projects',
        'record' => $this->project->getKey(),
        'relation' => 'tasks',
        'action' => 'delete',
        'related' => $this->task->getKey(),
    ])->assertRedirect();

    expect(Task::withTrashed()->find($this->task->getKey())->trashed())->toBeTrue();
});

/*
 * The client is told where to fetch these from
 */

it('sends the action-form endpoint with the relation table', function (): void {
    $response = $this->get(RelationPanel::url('projects/'.$this->project->getKey()));

    $relations = $response->viewData('page')['props']['relations'];
    $tasks = collect($relations)->firstWhere('key', 'tasks');

    expect($tasks['endpoints']['actionForm'])->toContain('relations/action-form')
        ->and($tasks['endpoints']['actionForm'])->toContain('relation=tasks');
});

it('marks a relation action that carries a schema as a form action', function (): void {
    $response = $this->get(RelationPanel::url('projects/'.$this->project->getKey()));

    $relations = $response->viewData('page')['props']['relations'];
    $tasks = collect($relations)->firstWhere('key', 'tasks');
    $rename = collect($tasks['rows'][0]['actions'] ?? [])->firstWhere('name', 'rename');

    // `type: form` with no `formUrl` is exactly the shape the client used to
    // mishandle: it fell through to the plain action endpoint and ran
    // immediately, with no dialog and no values.
    expect($rename)->not->toBeNull()
        ->and($rename['type'])->toBe('form')
        ->and($rename['formUrl'])->toBeNull()
        ->and($rename['hasForm'])->toBeTrue();
});

/*
 * Uploads on this surface, authorized as the action rather than the resource
 */

it('stores a file for a field on a relation action form', function (): void {
    Storage::fake('public');

    $response = $this->post(
        RelationPanel::url('uploads').'?'.http_build_query(renameContext($this->project, $this->task)),
        ['field' => 'evidence', 'file' => UploadedFile::fake()->create('note.txt', 1)],
    );

    // Reachable at all only because the upload endpoint resolves the same
    // context the form does. Before, an upload URL naming a relation *action*
    // had no branch to land in.
    expect($response->status())->toBe(200)
        ->and($response->json('path'))->toStartWith('evidence/');
});

it('refuses a file larger than the field allows', function (): void {
    Storage::fake('public');

    // The field declares maxSize(64) — kilobytes — and the limit is applied to
    // the real file rather than to what the browser claimed before sending it.
    $this->post(
        RelationPanel::url('uploads').'?'.http_build_query(renameContext($this->project, $this->task)),
        ['field' => 'evidence', 'file' => UploadedFile::fake()->create('big.txt', 512)],
    )->assertStatus(302);

    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('refuses a field the action schema never declared', function (): void {
    Storage::fake('public');

    $this->post(
        RelationPanel::url('uploads').'?'.http_build_query(renameContext($this->project, $this->task)),
        ['field' => 'nonexistent', 'file' => UploadedFile::fake()->create('x.txt', 1)],
    )->assertNotFound();

    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('refuses a field that is not a file component', function (): void {
    Storage::fake('public');

    // `name` is on this schema and is a text input. Being on the schema is not
    // the same as being somewhere a file may be stored.
    $this->post(
        RelationPanel::url('uploads').'?'.http_build_query(renameContext($this->project, $this->task)),
        ['field' => 'name', 'file' => UploadedFile::fake()->create('x.txt', 1)],
    )->assertStatus(400);

    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('carries the uploaded reference through to the handler', function (): void {
    Storage::fake('public');

    // The whole lifecycle: upload answers with a path, the path goes back as
    // an ordinary form value, and the handler receives it dehydrated with the
    // rest. Proving the endpoint answered is not proving this.
    $path = $this->post(
        RelationPanel::url('uploads').'?'.http_build_query(renameContext($this->project, $this->task)),
        ['field' => 'evidence', 'file' => UploadedFile::fake()->create('note.txt', 1)],
    )->json('path');

    $this->post(
        RelationPanel::url('relations/action-form').'?'.http_build_query(
            renameContext($this->project, $this->task),
        ),
        ['name' => 'After', 'reason' => 'Because', 'evidence' => $path],
    )->assertRedirect();

    expect(TasksRelationManager::$lastRowData['evidence'])->toBe($path)
        ->and(Storage::disk('public')->exists($path))->toBeTrue();
});

it('refuses that upload to somebody who may not run the action', function (): void {
    Storage::fake('public');

    TaskPolicy::$canRename = false;

    $this->post(
        RelationPanel::url('uploads').'?'.http_build_query(renameContext($this->project, $this->task)),
        ['field' => 'evidence', 'file' => UploadedFile::fake()->create('note.txt', 1)],
    )->assertForbidden();

    // An action the user may not run must not be a way to put a file on a disk.
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('speaks a contract version the published frontend can be checked against', function (): void {
    expect(FrontendContract::expected())->toBeGreaterThanOrEqual(2);
});

/*
 * Success lifecycle — the same one a resource action has
 */

it('flashes the action\'s own success message and returns to the page', function (): void {
    $response = $this->post(
        RelationPanel::url('relations/action-form').'?'.http_build_query(
            renameContext($this->project, $this->task),
        ),
        ['name' => 'After', 'reason' => 'Because'],
    );

    // `back()` with a flash, which is what the relation table's page re-renders
    // from — so the new row and the notification arrive together and no
    // relation needs behaviour of its own to get them.
    $response->assertRedirect()->assertSessionHas('success');
});

/*
 * Validation round-trip — the modal has to survive being refused
 */

it('keeps the values that were valid when one of them was not', function (): void {
    $response = $this->from(RelationPanel::url('projects/'.$this->project->getKey()))->post(
        RelationPanel::url('relations/action-form').'?'.http_build_query(
            renameContext($this->project, $this->task),
        ),
        ['name' => 'Kept', 'reason' => ''],
    );

    // Back to where the dialog is open, with the error on the field that
    // caused it and the rest of the input still in the session. The renderer
    // holds its own values across this, so nothing the user typed is lost.
    $response->assertRedirect(RelationPanel::url('projects/'.$this->project->getKey()))
        ->assertSessionHasErrors(['reason'])
        ->assertSessionDoesntHaveErrors(['name'])
        ->assertSessionHasInput('name', 'Kept');
});

/*
 * Tenant isolation
 *
 * The one scope failure that is invisible without two tenants. A related
 * record from another owner is caught by the relation query whether or not
 * tenancy exists; this is about the owner lookup itself, where two tenants own
 * structurally identical rows and only a scoped query tells them apart. The
 * child carries no tenant key of its own, so its isolation is entirely
 * inherited — which is the arrangement a leak actually hides in.
 */

describe('tenant isolation', function (): void {
    beforeEach(function (): void {
        TenancyPanel::boot();
        TenancyPanel::reset();

        $this->acme = Workspace::query()->create(['name' => 'Acme']);
        $this->beta = Workspace::query()->create(['name' => 'Beta']);

        $this->acmeDoc = Document::query()->create([
            'workspace_id' => $this->acme->getKey(),
            'title' => 'Acme plan',
        ]);

        $this->betaDoc = Document::query()->create([
            'workspace_id' => $this->beta->getKey(),
            'title' => 'Beta secrets',
        ]);

        $this->betaRevision = Revision::query()->create([
            'document_id' => $this->betaDoc->getKey(),
            'note' => 'Beta note',
        ]);

        $this->tenantUser = TenantUser::query()->create([
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'password' => bcrypt('secret-secret'),
        ]);

        $this->tenantUser->forceFill(['email_verified_at' => now()])->save();

        // Ada is in Acme only.
        $this->tenantUser->workspaces()->attach($this->acme->getKey());

        $this->actingAs($this->tenantUser);
    });

    function tenantActionForm(int|string $document, int|string $revision, int|string $workspace): string
    {
        return TenancyPanel::url('relations/action-form').'?'.http_build_query([
            'resource' => 'documents',
            'record' => $document,
            'relation' => 'revisions',
            'action' => 'annotate',
            'scope' => 'record',
            'related' => $revision,
            'workspace' => $workspace,
        ]);
    }

    it('describes the form for a record inside the current tenant', function (): void {
        $revision = Revision::query()->create([
            'document_id' => $this->acmeDoc->getKey(),
            'note' => 'Acme note',
        ]);

        // The control. Without it, the refusals below would also pass for a
        // relation manager that simply never worked.
        $this->getJson(tenantActionForm(
            $this->acmeDoc->getKey(),
            $revision->getKey(),
            $this->acme->getKey(),
        ))->assertOk();
    });

    it('refuses to describe a form for another tenant\'s owner record', function (): void {
        // Ada is in Acme; the document is Beta's. The owner is loaded through
        // the resource's own query, which is tenant scoped, so it is not found
        // rather than found and refused.
        $this->getJson(tenantActionForm(
            $this->betaDoc->getKey(),
            $this->betaRevision->getKey(),
            $this->acme->getKey(),
        ))->assertNotFound();
    });

    it('refuses a forged submit against another tenant\'s record', function (): void {
        $this->post(
            tenantActionForm(
                $this->betaDoc->getKey(),
                $this->betaRevision->getKey(),
                $this->acme->getKey(),
            ),
            ['note' => 'Injected'],
        )->assertNotFound();

        expect($this->betaRevision->fresh()->note)->toBe('Beta note');
    });

    it('refuses to enter a tenant this user does not belong to', function (): void {
        // Naming Beta in the URL does not put Ada in Beta: the tenant is
        // authorized before anything is resolved inside it.
        $this->getJson(tenantActionForm(
            $this->betaDoc->getKey(),
            $this->betaRevision->getKey(),
            $this->beta->getKey(),
        ))->assertForbidden();
    });

    it('refuses a related record belonging to another tenant\'s owner', function (): void {
        // A real owner in Ada's tenant, a related key from outside it. The
        // manager's query starts from the owner's relation, so the key
        // resolves to nothing.
        $this->getJson(tenantActionForm(
            $this->acmeDoc->getKey(),
            $this->betaRevision->getKey(),
            $this->acme->getKey(),
        ))->assertNotFound();
    });
});
