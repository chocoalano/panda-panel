<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Forms\Layouts\Relationship;
use Tests\Fixtures\Panel\Relations\Brief;
use Tests\Fixtures\Panel\Relations\Project;
use Tests\Fixtures\Panel\Relations\RelationSchema;

/*
|--------------------------------------------------------------------------
| Who may write a relation group
|--------------------------------------------------------------------------
|
| Embedding a related record in a form is a layout decision. It used to be an
| authorization decision too: `saveRelations()` wrote every `Relationship`
| group the schema declared, so whoever could pass the form's own permission
| could write all of them. Editing an employee and editing their salary are
| not the same permission, and the group did not even have to be rendered —
| a crafted body carrying `salary[amount]` was enough.
|
| `authorize()` gives the group its own answer, asked on the server, from the
| schema, every time the form is validated or written.
|
*/

beforeEach(function (): void {
    RelationSchema::create();

    $this->project = Project::query()->create(['name' => 'Alice']);

    Brief::query()->create([
        'project_id' => $this->project->getKey(),
        'summary' => '100',
    ]);
});

/** The current stored value of the related record. */
function briefSummary(Project $project): ?string
{
    return Brief::query()->where('project_id', $project->getKey())->value('summary');
}

function schemaWith(?Closure $authorize, string $page = 'edit'): FormSchema
{
    $group = Relationship::make('brief')->schema([TextInput::make('summary')]);

    if ($authorize !== null) {
        $group->authorize($authorize);
    }

    return FormSchema::make()
        ->model(Project::class)
        ->forPage($page)
        ->schema([TextInput::make('name'), $group]);
}

/** Runs the write the way `EditRecord` does: dehydrate, save, then relations. */
function applyEdit(FormSchema $schema, Project $project, array $submitted): void
{
    $project->forceFill($schema->dehydrate($submitted, $project))->save();

    $schema->saveRelations($project->fresh(), $submitted);
}

/*
 * T1 / T2 — nothing declared, and an allowed group
 */

it('writes a relation group that declares no authorization, as before', function (): void {
    applyEdit(schemaWith(null), $this->project, [
        'name' => 'Alicia',
        'brief' => ['summary' => '200'],
    ]);

    expect($this->project->fresh()->name)->toBe('Alicia')
        ->and(briefSummary($this->project))->toBe('200');
});

it('writes a relation group whose authorization allows it', function (): void {
    applyEdit(schemaWith(static fn (): bool => true), $this->project, [
        'name' => 'Alicia',
        'brief' => ['summary' => '200'],
    ]);

    expect(briefSummary($this->project))->toBe('200');
});

/*
 * T3 / T4 / T6 — the bug, and the atomicity around it
 */

it('does not persist a forged relationship payload when the relationship write is unauthorized', function (): void {
    applyEdit(schemaWith(static fn (): bool => false), $this->project, [
        'name' => 'Alicia',
        'brief' => ['summary' => '999'],
    ]);

    // The editable half of the form still saves; the guarded group does not.
    expect(briefSummary($this->project))->toBe('100')
        ->and($this->project->fresh()->name)->toBe('Alicia');
});

it('drops the guarded value before it can reach the attributes', function (): void {
    $attributes = schemaWith(static fn (): bool => false)
        ->dehydrate(['name' => 'Alicia', 'brief' => ['summary' => '999']], $this->project);

    expect($attributes)->toBe(['name' => 'Alicia']);
});

/*
 * T5 — create
 */

it('does not create a guarded relation on a create form', function (): void {
    $fresh = Project::query()->create(['name' => 'New']);

    schemaWith(static fn (): bool => false, 'create')
        ->saveRelations($fresh, ['name' => 'New', 'brief' => ['summary' => '999']]);

    expect(Brief::query()->where('project_id', $fresh->getKey())->exists())->toBeFalse();
});

it('creates it when the group allows the write', function (): void {
    $fresh = Project::query()->create(['name' => 'New']);

    schemaWith(static fn (): bool => true, 'create')
        ->saveRelations($fresh, ['name' => 'New', 'brief' => ['summary' => '5']]);

    expect(Brief::query()->where('project_id', $fresh->getKey())->value('summary'))->toBe('5');
});

/*
 * T7 — no payload is not a denial
 */

it('lets an ordinary edit through when the guarded group was not submitted', function (): void {
    applyEdit(schemaWith(static fn (): bool => false), $this->project, ['name' => 'Alicia']);

    expect($this->project->fresh()->name)->toBe('Alicia')
        ->and(briefSummary($this->project))->toBe('100');
});

/*
 * T9 / T10 / T11 — what the callback is handed
 */

it('hands the callback the record being edited', function (): void {
    $seen = null;

    applyEdit(
        schemaWith(function (?Model $record) use (&$seen): bool {
            $seen = $record?->getKey();

            return true;
        }),
        $this->project,
        ['name' => 'Alicia', 'brief' => ['summary' => '7']],
    );

    expect($seen)->toBe($this->project->getKey());
});

