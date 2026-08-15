<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use PandaPanel\Forms\Components\Builder;
use PandaPanel\Forms\Components\CheckboxList;
use PandaPanel\Forms\Components\CodeEditor;
use PandaPanel\Forms\Components\ColorPicker;
use PandaPanel\Forms\Components\CustomField;
use PandaPanel\Forms\Components\DateTimePicker;
use PandaPanel\Forms\Components\FileUpload;
use PandaPanel\Forms\Components\KeyValue;
use PandaPanel\Forms\Components\MarkdownEditor;
use PandaPanel\Forms\Components\Radio;
use PandaPanel\Forms\Components\Repeater;
use PandaPanel\Forms\Components\RichEditor;
use PandaPanel\Forms\Components\Slider;
use PandaPanel\Forms\Components\TagsInput;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Components\TimePicker;
use PandaPanel\Forms\Components\ToggleButtons;
use PandaPanel\Forms\Enums\CodeLanguage;
use PandaPanel\Forms\Enums\FieldType;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Forms\Support\Block;
use PandaPanel\Tables\Enums\BadgeColor;

/**
 * Rules are a mix of strings and rule objects, so they are compared as the
 * strings Laravel finally reads.
 *
 * @param  list<mixed>  $rules
 * @return list<string>
 */
function ruleStrings(array $rules): array
{
    return array_map(strval(...), $rules);
}

/*
 * Choice fields
 */

it('serializes a radio\'s options with their descriptions', function (): void {
    $field = Radio::make('plan')
        ->options(['free' => 'Free', 'pro' => 'Pro'])
        ->descriptions(['pro' => 'Everything, billed monthly'])
        ->inline();

    $definition = $field->toArray(null, 'create');

    expect($definition['type'])->toBe('radio')
        ->and($definition['inline'])->toBeTrue()
        ->and($definition['options'])->toBe([
            ['value' => 'free', 'label' => 'Free', 'description' => null],
            ['value' => 'pro', 'label' => 'Pro', 'description' => 'Everything, billed monthly'],
        ]);
});

it('accepts only the keys a choice field declared', function (): void {
    $rules = FormSchema::make()
        ->schema([Radio::make('plan')->options(['free' => 'Free'])])
        ->validationRules();

    // A value the schema never offered is not merely unexpected, it is
    // invalid: the whitelist is the rule.
    expect(ruleStrings($rules['plan']))->toContain('in:"free"');
});

it('validates each entry of a multi-choice field, not the set', function (): void {
    $rules = FormSchema::make()
        ->schema([
            CheckboxList::make('roles')->options(['a' => 'A', 'b' => 'B']),
        ])
        ->validationRules();

    expect($rules['roles'])->toContain('array')
        ->and(ruleStrings($rules['roles.*']))->toContain('in:"a","b"');
});

it('carries a colour and an icon per toggle button', function (): void {
    $definition = ToggleButtons::make('status')
        ->options(['live' => 'Live'])
        ->colors(['live' => BadgeColor::Success])
        ->icons(['live' => 'check'])
        ->toArray(null, 'create');

    expect($definition['options'][0])->toBe([
        'value' => 'live',
        'label' => 'Live',
        'color' => 'success',
        'icon' => 'check',
    ]);
});

it('validates a multiple toggle-buttons value as a set', function (): void {
    $rules = FormSchema::make()
        ->schema([
            ToggleButtons::make('tags')->options(['a' => 'A'])->multiple(),
        ])
        ->validationRules();

    expect($rules['tags'])->toContain('array')
        ->and(ruleStrings($rules['tags.*']))->toContain('in:"a"');
});

/*
 * Scalar fields
 */

it('rejects a colour that is not one', function (): void {
    expect(ColorPicker::isColor('#a1b2c3'))->toBeTrue()
        ->and(ColorPicker::isColor('rgb(1, 2, 3)'))->toBeTrue()
        // Not a colour but a stylesheet, which is the case that matters: a
        // stored value ends up inside a `style` attribute.
        ->and(ColorPicker::isColor('red; background: url(x)'))->toBeFalse()
        ->and(ColorPicker::isColor('expression(alert(1))'))->toBeFalse();
});

it('bounds a slider by the range it declares', function (): void {
    $rules = FormSchema::make()
        ->schema([Slider::make('weight')->range(10, 20, 5)])
        ->validationRules();

    expect($rules['weight'])->toContain('numeric')
        ->and($rules['weight'])->toContain('min:10')
        ->and($rules['weight'])->toContain('max:20');
});

it('limits both the number of tags and the length of each', function (): void {
    $rules = FormSchema::make()
        ->schema([TagsInput::make('tags')->maxTags(3)->maxLength(20)])
        ->validationRules();

    expect($rules['tags'])->toContain('array')
        ->and($rules['tags'])->toContain('max:3')
        ->and($rules['tags.*'])->toContain('max:20');
});

it('keeps a key-value field a map of strings', function (): void {
    $rules = FormSchema::make()
        ->schema([KeyValue::make('meta')])
        ->validationRules();

    expect($rules['meta'])->toContain('array');
});

it('formats a date-time for the control that will hold it', function (): void {
    $field = DateTimePicker::make('published_at');

    expect($field->toArray(null, 'create')['seconds'])->toBeFalse()
        ->and(DateTimePicker::make('at')->seconds()->toArray(null, 'create')['seconds'])
        ->toBeTrue()
        ->and(TimePicker::make('opens_at')->toArray(null, 'create')['type'])
        ->toBe('time');
});

/*
 * Editors
 */

