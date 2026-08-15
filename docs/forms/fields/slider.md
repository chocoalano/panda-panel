# Slider Field

`PandaPanel\Forms\Components\Slider` is a number chosen by dragging. Reach for it when the number has meaningful bounds and the user cares more about roughly where it sits than about the exact digits — a percentage, a weight, a priority. When the exact digits matter, use [`NumberInput`](number.md), which has a keyboard.

## A minimal form

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Products\Forms;

use PandaPanel\Forms\Components\Slider;
use PandaPanel\Forms\FormSchema;

final class ProductForm
{
    public static function configure(FormSchema $schema): FormSchema
    {
        return $schema->schema([
            Slider::make('discount')
                ->label('Discount (%)')
                ->range(0, 50, 5)
                ->default(0),
        ]);
    }
}
```

That renders a range input from 0 to 50 in steps of 5, with the current value shown beside it, and validates `discount` as `nullable|numeric|min:0|max:50`.

## The bounds are the validation

A slider that could not be dragged past 100 but accepted 1000 from a crafted request would be a control pretending to be a constraint. So `range()` does two things at once: it configures the control, and it produces the rules.

```php
Slider::make('weight')->range(10, 20, 5);
```

```php
// FormSchema::make()->schema([...])->validationRules()
['weight' => ['nullable', 'numeric', 'min:10', 'max:20']]
```

The browser gets the same three as hints — `numeric`, `min`, `max` — derived from the same declaration, so the two cannot drift into disagreeing. The server still validates everything again.

## Methods

### `range(float $min, float $max, float $step = 1): self`

Sets all three bounds at once. Defaults are `min: 0`, `max: 100`, `step: 1`.

```php
use PandaPanel\Forms\Components\Slider;

Slider::make('opacity')->range(0, 1, 0.05);

Slider::make('priority')->range(1, 5);          // step stays 1

Slider::make('score')->range(-100, 100, 10);
```

A step of zero or less is refused and falls back to `1`: a range input with a zero step has no positions to stop at.

There is no separate `min()`, `max()` or `step()` method. The three only mean anything together, and a range read off one line is easier to check than one assembled from three.

### `showValue(bool $show = true): self`

Default `true`. Whether the current value is printed beside the track.

```php
Slider::make('volume')->range(0, 11)->showValue(false);
```

Turn it off when the label already says everything — a five-point priority where the positions are the meaning, not the number.

### `type(): FieldType`

Returns `FieldType::Slider`, which serializes as `'slider'` and selects `SliderField.vue`.

## Serialized shape

`Slider::make('discount')->range(0, 50, 5)->toArray(null, 'create')` adds four keys to the base field payload:

| Key | Type | Default | From |
| --- | --- | --- | --- |
| `min` | number | `0` | `range()` |
| `max` | number | `100` | `range()` |
| `step` | number | `1` | `range()` |
| `showValue` | boolean | `true` | `showValue()` |

The value is normalized by `castForForm()`: a numeric value is returned as a number (`$value + 0`, so `"7"` becomes `7` and `"7.5"` becomes `7.5`), and anything else becomes `null`.

## Giving it a starting value

`default()` is inherited from `Field` and defaults to `null`. It applies only when there is no record:

```php
Slider::make('discount')->range(0, 50, 5)->default(10);
```

On an edit page the value comes from the record. If the column is null there and you want the control to start somewhere, shape it on the way in rather than with a default:

```php
use Illuminate\Database\Eloquent\Model;

Slider::make('discount')
    ->range(0, 50, 5)
    ->formatUsing(static fn (mixed $value, ?Model $record): float => is_numeric($value)
        ? (float) $value
        : 0.0);
```

## Making it required

```php
Slider::make('rating')->range(1, 5)->required();
```

`required()` swaps `nullable` for `required` and marks the label. Read the first gotcha below before using it — a range input has no empty state, and "the user never touched it" is not a state the control can show.

## Gotchas

- **An untouched slider submits `null`, while showing the minimum.** A range input always points somewhere, so `SliderField.vue` displays `min` when the value is not a finite number. It does not emit anything until the user drags. With `required()` that means a form the user believes is filled in comes back with "The Rating field is required." Either give it a `default()`, or leave it optional and treat null as unset.
- **The step is a control affordance, not a rule.** `range(0, 100, 25)` stops the thumb at 0, 25, 50, 75, 100, but the rules are only `numeric|min:0|max:100`. A request carrying `37` is valid. If the discrete positions are the constraint, add `->rules(['in:0,25,50,75,100'])`, or use a field whose options are a whitelist — [`Radio`](radio.md) or `PandaPanel\Forms\Components\ToggleButtons`.
- **`range()` does not check that `min` is below `max`.** An inverted range produces `min:20|max:10`, which nothing can satisfy, and the control has no positions. Only the step is defended, because a zero step is the one that breaks the control outright.
- **The stored type is whatever arrived.** The field validates `numeric` and persists the value untouched unless a hook changes it. Cast the attribute on the model — `'discount' => 'float'` or `'integer'` — rather than expecting the field to decide.
- **`min` and `max` mean value here, not length.** The browser's shared check follows Laravel: it measures a number's value, a string's length, or a collection's count. Because the slider also sends the `numeric` hint, a numeric string is measured as its value. That is the same rule the server applies.

## See also

- [Number field](number.md)
- [Radio field](radio.md)
- [Toggle field](toggle.md)
- [Text field](text.md)
- [Validation](../validation.md)
- [Hydration and dehydration](../hydration.md)
- [Forms overview](../overview.md)
- [Form layouts](../layouts.md)
