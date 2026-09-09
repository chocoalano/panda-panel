<?php

declare(strict_types=1);

use App\Models\User;
use Tests\Fixtures\Panel\Forms\FormFixturePanel;
use Tests\Fixtures\Panel\Forms\FormFixtureResource;
use Tests\Fixtures\Panel\Relations\Project;
use Tests\Fixtures\Panel\Relations\ProjectPolicy;
use Tests\Fixtures\Panel\Relations\RelationPanel;
use Tests\Fixtures\Panel\Relations\TaskPolicy;
use Tests\Fixtures\Panel\Relations\TasksRelationManager;
use Tests\Fixtures\Panel\Tenancy\Document;
use Tests\Fixtures\Panel\Tenancy\Revision;
use Tests\Fixtures\Panel\Tenancy\TenancyPanel;
use Tests\Fixtures\Panel\Tenancy\TenantUser;
use Tests\Fixtures\Panel\Tenancy\Workspace;

/*
|--------------------------------------------------------------------------
| `live()` as one contract, on every surface that renders a form
|--------------------------------------------------------------------------
|
| A field marked `live()` asks the server to rebuild the schema against what
| has been typed. The lifecycle behind that was mature on a resource's create
| and edit pages and absent everywhere else — an action's form and a relation's
| form went through the same renderer, declared the same `live()`, and had no
| URL to ask. The field worked or did nothing depending on which dialog it
| happened to be rendered in, which is the kind of inconsistency that gets
| worked around once per feature.
|
| The five surfaces now resolve through one context (`FormContext`) and one
| endpoint. These tests are about that being true of each of them, and about
| the three properties a rebuild must never lose: it writes nothing, it runs no
| handler, and it keeps what the user has typed.
|
*/

function liveUrl(array $query): string
{
    return route('panel.'.FormFixturePanel::ID.'.form-state', $query, absolute: false);
}

/**
 * @return array<string, mixed>|null
 */
function fieldNamed(array $response, string $name): ?array
{
    return collect($response['form']['schema'])->firstWhere('name', $name);
}

