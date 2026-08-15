<?php

declare(strict_types=1);

use App\Models\User;
use Tests\Fixtures\Panel\Relations\Project;
use Tests\Fixtures\Panel\Relations\RelationPanel;
use Tests\Fixtures\Panel\Relations\TaskPolicy;

beforeEach(function (): void {
    RelationPanel::boot();
    RelationPanel::reset();

    $this->actingAs(User::factory()->admin()->create());

    $this->project = Project::query()->create(['name' => 'Apollo']);
    $this->other = Project::query()->create(['name' => 'Gemini']);
});

it('registers a relation page under the record', function (): void {
    expect(route('panel.relation-host.resources.projects.tasks', ['record' => 7], absolute: false))
        ->toBe('/relation-host/projects/7/tasks');
});

it('shows only the record\'s related records', function (): void {
    $this->project->tasks()->create(['name' => 'Ours']);
    $this->other->tasks()->create(['name' => 'Theirs']);

    $response = $this->get(RelationPanel::url('projects/'.$this->project->getKey().'/tasks'));

    $response->assertOk();

    $relation = $response->viewData('page')['props']['relation'];

    expect(collect($relation['rows'])->pluck('cells.name')->all())->toBe(['Ours'])
        ->and($relation['key'])->toBe('tasks');
});

it('404s for a record outside the resource query', function (): void {
    $this->get(RelationPanel::url('projects/99999/tasks'))->assertNotFound();
});

it('403s when the relation may not be read', function (): void {
    TaskPolicy::$viewable = false;

    $this->get(RelationPanel::url('projects/'.$this->project->getKey().'/tasks'))
        ->assertForbidden();
});

it('appears in the record sub-navigation, and disappears when refused', function (): void {
    $items = fn (): array => collect(
        test()->get(RelationPanel::url('projects/'.$this->project->getKey()))
            ->viewData('page')['props']['page']['subNavigation']['items'],
    )->pluck('key')->all();

    expect($items())->toContain('tasks');

    TaskPolicy::$viewable = false;

    expect($items())->not->toContain('tasks');
});

it('marks itself active in the sub-navigation', function (): void {
    $response = $this->get(RelationPanel::url('projects/'.$this->project->getKey().'/tasks'));

    $active = collect($response->viewData('page')['props']['page']['subNavigation']['items'])
        ->firstWhere('active', true);

    expect($active['key'])->toBe('tasks');
});

it('puts the record in the breadcrumb trail', function (): void {
    $response = $this->get(RelationPanel::url('projects/'.$this->project->getKey().'/tasks'));

    $labels = collect($response->viewData('page')['props']['page']['breadcrumbs'])
        ->pluck('label')
        ->all();

    expect($labels)->toBe(['Dashboard', 'Projects', 'Apollo', 'Tasks']);
});
