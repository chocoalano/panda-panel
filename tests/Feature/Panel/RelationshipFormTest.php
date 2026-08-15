<?php

declare(strict_types=1);

use App\Models\User;
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Forms\Layouts\Relationship;
use Tests\Fixtures\Panel\Relations\Brief;
use Tests\Fixtures\Panel\Relations\Label;
use Tests\Fixtures\Panel\Relations\Project;
use Tests\Fixtures\Panel\Relations\RelationPanel;
use Tests\Fixtures\Panel\Relations\Task;

beforeEach(function (): void {
    RelationPanel::boot();
    RelationPanel::reset();

    $this->actingAs(User::factory()->admin()->create());
});

/*
 * Relationship groups: HasOne
 */

it('names a relation group\'s fields under the relation', function (): void {
    $schema = FormSchema::make()
        ->model(Project::class)
        ->schema([
            Relationship::make('brief')->schema([
                TextInput::make('summary'),
            ]),
        ]);

    expect(array_keys($schema->validationRules()))->toBe(['brief.summary']);
});

it('creates the related record on save when there is none yet', function (): void {
    $project = Project::query()->create(['name' => 'Apollo']);

    $this->put(RelationPanel::url('projects/'.$project->getKey().'/edit'), [
        'name' => 'Apollo',
        'brief' => ['summary' => 'Land on the moon'],
    ])->assertRedirect();

    expect(Brief::query()->where('project_id', $project->getKey())->value('summary'))
        ->toBe('Land on the moon');
});

it('updates the existing related record rather than creating a second', function (): void {
    $project = Project::query()->create(['name' => 'Apollo']);
    $project->brief()->create(['summary' => 'Before']);

    $this->put(RelationPanel::url('projects/'.$project->getKey().'/edit'), [
        'name' => 'Apollo',
        'brief' => ['summary' => 'After'],
    ])->assertRedirect();

    expect(Brief::query()->where('project_id', $project->getKey())->count())->toBe(1)
        ->and($project->brief()->first()->summary)->toBe('After');
});

it('fills a relation group from the related record', function (): void {
    $project = Project::query()->create(['name' => 'Apollo']);
    $project->brief()->create(['summary' => 'Existing']);

    $response = $this->get(RelationPanel::url('projects/'.$project->getKey().'/edit'));

    $group = collect($response->viewData('page')['props']['form']['schema'])
        ->firstWhere('component', 'relationship');

    expect($group['relation'])->toBe('brief')
        ->and($group['schema'][0]['name'])->toBe('brief.summary')
        ->and($group['schema'][0]['value'])->toBe('Existing');
});

it('does not write a relation group\'s fields onto the owner', function (): void {
    $project = Project::query()->create(['name' => 'Apollo']);

    $this->put(RelationPanel::url('projects/'.$project->getKey().'/edit'), [
        'name' => 'Apollo',
        'brief' => ['summary' => 'Elsewhere'],
    ])->assertRedirect();

    // `summary` is not a column on projects; if the group's fields leaked
    // into the owner's attributes the save would have failed outright.
    expect($project->fresh()->name)->toBe('Apollo');
});

/*
 * Relationship selects: BelongsTo and BelongsToMany
 */

it('resolves a belongs-to select against the related table and writes its foreign key', function (): void {
    $project = Project::query()->create(['name' => 'Apollo']);

    $field = Select::make('project')->relationship('project', 'name');

    $schema = FormSchema::make()->model(Task::class)->schema([$field]);

    $serialized = $schema->toArray()['schema'][0];

    expect(collect($serialized['options'])->pluck('label')->all())->toContain('Apollo')
        ->and($serialized['multiple'])->toBeFalse()
        // Named after the relation, persisted to the foreign key: no form has
        // to spell out `->dehydrateTo('project_id')` beside the relationship.
        ->and($schema->dehydrate(['project' => (string) $project->getKey()]))
        ->toBe(['project_id' => (string) $project->getKey()]);
});

it('validates a relationship select against the table, not the rendered page', function (): void {
    Project::query()->create(['name' => 'Apollo']);

    $rules = FormSchema::make()
        ->model(Task::class)
        ->schema([Select::make('project')->relationship('project', 'name')])
        ->validationRules();

    // An `exists` rule, never an `in` of whichever options happened to fit on
    // the page: a real key that sorted past the limit is still a real key.
    expect(collect($rules['project'])->map(strval(...))->implode(' '))
        ->toContain('exists:fixture_projects,id');
});

it('turns a many-to-many select into a multiple one and syncs it after the save', function (): void {
    $urgent = Label::query()->create(['name' => 'Urgent']);
    $later = Label::query()->create(['name' => 'Later']);

    $project = Project::query()->create(['name' => 'Apollo']);

    $field = Select::make('labels')->relationship('labels', 'name');

    $schema = FormSchema::make()->model(Project::class)->schema([$field]);

    $serialized = $schema->toArray()['schema'][0];

    expect($serialized['multiple'])->toBeTrue()
        // A pivot has no column on the record, so nothing is dehydrated for it.
        ->and($schema->dehydrate(['labels' => [(string) $urgent->getKey()]]))->toBe([]);

    $schema->saveRelations($project, ['labels' => [$urgent->getKey(), $later->getKey()]]);

    expect($project->labels()->pluck('fixture_labels.id')->sort()->values()->all())
        ->toBe([$urgent->getKey(), $later->getKey()]);
});

it('fills a many-to-many select from the pivot rather than from an attribute', function (): void {
    $urgent = Label::query()->create(['name' => 'Urgent']);
    $project = Project::query()->create(['name' => 'Apollo']);

    $project->labels()->attach($urgent->getKey());

    $serialized = FormSchema::make()
        ->model(Project::class)
        ->schema([Select::make('labels')->relationship('labels', 'name')])
        ->toArray($project)['schema'][0];

    expect($serialized['value'])->toBe([(string) $urgent->getKey()]);
});

it('validates each element of a multiple select', function (): void {
    $rules = FormSchema::make()
        ->model(Project::class)
        ->schema([Select::make('labels')->relationship('labels', 'name')])
        ->validationRules();

    expect($rules)->toHaveKey('labels.*')
        ->and(collect($rules['labels.*'])->map(strval(...))->implode(' '))
        ->toContain('exists:fixture_labels,id');
});

it('still validates a static option list as a whitelist', function (): void {
    $rules = FormSchema::make()
        ->schema([Select::make('status')->options(['open' => 'Open', 'shut' => 'Shut'])])
        ->validationRules();

    expect(collect($rules['status'])->map(strval(...))->implode(' '))
        ->toContain('in:"open","shut"');
});
