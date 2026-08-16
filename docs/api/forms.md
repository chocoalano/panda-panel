# Forms Reference

`PandaPanel\Forms\FormSchema`, every field type, every layout, and the hooks that shape a value on its way in and out. A form schema owns three things that must agree — what renders, what validates, and what persists — and all three derive from one field list.

## Namespaces

| Namespace | Holds |
| --- | --- |
| `PandaPanel\Forms\FormSchema` | The schema itself |
| `PandaPanel\Forms\Components\*` | Fields, and the `Field` / `FormComponent` bases |
| `PandaPanel\Forms\Layouts\*` | Containers: sections, grids, tabs, wizards, relation groups |
| `PandaPanel\Forms\Prime\*` | Non-input content: text, icons, images |
| `PandaPanel\Forms\Support\*` | `Block` for the builder field, `Condition` for live visibility |
| `PandaPanel\Forms\Enums\*` | The closed sets that cross into Vue |

## A form that runs

```php
<?php

namespace App\Panels\Admin\Resources\Posts\Forms;

use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\Components\Textarea;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Components\Toggle;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Forms\Layouts\Section;

final class PostForm
{
    public static function configure(FormSchema $schema): FormSchema
    {
        return $schema
            ->columns(2)
            ->schema([
                Section::make('Content')
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')->required()->maxLength(160),
                        Select::make('author')
                            ->relationship('author', 'name')
                            ->searchable()
                            ->required(),
                        Textarea::make('excerpt')->rows(3)->columnSpanFull(),
                    ]),

                Section::make('Publishing')->schema([
                    Toggle::make('is_published')->label('Published'),
                ]),
            ]);
    }
}
```

```php
public static function form(FormSchema $schema): FormSchema
{
    return PostForm::configure($schema);
}
```

Anything the browser sends that has no field here is discarded. A field cannot be validated without being declared, and a value cannot be persisted without a field that dehydrates it.

## `FormSchema`

`final class`. Every builder method returns `self`.

### Building

```php
public static function make(): self;
public function schema(array $components): self;      // array<array-key, FormComponent>
public function columns(int $columns): self;          // clamped to 1..4
public function model(string $modelClass): self;      // class-string<Model>
public function forPage(string $page): self;          // 'create' by default
```

`columns()` clamps through `PandaPanel\Support\ColumnCount::clamp()`, whose `MAX` is `4` — the renderer has literal Tailwind classes for one to four, and an interpolated `grid-cols-6` would not exist in the bundle at all.

`model()` is what lets a relation-backed `Select` learn what its relation points at; `CreateRecord` and `EditRecord` set it and `forPage()` for you.

Calling a field method on the schema throws a `BadMethodCallException` that says so:

```php
FormSchema::make()->columnSpanFull();
// columnSpanFull() belongs to a field, not to the form schema. Move it onto
// the component you meant: ...
```

### Reading

```php
public function getPage(): string;
public function getModelClass(): ?string;
public function fields(?Model $record = null): array;    // list<Field>, visible on this page
public function getComponents(): array;                  // list<FormComponent>, top level
public function field(string $name): ?Field;
public function wizard(): ?Wizard;
public function relationshipGroups(): array;             // list<Relationship>
```

### Validating, persisting, serializing

```php
public function validationRules(?Model $record = null): array;         // array<string, list<mixed>>
public function validationRulesForStep(int $step, ?Model $record = null): array;
public function dehydrate(array $validated, ?Model $record = null): array;
public function saveRelations(Model $record, array $validated): void;
public function toArray(?Model $record = null): array;                 // {columns, schema}
public function toArrayWithState(?Model $record, array $state): array;
```

```php
$schema = PostResource::form(FormSchema::make()->model(Post::class)->forPage('edit'));

$rules      = $schema->validationRules($post);        // ['title' => ['required','string','max:160'], ...]
$validated  = validator($request->all(), $rules)->validate();
$attributes = $schema->dehydrate($validated, $post);  // only fields that dehydrate

$post->forceFill($attributes)->save();
$schema->saveRelations($post, $validated);            // related records, pivot sync
```

