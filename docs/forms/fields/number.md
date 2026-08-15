# Number

`PandaPanel\Forms\Components\NumberInput` is a numeric input whose declared bounds are also its validation rules. Reach for it whenever a column holds a number a person types — a price, a quantity, a weight. When the number is chosen from a range rather than typed, use [Slider](slider.md) instead.

## The minimal example

```php
use PandaPanel\Forms\Components\NumberInput;
use PandaPanel\Forms\FormSchema;

FormSchema::make()->schema([
    NumberInput::make('quantity')
        ->integer()
        ->min(1)
        ->max(999)
        ->required(),
]);
```

## The methods

```php
public function integer(bool $integer = true): self     // default: false
public function min(int|float|null $min): self          // default: null
public function max(int|float|null $max): self          // default: null
public function step(int|float|null $step): self        // default: null
```

| Method | Default | Rule it adds | Attribute it sets |
| --- | --- | --- | --- |
| `integer()` | `false` | `integer` instead of `numeric` | — (it also decides the default `step`) |
| `min()` | `null` | `min:n` | the input's `min` |
| `max()` | `null` | `max:n` | the input's `max` |
| `step()` | `null` | none | the input's `step` |

```php
use PandaPanel\Forms\Components\NumberInput;

NumberInput::make('price')
    ->label('Price (EUR)')
    ->min(0)
    ->max(100_000)
    ->step(0.01)
    ->placeholder('0.00')
    ->helperText('Excluding tax.');
```

All four accept `null` to clear a previously set value, except `integer()`, which takes a bool.

### `step()` and its default

`step` never becomes a rule — it is a browser affordance, deciding what the spinner arrows do and what the native validity check accepts. What is serialized is:

```php
'step' => $this->step ?? ($this->integer ? 1 : null),
```

So an `integer()` field steps by one without being told, and a decimal field has no step at all — which in HTML means `step="1"` is *not* applied and the browser accepts decimals. Set `step(0.01)` for money, and `step(0.5)` for half units.

## Validation

```php
use PandaPanel\Forms\Components\NumberInput;
use PandaPanel\Forms\FormSchema;

FormSchema::make()
    ->schema([
        NumberInput::make('quantity')->integer()->min(1)->max(99)->required(),
        NumberInput::make('weight')->min(0.5),
    ])
    ->validationRules();

// [
//     'quantity' => ['required', 'integer', 'min:1', 'max:99'],
//     'weight'   => ['nullable', 'numeric', 'min:0.5'],
// ]
```

`min:` and `max:` on a numeric value compare the **value**, not its length — that is what `numeric` and `integer` decide. Getting this the other way round is the classic Laravel mistake, and it is why the type rule always comes first in the list.

The browser receives the same bounds twice: as `min`/`max` attributes on the input, and as `validation` hints that `validateFields()` checks before submitting.

```php
use PandaPanel\Forms\Components\NumberInput;

NumberInput::make('quantity')->integer()->min(1)->max(99)->toArray(null, 'create')['validation'];
// ['required' => false, 'numeric' => true, 'min' => 1.0, 'max' => 99.0]
```

`numeric` is the hint for both `numeric` and `integer` — the browser cannot honestly distinguish them any better than that, and the server checks the real rule.

## Hydration

```php
protected function castForForm(mixed $value): int|float|null
```

`is_numeric($value) ? $value + 0 : null`. The `+ 0` is what makes a `decimal` column returning the string `'19.99'` arrive as the float `19.99`, and an `int` column as an int. Anything non-numeric — including `''` — becomes `null`.

```php
use PandaPanel\Forms\Components\NumberInput;

NumberInput::make('price')->formValue($record);   // 19.99, 42, or null
```

The control mirrors this: an empty input emits `null`, not `''`, so an optional number clears cleanly to a nullable column.

## Recipes

**Money.** Store cents in an integer column and present units:

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\NumberInput;

NumberInput::make('price')
    ->label('Price')
    ->min(0)
    ->step(0.01)
    ->formatUsing(static fn (mixed $value, ?Model $record): ?float => is_numeric($value)
        ? ((int) $value) / 100
        : null)
    ->mutateUsing(static fn (mixed $value, ?Model $record): ?int => is_numeric($value)
        ? (int) round(((float) $value) * 100)
        : null);
```

**A bound that depends on another field.** `min()` takes a number, so a cross-field bound belongs in `rules()`, where Laravel can name the other field:

```php
use PandaPanel\Forms\Components\NumberInput;

NumberInput::make('min_quantity')->integer()->min(1),
NumberInput::make('max_quantity')->integer()->rules(['gte:min_quantity']),
```

**Driving a condition.** `GreaterThan` and `LessThan` compare numerically, on both sides of the wire:

```php
use PandaPanel\Forms\Components\NumberInput;
use PandaPanel\Forms\Components\Textarea;
use PandaPanel\Forms\Enums\ConditionOperator;

NumberInput::make('discount')->integer()->min(0)->max(100),
Textarea::make('discount_reason')
    ->visibleWhen('discount', ConditionOperator::GreaterThan, 0),
```

## What crosses the wire

```ts
interface NumberFieldDefinition extends BaseFieldDefinition {
    type: 'number';
    min: number | null;
    max: number | null;
    step: number | null;
}
```

## Gotchas

**`min()` and `max()` are the value, not the length.** Because the type rule is `numeric` or `integer`, Laravel compares magnitudes. There is no way to bound the number of digits with these methods; use a `regex` or `digits` rule for that.

**`integer()` rejects a decimal outright.** `integer` is not a cast — `19.5` fails rather than being rounded. Round in `mutateUsing()` if that is what you mean.

**A step is not a rule.** `step(0.01)` stops the spinner at cents and makes the browser mark `19.999` invalid, but the server accepts it. Add `->rules(['decimal:0,2'])` when the precision has to hold.

**Very large integers lose precision on the wire.** The value crosses as a JSON number and is read into a JavaScript `number`, which is a double. Anything beyond 2^53 should be a `TextInput` with a numeric rule, not a `NumberInput`.

**An empty input is `null`, and `nullable` allows it.** A required number therefore needs `required()`, not merely `min(1)` — `min` is skipped for a null value.

## See also

- [Slider](slider.md) — the same bounded number, dragged rather than typed
- [Text](text.md) — for numbers that are really identifiers
- [Date and Time](date.md) — the other bounded scalar
- [Validation](../validation.md)
- [Visibility](../visibility.md) — `GreaterThan` and `LessThan`
- [Forms and Schemas](../overview.md)
