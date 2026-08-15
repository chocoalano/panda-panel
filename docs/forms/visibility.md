# Field Visibility

A form field answers four separate questions about whether it appears: which page it is on, what the record is, what another field currently holds, and whether it is editable at all. They are deliberately four methods rather than one, because three of them are settled once on the server and the fourth has to be re-answered in the browser as somebody types. Reach for this page when a field should exist on create but not edit, when it depends on a record, or when it should appear only once another field says a particular thing.

## The minimal example

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Enums\ConditionOperator;
use PandaPanel\Forms\FormSchema;

FormSchema::make()->schema([
    TextInput::make('slug')->visibleOn(['edit']),          // which page
    TextInput::make('email')->disabledOn(['edit']),        // shown, not editable

    TextInput::make('reason')->visible(                    // the record
        static fn (?Model $record): bool => $record !== null,
    ),

    Select::make('kind')->options(['plain' => 'Plain', 'other' => 'Other']),
    TextInput::make('other')                               // another field's value
        ->visibleWhen('kind', ConditionOperator::Equals, 'other'),
]);
```

The first three are decided while the schema is built. The last is decided in the browser, every keystroke, without a request.

## What "hidden" means

A hidden field is not merely invisible. `FormSchema::fields()` filters on `Field::isHiddenOn()`, and everything the schema does is derived from that one list:

| Derived from `fields()` | Consequence for a hidden field |
| --- | --- |
| `toArray()` | not serialized, so the browser never receives it |
| `validationRules()` | no rule, so no message can name it |
| `dehydrate()` | no attribute, so nothing is written |

A request that posts `slug` to a create page whose schema hides `slug` cannot make it exist: the key is discarded before anything reads it, exactly as an undeclared key is.

## Page-aware visibility

Every schema is built for one page, set by `FormSchema::forPage()` and readable with `getPage()`. The page keys the framework uses are:

| Key | Where it comes from |
| --- | --- |
| `create` | `CreateRecord`, `CreateAction`, a relation form for a new related record |
| `edit` | `EditRecord`, a relation form editing an existing related record |
| `view` | `ViewRecord` |

```php
public function visibleOn(array $pages): static
public function hiddenOn(array $pages): static
public function disabledOn(array $pages): static
```

```php
use PandaPanel\Forms\Components\TextInput;

TextInput::make('slug')->visibleOn(['edit']);        // only on edit
TextInput::make('slug')->hiddenOn(['create']);       // everywhere but create
TextInput::make('email')->disabledOn(['edit', 'view']);
```

`visibleOn()` and `hiddenOn()` are inverses, and both exist because a field belonging to one page reads better as `visibleOn(['create'])` than as a list of every other page. `visibleOn()` defaults to `null` — meaning "no restriction" — which is not the same as `visibleOn([])`, which hides the field everywhere.

The schema's page is also available while you build it, which is the usual way to configure a field differently per page:

```php
use PandaPanel\Forms\Components\PasswordInput;

PasswordInput::make('password')
    ->when(
        $schema->getPage() === 'create',
        static fn (PasswordInput $field): PasswordInput => $field->required(),
        static fn (PasswordInput $field): PasswordInput => $field->optionalWhenFilled(),
    );
```

`when()` and `unless()` come from Laravel's `Illuminate\Support\Traits\Conditionable`, which `Field` uses. They are ordinary configuration, evaluated while the schema is built.

## Record-aware visibility

```php
public function hidden(Closure|bool $condition = true): static
public function visible(Closure|bool $condition = true): static
```

Both accept a bool or a `Closure(?Model $record): bool`. The record is `null` on a create page, which is what makes "only when editing" expressible:

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\Textarea;

Textarea::make('rejection_reason')->visible(
    static fn (?Model $record): bool => $record?->isRejected() === true,
);

Textarea::make('internal_note')->hidden(
    static fn (?Model $record): bool => $record === null,
);

Textarea::make('legacy_body')->hidden();   // bool form: always hidden
```

The closure runs once, while the schema is serialized. It cannot react to what is being typed — that is what the declarative conditions below are for, and the split is deliberate rather than a limitation nobody got to.

### The order the answers are checked

`Field::isHiddenOn()` asks four things in an order that makes the strictest win:

```php
public function isHiddenOn(string $page, ?Model $record = null): bool
```

1. an explicit `hidden()` that says yes,
2. a `visible()` that says no,
3. a `visibleOn()` list that does not name this page,
4. a `hiddenOn()` list that does.

So a field with both `visible(fn () => false)` and `visibleOn(['edit'])` is hidden on `edit` too. There is no combination in which a later check re-reveals a field an earlier one hid.

## Disabled is not hidden

```php
public function disabled(bool $disabled = true): static
public function disabledOn(array $pages): static
public function isDisabledOn(string $page, ?Model $record = null): bool
```