`validationRules()` also emits `{name}_confirmation` for a confirmed password, `{name}.*` from `Field::elementRules()`, and nested paths from `Field::nestedRules()` (a repeater's children live at `items.*.title`).

`saveRelations()` must run *after* the record is saved and *inside* the same transaction: a `HasOne` child and a pivot row both need a key that does not exist until the record does.

`toArrayWithState()` re-serializes the schema against values that are not the record's — that is what the live-form endpoint returns. Only fields the schema declares are read out of the state.

Two fields with one name throw `PanelSchemaException::duplicateFields()`. That is checked after relation prefixes are applied, because a relation group namespaces its children: `profile.bio` and `bio` are two names.

## `Field`

`abstract class Field extends FormComponent`. Uses `Illuminate\Support\Traits\Conditionable`, so every field has `when()` and `unless()`.

### Presentation

```php
public static function make(string $name): static;
public function label(string $label): static;              // Str::headline($name)
public function placeholder(string $placeholder): static;  // null
public function helperText(string $helperText): static;    // null
public function required(bool $required = true): static;   // false
public function disabled(bool $disabled = true): static;   // false
public function inlineLabel(bool $inline = true): static;  // false
public function columnSpan(int $span): static;             // 1, min 1
public function columnSpanFull(): static;                  // spans the container, whatever it is
public function default(mixed $default): static;           // null
```

`required()` marks the field for the user *and* adds the `required` rule; `required(false)` adds `nullable`. Removing the flag in the browser changes nothing — the rules are the server's.

`columnSpanFull()` is a word rather than a number because the number meaning "all of them" depends on the container: a field written `columnSpan(2)` inside a two-column section is full width until somebody makes it three.

### Validation

```php
public function rules(array $rules): static;               // []
public function rulesUsing(Closure $callback): static;     // Closure(?Model): list<mixed>
public function validationRules(?Model $record): array;
public function elementRules(): array;                     // rules for `field.*`
public function nestedRules(?Model $record = null): array; // keyed by full path
```

Rules are assembled as `[required|nullable, ...typeRules(), ...rules, ...rulesUsing($record)]`.

```php
use Illuminate\Validation\Rule;

TextInput::make('email')
    ->email()
    ->required()
    ->rulesUsing(static fn (?Model $record): array => [
        $record === null
            ? Rule::unique('users', 'email')
            : Rule::unique('users', 'email')->ignore($record->getKey()),
    ]);
```

### Visibility

```php
public function hiddenOn(array $pages): static;      // list<string>, e.g. ['edit']
public function visibleOn(array $pages): static;     // only these pages
public function disabledOn(array $pages): static;
public function hidden(Closure|bool $condition = true): static;   // Closure(?Model): bool
public function visible(Closure|bool $condition = true): static;
public function isHiddenOn(string $page, ?Model $record = null): bool;
public function isDisabledOn(string $page, ?Model $record = null): bool;
```

Checked strictest-first: an explicit `hidden()`, then a `visible()` that says no, then `visibleOn`, then `hiddenOn`. A field that is absent is not rendered, not validated, and not persisted — three consequences of one decision.

A disabled field is different: it is still shown, still says what the value is, and is still not persisted from the browser.

### Live conditions

```php
public function visibleWhen(string $field, ConditionOperator $operator = ConditionOperator::Truthy, mixed $value = null): static;
public function hiddenWhen(string $field, ConditionOperator $operator = ConditionOperator::Truthy, mixed $value = null): static;
public function matchesConditions(array $state): bool;
```

Re-evaluated in the browser as the user types. Several conditions are ANDed. The comparison is *described*, never scripted — nothing executable crosses the wire — so it has to be expressible as a `ConditionOperator`.

```php
use PandaPanel\Forms\Enums\ConditionOperator;

Select::make('type')->options(['person' => 'Person', 'company' => 'Company'])->live();

TextInput::make('company_number')
    ->visibleWhen('type', ConditionOperator::Equals, 'company');
```

`ConditionOperator`: `Equals`, `NotEquals`, `In`, `NotIn`, `Filled`, `Blank`, `GreaterThan`, `LessThan`, `Truthy`, `Falsy`.

### Live fields

```php
public function live(bool $onBlur = false, ?int $debounce = null): static;   // debounce defaults to 500 ms
public function isLive(): bool;
```

Asks the server to rebuild the form when this field changes, through the panel's `form-state` endpoint. For a dependency the declarative conditions cannot express — a select whose options come from another field's value, a computed total. Off by default, because a round trip per keystroke is the wrong default.

### State hooks

```php
public function formatUsing(Closure $callback): static;          // Closure(mixed, ?Model): mixed — model → form
public function afterStateHydrated(Closure $callback): static;   // observer; its return value is ignored
public function afterStateUpdated(Closure $callback): static;    // Closure(mixed $new, mixed $old, ?Model): void
public function mutateUsing(Closure $callback): static;          // form → model
public function dehydrateStateUsing(Closure $callback): static;  // the same thing, Filament's name
public function mutate(mixed $value, ?Model $record): mixed;
public function formValue(?Model $record): mixed;
public function handleStateUpdated(mixed $state, mixed $previous, ?Model $record = null): void;
```

`dehydrateStateUsing()` and `mutateUsing()` share one implementation, so a schema written either way behaves identically. `dehydrateStateUsing()` wins if both are set.

`afterStateHydrated()` is an observer, not a transformer: what it returns is ignored, so a hook written for its side effect cannot blank the field by returning nothing.

### Persistence

```php
public function dehydrated(Closure|bool $condition = true): static;   // Closure(?Model): bool
public function dehydrateWhen(Closure $callback): static;             // Closure(mixed): bool
public function dehydrateTo(string $attribute): static;
public function isDehydrated(?Model $record = null): bool;
public function shouldDehydrate(mixed $value): bool;
public function getDehydrateKey(): string;
```

`dehydrated()` answers whether this field is written *at all*; `dehydrateWhen()` answers whether *this value* is. `dehydrateTo()` maps a field name onto a different column:

```php
Toggle::make('verified')
    ->label('Email verified')
    ->formatUsing(fn (mixed $value, ?Model $record): bool => $record?->email_verified_at !== null)
    ->dehydrateTo('email_verified_at')
    ->mutateUsing(fn (mixed $value): mixed => $value === true ? now() : null);
```

### Names

```php
public function prefixNameWith(string $prefix): static;   // called by Relationship, never by a schema author
public function getName(): string;                        // 'profile.bio' inside a relation group
public function getAttribute(): string;                   // 'bio' — the attribute on its own record
public function getLabel(): string;
```

### Serialization

```php
public function toArray(?Model $record, string $page): ?array;
public function fields(): array;    // [$this]
abstract public function type(): FieldType;
```

`toArray()` returns `null` for a field hidden on this page. Otherwise: `component: 'field'`, `name`, `label`, `type`, `value`, `placeholder`, `helperText`, `required`, `disabled`, `inlineLabel`, `columnSpan`, `conditions`, `live`, `validation`, plus whatever the concrete field adds.

`validation` is only the subset a browser can honestly check — `required`, `email`, `numeric`, `url`, `min`, `max`, `confirmed`. Anything needing the database (`unique`, `exists`) is deliberately absent, because a frontend guessing at those would be confidently wrong.

## Field types

Every type is `PandaPanel\Forms\Components\*` and inherits the whole `Field` API above. The table is the extra surface.

| Class | `FieldType` | Own methods | Notable defaults |
| --- | --- | --- | --- |
| `TextInput` | `Text` | `email(bool = true)`, `maxLength(?int)`, `minLength(?int)` | `maxLength` **255** |
| `Textarea` | `Textarea` | `rows(int)`, `maxLength(?int)` | `rows` 4 |
| `PasswordInput` | `Password` | `confirmed(bool = true)`, `revealable(bool = true)`, `optionalWhenFilled()`, `isConfirmed()` | `revealable` true |
| `NumberInput` | `Number` | `integer(bool = true)`, `min(int\|float\|null)`, `max(...)`, `step(...)` | all null |
| `HiddenInput` | `Hidden` | — | — |
| `Checkbox` | `Checkbox` | — | `default` **false** |
| `Toggle` | `Toggle` | *(extends `Checkbox`)* | `default` false |
| `Select` | `Select` | `options(array)`, `relationship(string, string)`, `existsIn(string, string)`, `searchable(bool = true)`, `multiple(bool = true)`, `optionLimit(int)` | `optionLimit` 50 |
| `Radio` | `Radio` | `options(array)`, `descriptions(array)`, `inline(bool = true)` | `inline` false |
| `CheckboxList` | `CheckboxList` | `options(array)`, `descriptions(array)`, `columns(int)`, `bulkToggleable(bool = true)` | `columns` 1 |
| `ToggleButtons` | `ToggleButtons` | `options(array)`, `colors(array)`, `icons(array)`, `multiple(bool = true)`, `inline(bool = true)` | `inline` **true** |
| `DatePicker` | `Date` | `minDate(?string)`, `maxDate(?string)` | — |
| `DateTimePicker` | `DateTime` | `minDate(?string)`, `maxDate(?string)`, `seconds(bool = true)` | `seconds` false |
| `TimePicker` | `Time` | `seconds(bool = true)` | `seconds` false |
| `ColorPicker` | `ColorPicker` | `swatches(array)`, `ColorPicker::isColor(string)` | accepts hex, rgb(a), hsl(a) |
| `Slider` | `Slider` | `range(float $min, float $max, float $step = 1)`, `showValue(bool = true)` | 0–100 step 1 |
| `TagsInput` | `TagsInput` | `suggestions(array)`, `maxTags(int)`, `maxLength(int)`, `separator(string)` | `maxLength` 50 |
| `KeyValue` | `KeyValue` | `labels(string $key, string $value)`, `maxPairs(int)`, `addable(bool = true)`, `deletable(bool = true)`, `editableKeys(bool = true)` | labels `Key`/`Value` |
| `RichEditor` | `RichEditor` | `allowedTags(array)`, `toolbar(array)`, `maxLength(int)`, `sanitize(string)` | see below |
| `MarkdownEditor` | `MarkdownEditor` | `toolbar(array)`, `maxLength(int)`, `rows(int)` | `rows` 10 |
| `CodeEditor` | `CodeEditor` | `language(CodeLanguage)`, `rows(int)`, `maxLength(int)` | `Plain`, `rows` 12 |
| `FileUpload` | `FileUpload` | `disk(string)`, `directory(string)`, `multiple(bool)`, `maxSize(int)`, `maxFiles(int)`, `acceptedTypes(array)`, `image(bool)` | `public`, `uploads`, 5120 KB |
| `Repeater` | `Repeater` | see below | — |
| `Builder` | `Builder` | see below | — |
| `CustomField` | `Custom` | `component(string)`, `config(array)` | — |

### `TextInput`

```php
TextInput::make('name')->required()->maxLength(255)->placeholder('Ada Lovelace');
TextInput::make('email')->label('Email address')->email()->required();
TextInput::make('bio')->maxLength(null);      // drop the 255 default
```

### `PasswordInput`

```php
PasswordInput::make('password')
    ->confirmed()                 // adds `confirmed`, expects password_confirmation
    ->rules(['min:8'])
    ->when(
        $isCreate,
        fn (PasswordInput $field) => $field->required(),
        fn (PasswordInput $field) => $field->optionalWhenFilled(),
    );
```

`formValue()` always returns `null`: a password is never sent back to the browser, because rendering the stored hash would put it on screen and in the page payload.

`optionalWhenFilled()` is `required(false)` plus a `dehydrateWhen()` that keeps only non-empty strings — optional on edit, still validated when typed, and dropped when blank so the stored hash survives.

### `Select`

```php
Select::make('status')->options(['draft' => 'Draft', 'live' => 'Live'])->required();

Select::make('author')->relationship('author', 'name')->searchable();

Select::make('tags')->relationship('tags', 'name');    // BelongsToMany → multiple, synced after save
```

A static option list is a whitelist: the submitted value must be one of its keys. A relation is not — the list is one bounded page of a table that may have thousands of rows, so validity is the database's answer (`exists`) and the options are only what the browser could show.

`existsIn('table', 'column')` states that rule explicitly for a field with no relation.

Relation-backed extras:

```php
public function getRelation(): ?string;
public function isMultiple(): bool;
public function writesToPivot(string $modelClass): bool;
public function hydrateRelationship(string $modelClass): void;   // called by the schema
public function resolveOptions(?string $modelClass = null, ?string $search = null): array;
public function relatedKeys(Model $record): array;
public function foreignKeyFor(string $modelClass): ?string;
```

A `BelongsTo` select is named after the relation and persists the foreign key; the schema resolves that in `dehydrateKeyFor()`, so a form does not have to write `->dehydrateTo('author_id')` beside `->relationship('author')`. A `BelongsToMany` select has no column to write to and is synced by `saveRelations()`.

`searchable()` makes the field ask the panel's `options` endpoint for rows its bounded first page could not show. The field is resolved out of the schema that declared it, so nothing about the query comes from the request.

### `FileUpload`

```php
FileUpload::make('avatar')
    ->disk('public')
    ->directory('avatars')
    ->image()
    ->maxSize(2048)
    ->acceptedTypes(['image/png', 'image/jpeg']);
```

The field never carries file contents. The browser posts to the panel's `uploads` endpoint, which stores the file and answers with a path; the form then submits that path like any other string.

Disk, directory, accepted types, and size are enforced twice — by the upload endpoint and again when the form is submitted. `directory()` strips `..` and slashes, because the guarantee rests on a prefix comparison.

```php
public function accepts(string $path): bool;     // is this submitted path inside the declared directory
public function getDisk(): string;
public function getDirectory(): string;
public function getMaxSize(): int;
public function isMultiple(): bool;
public function getAcceptedTypes(): array;
```

### `Repeater`

```php
Repeater::make('items')
    ->schema([
        TextInput::make('title')->required(),
        NumberInput::make('quantity')->integer()->min(1),
    ])
    ->columns(2)
    ->minItems(1)
    ->maxItems(10)
    ->reorderable()
    ->collapsible()
    ->addLabel('Add line')
    ->itemLabel(fn (array $state, int $index): ?string => $state['title'] ?? 'Line '.($index + 1));
```

```php
public function schema(array $components): self;
public function minItems(int $min): self;
public function maxItems(int $max): self;
public function reorderable(bool $reorderable = true): self;   // true by default
public function collapsible(bool $collapsible = true): self;   // false by default
public function addable(bool $addable = true): self;
public function deletable(bool $deletable = true): self;
public function addLabel(string $label): self;
public function columns(int $columns): self;
public function itemLabel(Closure $callback): self;
public function fields(): array;        // [$this]
public function itemFields(): array;    // the children
```

Children validate under `items.*.title`, produced by `nestedRules()` — a path only the field that owns them can build.

### `Builder`

A repeater whose items may each be a different shape.

```php
use PandaPanel\Forms\Components\Builder;
use PandaPanel\Forms\Support\Block;

Builder::make('content')
    ->blocks([
        Block::make('paragraph')->icon('text')->schema([
            RichEditor::make('body'),
        ]),
        Block::make('image')->icon('image')->schema([
            FileUpload::make('path')->image(),
            TextInput::make('caption'),
        ]),
    ])
    ->minItems(1)
    ->reorderable();
```

```php
// Builder
public function blocks(array $blocks): self;
public function minItems(int $min): self;
public function maxItems(int $max): self;
public function reorderable(bool $reorderable = true): self;   // true
public function collapsible(bool $collapsible = true): self;   // true
public function addLabel(string $label): self;
public function block(string $name): ?Block;
public function validateEntries(mixed $value, ?Model $record = null): array;

// Block
public static function make(string $name): self;
public function schema(array $components): self;
public function label(string $label): self;
public function icon(string $icon): self;
public function getName(): string;
public function getLabel(): string;
public function fields(): array;
public function emptyData(): array;
public function dehydrate(array $data, ?Model $record): array;
```

### `RichEditor`

Allowed tags default to `p, br, strong, b, em, i, u, s, ul, ol, li, blockquote, code, pre, h2, h3, h4, a, hr` — no `script`, `style`, `iframe`, `object`, `embed`, or `form`. `sanitize()` is applied on the way in, strips `on*` handlers, and keeps `href`/`src` only when they are relative URLs or use `http`, `https`, `mailto`, or `tel`, so what is stored is what survives the allowlist.

Toolbar default: `bold, italic, strike, link, h2, h3, bulletList, orderedList, blockquote, undo, redo`.

`MarkdownEditor`'s toolbar default: `bold, italic, strike, link, heading, bulletList, orderedList, blockquote, code, preview`.

### `CodeEditor`

```php
use PandaPanel\Forms\Enums\CodeLanguage;

CodeEditor::make('payload')->language(CodeLanguage::Json)->rows(16);
```

`CodeLanguage`: `Plain`, `Json`, `Html`, `Css`, `JavaScript`, `Php`, `Sql`, `Yaml`, `Markdown`.

### `CustomField`

```php
CustomField::make('signature')
    ->component('Panels/Admin/Fields/SignaturePad')   // the path below resources/js/pages/, no extension
    ->config(['penColor' => '#111']);
```

A build-time registry key, never a filesystem path or a class. The key is exactly the component's path below `resources/js/pages/` with `.vue` dropped, and the glob only sees `Panels/**/Fields/*.vue`. An unregistered name renders a fallback rather than being fetched.

## Layouts

Every layout is a `FormComponent` and can nest. `fields()` walks to the leaves; `children()` returns the direct children.

| Class | Constructor | Own methods |
| --- | --- | --- |
| `Section` | `make(string $heading)` | `schema`, `description`, `columns`, `collapsible(bool = true)` |
| `Grid` | `make(int $columns = 2)` | `schema` |
| `Tabs` | `make(array $tabs = [])` | `tabs`, `persistTab(bool = true)` |
| `Tab` | `make(string $label)` | `schema`, `icon`, `badge`, `columns` |
| `Wizard` | `make(array $steps = [])` | `steps`, `submitLabel(string)`, `fieldNamesForStep(int)`, `countSteps()` |
| `Step` | `make(string $label)` | `schema`, `description`, `icon(?string)`, `columns` |
| `Callout` | `make(string $body)` | `tone(CalloutTone)`, `heading`, `icon`, `schema` |
| `EmptyState` | `make(string $heading)` | `description`, `icon` |
| `Relationship` | `make(string $relation)` | `schema`, `heading`, `description`, `columns`, `createsMissing(bool = true)` |
| `CustomComponent` | `make(string $component)` | `schema`, `config` |

`Section` is not collapsible by default; `Tabs` does not persist by default; `Wizard`'s submit label is `Submit`; `Callout`'s tone is `CalloutTone::Info` (`Info`, `Success`, `Warning`, `Danger`).

### Tabs and wizards

```php
use PandaPanel\Forms\Layouts\Step;
use PandaPanel\Forms\Layouts\Tab;
use PandaPanel\Forms\Layouts\Tabs;
use PandaPanel\Forms\Layouts\Wizard;

Tabs::make([
    Tab::make('Details')->icon('info')->columns(2)->schema([...]),
    Tab::make('SEO')->badge('2')->schema([...]),
])->persistTab();

Wizard::make([
    Step::make('Account')->description('Who is this for?')->schema([...]),
    Step::make('Billing')->icon('credit-card')->schema([...]),
])->submitLabel('Create account');
```

A wizard owns the whole form or none of it — a partly stepped form would have no answer to "which step is this field in". `FormSchema::wizard()` finds it, and the create and edit pages register a `.../step` route only when one is present.

Step rules are *derived*, never declared twice: `validationRulesForStep()` filters the whole form's rules down to `Wizard::fieldNamesForStep()`, plus the confirmation key belonging to a password in that step.

### `Relationship`

Fields belonging to a single related record — `BelongsTo`, `HasOne`, or `MorphOne` — edited inside the owner's form.

```php
use PandaPanel\Forms\Layouts\Relationship;

Relationship::make('profile')
    ->heading('Profile')
    ->columns(2)
    ->schema([
        TextInput::make('headline'),
        Textarea::make('bio'),
    ]);
```

Children are namespaced under the relation, so they render, validate, and return errors under `profile.bio` while `getAttribute()` stays `bio`. Laravel validates nested keys natively, so nothing needs a second definition, and a column named `bio` on the owner can coexist.

`createsMissing()` is on by default: a form that renders empty inputs for a profile the user does not have yet and then discards what they typed is worse than one that creates it.

The write happens in `saveRelations()`, after the owner is saved and inside the same transaction.

### `CustomComponent`

```php
use PandaPanel\Forms\Layouts\CustomComponent;

CustomComponent::make('Panels/Admin/Schemas/AddressLookup')
    ->config(['country' => 'ID'])
    ->schema([
        TextInput::make('postcode'),
        TextInput::make('street'),
    ]);
```

A registry key under the application's own tree — the path below `resources/js/pages/`, from the `Panels/**/Schemas/*.vue` glob — drawn around whatever it contains.

## Prime components

Non-input content, for a form that has to say something.

```php
use PandaPanel\Forms\Prime\Icon;
use PandaPanel\Forms\Prime\Image;
use PandaPanel\Forms\Prime\Text;
use PandaPanel\Tables\Enums\BadgeColor;

Text::make('Deleting an account cannot be undone.')->color(BadgeColor::Danger)->icon('alert');
Text::make(fn (?Model $record): string => 'Created '.($record?->created_at?->diffForHumans() ?? 'just now'))->small();
Icon::make('shield')->label('Protected')->color(BadgeColor::Success);
Image::make(fn (?Model $record): ?string => $record?->avatar_url)->alt('Avatar')->width(96)->rounded();
```

| Class | Constructor | Own methods |
| --- | --- | --- |
| `Text` | `make(string\|Closure $content)` | `color(BadgeColor)`, `icon(string)`, `small(bool = true)` |
| `Icon` | `make(string $icon)` | `color(BadgeColor)`, `label(string)` |
| `Image` | `make(string\|Closure $url)` | `alt(string)`, `width(int)`, `rounded(bool = true)` |

A closure is evaluated on the server against the record; only the resulting string crosses.

## Enums

| Enum | Cases |
| --- | --- |
| `FieldType` | `Text`, `Textarea`, `Password`, `Number`, `Hidden`, `Checkbox`, `Toggle`, `Select`, `Date`, `DateTime`, `Time`, `Radio`, `CheckboxList`, `ToggleButtons`, `ColorPicker`, `Slider`, `TagsInput`, `KeyValue`, `RichEditor`, `MarkdownEditor`, `CodeEditor`, `FileUpload`, `Repeater`, `Builder`, `Custom` |
| `ConditionOperator` | `Equals`, `NotEquals`, `In`, `NotIn`, `Filled`, `Blank`, `GreaterThan`, `LessThan`, `Truthy`, `Falsy` |
| `CalloutTone` | `Info`, `Success`, `Warning`, `Danger` |
| `CodeLanguage` | `Plain`, `Json`, `Html`, `Css`, `JavaScript`, `Php`, `Sql`, `Yaml`, `Markdown` |

## Endpoints a form uses

| Route name | Used by |
| --- | --- |
| `panel.{id}.options` | a searchable `Select` asking for more rows; create checks `canCreate()`, edit requires `record` and checks `canEdit($record)` |
| `panel.{id}.uploads` | `FileUpload` storing a file before submit |
| `panel.{id}.form-state` | a `live()` field asking what the form should look like now |
| `panel.{id}.resources.{slug}.validateCreateStep` / `validateEditStep` | a wizard moving between steps |

`form-state` is not a submit: nothing is validated and nothing is written.

## Notes

- **`TextInput` has a `maxLength` of 255 out of the box.** It matches the usual `string` column. Pass `maxLength(null)` for a text column.
- **A hidden field is not validated.** `fields()` filters by `isHiddenOn()` before rules are built, so a rule on a hidden field never runs — which is why hiding and disabling are different tools.
- **`columns()` is clamped to four everywhere.** Asking for six gives four rather than silently collapsing to one.
- **A relation-backed select is never validated against the options it rendered.** The shown page is a display decision; validity is `exists`.
- **`FormSchema` is rebuilt per request.** `Relationship::schema()` mutates its children's name prefix in place, which is safe precisely because nothing is shared between requests.
- **The wizard's step routes only exist when the schema has a `Wizard`.** `CreateRecord` sends `validateStepUrl: null` otherwise, and the frontend renders no stepper.

## See also

- [Forms overview](../forms/overview.md)
- [Layouts](../forms/layouts.md)
- [Validation](../forms/validation.md)
- [Visibility](../forms/visibility.md)
- [Disabled and hidden](../forms/disabled-hidden.md)
- [Live fields](../forms/live-fields.md)
- [State lifecycle](../forms/state-lifecycle.md)
- [Hydration](../forms/hydration.md)
- [Relationships](../forms/relationships.md)
- [File uploads](../forms/file-uploads.md)
- [Options endpoints](../forms/options-endpoints.md)
- [Prime components](../forms/prime-components.md)
- [Custom fields](../forms/custom-fields.md)
- [Resources reference](resources.md)
- [Actions reference](actions.md)
- [Exceptions reference](exceptions.md)
