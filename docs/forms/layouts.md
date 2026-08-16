# Form Layouts

Layouts arrange a form without changing it. They group fields, divide rows into columns, split a long form into tabs or steps, and put a note where it belongs. You reach for them as soon as a form is longer than a handful of inputs. The rule that makes them safe to move things between: **layout never affects validation or persistence**, so a field means the same thing wherever it sits.

## A minimal example

```php
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Components\Textarea;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Forms\Layouts\Section;

public static function form(FormSchema $schema): FormSchema
{
    return $schema
        ->columns(2)
        ->schema([
            Section::make('Details')
                ->description('Shown on the public page.')
                ->columns(2)
                ->schema([
                    TextInput::make('title')->required(),
                    TextInput::make('slug'),
                    Textarea::make('excerpt')->columnSpanFull(),
                ]),
        ]);
}
```

## Columns and spans

A container is divided by `columns()`; a field says how much of that division it takes with `columnSpan()` or `columnSpanFull()`.

Counts are clamped to 1–4 by `PandaPanel\Support\ColumnCount::clamp()` — the renderer has literal Tailwind classes for one through four, and an interpolated `grid-cols-${n}` compiles to nothing. They are also responsive:

| `columns(n)` | base | `md` (768px) | `lg` (1024px) |
| --- | --- | --- | --- |
| 1 | 1 | 1 | 1 |
| 2 | 1 | 2 | 2 |
| 3 | 1 | 2 | 3 |
| 4 | 1 | 2 | 4 |

A span is clamped against that table separately at each breakpoint, so `columnSpan(3)` inside `columns(4)` is two columns at `md` and three at `lg`. `columnSpanFull()` crosses the wire as the string `'full'` and becomes `col-span-full` — the whole row at every width.

Spans belong to fields and infolist entries. A layout already takes the whole row wherever it appears, and calling a span on the schema raises a `BadMethodCallException` naming the mistake rather than only the class.

## `Section`

A titled group of fields.

| Method | Signature | Default |
| --- | --- | --- |
| `make()` | `static make(string $heading): self` | |
| `schema()` | `schema(array<array-key, FormComponent> $components): self` | `[]` |
| `description()` | `description(string $description): self` | `null` |
| `columns()` | `columns(int $columns): self` | `1` |
| `collapsible()` | `collapsible(bool $collapsible = true): self` | `false` |

```php
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Layouts\Section;

Section::make('Security')
    ->description('Leave the password blank to keep the current one.')
    ->collapsible()
    ->columns(2)
    ->schema([TextInput::make('password')]);
```

## `Grid`

An untitled column grid, for arranging without adding a heading.

| Method | Signature | Default |
| --- | --- | --- |
| `make()` | `static make(int $columns = 2): self` | `2`, clamped to 1–4 |
| `schema()` | `schema(array<array-key, FormComponent> $components): self` | `[]` |

```php
use PandaPanel\Forms\Components\DatePicker;
use PandaPanel\Forms\Layouts\Grid;

Grid::make(3)->schema([
    DatePicker::make('starts_at'),
    DatePicker::make('ends_at'),
    DatePicker::make('reviewed_at'),
]);
```

The column count is fixed at construction; there is no `columns()` setter on `Grid`.

## `Tabs` and `Tab`

A form split into tabs. Every field in every tab is validated on submit, whichever tab is showing — which is what lets the frontend open the tab holding a rejected field.

| Class | Method | Signature | Default |
| --- | --- | --- | --- |
| `Tabs` | `make()` | `static make(array<array-key, Tab> $tabs = []): self` | `[]` |
| `Tabs` | `tabs()` | `tabs(array<array-key, Tab> $tabs): self` | |
| `Tabs` | `persistTab()` | `persistTab(bool $persist = true): self` | `false` |
| `Tab` | `make()` | `static make(string $label): self` | |
| `Tab` | `schema()` | `schema(array<array-key, FormComponent> $components): self` | `[]` |
| `Tab` | `icon()` | `icon(string $icon): self` | `null` |
| `Tab` | `badge()` | `badge(string $badge): self` | `null` |
| `Tab` | `columns()` | `columns(int $columns): self` | `1` |