A disabled field is still rendered, still shows its value, and still appears in the rules — it is a presentation state, not an absence:

```php
use PandaPanel\Forms\Components\TextInput;

TextInput::make('email')->disabled();
TextInput::make('email')->disabledOn(['edit']);
```

`disabled()` takes a bool only. There is no record-aware disable callback on `Field`; the record-aware questions are `hidden()` and `visible()`. If a field must be read-only for some records and editable for others, hide it and show a read-only alternative, or branch on the page while building the schema.

Because a disabled control is a browser state, it is not a control over what is submitted. The value still validates and still dehydrates. Use `dehydrated(false)` when a field must not be written whatever arrives.

## Conditions on another field's value

```php
public function visibleWhen(
    string $field,
    ConditionOperator $operator = ConditionOperator::Truthy,
    mixed $value = null,
): static

public function hiddenWhen(
    string $field,
    ConditionOperator $operator = ConditionOperator::Truthy,
    mixed $value = null,
): static
```

These are different in kind from everything above. They have to react as somebody types, which means being evaluated in the browser — and nothing executable crosses the wire. So the server sends a *description* of the comparison and the compiled-in frontend performs it.

```php
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Components\Toggle;
use PandaPanel\Forms\Enums\ConditionOperator;

Select::make('kind')->options(['plain' => 'Plain', 'other' => 'Other']),
TextInput::make('other_kind')
    ->visibleWhen('kind', ConditionOperator::Equals, 'other'),

Toggle::make('notify'),
TextInput::make('notify_email')
    ->visibleWhen('notify'),                                  // Truthy, the default

TextInput::make('override')
    ->hiddenWhen('locked', ConditionOperator::Truthy),
```

Each call appends a condition. Several `visibleWhen()` calls are ANDed, and any matching `hiddenWhen()` wins:

```php
TextInput::make('other')
    ->visibleWhen('kind', ConditionOperator::Filled)
    ->visibleWhen('quantity', ConditionOperator::GreaterThan, 0)
    ->hiddenWhen('locked', ConditionOperator::Truthy);
```

### The operators

`PandaPanel\Forms\Enums\ConditionOperator` is a closed set. Anything not expressible here belongs in a server-side `visible()` closure, which is honest about being evaluated once.

| Case | Wire value | Needs a value | Holds when |
| --- | --- | --- | --- |
| `Equals` | `equals` | yes | the two compare equal as strings |
| `NotEquals` | `not_equals` | yes | they do not |
| `In` | `in` | yes (array) | the value is one of the listed ones |
| `NotIn` | `not_in` | yes (array) | it is not, or nothing was listed |
| `Filled` | `filled` | no | not `null`, `''`, or `[]` |
| `Blank` | `blank` | no | `null`, `''`, or `[]` |
| `GreaterThan` | `greater_than` | yes | both sides are numeric and left > right |
| `LessThan` | `less_than` | yes | both sides are numeric and left < right |
| `Truthy` | `truthy` | no | PHP truthiness — `'0'` is false |
| `Falsy` | `falsy` | no | the opposite |

```php
public function needsValue(): bool
public function matches(mixed $state, mixed $expected): bool
```

```php
use PandaPanel\Forms\Enums\ConditionOperator;

ConditionOperator::In->matches('b', ['a', 'b']);          // true
ConditionOperator::GreaterThan->matches('5', 3);          // true
ConditionOperator::GreaterThan->matches('abc', 3);        // false — unanswerable, not "greater"
ConditionOperator::Filled->needsValue();                  // false
```

Comparisons are made as strings, so `1` from a model and `'1'` from a form are the same answer. A form's values arrive as text whatever the column holds.

### The `Condition` object

`visibleWhen()` and `hiddenWhen()` build `PandaPanel\Forms\Support\Condition` values. You rarely construct one yourself, but the class is public and is what serializes:

```php
final readonly class Condition
{
    public function __construct(
        public string $field,
        public ConditionOperator $operator,
        public mixed $value = null,
    ) {}

    public static function make(
        string $field,
        ConditionOperator $operator = ConditionOperator::Truthy,
        mixed $value = null,
    ): self;

    /** @param array<string, mixed> $state */
    public function matches(array $state): bool;

    /** @return array{field: string, operator: string, value: mixed} */
    public function toArray(): array;
}
```

```php
use PandaPanel\Forms\Enums\ConditionOperator;
use PandaPanel\Forms\Support\Condition;

Condition::make('kind', ConditionOperator::Equals, 'special')
    ->matches(['kind' => 'special']);                     // true

// An operator that compares against nothing sends no value.
Condition::make('kind', ConditionOperator::Filled, 'ignored')->toArray();
// ['field' => 'kind', 'operator' => 'filled', 'value' => null]
```

