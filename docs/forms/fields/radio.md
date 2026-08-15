# Radio

`PandaPanel\Forms\Components\Radio` takes one choice out of a handful, with every option visible at once. It holds the same data a select holds and trades space for the ability to read all the choices without opening anything — so reach for it when there are three or four options that need comparing, and for a [Select](select.md) when there are more.

## The minimal example

```php
use PandaPanel\Forms\Components\Radio;
use PandaPanel\Forms\FormSchema;

FormSchema::make()->schema([
    Radio::make('plan')
        ->options([
            'free' => 'Free',
            'pro' => 'Pro',
            'enterprise' => 'Enterprise',
        ])
        ->required(),
]);
```

## The methods

```php
public function options(array $options): self        // array<array-key, string>
public function descriptions(array $descriptions): self  // array<array-key, string>
public function inline(bool $inline = true): self    // default: false
```

| Method | Default | Effect |
| --- | --- | --- |
| `options()` | `[]` | the choices, keyed by stored value; also the validation whitelist |
| `descriptions()` | `[]` | a line under an option's label, keyed the same way |
| `inline()` | `false` | lays the options out in a wrapping row rather than a column |

```php
use PandaPanel\Forms\Components\Radio;

Radio::make('plan')
    ->label('Subscription')
    ->options([
        'free' => 'Free',
        'pro' => 'Pro',
        'enterprise' => 'Enterprise',
    ])
    ->descriptions([
        'pro' => 'Everything, billed monthly',
        'enterprise' => 'Volume pricing and a named contact',
    ])
    ->default('free')
    ->helperText('Changing this takes effect at the next renewal.');
```

`descriptions()` is keyed by option key, not by position, and an option with no description simply has none. Both arrays accept int keys, which is what makes an enum-backed column work:

```php
use PandaPanel\Forms\Components\Radio;

Radio::make('priority')
    ->options([1 => 'Low', 2 => 'Normal', 3 => 'High'])
    ->inline();
```

## Validation

The declared options are the whitelist. A value the schema never offered is not merely unexpected, it is invalid:

```php
use PandaPanel\Forms\Components\Radio;
use PandaPanel\Forms\FormSchema;

$rules = FormSchema::make()
    ->schema([Radio::make('plan')->options(['free' => 'Free', 'pro' => 'Pro'])])
    ->validationRules();

// ['plan' => ['nullable', Illuminate\Validation\Rules\In]]
// The rule renders as: in:"free","pro"
```

The rule is built with `Illuminate\Validation\Rule::in()` over the option keys cast to strings, so `[1 => 'Low']` produces `in:"1"` and a form posting `'1'` passes.

**With no options declared, no `in` rule is generated at all.** A radio group with an empty option list renders nothing and validates nothing beyond `nullable`/`required` — which is worth knowing when the options come from a query that returned no rows.

## Hydration and the value

```php
protected function castForForm(mixed $value): string|int|null
```

A string or an int is passed through; anything else — a bool, an enum instance, an array — becomes `null`.

On the wire, every option value is a **string**:

```php
use PandaPanel\Forms\Components\Radio;

Radio::make('plan')
    ->options(['free' => 'Free', 'pro' => 'Pro'])
    ->descriptions(['pro' => 'Everything, billed monthly'])
    ->inline()
    ->toArray(null, 'create');
```

```json
{
    "type": "radio",
    "inline": true,
    "options": [
        { "value": "free", "label": "Free", "description": null },
        { "value": "pro", "label": "Pro", "description": "Everything, billed monthly" }
    ]
}
```

The control compares the selection as a string too, so a key that is `1` in the database and `'1'` in the form is the same option rather than two.

## Working with enums

A backed enum is not accepted directly by `options()`, which wants `array<array-key, string>`. Build the map, and cast back on the way out if the column expects the enum:

```php
use App\Enums\Priority;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\Radio;

Radio::make('priority')
    ->options(array_combine(
        array_map(static fn (Priority $case): string => $case->value, Priority::cases()),
        array_map(static fn (Priority $case): string => $case->label(), Priority::cases()),
    ))
    ->formatUsing(static fn (mixed $value, ?Model $record): ?string => $value instanceof Priority
        ? $value->value
        : (is_string($value) ? $value : null))
    ->mutateUsing(static fn (mixed $value, ?Model $record): ?Priority => is_string($value)
        ? Priority::tryFrom($value)
        : null);
```

`formatUsing()` is needed because an enum-cast attribute returns a `Priority` instance, and `castForForm()` answers `null` for it.

## Driving other fields

A radio group is a natural subject for a declarative condition, and both `Equals` and `In` work without a request:

```php
use PandaPanel\Forms\Components\Radio;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Enums\ConditionOperator;

Radio::make('plan')->options(['free' => 'Free', 'pro' => 'Pro', 'enterprise' => 'Enterprise']),

TextInput::make('seats')
    ->visibleWhen('plan', ConditionOperator::In, ['pro', 'enterprise']),

TextInput::make('purchase_order')
    ->visibleWhen('plan', ConditionOperator::Equals, 'enterprise'),
```

If something the server has to compute depends on the choice, mark it `live()` instead.

## What crosses the wire

```ts
interface DescribedOption extends SelectOption {
    value: string;
    label: string;
    description: string | null;
}

interface RadioFieldDefinition extends BaseFieldDefinition {
    type: 'radio';
    options: DescribedOption[];
    inline: boolean;
}
```

## Gotchas

**The submitted value is a string.** `'1'` reaches the server, not `1`. Laravel will happily write it to an integer column, but a strict comparison in a hook will not match. Cast it in `mutateUsing()` if the type matters.

**No options means no whitelist.** An empty `options()` array skips the `in` rule entirely. If the list is built from data, guard against it being empty rather than assuming the rule protects you.

**Options are not searchable and not lazy.** Every option is serialized with the form. A list long enough to want a search box wants a [Select](select.md) with `searchable()`, which fetches through the options endpoint.

**`descriptions()` does not create options.** A description for a key that is not in `options()` is ignored.

**A radio cannot be cleared by the user.** The control offers no "none" affordance; once an option is picked there is no way back to null. Declare the empty choice as a real option with its own key, and map it to null on the way out:

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\Radio;

Radio::make('assignee_kind')
    ->options([
        'none' => 'Unassigned',
        'user' => 'A person',
        'team' => 'A team',
    ])
    ->default('none')
    ->mutateUsing(static fn (mixed $value, ?Model $record): ?string => $value === 'none'
        ? null
        : (is_string($value) ? $value : null));
```

**`inline()` is layout only.** It wraps the options into a row; it does not change the value, the rules, or anything the server sees.

## See also

- [Select](select.md) — the same data, with search and relationships
- [Checkbox](checkbox.md) — a single boolean
- [Toggle](toggle.md)
- [Visibility](../visibility.md) — `Equals`, `In`, and the rest
- [Live Fields](../live-fields.md)
- [Validation](../validation.md)
- [Forms and Schemas](../overview.md)