```php
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Layouts\Tab;
use PandaPanel\Forms\Layouts\Tabs;

Tabs::make([
    Tab::make('Details')->schema([TextInput::make('name')]),
    Tab::make('Security')->icon('shield')->badge('2')->schema([
        TextInput::make('password'),
    ]),
])->persistTab();
```

Each tab serializes a `key` of `Str::slug($label)` and the list of field names it holds, so the frontend can open the right one for a server error without knowing the layout. `persistTab()` remembers the open tab across a reload, in the URL.

The icon is a registry key, never a path. See [Icons](../frontend/icons.md).

## `Wizard` and `Step`

A form split into steps. Presentation only: validation stays whole and server-side, and the frontend jumps to the first step holding a rejected field.

| Class | Method | Signature | Default |
| --- | --- | --- | --- |
| `Wizard` | `make()` | `static make(array<array-key, Step> $steps = []): self` | `[]` |
| `Wizard` | `steps()` | `steps(array<array-key, Step> $steps): self` | |
| `Wizard` | `submitLabel()` | `submitLabel(string $submitLabel): self` | `'Submit'` |
| `Wizard` | `countSteps()` | `countSteps(): int` | |
| `Wizard` | `fieldNamesForStep()` | `fieldNamesForStep(int $step): list<string>` | |
| `Step` | `make()` | `static make(string $label): self` | |
| `Step` | `schema()` | `schema(array<array-key, FormComponent> $components): self` | `[]` |
| `Step` | `description()` | `description(string $description): self` | `null` |
| `Step` | `icon()` | `icon(?string $icon): self` | `null` |
| `Step` | `columns()` | `columns(int $columns): self` | `1` |

```php
use PandaPanel\Forms\Components\PasswordInput;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Layouts\Step;
use PandaPanel\Forms\Layouts\Wizard;

Wizard::make([
    Step::make('Identity')
        ->description('Who they are')
        ->icon('user')
        ->schema([
            TextInput::make('name')->required(),
            TextInput::make('email')->email()->required(),
        ]),

    Step::make('Access')->schema([
        PasswordInput::make('password')->confirmed()->required(),
    ]),
])->submitLabel('Create user');
```

A wizard owns the whole form or none of it: `FormSchema::wizard()` returns the first `Wizard` among the top-level components, and the frontend hands the form over to it only when the wizard is the schema's single node. A form that was partly stepped would have no answer to "which step is this field in".