describe('resource surfaces', function (): void {
    beforeEach(function (): void {
        FormFixturePanel::boot();
        FormFixturePanel::reset();

        FormFixtureResource::$lastData = [];

        $this->actingAs(User::factory()->create());
    });

    /*
     * T1 / T2 — create and edit
     */

    it('rebuilds a create form when a live field changes', function (): void {
        $response = $this->postJson(
            liveUrl(['resource' => 'form-fixtures', 'page' => 'create']),
            ['state' => ['name' => 'Apollo'], 'changed' => 'kind'],
        );

        $response->assertOk();

        expect(fieldNamed($response->json(), 'name')['value'])->toBe('Apollo');
    });

    it('rebuilds an edit form against the record it names', function (): void {
        $project = Project::query()->create(['name' => 'Apollo']);

        $response = $this->postJson(
            liveUrl([
                'resource' => 'form-fixtures',
                'page' => 'edit',
                'record' => (string) $project->getKey(),
            ]),
            ['state' => ['name' => 'Apollo'], 'changed' => 'kind'],
        );

        $response->assertOk();
    });

    /*
     * T3 — the unsaved half of the form is the point
     */

    it('keeps an unsaved sibling value across the rebuild', function (): void {
        $project = Project::query()->create(['name' => 'Saved name']);

        $response = $this->postJson(
            liveUrl([
                'resource' => 'form-fixtures',
                'page' => 'edit',
                'record' => (string) $project->getKey(),
            ]),
            [
                // Typed but not submitted. Rebuilding from the model would
                // hand back "Saved name" and silently discard the edit.
                'state' => ['name' => 'Draft name', 'employment_type' => 'contract'],
                'changed' => 'employment_type',
            ],
        );

        expect(fieldNamed($response->json(), 'name')['value'])->toBe('Draft name');
    });

    /*
     * T16 — visibility decided on the server, from the state
     */

    it('adds a field the new state makes relevant', function (): void {
        $before = $this->postJson(
            liveUrl(['resource' => 'form-fixtures', 'page' => 'create']),
            ['state' => ['employment_type' => 'permanent'], 'changed' => 'employment_type'],
        );

        $after = $this->postJson(
            liveUrl(['resource' => 'form-fixtures', 'page' => 'create']),
            ['state' => ['employment_type' => 'contract'], 'changed' => 'employment_type'],
        );

        // A rule a client-side comparison could also express, standing in for
        // the ones it cannot — the point is that the server decides and the
        // schema that comes back differs.
        expect(fieldNamed($before->json(), 'contract_end_date'))->toBeNull()
            ->and(fieldNamed($after->json(), 'contract_end_date'))->not->toBeNull();
    });

    /*
     * T17 — options recomputed eagerly, no async endpoint involved
     */

    it('recomputes a dependent field\'s options in the rebuilt schema', function (): void {
        $response = $this->postJson(
            liveUrl(['resource' => 'form-fixtures', 'page' => 'create']),
            ['state' => ['parent' => 'gudang'], 'changed' => 'parent'],
        );

        expect(collect(fieldNamed($response->json(), 'child')['options'])->pluck('value')->all())
            ->toBe(['picker']);
    });

    /*
     * T18 — two live fields in sequence
     */

    it('answers two sequential live changes consistently', function (): void {
        $first = $this->postJson(
            liveUrl(['resource' => 'form-fixtures', 'page' => 'create']),
            ['state' => ['parent' => 'produksi', 'employment_type' => 'contract'], 'changed' => 'parent'],
        );

        $second = $this->postJson(
            liveUrl(['resource' => 'form-fixtures', 'page' => 'create']),
            ['state' => ['parent' => 'gudang', 'employment_type' => 'contract'], 'changed' => 'employment_type'],
        );

        // Each request is answered from the state it was given. The first
        // does not leak into the second, and the field the first change was
        // about is still resolved from the second's state.
        expect(collect(fieldNamed($first->json(), 'child')['options'])->pluck('value')->all())
            ->toBe(['welder', 'operator'])
            ->and(collect(fieldNamed($second->json(), 'child')['options'])->pluck('value')->all())
            ->toBe(['picker'])
            ->and(fieldNamed($second->json(), 'contract_end_date'))->not->toBeNull();
    });

    /*
     * T4 / T6 — a rebuild is a read
     */

    it('writes nothing and runs no action handler', function (): void {
        $project = Project::query()->create(['name' => 'Apollo']);

        $this->postJson(
            liveUrl([
                'resource' => 'form-fixtures',
                'action' => 'categorise',
                'scope' => 'table',
            ]),
            ['state' => ['group' => 'b'], 'changed' => 'group'],
        )->assertOk();

        expect(FormFixtureResource::$lastData)->toBe([])
            ->and($project->fresh()?->getAttribute('name'))->toBe('Apollo');
    });

    /*
     * T5 — a resource action's own form
     */

    it('rebuilds a resource action form', function (): void {
        $response = $this->postJson(
            liveUrl([
                'resource' => 'form-fixtures',
                'action' => 'categorise',
                'scope' => 'table',
            ]),
            ['state' => ['group' => 'b'], 'changed' => 'group'],
        );

        $response->assertOk();

        expect(collect(fieldNamed($response->json(), 'member')['options'])->pluck('value')->all())
            ->toBe(['b1', 'b2']);
    });

    /*
     * T14 / T19
     */

    it('refuses a rebuild for an action the resource never declared', function (): void {
        $this->postJson(
            liveUrl(['resource' => 'form-fixtures', 'action' => 'nonsense', 'scope' => 'table']),
            ['state' => [], 'changed' => 'group'],
        )->assertNotFound();
    });

    it('runs no hook for a field that never declared itself live', function (): void {
        FormFixtureResource::$lastGet = null;

        $this->postJson(
            liveUrl(['resource' => 'form-fixtures', 'page' => 'create']),
            ['state' => ['name' => 'Apollo'], 'changed' => 'name'],
        )->assertOk();

        expect(FormFixtureResource::$lastGet)->toBeNull();
    });

    /*
     * T11 — authorization is asked again, every time
     */

    it('refuses a rebuild for a form the user may not open', function (): void {
        ProjectPolicy::$creatable = false;

        $this->postJson(
            liveUrl(['resource' => 'form-fixtures', 'page' => 'create']),
            ['state' => [], 'changed' => 'kind'],
        )->assertForbidden();
    });
});

