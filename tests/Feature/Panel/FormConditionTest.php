<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Enums\ConditionOperator;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Forms\Support\Condition;
use Tests\Fixtures\Panel\Relations\Project;
use Tests\Fixtures\Panel\Relations\RelationSchema;

beforeEach(function (): void {
    RelationSchema::create();
});

/*
 * Page-aware visibility
 */

it('shows a field only on the pages it names', function (): void {
    $field = TextInput::make('slug')->visibleOn(['edit']);

    expect($field->isHiddenOn('create'))->toBeTrue()
        ->and($field->isHiddenOn('edit'))->toBeFalse();
});

it('hides a field on the pages it excludes', function (): void {
    $field = TextInput::make('slug')->hiddenOn(['create']);

    expect($field->isHiddenOn('create'))->toBeTrue()
        ->and($field->isHiddenOn('edit'))->toBeFalse();
});

it('disables a field per page without hiding it', function (): void {
    $field = TextInput::make('email')->disabledOn(['edit']);

    expect($field->isDisabledOn('edit'))->toBeTrue()
        ->and($field->isDisabledOn('create'))->toBeFalse()
        ->and($field->isHiddenOn('edit'))->toBeFalse();
});

it('decides visibility from the record when given a closure', function (): void {
    $field = TextInput::make('reason')->visible(
        static fn (?Model $record): bool => $record !== null,
    );

    expect($field->isHiddenOn('create'))->toBeTrue()
        ->and($field->isHiddenOn('edit', new Project))->toBeFalse();
});

it('leaves a hidden field out of the schema entirely', function (): void {
    $schema = FormSchema::make()
        ->forPage('create')
        ->schema([
            TextInput::make('name'),
            TextInput::make('slug')->visibleOn(['edit']),
        ]);

    $names = array_column($schema->toArray()['schema'], 'name');

    // Not merely absent from the page: absent from the rules too, so a
    // request that sends it cannot make it appear.
    expect($names)->toBe(['name'])
        ->and($schema->validationRules())->not->toHaveKey('slug');
});

/*
 * Declarative conditions
 */

it('describes a condition rather than scripting it', function (): void {
    $definition = TextInput::make('other')
        ->visibleWhen('kind', ConditionOperator::Equals, 'special')
        ->toArray(null, 'create');

    expect($definition['conditions']['visibleWhen'])->toBe([
        ['field' => 'kind', 'operator' => 'equals', 'value' => 'special'],
    ]);
});

it('sends no value for an operator that compares against nothing', function (): void {
    $condition = Condition::make('kind', ConditionOperator::Filled, 'ignored');

    expect($condition->toArray()['value'])->toBeNull();
});

it('answers the same condition the same way the browser will', function (): void {
    $field = TextInput::make('other')
        ->visibleWhen('kind', ConditionOperator::Equals, 'special');

    expect($field->matchesConditions(['kind' => 'special']))->toBeTrue()
        ->and($field->matchesConditions(['kind' => 'plain']))->toBeFalse()
        // A form's values arrive as text whatever the column holds, so `1`
        // and `'1'` are the same answer.
        ->and(
            TextInput::make('x')
                ->visibleWhen('flag', ConditionOperator::Equals, 1)
                ->matchesConditions(['flag' => '1']),
        )->toBeTrue();
});

it('ANDs several conditions and lets any hidden rule win', function (): void {
    $field = TextInput::make('other')
        ->visibleWhen('kind', ConditionOperator::Filled)
        ->hiddenWhen('locked', ConditionOperator::Truthy);

    expect($field->matchesConditions(['kind' => 'a', 'locked' => false]))->toBeTrue()
        ->and($field->matchesConditions(['kind' => '', 'locked' => false]))->toBeFalse()
        ->and($field->matchesConditions(['kind' => 'a', 'locked' => true]))->toBeFalse();
});