Moving between steps can be checked without a submit — see [Wizard steps](validation.md#wizard-steps) for the endpoint, `validationRulesForStep()`, and how a confirmation field is grouped with its password.

## `Callout`

A note in the middle of a form. Content, not a control: it holds no fields of its own by default and persists nothing.

| Method | Signature | Default |
| --- | --- | --- |
| `make()` | `static make(string $body): self` | |
| `tone()` | `tone(CalloutTone $tone): self` | `CalloutTone::Info` |
| `heading()` | `heading(string $heading): self` | `null` |
| `icon()` | `icon(string $icon): self` | the tone's own |
| `schema()` | `schema(array<array-key, FormComponent> $components): self` | `[]` |

```php
use PandaPanel\Forms\Components\Checkbox;
use PandaPanel\Forms\Enums\CalloutTone;
use PandaPanel\Forms\Layouts\Callout;

Callout::make('Publishing sends an email to every subscriber.')
    ->heading('This is not reversible')
    ->tone(CalloutTone::Warning)
    ->schema([Checkbox::make('acknowledged')->required()]);
```

`PandaPanel\Forms\Enums\CalloutTone` is a closed set, and each case carries a default icon:

| Case | Value | Icon |
| --- | --- | --- |
| `Info` | `info` | `info` |
| `Success` | `success` | `check` |
| `Warning` | `warning` | `triangle-alert` |
| `Danger` | `danger` | `circle-alert` |

Wrapping components is what makes a callout more than a paragraph: a warning belongs *with* the fields it is about. Fields inside one are validated and persisted exactly as they would be anywhere else.

## `EmptyState`

A stand-in for a part of a schema that has nothing to show — a relation with no records yet, a step that only applies once something else exists.

| Method | Signature | Default |
| --- | --- | --- |
| `make()` | `static make(string $heading): self` | |
| `description()` | `description(string $description): self` | `null` |
| `icon()` | `icon(string $icon): self` | `null` |

```php
use PandaPanel\Forms\Layouts\EmptyState;

EmptyState::make('No invoices yet')
    ->description('They appear here once the first order is paid.')
    ->icon('receipt');
```

It holds no fields and accepts none. Rendering nothing in its place would leave a gap the reader has to interpret; saying why is better.

## `CustomComponent`

A layout drawn by a component of your own, which may still hold ordinary fields.

| Method | Signature | Default |
| --- | --- | --- |
| `make()` | `static make(string $component): self` | |
| `schema()` | `schema(array<array-key, FormComponent> $components): self` | `[]` |
| `config()` | `config(array<string, mixed> $config): self` | `[]` |

```php
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Layouts\CustomComponent;

CustomComponent::make('Panels/Admin/Schemas/Banner')
    ->config(['dismissible' => true])
    ->schema([TextInput::make('name')]);
```

The name is a build-time registry key, never markup and never a path. An unregistered name still renders its children — the wrapper is decoration and the fields inside it are the form. See [Custom fields](custom-fields.md).

## `Relationship`

A group of fields belonging to a related record, namespaced under the relation and written after the owner. Covered in [Relationship forms](relationships.md).

## What crosses the wire

Every layout serializes with a `component` discriminant the frontend switches on:

| Class | `component` | Nests under |
| --- | --- | --- |
| `Section` | `section` | `schema` |
| `Grid` | `grid` | `schema` |
| `Tabs` | `tabs` | `tabs` |
| `Tab` | `tab` | `schema` |
| `Wizard` | `wizard` | `steps` |
| `Step` | `step` | `schema` |
| `Callout` | `callout` | `schema` |
| `EmptyState` | `empty-state` | — |
| `CustomComponent` | `custom` | `schema` |
| `Relationship` | `relationship` | `schema` |
| Fields | `field` | — |
| Prime components | `prime-text`, `prime-icon`, `prime-image` | — |

## Writing your own layout

Extend `PandaPanel\Forms\Components\FormComponent` and implement two methods:

```php
abstract public function fields(): array;                          // list<Field>, recursively
abstract public function toArray(?Model $record, string $page): ?array;
public function children(): array;                                 // list<FormComponent>, optional
```

`fields()` is how validation, hydration, and dehydration see through the layout — return the fields your children hold, or `[]` for content. `children()` matters when a `Relationship` could be nested inside yours, because that is how the schema finds one without knowing every layout type. Note that the frontend renders a fixed set of `component` discriminants: a new PHP layout needs a Vue counterpart, which for most cases is what `CustomComponent` already is.

## Notes

- **Containers always render.** Only a field can disappear on a page, and its container renders as an empty container rather than as gaps.
- **A hidden field drops out of its layout entirely.** It is not in `schema` and not in the rules. A tab's and a step's `fields` list is the exception: it names every field they hold, hidden ones included, because it exists only to point at the tab or step a rejected field sits in.
- **`Grid::make()` clamps, `Grid` has no setter.** Every other container clamps in `columns()`.
- **Tab keys are slugs.** Two tabs whose labels slug to the same string produce the same key.
- **One wizard per form.** `FormSchema::wizard()` returns the first one, and the frontend only hands over when the wizard is the schema's single top-level node.
- **A wizard's per-step rules are derived, not declared.** The step already knows which fields it holds; a second definition could only disagree.
- **Layout cannot change the write.** `dehydrate()` on a stepped form and on the equivalent flat form produce the same attributes.

## See also

- [FormSchema basics](overview.md)
- [Prime components](prime-components.md)
- [Custom fields](custom-fields.md)
- [Relationship forms](relationships.md)
- [Validation](validation.md)
- [Disabled and hidden fields](disabled-hidden.md)
- [Infolist layouts](../infolists/layouts.md)
- [Icons](../frontend/icons.md)
