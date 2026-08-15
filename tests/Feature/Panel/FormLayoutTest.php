<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Enums\CalloutTone;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Forms\Layouts\Callout;
use PandaPanel\Forms\Layouts\CustomComponent;
use PandaPanel\Forms\Layouts\EmptyState;
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
