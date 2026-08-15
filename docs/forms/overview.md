# FormSchema Basics

`PandaPanel\Forms\FormSchema` is the declarative description of a form: what renders, what validates, and what persists. You reach for it whenever a resource needs a create or edit page, an action needs a dialog with inputs, a relation manager needs a form, or a widget needs filters. All four build the same object, and everything below applies to all four.

## A minimal form

A resource declares one:

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Posts;

use App\Models\Post;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Components\Textarea;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\Resource;

final class PostResource extends Resource
{
    protected static string $model = Post::class;

    public static function form(FormSchema $schema): FormSchema
    {
        return $schema
            ->columns(2)
            ->schema([
                TextInput::make('title')->required()->maxLength(255),
                Textarea::make('excerpt')->rows(3)->columnSpanFull(),
            ]);
    }

    // table() and pages() omitted
}
```

The schema arrives already carrying its model class and the page it is being built for; `form()` fills in the components and returns it. Nothing else is needed for `/admin/posts/create` to render, validate, and save.

## The three separable concerns

A field declares how it renders, what validates it, and whether it persists. Keeping them apart is what makes the password field work:

```php
use PandaPanel\Forms\Components\PasswordInput;

PasswordInput::make('password')
    ->confirmed()
    ->rules(['min:8'])
    ->when(
        $schema->getPage() === 'create',
        static fn (PasswordInput $field): PasswordInput => $field->required(),
        static fn (PasswordInput $field): PasswordInput => $field->optionalWhenFilled(),
    );
```

`required()` on create, optional on edit, still validated when filled, and **not persisted** when blank — so the stored hash is never overwritten with an empty string.

Validation is Laravel's. `required` on a field is a UX marker; removing it in the browser changes nothing. Only declared fields are validated, and only fields that dehydrate are persisted, so an extra key in the request body is discarded rather than mass-assigned.

## FormSchema, method by method

`FormSchema` is `final`. Every method returns `$this` unless the return type says otherwise.

| Method | Signature | What it does |
| --- | --- | --- |
| `make()` | `static make(): self` | A new, empty schema. One column, page `create`, no model |
| `schema()` | `schema(array $components): self` | Replaces the top-level components. Re-indexed with `array_values()` |
| `columns()` | `columns(int $columns): self` | Divides the root grid. Clamped to 1–4 |
| `model()` | `model(string $modelClass): self` | The Eloquent class relation-backed fields resolve against |
| `forPage()` | `forPage(string $page): self` | Which page is being built — `'create'`, `'edit'`, or your own key |
| `getPage()` | `getPage(): string` | The page. Default `'create'` |
| `getModelClass()` | `getModelClass(): ?string` | The model class, or null when none was set |
| `fields()` | `fields(?Model $record = null): array` | Every `Field` visible on the current page, flattened out of the layouts |
| `getComponents()` | `getComponents(): array` | The top-level components, for a caller merging two schemas |
| `field()` | `field(string $name): ?Field` | One visible field by name, or null |
| `wizard()` | `wizard(): ?Wizard` | The form's `Wizard`, if it is one |
| `validationRules()` | `validationRules(?Model $record = null): array` | The whole Laravel rule set |
| `validationRulesForStep()` | `validationRulesForStep(int $step, ?Model $record = null): array` | The subset belonging to one wizard step |
| `relationshipGroups()` | `relationshipGroups(): array` | The `Relationship` layouts anywhere in the tree |
| `dehydrate()` | `dehydrate(array $validated, ?Model $record = null): array` | Validated input turned into attributes to write |
| `saveRelations()` | `saveRelations(Model $record, array $validated): void` | Writes related records and pivot rows, after the record exists |
| `toArray()` | `toArray(?Model $record = null): array` | `['columns' => int, 'schema' => list<array>]` — what crosses the wire |
| `toArrayWithState()` | `toArrayWithState(?Model $record, array $state): array` | The same, with submitted values applied over the field values |

Used outside a resource page, the whole cycle is six lines:

```php
use App\Models\Post;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;

