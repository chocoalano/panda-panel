<?php

declare(strict_types=1);

use App\Models\User;
use PandaPanel\Core\PanelManager;
use PandaPanel\Support\ParentRecord;
use Tests\Fixtures\Panel\Relations\NestedTaskResource;
use Tests\Fixtures\Panel\Relations\Project;
use Tests\Fixtures\Panel\Relations\ProjectRelationResource;
use Tests\Fixtures\Panel\Relations\RelationPanel;

beforeEach(function (): void {
    RelationPanel::boot();
    RelationPanel::reset();

    $this->actingAs(User::factory()->admin()->create());

    $this->project = Project::query()->create(['name' => 'Apollo']);
    $this->other = Project::query()->create(['name' => 'Gemini']);
});

/*
 * Routing
 */

it('registers every page beneath the parent record', function (): void {
    expect(route('panel.relation-host.resources.nested-tasks.index', ['parentRecord' => 7], absolute: false))
        ->toBe('/relation-host/projects/7/nested-tasks');
});

it('has no index of its own outside a parent', function (): void {
    $this->get(RelationPanel::url('nested-tasks'))->assertNotFound();
});

/*
 * The parent is the scope
 */

it('lists only the parent record\'s children', function (): void {
    $this->project->tasks()->create(['name' => 'Ours']);
    $this->other->tasks()->create(['name' => 'Theirs']);

    $response = $this->get(RelationPanel::url('projects/'.$this->project->getKey().'/nested-tasks'));

    $response->assertOk();

    $rows = $response->viewData('page')['props']['rows'];

    expect(collect($rows)->pluck('cells.name')->all())->toBe(['Ours']);
});

it('404s on a child belonging to another parent', function (): void {
    $theirs = $this->other->tasks()->create(['name' => 'Theirs']);

    $this->get(RelationPanel::url(
        'projects/'.$this->project->getKey().'/nested-tasks/'.$theirs->getKey().'/edit',
    ))->assertNotFound();
});

it('404s on a parent that does not exist', function (): void {
    $this->get(RelationPanel::url('projects/99999/nested-tasks'))->assertNotFound();
});

it('scopes the query without the resource declaring one', function (): void {
    $mine = $this->project->tasks()->create(['name' => 'Ours']);
    $this->other->tasks()->create(['name' => 'Theirs']);

    ParentRecord::bind($this->project);
    app(PanelManager::class)->setCurrentPanel(panel(RelationPanel::ID));

    expect(NestedTaskResource::query()->pluck('id')->all())->toBe([$mine->getKey()]);
});

/*
 * URLs, navigation, and breadcrumbs
 */

it('builds its URLs with the request\'s own parent', function (): void {
    ParentRecord::bind($this->project);
    app(PanelManager::class)->setCurrentPanel(panel(RelationPanel::ID));

    expect(NestedTaskResource::url())
        ->toBe('/relation-host/projects/'.$this->project->getKey().'/nested-tasks');
});

it('accepts an explicit parent for a link to another one', function (): void {
    ParentRecord::bind($this->project);
    app(PanelManager::class)->setCurrentPanel(panel(RelationPanel::ID));

    expect(NestedTaskResource::url(parent: $this->other))
        ->toBe('/relation-host/projects/'.$this->other->getKey().'/nested-tasks');
});

it('is absent from the sidebar, because there is no all-children to open', function (): void {
    $panel = panel(RelationPanel::ID);

    expect(NestedTaskResource::navigationItem($panel))->toBeNull()
        ->and(ProjectRelationResource::navigationItem($panel))->not->toBeNull();
});

it('puts the parent in the breadcrumb trail', function (): void {
    $response = $this->get(RelationPanel::url('projects/'.$this->project->getKey().'/nested-tasks'));

    $labels = collect($response->viewData('page')['props']['page']['breadcrumbs'])
        ->pluck('label')
        ->all();

    expect($labels)->toBe(['Dashboard', 'Projects', 'Apollo', 'Tasks']);
});

it('sends the parent key so an action can be scoped', function (): void {
    $response = $this->get(RelationPanel::url('projects/'.$this->project->getKey().'/nested-tasks'));

    $resource = $response->viewData('page')['props']['resource'];

    expect($resource['parentKey'])->toBe($this->project->getKey());
});

/*
 * Writes
 */

it('updates a child under its own parent', function (): void {
    $task = $this->project->tasks()->create(['name' => 'Before']);

    $this->put(
        RelationPanel::url('projects/'.$this->project->getKey().'/nested-tasks/'.$task->getKey().'/edit'),
        ['name' => 'After'],
    )->assertRedirect();

    expect($task->fresh()->name)->toBe('After');
});

it('refuses to update a child through the wrong parent', function (): void {
    $theirs = $this->other->tasks()->create(['name' => 'Theirs']);

    $this->put(
        RelationPanel::url('projects/'.$this->project->getKey().'/nested-tasks/'.$theirs->getKey().'/edit'),
        ['name' => 'Hijacked'],
    )->assertNotFound();

    expect($theirs->fresh()->name)->toBe('Theirs');
});

it('scopes an action posted for a nested resource to the parent it names', function (): void {
    $theirs = $this->other->tasks()->create(['name' => 'Theirs']);

    // The action endpoint carries no parent segment, so the parent travels in
    // the payload — and is resolved and authorized exactly as the route
    // middleware would.
    $this->post(RelationPanel::url('actions/record'), [
        'resource' => 'nested-tasks',
        'action' => 'delete',
        'record' => $theirs->getKey(),
        'parent' => $this->project->getKey(),
    ])->assertNotFound();
});

it('refuses an action on a nested resource with no parent named', function (): void {
    $task = $this->project->tasks()->create(['name' => 'Ours']);

    $this->post(RelationPanel::url('actions/record'), [
        'resource' => 'nested-tasks',
        'action' => 'delete',
        'record' => $task->getKey(),
    ])->assertStatus(422);
});