it('tells the callback which operation it is', function (): void {
    $seen = [];

    $edit = schemaWith(function (?Model $record, ?string $page) use (&$seen): bool {
        $seen[] = $page;

        return true;
    }, 'edit');

    $create = schemaWith(function (?Model $record, ?string $page) use (&$seen): bool {
        $seen[] = $page;

        return true;
    }, 'create');

    $edit->saveRelations($this->project, ['brief' => ['summary' => '1']]);
    $create->saveRelations($this->project, ['brief' => ['summary' => '1']]);

    expect($seen)->toContain('edit')->toContain('create');
});

it('has no record to hand the callback on create', function (): void {
    $seen = 'unset';

    $schema = schemaWith(function (?Model $record) use (&$seen): bool {
        $seen = $record;

        return false;
    }, 'create');

    // Nothing is faked: a create form has no persisted parent when the rules
    // are built, and the callback is told so.
    $schema->validationRules();

    expect($seen)->toBeNull();
});

/*
 * T12 / T13 — validation must not punish the wrong person
 */

it('does not demand a required field the actor may not write', function (): void {
    $group = Relationship::make('brief')
        ->schema([TextInput::make('summary')->required()])
        ->authorize(static fn (): bool => false);

    $rules = FormSchema::make()
        ->model(Project::class)
        ->forPage('edit')
        ->schema([TextInput::make('name'), $group])
        ->validationRules($this->project);

    // Otherwise somebody who may not touch the salary cannot edit the name.
    expect($rules['brief.summary'])->toBe(['nullable'])
        ->and($rules['name'])->toContain('nullable');
});

it('still demands a required field in a group the actor may write', function (): void {
    $group = Relationship::make('brief')
        ->schema([TextInput::make('summary')->required()])
        ->authorize(static fn (): bool => true);

    $rules = FormSchema::make()
        ->model(Project::class)
        ->forPage('edit')
        ->schema([$group])
        ->validationRules($this->project);

    expect($rules['brief.summary'])->toContain('required');
});

/*
 * T8 — groups are decided one at a time
 */

it('writes the allowed group and refuses the denied one in the same form', function (): void {
    $allowed = Relationship::make('brief')
        ->schema([TextInput::make('summary')])
        ->authorize(static fn (): bool => true);

    $schema = FormSchema::make()
        ->model(Project::class)
        ->forPage('edit')
        ->schema([TextInput::make('name'), $allowed]);

    applyEdit($schema, $this->project, [
        'name' => 'Alicia',
        'brief' => ['summary' => '250'],
    ]);

    expect(briefSummary($this->project))->toBe('250');

    // The same relation, now refused.
    $denied = Relationship::make('brief')
        ->schema([TextInput::make('summary')])
        ->authorize(static fn (): bool => false);

    FormSchema::make()
        ->model(Project::class)
        ->forPage('edit')
        ->schema([TextInput::make('name'), $denied])
        ->saveRelations($this->project->fresh(), ['brief' => ['summary' => '999']]);

    expect(briefSummary($this->project))->toBe('250');
});

/*
 * T17 — group denial is not something a field can argue with
 */

it('cannot be overridden by a field asking to be dehydrated', function (): void {
    $group = Relationship::make('brief')
        ->schema([TextInput::make('summary')->dehydrated(true)])
        ->authorize(static fn (): bool => false);

    FormSchema::make()
        ->model(Project::class)
        ->forPage('edit')
        ->schema([$group])
        ->saveRelations($this->project, ['brief' => ['summary' => '999']]);

    expect(briefSummary($this->project))->toBe('100');
});

/*
 * T16 — PP-21 still applies inside an allowed group
 */

it('still protects an immutable field inside a group the actor may write', function (): void {
    $group = Relationship::make('brief')
        ->schema([TextInput::make('summary')->immutableOn(['edit'])])
        ->authorize(static fn (): bool => true);

    FormSchema::make()
        ->model(Project::class)
        ->forPage('edit')
        ->schema([$group])
        ->saveRelations($this->project, ['brief' => ['summary' => '999']]);

    // The group is writable; this field within it is not.
    expect(briefSummary($this->project))->toBe('100');
});

/*
 * T18 — asked once per group, not once per field
 */

it('asks the group once per write rather than once per field', function (): void {
    $calls = 0;

    $group = Relationship::make('brief')
        ->schema([TextInput::make('summary'), TextInput::make('other')])
        ->authorize(function () use (&$calls): bool {
            $calls++;

            return true;
        });

    FormSchema::make()
        ->model(Project::class)
        ->forPage('edit')
        ->schema([$group])
        ->saveRelations($this->project, ['brief' => ['summary' => '1']]);

    expect($calls)->toBe(1);
});
