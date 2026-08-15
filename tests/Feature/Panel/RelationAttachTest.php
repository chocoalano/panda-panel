<?php

declare(strict_types=1);

use App\Models\User;
use PandaPanel\Support\RelationOperation;
use PandaPanel\Tables\Filters\TrashedFilter;
use Tests\Fixtures\Panel\Relations\Label;
use Tests\Fixtures\Panel\Relations\Project;
use Tests\Fixtures\Panel\Relations\ProjectPolicy;
use Tests\Fixtures\Panel\Relations\RelationPanel;
use Tests\Fixtures\Panel\Relations\Task;
use Tests\Fixtures\Panel\Relations\TaskPolicy;

beforeEach(function (): void {
    RelationPanel::boot();
    RelationPanel::reset();

    $this->actingAs(User::factory()->admin()->create());

    $this->project = Project::query()->create(['name' => 'Apollo']);
    $this->urgent = Label::query()->create(['name' => 'Urgent']);
    $this->later = Label::query()->create(['name' => 'Later']);
});

function relationUrl(array $context): string
{
    return RelationPanel::url('relations/form').'?'.http_build_query($context);
}

/**
 * @return array<string, mixed>
 */
function labelsRelation(Project $project): array
{
    $props = test()->get(RelationPanel::url('projects/'.$project->getKey()))
        ->viewData('page')['props'];

    return collect($props['relations'])->firstWhere('key', 'labels');
}

/*
 * Attach
 */

it('attaches an existing record with its pivot attributes', function (): void {
    $this->post(relationUrl([
        'resource' => 'projects',
        'record' => $this->project->getKey(),
        'relation' => 'labels',
        'operation' => RelationOperation::Attach->value,
    ]), [
        'related' => (string) $this->urgent->getKey(),
        'pivot' => ['role' => 'primary'],
    ])->assertRedirect();

    $attached = $this->project->labels()->first();

    expect($attached?->getKey())->toBe($this->urgent->getKey())
        ->and($attached?->pivot->role)->toBe('primary');
});

it('discards a pivot column the manager never declared', function (): void {
    $this->post(relationUrl([
        'resource' => 'projects',
        'record' => $this->project->getKey(),
        'relation' => 'labels',
        'operation' => RelationOperation::Attach->value,
    ]), [
        'related' => (string) $this->urgent->getKey(),
        'pivot' => ['role' => 'primary', 'smuggled' => 'value'],
    ])->assertRedirect();

    $row = DB::table('fixture_label_project')->first();

    expect($row->role)->toBe('primary')
        ->and((array) $row)->not->toHaveKey('smuggled');
});

it('refuses to attach a record that is already in the relation', function (): void {
    $this->project->labels()->attach($this->urgent->getKey());

    $this->post(relationUrl([
        'resource' => 'projects',
        'record' => $this->project->getKey(),
        'relation' => 'labels',
        'operation' => RelationOperation::Attach->value,
    ]), ['related' => (string) $this->urgent->getKey()])
        ->assertStatus(422);

    expect($this->project->labels()->count())->toBe(1);
});

it('refuses to attach a record that does not exist', function (): void {
    $this->post(relationUrl([
        'resource' => 'projects',
        'record' => $this->project->getKey(),
        'relation' => 'labels',
        'operation' => RelationOperation::Attach->value,
    ]), ['related' => '99999'])->assertSessionHasErrors('related');

    expect($this->project->labels()->count())->toBe(0);
});

it('refuses an attach the owner policy does not allow', function (): void {
    ProjectPolicy::$attachable = false;

    $this->post(relationUrl([
        'resource' => 'projects',
        'record' => $this->project->getKey(),
        'relation' => 'labels',
        'operation' => RelationOperation::Attach->value,
    ]), ['related' => (string) $this->urgent->getKey()])->assertForbidden();

    expect($this->project->labels()->count())->toBe(0);
});

it('offers only the records not already in the relation', function (): void {
    $this->project->labels()->attach($this->urgent->getKey());

    $response = $this->getJson(relationUrl([
        'resource' => 'projects',
        'record' => $this->project->getKey(),
        'relation' => 'labels',
        'operation' => RelationOperation::Attach->value,
    ]));

    $response->assertOk();

    $select = collect($response->json('form.schema'))->firstWhere('name', 'related');

    expect(collect($select['options'])->pluck('label')->all())->toBe(['Later']);
});

it('refuses an attach on a relation with no pivot', function (): void {
    $this->post(relationUrl([
        'resource' => 'projects',
        'record' => $this->project->getKey(),
        'relation' => 'tasks',
        'operation' => RelationOperation::Attach->value,
    ]), ['related' => '1'])->assertForbidden();
});

/*
 * Detach
 */

it('detaches without deleting either record', function (): void {
    $this->project->labels()->attach($this->urgent->getKey());

    $this->post(RelationPanel::url('relations/action'), [
        'resource' => 'projects',
        'record' => $this->project->getKey(),
        'relation' => 'labels',
        'action' => 'detach',
        'related' => $this->urgent->getKey(),
    ])->assertRedirect();

    expect($this->project->labels()->count())->toBe(0)
        ->and(Label::query()->find($this->urgent->getKey()))->not->toBeNull();
});

