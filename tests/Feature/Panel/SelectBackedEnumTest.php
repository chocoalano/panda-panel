<?php

declare(strict_types=1);

use App\Models\User;
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\FormSchema;
use Tests\Fixtures\Panel\Enums\EmploymentType;
use Tests\Fixtures\Panel\Enums\Priority;
use Tests\Fixtures\Panel\Forms\FormFixturePanel;
use Tests\Fixtures\Panel\Relations\Project;

/*
|--------------------------------------------------------------------------
| A Select bound to a backed enum
|--------------------------------------------------------------------------
|
| A cast attribute arrives as whatever the model casts it to, and for an enum
| that is a case object — neither a string nor an int. `castForForm()` tested
| for exactly those two types and answered null for anything else, so an enum
| became null on the way into the form.
|
| That is not a display bug. The control rendered empty, the browser submitted
| the empty value back with the rest of the form, and an edit that meant to
| change somebody's name wrote null over their employment type. The value was
| lost by saving a form nobody had touched, which is the worst way to lose one:
| no error, no warning, and the field the user was actually editing saved
| correctly.
|
*/

beforeEach(function (): void {
    FormFixturePanel::boot();
    FormFixturePanel::reset();

    $this->actingAs(User::factory()->create());
});

/** The schema the fixture resource's own edit page builds. */
function enumSchema(): FormSchema
{
    return FormSchema::make()
        ->model(Project::class)
        ->forPage('edit')
        ->schema([
            Select::make('employment_type')
                ->options(['permanent' => 'Permanent', 'contract' => 'Contract']),
            Select::make('priority')->options([1 => 'Low', 2 => 'High']),
        ]);
}

/**
 * @return array<string, mixed>
 */
function serializedValues(FormSchema $schema, ?Project $record): array
{
    $values = [];

    foreach ($schema->toArray($record)['schema'] as $field) {
        $values[$field['name']] = $field['value'];
    }

    return $values;
}

/*
 * T1–T5 — the cast itself
 */

it('binds a string-backed enum to its backing value', function (): void {
    $record = Project::query()->create([
        'name' => 'Alice',
        'employment_type' => EmploymentType::Permanent,
    ]);

    expect(serializedValues(enumSchema(), $record->fresh())['employment_type'])
        ->toBe('permanent');
});

it('binds an int-backed enum to its backing int, not a string', function (): void {
    $record = Project::query()->create(['name' => 'Alice', 'priority' => Priority::High]);

    // `2`, not `"2"`. An int column already serializes as an int here, and
    // stringifying enums would make the two disagree.
    expect(serializedValues(enumSchema(), $record->fresh())['priority'])->toBe(2);
});

it('leaves a null enum as null', function (): void {
    $record = Project::query()->create(['name' => 'Alice']);

    $values = serializedValues(enumSchema(), $record->fresh());

    expect($values['employment_type'])->toBeNull()
        ->and($values['priority'])->toBeNull();
});

it('leaves a plain string value untouched', function (): void {
    $schema = FormSchema::make()->schema([
        Select::make('kind')->options(['a' => 'A']),
    ]);

    $record = Project::query()->create(['name' => 'a']);

    expect($schema->toArray($record)['schema'][0]['value'])->toBeNull();

    $plain = FormSchema::make()->schema([
        Select::make('name')->options(['Alice' => 'Alice']),
    ]);

    expect($plain->toArray($record->fresh())['schema'][0]['value'])->toBe('a');
});

it('leaves a plain int value untouched', function (): void {
    $record = Project::query()->create(['name' => 'Alice']);

    $record->forceFill(['priority' => 1])->save();

    expect(serializedValues(enumSchema(), $record->fresh())['priority'])->toBe(1);
});

/*
 * T8 / T9 — the reason this is a data-integrity bug, not a rendering one
 */

it('does not null an untouched backed enum when an unrelated field is edited', function (): void {
    $record = Project::query()->create([
        'name' => 'Alice',
        'employment_type' => EmploymentType::Permanent,
        'priority' => Priority::High,
    ]);

    $schema = enumSchema();

    // Exactly what the browser holds: the values the server serialized into
    // the form. Before the fix both enums arrived as null, so submitting the
    // form unchanged wrote null over them.
    $submitted = serializedValues($schema, $record->fresh());
    $submitted['name'] = 'Alicia';

    $record->forceFill($schema->dehydrate($submitted, $record))->save();

    $fresh = $record->fresh();

    expect($fresh->employment_type)->toBe(EmploymentType::Permanent)
        ->and($fresh->priority)->toBe(Priority::High);
});