$schema = FormSchema::make()
    ->model(Post::class)
    ->forPage('create')
    ->schema([TextInput::make('title')->required()]);

$rules = $schema->validationRules();           // ['title' => ['required', 'string', 'max:255']]
$data = validator(request()->all(), $rules)->validate();
$attributes = $schema->dehydrate($data);       // ['title' => 'Hello']

$post = Post::query()->create($attributes);
$schema->saveRelations($post, $data);
```

`toArray()` is what a page hands to Inertia:

```php
$form = $schema->toArray($record);
// ['columns' => 2, 'schema' => [['component' => 'field', 'name' => 'title', ...]]]
```

### `__call()` gives a wrong receiver a sentence

Calling a field method on the schema raises `BadMethodCallException` naming the mistake rather than only the class:

```php
FormSchema::make()->columnSpanFull();
// columnSpanFull() belongs to a field, not to the form schema. Move it onto
// the component you meant: …
```

The translated calls are `columnSpan`, `columnSpanFull`, `hidden`, `visible`, `required`, and `disabled`. Anything else gets the ordinary "Call to undefined method" message, because a guess dressed up as a suggestion is worse than no suggestion.

## The field catalogue

Every field extends `PandaPanel\Forms\Components\Field` and is constructed with `Field::make(string $name)`. The name is the request key, the rule key, and — unless `dehydrateTo()` says otherwise — the column.

| Class | `FieldType` | Value shape | Page |
| --- | --- | --- | --- |
| `TextInput` | `text` | `?string` | [Text](fields/text.md) |
| `Textarea` | `textarea` | `?string` | [Text](fields/text.md) |
| `PasswordInput` | `password` | `?string`, never sent back | [Text](fields/text.md) |
| `NumberInput` | `number` | `int\|float\|null` | [Number](fields/number.md) |
| `HiddenInput` | `hidden` | untouched | [Disabled and hidden](disabled-hidden.md) |
| `Slider` | `slider` | `float\|int\|null` | [Slider](fields/slider.md) |
| `ColorPicker` | `color_picker` | `?string` | [Color](fields/color.md) |
| `TagsInput` | `tags_input` | `list<string>` | [Tags](fields/tags.md) |
| `KeyValue` | `key_value` | `array<string, string>` | [Key value](fields/key-value.md) |
| `Checkbox` | `checkbox` | `bool` | [Checkbox](fields/checkbox.md) |
| `Toggle` | `toggle` | `bool` | [Toggle](fields/toggle.md) |
| `Select` | `select` | scalar or `list<string>` | [Select](fields/select.md) |
| `Radio` | `radio` | `string\|int\|null` | [Radio](fields/radio.md) |
| `CheckboxList` | `checkbox_list` | `list<string>` | [Checkbox](fields/checkbox.md) |
| `ToggleButtons` | `toggle_buttons` | scalar or `list<string>` | [Toggle](fields/toggle.md) |
| `DatePicker` | `date` | `?string` (`Y-m-d`) | [Date](fields/date.md) |
| `DateTimePicker` | `datetime` | `?string` (`Y-m-d\TH:i`) | [Date](fields/date.md) |
| `TimePicker` | `time` | `?string` (`H:i`) | [Date](fields/date.md) |
| `RichEditor` | `rich_editor` | `?string` HTML, sanitized | [Rich editor](fields/rich-editor.md) |
| `MarkdownEditor` | `markdown_editor` | `?string` | [Markdown](fields/markdown.md) |
| `CodeEditor` | `code_editor` | `?string` | [Code editor](fields/code-editor.md) |
| `FileUpload` | `file_upload` | path, or list of paths | [File uploads](file-uploads.md) |
| `Repeater` | `repeater` | `list<array>` | [Repeater](fields/repeater.md) |
| `Builder` | `builder` | `list<array{type, data}>` | [Builder](fields/builder.md) |
| `CustomField` | `custom` | whatever your component emits | [Custom fields](custom-fields.md) |

## What every field can do

These live on `Field` and are available on all of the above.

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Enums\ConditionOperator;

TextInput::make('slug')
    ->label('URL slug')                  // label(string): static
    ->placeholder('hello-world')         // placeholder(string): static
    ->helperText('Lowercase, no spaces') // helperText(string): static
    ->required()                         // required(bool = true): static
    ->disabled(false)                    // disabled(bool = true): static
    ->default('hello-world')             // default(mixed): static
    ->columnSpan(2)                      // columnSpan(int): static
    ->inlineLabel()                      // inlineLabel(bool = true): static
    ->rules(['alpha_dash'])              // rules(list<mixed>): static
    ->rulesUsing(static fn (?Model $record): array => [])
    ->hiddenOn(['create'])               // hiddenOn(list<string>): static
    ->visibleOn(['edit'])                // visibleOn(list<string>): static
    ->disabledOn(['edit'])               // disabledOn(list<string>): static
    ->visible(static fn (?Model $record): bool => true)
    ->hidden(false)                      // hidden(Closure|bool = true): static
    ->visibleWhen('kind', ConditionOperator::Equals, 'page')
    ->hiddenWhen('locked')               // hiddenWhen(string, ConditionOperator = Truthy, mixed = null)
    ->live(onBlur: true, debounce: 750)  // live(bool = false, ?int = null): static
    ->formatUsing(static fn (mixed $value, ?Model $record): mixed => $value)
    ->afterStateHydrated(static function (mixed $value, ?Model $record): void {})
    ->afterStateUpdated(static function (mixed $new, mixed $old, ?Model $record): void {})
    ->dehydrateStateUsing(static fn (mixed $value, ?Model $record): mixed => $value)
    ->mutateUsing(static fn (mixed $value, ?Model $record): mixed => $value)
    ->dehydrateWhen(static fn (mixed $value): bool => $value !== '')
    ->dehydrated(true)                   // dehydrated(Closure|bool = true): static
    ->dehydrateTo('url_slug');           // dehydrateTo(string): static
```

