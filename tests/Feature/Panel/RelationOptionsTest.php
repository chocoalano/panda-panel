<?php

declare(strict_types=1);

use App\Models\User;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Routing\PanelRouteRegistrar;
use PandaPanel\Support\RelationOperation;
use Tests\Fixtures\Panel\Relations\CollidingTaskResource;
use Tests\Fixtures\Panel\Relations\Label;
use Tests\Fixtures\Panel\Relations\Project;
use Tests\Fixtures\Panel\Relations\ProjectPolicy;
use Tests\Fixtures\Panel\Relations\ProjectRelationResource;
use Tests\Fixtures\Panel\Relations\RelationPanel;
use Tests\Fixtures\Panel\Relations\Task;
use Tests\Fixtures\Panel\Relations\TaskPolicy;

beforeEach(function (): void {
    RelationPanel::boot();
    RelationPanel::reset();

    $this->actingAs(User::factory()->admin()->create());

    $this->project = Project::query()->create(['name' => 'Apollo']);
});

function optionsUrl(array $query): string
{
    return RelationPanel::url('options').'?'.http_build_query($query);
}

/*
 * Searching a relation's attachable records
 */

it('searches beyond the page the form arrived with', function (): void {
    // More labels than the attach dialog's option limit, so the last one is
    // unreachable without the endpoint — a field that validated a key it
    // could never show would be a dead end.
    foreach (range(1, 60) as $index) {
        Label::query()->create(['name' => sprintf('Label %02d', $index)]);
    }

    $needle = Label::query()->create(['name' => 'Zebra']);

    $response = $this->getJson(optionsUrl([
        'resource' => 'projects',
        'record' => $this->project->getKey(),
        'relation' => 'labels',
        'operation' => RelationOperation::Attach->value,
        'field' => 'related',
        'search' => 'Zeb',
    ]));

    $response->assertOk();

    expect($response->json('options'))->toBe([
        ['value' => (string) $needle->getKey(), 'label' => 'Zebra'],
    ]);
});

it('never offers a record that is already in the relation', function (): void {
    $attached = Label::query()->create(['name' => 'Urgent']);
    $free = Label::query()->create(['name' => 'Urgently free']);

    $this->project->labels()->attach($attached->getKey());

    $response = $this->getJson(optionsUrl([
        'resource' => 'projects',
        'record' => $this->project->getKey(),
        'relation' => 'labels',
        'operation' => RelationOperation::Attach->value,
        'field' => 'related',
        'search' => 'Urgent',
    ]));

    // Both match the term; only the one that is not already joined is
    // offered, or attaching it would be refused the moment it was picked.
    expect(collect($response->json('options'))->pluck('value')->all())
        ->toBe([(string) $free->getKey()])
        ->not->toContain((string) $attached->getKey());
});

it('refuses options for an operation the user may not perform', function (): void {
    ProjectPolicy::$attachable = false;

    $this->getJson(optionsUrl([
        'resource' => 'projects',
        'record' => $this->project->getKey(),
        'relation' => 'labels',
        'operation' => RelationOperation::Attach->value,
        'field' => 'related',
    ]))->assertForbidden();
});

it('refuses options for a relation the user may not read', function (): void {
    TaskPolicy::$viewable = false;

    $this->getJson(optionsUrl([
        'resource' => 'projects',
        'record' => $this->project->getKey(),
        'relation' => 'tasks',
        'operation' => RelationOperation::Create->value,
        'field' => 'name',
    ]))->assertForbidden();
});

/*
 * Only fields the schema declares
 */

it('404s on a field the schema does not declare', function (): void {
    $this->getJson(optionsUrl([
        'resource' => 'projects',
        'page' => 'create',
        'field' => 'password',
    ]))->assertNotFound();
});

it('refuses a field that is not a select', function (): void {
    $this->getJson(optionsUrl([
        'resource' => 'projects',
        'page' => 'create',
        'field' => 'name',
    ]))->assertStatus(400);
});