it('strips everything a rich editor did not allow', function (): void {
    $field = RichEditor::make('body');

    $sanitized = $field->sanitize(
        '<p>Hello <script>alert(1)</script><b onclick="steal()">there</b></p>',
    );

    expect($sanitized)->not->toContain('script')
        ->and($sanitized)->not->toContain('onclick')
        ->and($sanitized)->toContain('<b>there</b>');
});

it('refuses a javascript link inside stored HTML', function (): void {
    $sanitized = RichEditor::make('body')
        ->sanitize('<a href="javascript:alert(1)">click</a>');

    expect($sanitized)->not->toContain('javascript:');
});

it('sanitizes on the way to the record, not merely on display', function (): void {
    $mutated = RichEditor::make('body')
        ->mutate('<p>ok</p><iframe src="https://evil.test"></iframe>', null);

    expect($mutated)->toBe('<p>ok</p>');
});

it('stores markdown and code as the text they are', function (): void {
    $markdown = MarkdownEditor::make('notes')->toArray(null, 'create');
    $code = CodeEditor::make('config')
        ->language(CodeLanguage::Json)
        ->toArray(null, 'create');

    expect($markdown['type'])->toBe('markdown_editor')
        ->and($code['language'])->toBe('json');
});

/*
 * Files
 */

it('accepts only a path it issued, on the disk it declared', function (): void {
    Storage::fake('local');

    $field = FileUpload::make('avatar')->disk('local')->directory('avatars');

    Storage::disk('local')->put('avatars/one.png', 'x');
    Storage::disk('local')->put('elsewhere/two.png', 'x');

    expect($field->accepts('avatars/one.png'))->toBeTrue()
        // Outside the declared directory, missing, or trying to climb out of
        // it — all the same answer.
        ->and($field->accepts('elsewhere/two.png'))->toBeFalse()
        ->and($field->accepts('avatars/missing.png'))->toBeFalse()
        ->and($field->accepts('avatars/../elsewhere/two.png'))->toBeFalse();
});

it('drops a path the field would not accept rather than storing it', function (): void {
    Storage::fake('local');

    $field = FileUpload::make('avatar')->disk('local')->directory('avatars');

    expect($field->mutate('avatars/never-uploaded.png', null))->toBeNull();
});

it('keeps a multiple upload a list of accepted paths', function (): void {
    Storage::fake('local');

    Storage::disk('local')->put('avatars/one.png', 'x');

    $field = FileUpload::make('gallery')
        ->disk('local')
        ->directory('avatars')
        ->multiple();

    expect($field->mutate(['avatars/one.png', 'avatars/fake.png'], null))
        ->toBe(['avatars/one.png']);
});

/*
 * Repeaters and builders
 */

it('validates each repeater entry against the item schema', function (): void {
    $rules = FormSchema::make()
        ->schema([
            Repeater::make('items')->schema([
                TextInput::make('title')->required(),
            ]),
        ])
        ->validationRules();

    expect($rules)->toHaveKey('items.*.title')
        ->and($rules['items.*.title'])->toContain('required');
});

it('discards a repeater key the item schema never declared', function (): void {
    $field = Repeater::make('items')->schema([TextInput::make('title')]);

    expect($field->mutate([
        ['title' => 'One', 'injected' => 'nope'],
    ], null))->toBe([['title' => 'One']]);
});

it('drops a builder block whose type the schema does not declare', function (): void {
    $field = Builder::make('content')->blocks([
        Block::make('paragraph')->schema([TextInput::make('body')]),
    ]);

    $mutated = $field->mutate([
        ['type' => 'paragraph', 'data' => ['body' => 'Hello']],
        ['type' => 'unknown', 'data' => ['body' => 'Hello']],
    ], null);

    expect($mutated)->toBe([
        ['type' => 'paragraph', 'data' => ['body' => 'Hello']],
    ]);
});

it('sends each block\'s own schema and blank entry', function (): void {
    $definition = Builder::make('content')
        ->blocks([
            Block::make('quote')->label('Quotation')->schema([
                TextInput::make('body'),
            ]),
        ])
        ->toArray(null, 'create');

    expect($definition['blocks'][0]['name'])->toBe('quote')
        ->and($definition['blocks'][0]['label'])->toBe('Quotation')
        ->and($definition['blocks'][0]['emptyData'])->toHaveKey('body');
});

/*
 * Custom fields
 */

it('sends a custom field as a registry key and a configuration', function (): void {
    $definition = CustomField::make('rating')
        ->component('Panels/Admin/Fields/StarRating')
        ->config(['max' => 5])
        ->toArray(null, 'create');

    expect($definition['type'])->toBe('custom')
        ->and($definition['componentName'])->toBe('Panels/Admin/Fields/StarRating')
        ->and($definition['config'])->toBe(['max' => 5]);
});

/*
 * The two halves of the union
 */

it('describes every field type on the frontend as well', function (): void {
    $definitions = file_get_contents(
        base_path('resources/js/panel/types/form.ts'),
    );

    expect($definitions)->not->toBeFalse();

    preg_match_all("/^\\s+type: '([a-z_]+)';$/m", (string) $definitions, $matches);

    $described = $matches[1];

    $declared = array_map(
        static fn (FieldType $type): string => $type->value,
        FieldType::cases(),
    );

    // The renderer's own switch is exhaustive over the TypeScript union, so
    // a type in that union without a control is a compile error. What the
    // compiler cannot see is a PHP case that never reached the union at all
    // — which would render nothing, silently, in production.
    expect(array_diff($declared, $described))->toBe([]);
});