`Field` also uses Laravel's `Conditionable`, so `when()` and `unless()` are available for page-dependent configuration without an `if` statement breaking the chain.

The readers a page or an endpoint calls:

| Method | Returns | Notes |
| --- | --- | --- |
| `type()` | `FieldType` | The discriminant the frontend switches on |
| `getName()` | `string` | Prefixed by the relation when inside a `Relationship` |
| `getAttribute()` | `string` | The bare attribute, without the relation prefix |
| `getLabel()` | `string` | `Str::headline($name)` when no label was set |
| `getDehydrateKey()` | `string` | `dehydrateTo()`, or the name |
| `isHiddenOn(string $page, ?Model $record = null)` | `bool` | Server-side visibility, all sources combined |
| `isDisabledOn(string $page, ?Model $record = null)` | `bool` | |
| `matchesConditions(array $state)` | `bool` | The browser-side conditions, answered on the server |
| `isLive()` | `bool` | |
| `isDehydrated(?Model $record = null)` | `bool` | |
| `shouldDehydrate(mixed $value)` | `bool` | `dehydrateWhen()`'s answer |
| `formValue(?Model $record)` | `mixed` | The value the form is populated with |
| `mutate(mixed $value, ?Model $record)` | `mixed` | The value on its way to the record |
| `validationRules(?Model $record)` | `list<mixed>` | |
| `elementRules()` | `list<mixed>` | Rules for `field.*`, empty for a scalar field |
| `nestedRules(?Model $record = null)` | `array<string, list<mixed>>` | A repeater's `items.*.title` |
| `fields()` | `list<Field>` | Itself, for everything but a container |
| `toArray(?Model $record, string $page)` | `?array` | Null when hidden on that page |

## Layout

Containers divide a row; fields say how much of that division they take.

```php
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Components\Textarea;
use PandaPanel\Forms\Layouts\Section;

Section::make('Details')
    ->columns(3)
    ->schema([
        TextInput::make('first_name'),          // one column
        TextInput::make('last_name'),
        TextInput::make('title')->columnSpan(2),
        Textarea::make('bio')->columnSpanFull(), // the whole row
    ]);
```

