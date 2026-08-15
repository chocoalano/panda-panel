# Repeater

`PandaPanel\Forms\Components\Repeater` holds a list of entries that all share one sub-schema. Its children are not fields of the form — they are fields of each *item*, so they validate at `items.*.title` and dehydrate once per entry. Reach for it when a record carries a list of the same shape: line items, opening hours, a set of links. When the entries have different shapes, use a [Builder](builder.md).

## The minimal example

```php
use PandaPanel\Forms\Components\NumberInput;
use PandaPanel\Forms\Components\Repeater;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;

FormSchema::make()->schema([
    Repeater::make('line_items')
        ->schema([
            TextInput::make('description')->required(),
            NumberInput::make('quantity')->integer()->min(1)->default(1),
        ])
        ->minItems(1)
        ->columnSpanFull(),
]);
```

The value is a list of maps, so cast the attribute:

```php
protected function casts(): array
{
    return ['line_items' => 'array'];
}
```

## Why its children are not the form's fields

`Repeater::fields()` returns the repeater alone. If it returned its children, `FormSchema` would validate `description` as a top-level name and persist it to a column called `description`. Everything nested is this class's business, which it exposes separately:

```php
/** @return list<Field> the repeater alone */
public function fields(): array

/** @return list<Field> the fields of one entry */
public function itemFields(): array
```

`itemFields()` flattens whatever `schema()` was given, so layouts inside an entry are walked through to the fields they contain.

## The methods

```php
public function schema(array $components): self          // array<array-key, FormComponent>
public function minItems(int $min): self                 // default: null, clamped to >= 0
public function maxItems(int $max): self                 // default: null, clamped to >= 1
public function reorderable(bool $reorderable = true): self  // default: true
public function collapsible(bool $collapsible = true): self  // default: false
public function addable(bool $addable = true): self          // default: true
public function deletable(bool $deletable = true): self      // default: true
public function addLabel(string $label): self                // default: 'Add item'
public function columns(int $columns): self                  // default: 1, clamped to 1..4
public function itemLabel(Closure $callback): self            // default: none
```

| Method | Default | Effect |
| --- | --- | --- |
| `schema()` | `[]` | the components that edit one entry |
| `minItems()` | `null` | adds `min:n`; the frontend stops offering Remove at the floor |
| `maxItems()` | `null` | adds `max:n`; the frontend stops offering Add at the ceiling |
| `reorderable()` | `true` | draws the up/down buttons |
| `collapsible()` | `false` | draws the collapse toggle |
| `addable()` | `true` | draws the add button |
| `deletable()` | `true` | draws the remove button |
| `addLabel()` | `'Add item'` | the add button's label |
| `columns()` | `1` | serialized, but see Gotchas |
| `itemLabel()` | — | the heading each entry wears |

```php
use PandaPanel\Forms\Components\Repeater;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Components\TimePicker;
use PandaPanel\Forms\Layouts\Grid;

Repeater::make('opening_hours')
    ->schema([
        Grid::make(3)->schema([
            TextInput::make('day'),
            TimePicker::make('opens_at'),
            TimePicker::make('closes_at'),
        ]),
    ])
    ->minItems(1)
    ->maxItems(7)
    ->collapsible()
    ->addLabel('Add a day')
    ->columnSpanFull();
```

### `itemLabel()`

```php
/** @param Closure(array<string, mixed> $entry, int $index): ?string $callback */
public function itemLabel(Closure $callback): self
```

The heading an entry shows, resolved **on the server** from that entry's own values, so an item can be named after whatever it holds without the frontend knowing the shape:

```php
use PandaPanel\Forms\Components\Repeater;
use PandaPanel\Forms\Components\TextInput;

Repeater::make('line_items')
    ->schema([TextInput::make('description')])
    ->itemLabel(static fn (array $entry, int $index): ?string => is_string($entry['description'] ?? null)
        && $entry['description'] !== ''
            ? $entry['description']
            : 'Line '.($index + 1));
```

Returning `null` for an entry falls back to `Item N` in the browser, as does declaring no `itemLabel()` at all — in which case `itemLabels` is serialized as an empty list.

## Validation

Two levels, generated from the same field declarations:

```php
use PandaPanel\Forms\Components\NumberInput;
use PandaPanel\Forms\Components\Repeater;
use PandaPanel\Forms\Components\TagsInput;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;

FormSchema::make()
    ->schema([
        Repeater::make('items')
            ->minItems(1)
            ->maxItems(5)
            ->schema([
                TextInput::make('title')->required()->maxLength(80),
                NumberInput::make('quantity')->integer()->min(1),
                TagsInput::make('labels'),
            ]),
    ])
    ->validationRules();

// [
//     'items'            => ['nullable', 'array', 'min:1', 'max:5'],
//     'items.*.title'    => ['required', 'string', 'max:80'],
//     'items.*.quantity' => ['nullable', 'integer', 'min:1'],
//     'items.*.labels'   => ['nullable', 'array'],
//     'items.*.labels.*' => ['string', 'max:50'],
// ]
```

`nestedRules()` is what produces the `items.*.…` keys — a path only the field that owns the children can derive:

```php
public function nestedRules(?Model $record = null): array
```

A child that itself validates a list contributes a third level, `items.*.labels.*`, from its own `elementRules()`.

