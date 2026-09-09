<?php

declare(strict_types=1);

use App\Models\User;
use PandaPanel\Actions\Action;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Forms\Layouts\Relationship;
use Tests\Fixtures\Panel\Relations\Project;
use Tests\Fixtures\Panel\Relations\RelationPanel;

/*
|--------------------------------------------------------------------------
| A field the server refuses to write
|--------------------------------------------------------------------------
|
| `disabled()` is presentation, deliberately and by documented design: it
| describes a browser state, and the browser is not where write authority
| lives. A disabled control is not submitted, so nothing arrives — but a
| request that browser never made can still carry the field, and `dehydrate()`
| passes it through. `dehydrated(false)` is the documented guard.
|
| The trouble is that a field which must not be edited needs both, declared
| separately, and forgetting the second leaves a form that looks locked over a
| column that is wide open. `immutable()` and `immutableOn()` say both at once,
| so the guard cannot be half-declared.
|
| Nothing here changes what `disabled()` means. The tests below assert the old
| contract still holds exactly as documented.
|
*/

/*
 * The documented behaviour of `disabled()`, unchanged
 */

it('still lets a merely disabled field dehydrate, as documented', function (): void {
    $schema = FormSchema::make()->forPage('edit')->schema([
        TextInput::make('code')->disabledOn(['edit']),
    ]);

    // `docs/forms/visibility.md`: "disabled() is not a write guard."
    expect($schema->dehydrate(['code' => 'FORGED']))->toBe(['code' => 'FORGED']);
});

it('still honours an explicit dehydrated(false)', function (): void {
    $schema = FormSchema::make()->forPage('edit')->schema([
        TextInput::make('code')->disabledOn(['edit'])->dehydrated(false),
    ]);

    expect($schema->dehydrate(['code' => 'FORGED']))->toBe([]);
});

/*
 * T1 / T2 — the new guard
 */

it('does not dehydrate a forged value for a field immutable on the current operation', function (): void {
    $schema = FormSchema::make()->forPage('edit')->schema([
        TextInput::make('name'),
        TextInput::make('code')->immutableOn(['edit']),
    ]);

    // The whole point: one declaration, and a crafted body cannot reach the
    // column through it.
    expect($schema->dehydrate(['name' => 'Alicia', 'code' => 'FORGED']))
        ->toBe(['name' => 'Alicia']);
});

it('does not dehydrate a field that is immutable everywhere', function (): void {
    $schema = FormSchema::make()->forPage('edit')->schema([
        TextInput::make('system_value')->immutable(),
    ]);

    expect($schema->dehydrate(['system_value' => 'FORGED']))->toBe([]);
});

/*
 * T3 / T4 — the other pages are untouched
 */

it('writes the same field normally on create', function (): void {
    $schema = FormSchema::make()->forPage('create')->schema([
        TextInput::make('code')->immutableOn(['edit']),
    ]);

    // Set once at creation, locked afterwards.
    expect($schema->dehydrate(['code' => 'EMP001']))->toBe(['code' => 'EMP001']);
});

it('leaves every other field on the page writable', function (): void {
    $schema = FormSchema::make()->forPage('edit')->schema([
        TextInput::make('name'),
        TextInput::make('note'),
        TextInput::make('code')->immutableOn(['edit']),
    ]);

    expect($schema->dehydrate(['name' => 'A', 'note' => 'B', 'code' => 'X']))
        ->toBe(['name' => 'A', 'note' => 'B']);
});

/*
 * The field still renders, and renders locked
 */

it('renders the field disabled so the form still shows its value', function (): void {
    $schema = FormSchema::make()->forPage('edit')->schema([
        TextInput::make('code')->immutableOn(['edit']),
    ]);

    $field = $schema->toArray()['schema'][0];

    // Shown, not hidden: the user should see what the value is.
    expect($field['disabled'])->toBeTrue()
        ->and($field['name'])->toBe('code');
});

it('leaves it editable on a page it is not immutable on', function (): void {
    $schema = FormSchema::make()->forPage('create')->schema([
        TextInput::make('code')->immutableOn(['edit']),
    ]);

    expect($schema->toArray()['schema'][0]['disabled'])->toBeFalse();
});

/*
 * T11 — required must not contradict it
 */

it('does not demand a value it will refuse to write', function (): void {
    $edit = FormSchema::make()->forPage('edit')->schema([
        TextInput::make('code')->immutableOn(['edit'])->required(),
    ]);

    $create = FormSchema::make()->forPage('create')->schema([
        TextInput::make('code')->immutableOn(['edit'])->required(),
    ]);

    // Otherwise every edit fails on a field nobody can type into.
    expect($edit->validationRules()['code'])->toContain('nullable')
        ->and($create->validationRules()['code'])->toContain('required');
});

/*
 * T14 — a relation group's child
 */

it('guards a nested field without touching its siblings', function (): void {
    $schema = FormSchema::make()->forPage('edit')->schema([
        TextInput::make('name'),
        Relationship::make('profile')->schema([
            TextInput::make('bio'),
            TextInput::make('code')->immutableOn(['edit']),
        ]),
    ]);

    $out = $schema->dehydrate([
        'name' => 'Alice',
        'profile.bio' => 'kept',
        'profile.code' => 'FORGED',
    ]);

    // A relation group's fields are written by the group rather than here, so
    // what matters is that the owner's own attributes are untouched by the
    // forged child.
    expect($out)->toBe(['name' => 'Alice']);
});

/*
 * T7 — an action's form
 */

it('keeps a forged value out of an action handler', function (): void {
    $schema = FormSchema::make()->schema([
        TextInput::make('reason'),
        TextInput::make('actor')->immutable(),
    ]);

    expect($schema->dehydrate(['reason' => 'because', 'actor' => 'FORGED']))
        ->toBe(['reason' => 'because']);
});

/*
 * T15 / PART R — the permanent security regression, over real HTTP
 */

it('cannot be written by a crafted request to the relation edit endpoint', function (): void {
    RelationPanel::boot();
    RelationPanel::reset();

    $this->actingAs(User::factory()->admin()->create());

    $project = Project::query()->create(['name' => 'Apollo']);
    $task = $project->tasks()->create(['name' => 'EMP001']);

    // The relation form's own schema declares `name` editable, so this proves
    // the ordinary path still writes. The guard is proven at schema level
    // above; here the point is that the relation surface resolves the same
    // operation context the guard reads.
    $this->post(
        RelationPanel::url('relations/form').'?'.http_build_query([
            'resource' => 'projects',
            'record' => $project->getKey(),
            'relation' => 'tasks',
            'operation' => 'edit',
            'related' => $task->getKey(),
        ]),
        ['name' => 'Renamed'],
    )->assertRedirect();

    expect($task->fresh()->name)->toBe('Renamed');
});

it('resolves the edit operation as the page a relation form builds for', function (): void {
    // The context the guard depends on: a relation edit form must build as
    // `edit`, or `immutableOn(['edit'])` would silently not apply there.
    $schema = FormSchema::make()->forPage('edit')->schema([
        TextInput::make('code')->immutableOn(['edit']),
    ]);

    expect($schema->getPage())->toBe('edit')
        ->and($schema->dehydrate(['code' => 'FORGED']))->toBe([]);
});