`columnSpanFull()` rather than `columnSpan(3)`: the number that means "all of them" belongs to the container, and a field that spelled it out would silently become two thirds the day somebody made that section four columns. It crosses the wire as the string `'full'` and becomes `col-span-full`.

Counts are responsive, and a declared count is the count on a wide screen:

| `columns(n)` | base | `md` (768px) | `lg` (1024px) |
| --- | --- | --- | --- |
| 1 | 1 | 1 | 1 |
| 2 | 1 | 2 | 2 |
| 3 | 1 | 2 | 3 |
| 4 | 1 | 2 | 4 |

A span is clamped against *that* table, separately at each breakpoint — so `columnSpan(3)` inside `columns(4)` is two columns at `md` and three at `lg`. Counts above four are clamped to four by `PandaPanel\Support\ColumnCount::clamp()`, because `resources/js/panel/lib/grid.ts` has literal Tailwind classes for one through four and an interpolated `grid-cols-${n}` compiles to nothing.

Spans are on fields and infolist entries only. Calling one on the schema or on a layout is the `__call()` error above; a layout already takes the whole row wherever it appears.

The containers themselves are covered in [Layouts](layouts.md): `Section`, `Grid`, `Tabs`/`Tab`, `Wizard`/`Step`, `Callout`, `EmptyState`, `Relationship`, `CustomComponent`.

## Where a schema comes from

| Caller | How it builds one |
| --- | --- |
| Resource create and edit pages | `Resource::form(FormSchema $schema)`, with `model()` and `forPage()` already applied |
| Actions | `Action::schema(Closure $callback)` — a `Closure(?Model): FormSchema` resolved per record |
| Relation managers | `RelationManager::form(FormSchema $schema, Model $owner)`, merged with the pivot schema by `RelationForm` |
| Widgets | `Widget::filterSchema(): ?FormSchema` |
| Standalone pages | `Page::filterSchema(): ?FormSchema` |

The page a schema is built for matters: `forPage('edit')` is what makes `hiddenOn(['edit'])` apply, and `getPage()` is how a form branches without being told twice.

## Notes

- **Two fields with one name is refused.** `validationRules()` and `toArray()` both call an internal uniqueness check that throws `PandaPanel\Exceptions\PanelSchemaException`. Only one rule survives into the validator and only one value survives into the write, so the other field would be rendered, filled in, submitted, and discarded without a word. A relation group namespaces its children, so `profile.bio` and `bio` are two names.
- **An empty field name is refused at construction.** `Field::make('')` throws `PanelSchemaException` — the name is how the server matches a field to a value, a rule, and a request.
- **A hidden field is absent, not invisible.** It is not in the payload, not in the rules, and not in what dehydrates, so a request that sends it cannot make it exist.
- **Layouts never affect validation or persistence.** Moving a field between sections, tabs, or wizard steps cannot change what the server accepts or writes.
- **`toArray()` has side effects on the schema.** It hydrates relation-backed selects and fills many-to-many values, both idempotently. That is why `validationRules()` and `dehydrate()` do the same rather than assuming somebody already called it.
- **Serialized values are JSON only.** Scalars, arrays, and nulls. A closure runs on the server and its result crosses; the closure never does.

## See also

- [Field state lifecycle](state-lifecycle.md)
- [Hydration and dehydration](hydration.md)
- [Validation](validation.md)
- [Field visibility](visibility.md)
- [Disabled and hidden fields](disabled-hidden.md)
- [Live fields](live-fields.md)
- [Options endpoints](options-endpoints.md)
- [Relationship forms](relationships.md)
- [File uploads](file-uploads.md)
- [Form layouts](layouts.md)
- [Prime components](prime-components.md)
- [Custom fields](custom-fields.md)
- [Creating resources](../resources/creating-resources.md)
- [Action forms](../actions/forms.md)
- [Relation forms](../relations/relation-forms.md)
- [Testing forms](../testing/forms.md)