Errors come back keyed the same way, and `RepeaterField.vue` strips the `items.0.` prefix before handing them to the entry, so a message lands on the field that produced it rather than on the repeater as a whole.

## Dehydration

```php
public function mutate(mixed $value, ?Model $record): mixed
```

Each entry is dehydrated by the fields that describe it, so a key the sub-schema never declared is discarded here exactly as it is at the top level:

```php
use PandaPanel\Forms\Components\Repeater;
use PandaPanel\Forms\Components\TextInput;

$field = Repeater::make('items')->schema([TextInput::make('title')]);

$field->mutate([['title' => 'One', 'injected' => 'nope']], null);
// [['title' => 'One']]
```

Per entry, and per field within it, the same three questions the top-level schema asks are asked again: `isDehydrated()`, `shouldDehydrate()`, and `getDehydrateKey()`. So `dehydrated(false)` on a child keeps it out of every entry, and `dehydrateTo('label')` renames the key inside each one.

A submitted value that is not an array becomes `[]`; an entry that is not an array is skipped.

## Hydration

```php
protected function castForForm(mixed $value): array
```

Any array whose members are arrays. Non-array members are dropped. Each entry is a plain map — not a model — which is why the item schema is serialized against no record: a field inside an entry reads its `default()` rather than an attribute.

### `emptyItem`

The blank entry the frontend adds is built here, from each field's `formValue(null)`, rather than invented in Vue:

```php
use PandaPanel\Forms\Components\NumberInput;
use PandaPanel\Forms\Components\Repeater;
use PandaPanel\Forms\Components\TextInput;

Repeater::make('items')
    ->schema([
        TextInput::make('title'),
        NumberInput::make('quantity')->default(1),
    ])
    ->toArray(null, 'create')['emptyItem'];

// ['title' => null, 'quantity' => 1]
```

## What crosses the wire

```ts
interface RepeaterFieldDefinition extends BaseFieldDefinition {
    type: 'repeater';
    schema: FormComponentDefinition[];
    minItems: number | null;
    maxItems: number | null;
    reorderable: boolean;
    collapsible: boolean;
    addable: boolean;
    deletable: boolean;
    addLabel: string;
    columns: number;
    /** One label per current entry, or empty when none were declared. */
    itemLabels: string[];
    emptyItem: Record<string, unknown>;
}
```

`schema` is serialized once, with `toArray(null, 'create')`, and rendered against each entry's own values by the ordinary component renderer. That is what makes a field inside a repeater behave exactly as it does outside one — including declarative conditions, which read **the entry** rather than the form:

```php
use PandaPanel\Forms\Components\Repeater;
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Enums\ConditionOperator;

Repeater::make('contacts')->schema([
    Select::make('kind')->options(['email' => 'Email', 'phone' => 'Phone']),
    TextInput::make('address')->visibleWhen('kind', ConditionOperator::Equals, 'email'),
    TextInput::make('number')->visibleWhen('kind', ConditionOperator::Equals, 'phone'),
]);
```

`kind` here means *this entry's* `kind`, which is why the sub-schema's names stay plain.

## Repeater or relation manager

A repeater stores a list **in a column**. If the entries are rows in another table, a [relation manager](../../relations/relation-managers.md) is the right tool: it gives each entry its own record, its own policy, its own table, and its own actions. Use a repeater when the list is part of the record — an invoice's line items snapshotted at the time of issue — rather than something with an independent life.

## Gotchas

**`columns()` is serialized but not drawn.** `RepeaterField.vue` stacks an entry's components in a single column and does not read `columns`. Wrap the item schema in a `Grid` to lay it out:

```php
use PandaPanel\Forms\Components\Repeater;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Layouts\Grid;

Repeater::make('items')->schema([
    Grid::make(2)->schema([
        TextInput::make('title'),
        TextInput::make('sku'),
    ]),
]);
```

**Item labels do not follow typing.** They are resolved on the server when the schema is serialized, so an entry renamed in the browser keeps the label it was rendered with until the form is rebuilt or reloaded.

**`min:` and `max:` count entries.** They apply to the array. To bound a value inside an entry, put the rule on the child field, where it becomes `items.*.quantity`.

**A required repeater rejects an empty list.** `required()` on an array means at least one entry; `minItems(1)` says the same thing with a clearer message. Using both is harmless.

**`live()` on a child field is not wired.** The form-state endpoint rebuilds from the form's flat values, and a field inside an entry is not one of them. Declarative conditions do work, because they are evaluated against the entry in the browser.

**`hiddenOn()` inside an entry does nothing useful.** The item schema is always serialized for the `create` page, whatever page the repeater is on. Page-aware visibility belongs on the repeater itself.

**Duplicate names are checked at the top level only.** `FormSchema` asserts unique field names across the components it holds, and a repeater reports only itself — so two children of one repeater with the same name are not caught there. Keep them distinct anyway: the entry is a map, and the second would win.

**Reordering rewrites nothing but order.** The list is stored in the order it is submitted; there is no position column and no sort key.

## See also

- [Builder](builder.md) — entries with different shapes
- [Relation Managers](../../relations/relation-managers.md) — when the entries are rows
- [Layouts](../layouts.md) — `Grid` and `Section` inside an entry
- [Validation](../validation.md)
- [Visibility](../visibility.md) — conditions inside an entry
- [Forms and Schemas](../overview.md)