it('refuses a detach the owner policy does not allow', function (): void {
    $this->project->labels()->attach($this->urgent->getKey());

    ProjectPolicy::$detachable = false;

    $this->post(RelationPanel::url('relations/action'), [
        'resource' => 'projects',
        'record' => $this->project->getKey(),
        'relation' => 'labels',
        'action' => 'detach',
        'related' => $this->urgent->getKey(),
    ])->assertForbidden();

    expect($this->project->labels()->count())->toBe(1);
});

it('detaches nothing when one of a selection is refused', function (): void {
    $this->project->labels()->attach([$this->urgent->getKey(), $this->later->getKey()]);

    ProjectPolicy::$detachable = false;

    $this->post(RelationPanel::url('relations/bulk'), [
        'resource' => 'projects',
        'record' => $this->project->getKey(),
        'relation' => 'labels',
        'action' => 'detach',
        'records' => [$this->urgent->getKey(), $this->later->getKey()],
    ])->assertForbidden();

    expect($this->project->labels()->count())->toBe(2);
});

/*
 * Pivot attributes
 */

it('reads a pivot column into the table', function (): void {
    $this->project->labels()->attach($this->urgent->getKey(), ['role' => 'primary']);

    $labels = labelsRelation($this->project);

    expect($labels['rows'][0]['cells']['pivot.role'])->toBe('primary');
});

it('updates a pivot column without touching the related record', function (): void {
    $this->project->labels()->attach($this->urgent->getKey(), ['role' => 'primary']);

    $this->post(relationUrl([
        'resource' => 'projects',
        'record' => $this->project->getKey(),
        'relation' => 'labels',
        'operation' => RelationOperation::Edit->value,
        'related' => $this->urgent->getKey(),
    ]), [
        'name' => 'Urgent',
        'pivot' => ['role' => 'secondary'],
    ])->assertRedirect();

    expect($this->project->labels()->first()->pivot->role)->toBe('secondary')
        ->and($this->urgent->fresh()->name)->toBe('Urgent');
});

it('namespaces pivot fields so they cannot shadow the record\'s own columns', function (): void {
    $response = $this->getJson(relationUrl([
        'resource' => 'projects',
        'record' => $this->project->getKey(),
        'relation' => 'labels',
        'operation' => RelationOperation::Create->value,
    ]));

    expect(collect($response->json('form.schema'))->pluck('name')->all())
        ->toBe(['name', 'pivot.role']);
});

/*
 * Dissociate
 */

it('dissociates a child by nulling its foreign key', function (): void {
    $task = $this->project->tasks()->create(['name' => 'Loose']);

    $this->post(RelationPanel::url('relations/action'), [
        'resource' => 'projects',
        'record' => $this->project->getKey(),
        'relation' => 'tasks',
        'action' => 'dissociate',
        'related' => $task->getKey(),
    ])->assertRedirect();

    expect($task->fresh()->project_id)->toBeNull()
        ->and($this->project->tasks()->count())->toBe(0);
});

/*
 * Soft deletes
 */

it('hides trashed related records until the filter asks for them', function (): void {
    $task = $this->project->tasks()->create(['name' => 'Gone']);
    $task->delete();

    $visible = fn (array $filters): array => collect(
        test()->get(RelationPanel::url('projects/'.$this->project->getKey()).'?'.http_build_query($filters))
            ->viewData('page')['props']['relations'],
    )->firstWhere('key', 'tasks')['rows'];

    expect($visible([]))->toBe([])
        ->and($visible([
            'relations' => ['tasks' => ['filters' => ['trashed' => TrashedFilter::ONLY]]],
        ]))->toHaveCount(1);
});

it('restores a trashed related record', function (): void {
    $task = $this->project->tasks()->create(['name' => 'Gone']);
    $task->delete();

    $this->post(RelationPanel::url('relations/action'), [
        'resource' => 'projects',
        'record' => $this->project->getKey(),
        'relation' => 'tasks',
        'action' => 'restore',
        'related' => $task->getKey(),
    ])->assertRedirect();

    expect($task->fresh()->trashed())->toBeFalse();
});

it('will not restore a record that is not trashed', function (): void {
    $task = $this->project->tasks()->create(['name' => 'Alive']);

    // Hidden for a live record, so the endpoint refuses it too: hiding a
    // button is never what protects an operation.
    $this->post(RelationPanel::url('relations/action'), [
        'resource' => 'projects',
        'record' => $this->project->getKey(),
        'relation' => 'tasks',
        'action' => 'restore',
        'related' => $task->getKey(),
    ])->assertRedirect();

    expect($task->fresh()->trashed())->toBeFalse();
});

it('force deletes a trashed related record for good', function (): void {
    $task = $this->project->tasks()->create(['name' => 'Gone']);
    $task->delete();

    $this->post(RelationPanel::url('relations/action'), [
        'resource' => 'projects',
        'record' => $this->project->getKey(),
        'relation' => 'tasks',
        'action' => 'forceDelete',
        'related' => $task->getKey(),
    ])->assertRedirect();

    expect(Task::withTrashed()->find($task->getKey()))->toBeNull();
});

it('refuses a restore the policy does not allow', function (): void {
    $task = $this->project->tasks()->create(['name' => 'Gone']);
    $task->delete();

    TaskPolicy::$restorable = false;

    $this->post(RelationPanel::url('relations/action'), [
        'resource' => 'projects',
        'record' => $this->project->getKey(),
        'relation' => 'tasks',
        'action' => 'restore',
        'related' => $task->getKey(),
    ])->assertForbidden();

    expect($task->fresh()->trashed())->toBeTrue();
});
