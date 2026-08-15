<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Enums\CalloutTone;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Forms\Layouts\Callout;
use PandaPanel\Forms\Layouts\CustomComponent;
use PandaPanel\Forms\Layouts\EmptyState;
use PandaPanel\Forms\Layouts\Grid;
use PandaPanel\Forms\Layouts\Section;
use PandaPanel\Forms\Layouts\Tab;
use PandaPanel\Forms\Layouts\Tabs;
use PandaPanel\Forms\Prime\Icon;
use PandaPanel\Forms\Prime\Image;
use PandaPanel\Forms\Prime\Text;
use PandaPanel\Tables\Enums\BadgeColor;
use Tests\Fixtures\Panel\Relations\Project;
use Tests\Fixtures\Panel\Relations\RelationSchema;

beforeEach(function (): void {
    RelationSchema::create();
});

/*
 * Tabs
 */

it('names the fields in each tab so an error can find its panel', function (): void {
    $definition = Tabs::make([
        Tab::make('Details')->schema([TextInput::make('name')]),
        Tab::make('Security')->icon('shield')->schema([TextInput::make('password')]),
    ])->toArray(null, 'create');

    expect($definition['tabs'][0]['key'])->toBe('details')
        ->and($definition['tabs'][0]['fields'])->toBe(['name'])
        ->and($definition['tabs'][1]['icon'])->toBe('shield')
        ->and($definition['tabs'][1]['fields'])->toBe(['password']);
});

it('validates every tab, not merely the one on screen', function (): void {
    $rules = FormSchema::make()
        ->schema([
            Tabs::make([
                Tab::make('Details')->schema([TextInput::make('name')->required()]),
                Tab::make('More')->schema([TextInput::make('note')->required()]),
            ]),
        ])
        ->validationRules();

    expect(array_keys($rules))->toBe(['name', 'note']);
});

it('leaves a hidden field out of the tab that held it', function (): void {
    $definition = Tabs::make([
        Tab::make('Details')->schema([
            TextInput::make('name'),
            TextInput::make('slug')->visibleOn(['edit']),
        ]),
    ])->toArray(null, 'create');

    expect(array_column($definition['tabs'][0]['schema'], 'name'))->toBe(['name']);
});

/*
 * Callouts
 */

it('carries the icon its tone implies unless told otherwise', function (): void {
    $default = Callout::make('Careful.')->tone(CalloutTone::Warning)
        ->toArray(null, 'create');

    $named = Callout::make('Careful.')->tone(CalloutTone::Warning)->icon('info')
        ->toArray(null, 'create');

    expect($default['icon'])->toBe('triangle-alert')
        ->and($named['icon'])->toBe('info');
});

it('validates the fields a callout wraps', function (): void {
    $rules = FormSchema::make()
        ->schema([
            Callout::make('Read this.')->schema([
                TextInput::make('acknowledged')->required(),
            ]),
        ])
        ->validationRules();

    expect($rules)->toHaveKey('acknowledged');
});

/*
 * Content-only components
 */

it('holds no fields in an empty state or a prime', function (): void {
    expect(EmptyState::make('Nothing here')->fields())->toBe([])
        ->and(Text::make('Hello')->fields())->toBe([])
        ->and(Icon::make('check')->fields())->toBe([])
        ->and(Image::make('https://example.test/a.png')->fields())->toBe([]);
});

it('resolves a prime\'s content against the record', function (): void {
    $project = new Project(['name' => 'Apollo']);

    $definition = Text::make(
        static fn (?Model $record): string => 'Editing '.($record?->getAttribute('name') ?? 'nothing'),
    )->color(BadgeColor::Info)->toArray($project, 'edit');

    expect($definition['content'])->toBe('Editing Apollo')
        ->and($definition['color'])->toBe('info');
});

it('sends no image at all rather than a broken one', function (): void {
    expect(Image::make('')->toArray(null, 'create')['url'])->toBeNull();
});

/*
 * Custom components
 */

it('sends a custom component as a registry key and its children', function (): void {
    $definition = CustomComponent::make('Panels/Admin/Schemas/Banner')
        ->config(['dismissible' => true])
        ->schema([TextInput::make('name')])
        ->toArray(null, 'create');

    expect($definition['componentName'])->toBe('Panels/Admin/Schemas/Banner')
        ->and($definition['config'])->toBe(['dismissible' => true])
        ->and($definition['schema'][0]['name'])->toBe('name');
});

it('validates a field wherever a custom component put it', function (): void {
    $rules = FormSchema::make()
        ->schema([
            CustomComponent::make('Panels/Admin/Schemas/Banner')->schema([
                TextInput::make('name')->required(),
            ]),
        ])
        ->validationRules();

    expect($rules)->toHaveKey('name');
});