it('404s on an unknown resource, relation, or operation', function (): void {
    $this->getJson(optionsUrl(['resource' => 'invented', 'field' => 'name']))
        ->assertNotFound();

    $this->getJson(optionsUrl([
        'resource' => 'projects',
        'record' => $this->project->getKey(),
        'relation' => 'invented',
        'operation' => 'create',
        'field' => 'name',
    ]))->assertNotFound();

    $this->getJson(optionsUrl([
        'resource' => 'projects',
        'record' => $this->project->getKey(),
        'relation' => 'tasks',
        'operation' => 'obliterate',
        'field' => 'name',
    ]))->assertNotFound();
});

it('sends the options endpoint with the relation form', function (): void {
    $response = $this->getJson(RelationPanel::url('relations/form').'?'.http_build_query([
        'resource' => 'projects',
        'record' => $this->project->getKey(),
        'relation' => 'labels',
        'operation' => RelationOperation::Attach->value,
    ]));

    // Built on the server and sent as data, so no panel URL is constructed
    // in Vue.
    expect($response->json('optionsUrl'))
        ->toContain('/relation-host/options')
        ->toContain('relation=labels');
});

it('sends the options endpoint with a resource form', function (): void {
    $response = $this->get(RelationPanel::url('projects/'.$this->project->getKey().'/edit'));

    expect($response->viewData('page')['props']['optionsUrl'])
        ->toContain('/relation-host/options')
        ->toContain('page=edit')
        ->toContain('record='.$this->project->getKey());
});

/*
 * Associate
 */

it('offers associate on a one-to-many and attach on a many-to-many, never both', function (): void {
    $relations = $this->get(RelationPanel::url('projects/'.$this->project->getKey()))
        ->viewData('page')['props']['relations'];

    $names = fn (string $key): array => collect($relations)
        ->firstWhere('key', $key)['headerActions'];

    expect(collect($names('tasks'))->pluck('name')->all())
        ->toContain('associate')
        ->not->toContain('attach')
        ->and(collect($names('labels'))->pluck('name')->all())
        ->toContain('attach')
        ->not->toContain('associate');
});

it('associates an existing child by writing its foreign key', function (): void {
    $orphan = Task::query()->create([
        'name' => 'Orphan',
        'project_id' => null,
    ]);

    $this->post(RelationPanel::url('relations/form').'?'.http_build_query([
        'resource' => 'projects',
        'record' => $this->project->getKey(),
        'relation' => 'tasks',
        'operation' => RelationOperation::Associate->value,
    ]), ['related' => (string) $orphan->getKey()])->assertRedirect();

    expect($orphan->fresh()->project_id)->toBe($this->project->getKey());
});

it('refuses an associate the owner policy does not allow', function (): void {
    $orphan = Task::query()->create([
        'name' => 'Orphan',
        'project_id' => null,
    ]);

    ProjectPolicy::$associable = false;

    $this->post(RelationPanel::url('relations/form').'?'.http_build_query([
        'resource' => 'projects',
        'record' => $this->project->getKey(),
        'relation' => 'tasks',
        'operation' => RelationOperation::Associate->value,
    ]), ['related' => (string) $orphan->getKey()])->assertForbidden();

    expect($orphan->fresh()->project_id)->toBeNull();
});

it('refuses an associate on a many-to-many, which has a pivot instead', function (): void {
    $label = Label::query()->create(['name' => 'Urgent']);

    $this->post(RelationPanel::url('relations/form').'?'.http_build_query([
        'resource' => 'projects',
        'record' => $this->project->getKey(),
        'relation' => 'labels',
        'operation' => RelationOperation::Associate->value,
    ]), ['related' => (string) $label->getKey()])->assertForbidden();
});

/*
 * Colliding paths
 */

it('refuses two resources claiming one path', function (): void {
    $manager = app(PanelManager::class);

    $panel = $manager->register(
        Panel::make('collision-host')
            ->path('collision-host')
            ->settings(false)
            ->resources([
                ProjectRelationResource::class,
                CollidingTaskResource::class,
            ]),
    );

    // `projects/{record}/tasks` and `projects/{parentRecord}/tasks` are the
    // same shape to the router: it would match the first and silently ignore
    // the second, leaving one of them unreachable.
    expect(fn () => app(PanelRouteRegistrar::class)->register($panel))
        ->toThrow(PanelRegistrationException::class, 'Only the first would ever match.');
});