describe('relation surfaces', function (): void {
    beforeEach(function (): void {
        RelationPanel::boot();
        RelationPanel::reset();

        TasksRelationManager::$lastHeaderData = [];
        TasksRelationManager::$lastRowData = [];

        $this->actingAs(User::factory()->admin()->create());

        $this->project = Project::query()->create(['name' => 'Apollo']);
        $this->other = Project::query()->create(['name' => 'Gemini']);
        $this->task = $this->project->tasks()->create(['name' => 'One']);
    });

    function relationLiveUrl(array $query): string
    {
        return RelationPanel::url('form-state').'?'.http_build_query($query);
    }

    function rowActionQuery(Project $project, int|string $task): array
    {
        return [
            'resource' => 'projects',
            'record' => $project->getKey(),
            'relation' => 'tasks',
            'action' => 'setStatus',
            'scope' => 'record',
            'related' => $task,
        ];
    }

    /*
     * T7 — relation record action
     */

    it('rebuilds a relation record action form', function (): void {
        $response = $this->postJson(
            relationLiveUrl(rowActionQuery($this->project, $this->task->getKey())),
            ['state' => ['kind' => 'closed'], 'changed' => 'kind'],
        );

        $response->assertOk();

        expect(collect(fieldNamed($response->json(), 'detail')['options'])->pluck('value')->all())
            ->toBe(['done', 'cancelled']);
    });

    /*
     * T8 — relation header action, the surface that had no resolver at all
     */

    it('rebuilds a relation header action form', function (): void {
        $response = $this->postJson(
            relationLiveUrl([
                'resource' => 'projects',
                'record' => $this->project->getKey(),
                'relation' => 'tasks',
                'action' => 'addSpecial',
                'scope' => 'table',
            ]),
            ['state' => ['name' => 'Draft'], 'changed' => 'name'],
        );

        $response->assertOk();

        // No related record anywhere in the context, and the schema still
        // resolves — a header action is about the relation, not a row.
        expect(fieldNamed($response->json(), 'name')['value'])->toBe('Draft')
            ->and(fieldNamed($response->json(), 'priority'))->not->toBeNull();
    });

    /*
     * T10 — the relation's own create/edit form
     */

    it('rebuilds a relation create form', function (): void {
        $this->postJson(
            relationLiveUrl([
                'resource' => 'projects',
                'record' => $this->project->getKey(),
                'relation' => 'tasks',
                'operation' => 'create',
            ]),
            ['state' => ['name' => 'Draft'], 'changed' => 'name'],
        )->assertOk();
    });

    it('rebuilds a relation edit form against the related record', function (): void {
        $this->postJson(
            relationLiveUrl([
                'resource' => 'projects',
                'record' => $this->project->getKey(),
                'relation' => 'tasks',
                'operation' => 'edit',
                'related' => $this->task->getKey(),
            ]),
            ['state' => ['name' => 'Draft'], 'changed' => 'name'],
        )->assertOk();
    });

    /*
     * T9 — still a read
     */

    it('runs no relation action handler while rebuilding', function (): void {
        $this->postJson(
            relationLiveUrl(rowActionQuery($this->project, $this->task->getKey())),
            ['state' => ['kind' => 'closed', 'detail' => 'done'], 'changed' => 'kind'],
        )->assertOk();

        expect($this->task->fresh()->status)->toBeNull()
            ->and(TasksRelationManager::$lastRowData)->toBe([]);
    });

    /*
     * T12 / T13 — the resolver is the same one the form uses
     */

    it('refuses a rebuild for a relation action the user may not run', function (): void {
        TaskPolicy::$canRename = false;

        $this->postJson(
            relationLiveUrl([
                ...rowActionQuery($this->project, $this->task->getKey()),
                'action' => 'rename',
            ]),
            ['state' => [], 'changed' => 'name'],
        )->assertForbidden();
    });

    it('refuses a rebuild naming a related record from another owner', function (): void {
        $theirs = $this->other->tasks()->create(['name' => 'Theirs']);

        $this->postJson(
            relationLiveUrl(rowActionQuery($this->project, $theirs->getKey())),
            ['state' => [], 'changed' => 'kind'],
        )->assertNotFound();
    });

    it('refuses a rebuild for a relation the resource never declared', function (): void {
        $this->postJson(
            relationLiveUrl([
                ...rowActionQuery($this->project, $this->task->getKey()),
                'relation' => 'nonsense',
            ]),
            ['state' => [], 'changed' => 'kind'],
        )->assertNotFound();
    });
});

/*
 * T15 — the rebuild cannot reach across a tenant boundary
 */

describe('tenant isolation', function (): void {
    beforeEach(function (): void {
        TenancyPanel::boot();
        TenancyPanel::reset();

        $this->acme = Workspace::query()->create(['name' => 'Acme']);
        $this->beta = Workspace::query()->create(['name' => 'Beta']);

        $this->betaDoc = Document::query()->create([
            'workspace_id' => $this->beta->getKey(),
            'title' => 'Beta secrets',
        ]);

        $this->betaRevision = Revision::query()->create([
            'document_id' => $this->betaDoc->getKey(),
            'note' => 'Beta note',
        ]);

        $this->user = TenantUser::query()->create([
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'password' => bcrypt('secret-secret'),
        ]);

        $this->user->forceFill(['email_verified_at' => now()])->save();
        $this->user->workspaces()->attach($this->acme->getKey());

        $this->actingAs($this->user);
    });

    it('cannot rebuild a form for another tenant\'s record', function (): void {
        $this->postJson(
            TenancyPanel::url('form-state').'?'.http_build_query([
                'resource' => 'documents',
                'record' => $this->betaDoc->getKey(),
                'relation' => 'revisions',
                'action' => 'annotate',
                'scope' => 'record',
                'related' => $this->betaRevision->getKey(),
                'workspace' => $this->acme->getKey(),
            ]),
            ['state' => [], 'changed' => 'note'],
        )->assertNotFound();
    });
});
