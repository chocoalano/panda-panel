<?php

declare(strict_types=1);

use PandaPanel\Forms\Components\Repeater;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Forms\Layouts\Section;

/**
 * A repeater inside a repeater is validated to the bottom.
 *
 * `Repeater::schema()` is documented as taking `FormComponent` and a repeater
 * is one, so a repeater inside a repeater is a shape the published signature
 * accepts. Dehydration always recursed correctly — the inner field's own
 * allowlist ran, so nothing was ever mass-assigned — but rule generation
 * stopped one level down: `outer.*.inner` was declared `array` and nothing
 * beneath it existed. A `required()` on an inner field was accepted, rendered,
 * serialized, and never enforced, which is the one failure mode worse than
 * refusing the declaration outright.
 *
 * `Repeater::nestedRules()` now asks each child for its own nested rules and
 * prefixes them, which is the same delegation `FormSchema` performs at the top
 * level. Because the child answers by delegating in turn, depth is not a
 * parameter anywhere.
 */

/**
 * @param  list<mixed>  $rules
 * @return list<string>
 */
function nestedRuleStrings(array $rules): array
{
    return array_map(
        static fn (mixed $rule): string => is_string($rule) ? $rule : $rule::class,
        $rules,
    );
}

it('validates a field inside a nested repeater', function (): void {
    $rules = FormSchema::make()
        ->schema([
            Repeater::make('outer')->schema([
                TextInput::make('title')->required(),
                Repeater::make('inner')->schema([
                    TextInput::make('name')->required(),
                ]),
            ]),
        ])
        ->validationRules();

    // The whole finding: this key did not exist, so the `required()` below it
    // was silently unenforced.
    expect($rules)->toHaveKey('outer.*.inner.*.name')
        ->and(nestedRuleStrings($rules['outer.*.inner.*.name']))->toContain('required');
});

it('keeps declaring the nested repeater itself', function (): void {
    $rules = FormSchema::make()
        ->schema([
            Repeater::make('outer')->schema([
                Repeater::make('inner')->schema([TextInput::make('name')]),
            ]),
        ])
        ->validationRules();

    expect($rules)->toHaveKey('outer.*.inner')
        ->and(nestedRuleStrings($rules['outer.*.inner']))->toContain('array');
});

it('carries the nested repeater min and max down with it', function (): void {
    $rules = FormSchema::make()
        ->schema([
            Repeater::make('outer')->schema([
                Repeater::make('inner')
                    ->schema([TextInput::make('name')])
                    ->minItems(1)
                    ->maxItems(3),
            ]),
        ])
        ->validationRules();

    expect(nestedRuleStrings($rules['outer.*.inner']))
        ->toContain('min:1')
        ->toContain('max:3');
});

it('validates three levels down', function (): void {
    $rules = FormSchema::make()
        ->schema([
            Repeater::make('a')->schema([
                Repeater::make('b')->schema([
                    Repeater::make('c')->schema([
                        TextInput::make('name')->required(),
                    ]),
                ]),
            ]),
        ])
        ->validationRules();

    // Depth is not a parameter: each level delegates to the one below it.
    expect($rules)->toHaveKey('a.*.b.*.c.*.name')
        ->and(nestedRuleStrings($rules['a.*.b.*.c.*.name']))->toContain('required');
});

it('reaches a nested repeater declared inside a layout', function (): void {
    $rules = FormSchema::make()
        ->schema([
            Repeater::make('outer')->schema([
                Section::make('Details')->schema([
                    Repeater::make('inner')->schema([
                        TextInput::make('name')->required(),
                    ]),
                ]),
            ]),
        ])
        ->validationRules();

    // `itemFields()` flattens layouts, so a section between the two repeaters
    // must make no difference to the rules.
    expect($rules)->toHaveKey('outer.*.inner.*.name');
});

it('leaves a flat repeater exactly as it was', function (): void {
    $rules = FormSchema::make()
        ->schema([
            Repeater::make('items')->schema([
                TextInput::make('title')->required(),
            ]),
        ])
        ->validationRules();

    expect(array_keys($rules))->toBe(['items', 'items.*.title']);
});

it('still discards a key the nested schema never declared', function (): void {
    $field = Repeater::make('outer')->schema([
        Repeater::make('inner')->schema([TextInput::make('name')]),
    ]);

    // Dehydration recursed before this change and must keep doing so: the
    // rules are the new guarantee, not a replacement for the allowlist.
    expect($field->mutate([
        ['inner' => [['name' => 'kept', 'smuggled' => 'nope']]],
    ], null))->toBe([['inner' => [['name' => 'kept']]]]);
});

/*
 * The rules are only worth having if the validator enforces them, so these
 * run it rather than reading the array it was built from.
 */

/** @return array<string, list<mixed>> */
function nestedSchemaRules(): array
{
    return FormSchema::make()
        ->schema([
            Repeater::make('outer')->schema([
                TextInput::make('title')->required(),
                Repeater::make('inner')->schema([
                    TextInput::make('name')->required(),
                ]),
            ]),
        ])
        ->validationRules();
}

it('rejects a submission missing a nested required field', function (): void {
    $validator = validator([
        'outer' => [
            ['title' => 'One', 'inner' => [['name' => '']]],
        ],
    ], nestedSchemaRules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->keys())->toContain('outer.0.inner.0.name');
});

it('accepts a submission that fills the nested required field', function (): void {
    $validator = validator([
        'outer' => [
            ['title' => 'One', 'inner' => [['name' => 'Deep']]],
        ],
    ], nestedSchemaRules());

    expect($validator->fails())->toBeFalse();
});

it('accepts an outer entry with no inner entries at all', function (): void {
    // The nested repeater is nullable, so an entry that declared none is not
    // a failure — only an entry that exists and is incomplete is.
    $validator = validator([
        'outer' => [['title' => 'One']],
    ], nestedSchemaRules());

    expect($validator->fails())->toBeFalse();
});