### What the browser receives

Every serialized field carries a `conditions` key, whatever it declared:

```php
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Enums\ConditionOperator;

TextInput::make('other')
    ->visibleWhen('kind', ConditionOperator::Equals, 'special')
    ->toArray(null, 'create')['conditions'];
```

```json
{
    "visibleWhen": [{ "field": "kind", "operator": "equals", "value": "special" }],
    "hiddenWhen": []
}
```

`resources/js/panel/forms/conditions.ts` is the compiled-in half. It mirrors the PHP enum case for case, including PHP's truthiness for `'0'`, and exports:

```ts
export function matchesConditions(
    conditions: FieldConditions | undefined,
    values: FormValues,
): boolean;

export function conditionDependencies(
    conditions: FieldConditions | undefined,
): string[];
```

`FormComponentRenderer.vue` renders nothing for a field whose conditions do not hold, and `validateFields()` skips it in the client-side pre-check.

### Answering a condition in PHP

```php
/** @param array<string, mixed> $state */
public function matchesConditions(array $state): bool
```

The same object answers on the server, which is how you can assert in a test that a form will behave the way you think:

```php
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Enums\ConditionOperator;

$field = TextInput::make('other')
    ->visibleWhen('kind', ConditionOperator::Equals, 'special');

$field->matchesConditions(['kind' => 'special']);   // true
$field->matchesConditions(['kind' => 'plain']);     // false
```

It considers the declarative conditions only. The server-side answers are already settled by the time a schema is serialized — a field they hid is not in the payload at all.

## Method reference

| Method | Signature | Evaluated |
| --- | --- | --- |
| `visibleOn()` | `(list<string> $pages): static` | server, at build time |
| `hiddenOn()` | `(list<string> $pages): static` | server, at build time |
| `disabledOn()` | `(list<string> $pages): static` | server, at build time |
| `visible()` | `(Closure(?Model): bool\|bool $condition = true): static` | server, once per render |
| `hidden()` | `(Closure(?Model): bool\|bool $condition = true): static` | server, once per render |
| `disabled()` | `(bool $disabled = true): static` | server, at build time |
| `visibleWhen()` | `(string $field, ConditionOperator $operator = Truthy, mixed $value = null): static` | browser, per keystroke |
| `hiddenWhen()` | `(string $field, ConditionOperator $operator = Truthy, mixed $value = null): static` | browser, per keystroke |
| `isHiddenOn()` | `(string $page, ?Model $record = null): bool` | — |
| `isDisabledOn()` | `(string $page, ?Model $record = null): bool` | — |
| `matchesConditions()` | `(array<string, mixed> $state): bool` | — |

## Gotchas

**A conditionally hidden field is still in the server's rule set.** `FormSchema::validationRules()` is built from `isHiddenOn()`, which does not consult `visibleWhen()`/`hiddenWhen()`. So `->required()->visibleWhen('kind', Equals, 'other')` produces a `required` rule that applies whether or not the browser drew the field, and a form can be rejected for a field nobody could see. Express the server half with Laravel's own conditional rules instead:

```php
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Enums\ConditionOperator;

TextInput::make('other_kind')
    ->visibleWhen('kind', ConditionOperator::Equals, 'other')
    ->rules(['required_if:kind,other']);
```

**Conditions name a field, not a path.** The name is looked up in the form's flat value map, so it is the field's `getName()` — including the relation prefix for a field inside a `Relationship` group (`profile.bio`). Inside a repeater or a builder block, the values a condition reads are that *entry's* values, so the names there are the sub-schema's plain names.

**A hidden field's value is still submitted.** The browser keeps the value of a field its conditions hid and posts it; it is the server's rule set and `dehydrate()` that decide what happens to it. Nothing is stripped client-side, because a client that stripped values would be a client deciding what the form is.

**`visibleOn([])` hides the field everywhere.** An empty list is a list that names no page. Pass no `visibleOn()` at all for "no page restriction".

**`disabled()` is not a write guard.** Use `dehydrated(false)` for a field that must never reach a column, and `hidden()` for one that must not exist at all.

**Conditions cannot read a relation manager's owner or the record.** They read the form's own values and nothing else. Record-dependent visibility is `visible()`/`hidden()`.

## See also

- [Forms and Schemas](overview.md) — the schema these fields live in
- [Disabled and Hidden Fields](disabled-hidden.md)
- [Validation](validation.md) — how the rule set is assembled
- [Live Fields](live-fields.md) — when a condition cannot express the dependency
- [State Lifecycle](state-lifecycle.md) — `dehydrated()`, `dehydrateTo()`, and the hooks
- [Layouts](layouts.md) — sections, grids, tabs, and wizards
- [Resource Pages](../resources/resource-pages.md) — where the page key comes from