it('preserves both enums through a round trip that changes neither', function (): void {
    $record = Project::query()->create([
        'name' => 'Alice',
        'employment_type' => EmploymentType::Contract,
        'priority' => Priority::Low,
    ]);

    $schema = enumSchema();
    $submitted = serializedValues($schema, $record->fresh());

    $record->forceFill($schema->dehydrate($submitted, $record))->save();

    expect($record->fresh()->employment_type)->toBe(EmploymentType::Contract)
        ->and($record->fresh()->priority)->toBe(Priority::Low);
});

/*
 * T10 — a change the user did mean
 */

it('persists an enum the user deliberately changed', function (): void {
    $record = Project::query()->create([
        'name' => 'Alice',
        'employment_type' => EmploymentType::Permanent,
    ]);

    $schema = enumSchema();

    $record->forceFill($schema->dehydrate(
        ['employment_type' => 'contract', 'priority' => null],
        $record,
    ))->save();

    expect($record->fresh()->employment_type)->toBe(EmploymentType::Contract);
});

/*
 * T11 — nullable semantics are unchanged
 */

it('lets a nullable enum be cleared deliberately', function (): void {
    $record = Project::query()->create([
        'name' => 'Alice',
        'employment_type' => EmploymentType::Permanent,
    ]);

    $schema = enumSchema();

    $record->forceFill($schema->dehydrate(
        ['employment_type' => null, 'priority' => null],
        $record,
    ))->save();

    expect($record->fresh()->employment_type)->toBeNull();
});

/*
 * T12 — the fix must not become a way past validation
 */

it('still refuses a value that is not one of the options', function (): void {
    $rules = enumSchema()->validationRules();

    $validator = validator(['employment_type' => 'not-a-case'], $rules);

    expect($validator->fails())->toBeTrue()
        ->and(array_keys($validator->errors()->toArray()))->toContain('employment_type');
});

it('accepts a real option', function (): void {
    expect(validator(['employment_type' => 'contract'], enumSchema()->validationRules())->fails())
        ->toBeFalse();
});

/*
 * T13 — create
 */

it('writes an enum chosen on a create form', function (): void {
    $schema = FormSchema::make()->model(Project::class)->forPage('create')->schema([
        Select::make('employment_type')->options(['permanent' => 'Permanent', 'contract' => 'Contract']),
    ]);

    $record = Project::query()->create([
        'name' => 'New',
        ...$schema->dehydrate(['employment_type' => 'permanent']),
    ]);

    expect($record->fresh()->employment_type)->toBe(EmploymentType::Permanent);
});

/*
 * T14 — the other hook on this path still owns the value when it is set
 */

it('leaves a custom formatUsing in charge of the value', function (): void {
    $record = Project::query()->create([
        'name' => 'Alice',
        'employment_type' => EmploymentType::Permanent,
    ]);

    $schema = FormSchema::make()->model(Project::class)->forPage('edit')->schema([
        Select::make('employment_type')
            ->options(['permanent' => 'Permanent', 'contract' => 'Contract'])
            // `formatUsing()` replaces the cast rather than running after it,
            // which is the order that existed before this change and still
            // does.
            ->formatUsing(static fn (mixed $value): string => 'contract'),
    ]);

    expect($schema->toArray($record->fresh())['schema'][0]['value'])->toBe('contract');
});

/*
 * The full page lifecycle, through the resource's own edit form
 */

it('renders the enum on the resource edit page', function (): void {
    $record = Project::query()->create([
        'name' => 'Alice',
        'employment_type' => EmploymentType::Permanent,
        'priority' => Priority::High,
    ]);

    $response = $this->get(FormFixturePanel::url('form-fixtures/'.$record->getKey().'/edit'));

    $response->assertOk();

    $schema = $response->viewData('page')['props']['form']['schema'];
    $values = collect($schema)->pluck('value', 'name');

    expect($values['employment_type'])->toBe('permanent')
        ->and($values['priority'])->toBe(2);
});