/*
 * Rebuilding against submitted state
 */

it('rebuilds the schema holding what has been typed so far', function (): void {
    $schema = FormSchema::make()->schema([
        TextInput::make('name'),
        TextInput::make('note'),
    ]);

    $form = $schema->toArrayWithState(null, ['name' => 'Apollo']);

    expect($form['schema'][0]['value'])->toBe('Apollo')
        // Untouched fields keep whatever the schema gave them.
        ->and($form['schema'][1]['value'])->toBeNull();
});

it('applies state to fields nested inside a layout', function (): void {
    $schema = FormSchema::make()->schema([
        Tabs::make([
            Tab::make('Details')->schema([TextInput::make('name')]),
        ]),
    ]);

    $form = $schema->toArrayWithState(null, ['name' => 'Apollo']);

    expect($form['schema'][0]['tabs'][0]['schema'][0]['value'])->toBe('Apollo');
});

/*
 * Column spans
 */

it('serializes a field span as the number it was given', function (): void {
    $definition = TextInput::make('name')->columnSpan(2)->toArray(null, 'create');

    expect($definition['columnSpan'])->toBe(2);
});

it('serializes a full-width field as full rather than as a number', function (): void {
    // A number would have to be the container's column count, which the
    // field does not know and which can change without it being touched.
    $definition = TextInput::make('bio')->columnSpanFull()->toArray(null, 'create');

    expect($definition['columnSpan'])->toBe('full');
});

it('refuses a span below one', function (): void {
    $definition = TextInput::make('name')->columnSpan(0)->toArray(null, 'create');

    expect($definition['columnSpan'])->toBe(1);
});

it('lets the last call decide the span', function (): void {
    expect(TextInput::make('name')->columnSpanFull()->columnSpan(2)->toArray(null, 'create')['columnSpan'])
        ->toBe(2)
        ->and(TextInput::make('name')->columnSpan(2)->columnSpanFull()->toArray(null, 'create')['columnSpan'])
        ->toBe('full');
});

it('spans a field inside a section the same way', function (): void {
    $definition = Section::make('Details')
        ->columns(3)
        ->schema([TextInput::make('bio')->columnSpanFull()])
        ->toArray(null, 'create');

    // The section carries its column count and the field carries `full`. The
    // two are resolved together where the row is drawn, so a section changed
    // from three columns to two keeps this field full width.
    expect($definition['columns'])->toBe(3)
        ->and($definition['schema'][0]['columnSpan'])->toBe('full');
});

/*
 * Calls that landed on the wrong receiver
 */

it('says where a span belongs when it is called on the schema', function (): void {
    // PHP's own "Call to unknown method FormSchema::columnSpanFull()" names
    // the class but not the mistake, which is that a span belongs to a
    // component inside the schema. The schema is the root; there is nothing
    // outside it to span.
    expect(fn () => FormSchema::make()->columnSpanFull())
        ->toThrow(
            BadMethodCallException::class,
            'columnSpanFull() belongs to a field, not to the form schema.',
        );
});

it('leaves an unrecognised call to the ordinary error', function (): void {
    expect(fn () => FormSchema::make()->definitelyNotAThing())
        ->toThrow(
            BadMethodCallException::class,
            'Call to undefined method PandaPanel\Forms\FormSchema::definitelyNotAThing().',
        );
});

/*
 * Column counts
 */

it('clamps a column count to what the renderer can draw', function (): void {
    // Six was serialized as six, the renderer had no literal class for it,
    // and the fallback drew one column — the widest ask reported as the
    // narrowest result, silently.
    expect(FormSchema::make()->columns(6)->toArray()['columns'])->toBe(4)
        ->and(Section::make('Details')->columns(12)->toArray(null, 'create')['columns'])->toBe(4)
        ->and(Grid::make(9)->toArray(null, 'create')['columns'])->toBe(4);
});

it('clamps a column count below one', function (): void {
    expect(FormSchema::make()->columns(0)->toArray()['columns'])->toBe(1)
        ->and(Grid::make(-3)->toArray(null, 'create')['columns'])->toBe(1);
});

it('carries the root column count to the renderer', function (): void {
    // Sent all along; ignored all along. The root stacked its nodes and a
    // field that asked for half a row got a whole one.
    $form = FormSchema::make()
        ->columns(2)
        ->schema([TextInput::make('name'), TextInput::make('email')])
        ->toArray();

    expect($form['columns'])->toBe(2);
});