it('compares membership and magnitude as described', function (): void {
    expect(ConditionOperator::In->matches('b', ['a', 'b']))->toBeTrue()
        ->and(ConditionOperator::NotIn->matches('c', ['a', 'b']))->toBeTrue()
        ->and(ConditionOperator::GreaterThan->matches('5', 3))->toBeTrue()
        ->and(ConditionOperator::LessThan->matches('5', 3))->toBeFalse()
        // Not numeric on either side is not "greater", it is unanswerable.
        ->and(ConditionOperator::GreaterThan->matches('abc', 3))->toBeFalse();
});

/*
 * Presentation
 */

it('marks a field that wants its label beside the control', function (): void {
    expect(TextInput::make('name')->inlineLabel()->toArray(null, 'create')['inlineLabel'])
        ->toBeTrue()
        ->and(TextInput::make('name')->toArray(null, 'create')['inlineLabel'])
        ->toBeFalse();
});

it('says when a field wants the server told it changed', function (): void {
    $live = Select::make('kind')->live(onBlur: true, debounce: 250)
        ->toArray(null, 'create');

    expect($live['live'])->toBe(['onBlur' => true, 'debounce' => 250])
        ->and(Select::make('kind')->toArray(null, 'create')['live'])->toBeNull();
});

/*
 * Lifecycle hooks
 */

it('runs a hydration hook after the value is read', function (): void {
    $seen = null;

    $field = TextInput::make('name')->afterStateHydrated(
        static function (mixed $state) use (&$seen): void {
            $seen = $state;
        },
    );

    $project = new Project(['name' => 'Apollo']);

    expect($field->formValue($project))->toBe('Apollo')
        ->and($seen)->toBe('Apollo');
});

it('runs an update hook only for a field that asked to be live', function (): void {
    $calls = 0;

    $live = TextInput::make('a')->live()->afterStateUpdated(
        static function () use (&$calls): void {
            $calls++;
        },
    );

    $live->handleStateUpdated('new', 'old');

    expect($calls)->toBe(1)
        ->and($live->isLive())->toBeTrue()
        ->and(TextInput::make('b')->isLive())->toBeFalse();
});

it('mutates state on the way out when a dehydration hook says so', function (): void {
    $field = TextInput::make('name')->dehydrateStateUsing(
        static fn (mixed $state): string => mb_strtoupper((string) $state),
    );

    expect($field->mutate('apollo', null))->toBe('APOLLO');
});

it('keeps a non-dehydrating field out of what is written', function (): void {
    $schema = FormSchema::make()->schema([
        TextInput::make('name'),
        TextInput::make('confirmation')->dehydrated(false),
    ]);

    $dehydrated = $schema->dehydrate([
        'name' => 'Apollo',
        'confirmation' => 'yes',
    ]);

    expect($dehydrated)->toBe(['name' => 'Apollo']);
});

it('still validates a field it will not persist', function (): void {
    $rules = FormSchema::make()
        ->schema([TextInput::make('confirmation')->required()->dehydrated(false)])
        ->validationRules();

    // The two are separate questions: "must this be filled in" and "does it
    // reach a column".
    expect($rules)->toHaveKey('confirmation');
});

/*
 * Frontend validation hints
 */

it('sends only the rules a browser can honestly check', function (): void {
    $hints = TextInput::make('email')
        ->required()
        ->email()
        ->maxLength(100)
        ->toArray(null, 'create')['validation'];

    expect($hints['required'])->toBeTrue()
        ->and($hints['email'])->toBeTrue()
        ->and($hints['max'])->toBe(100.0);
});

it('never sends a rule that needs the database', function (): void {
    $hints = TextInput::make('email')
        ->rules(['unique:users,email', 'exists:accounts,email'])
        ->toArray(null, 'create')['validation'];

    // A frontend that guessed at uniqueness would be confidently wrong.
    expect($hints)->not->toHaveKey('unique')
        ->and($hints)->not->toHaveKey('exists');
});
