# Toggle Field

`PandaPanel\Forms\Components\Toggle` is a boolean rendered as a switch. It extends `PandaPanel\Forms\Components\Checkbox` and adds nothing but a different `FieldType`, so everything true of a checkbox is true of it. Reach for it for settings that read as "on or off"; reach for a [checkbox](checkbox.md) when the field reads as "I agree" or belongs in a list of them.

## A minimal form

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Users\Forms;

use PandaPanel\Forms\Components\Toggle;
use PandaPanel\Forms\FormSchema;

final class UserForm
{
    public static function configure(FormSchema $schema): FormSchema
    {
        return $schema->schema([
            Toggle::make('is_admin')
                ->label('Administrator')
                ->helperText('Administrators can reach the Admin panel and manage users.'),
        ]);
    }
}
```

Rules are `nullable|boolean`. The value is persisted to the `is_admin` column as `true` or `false`.

## The whole class

```php
final class Toggle extends Checkbox
{
    public function type(): FieldType
    {
        return FieldType::Toggle;
    }
}
```

There is no `Toggle`-only method. What the field does comes from `Checkbox` and from `Field`.

## Inherited from `Checkbox`

| Member | Value | Effect |
| --- | --- | --- |
| `$default` | `false` | Overrides `Field`'s `null`, so a create form opens with the switch off rather than in no state at all. |
| `typeRules()` | `['boolean']` | Laravel's `boolean` rule: `true`, `false`, `1`, `0`, `"1"`, `"0"`. |
| `castForForm()` | `(bool) $value` | A `0`, `"0"`, `null` or `""` column all arrive as `false`. |

`FieldType::Toggle` serializes as `'toggle'`; `FieldType::Checkbox` serializes as `'checkbox'`. That string is the only difference on the wire, and it is what picks the Vue control.

## Serialized shape

`Toggle::make('is_admin')->toArray(null, 'create')` produces the base field payload and nothing extra — the class defines no `extraArray()`:

| Key | Value |
| --- | --- |
| `type` | `'toggle'` |
| `name` | `'is_admin'` |
| `label` | `'Is Admin'` unless `label()` was called |
| `value` | `false` on create; `(bool) $record->is_admin` on edit |
| `validation` | `{ required: false }` |

`boolean` is not among the hints a browser checks, because a switch cannot produce anything else.

## Writing to a timestamp instead of a column

The interesting use of a toggle is when there is no boolean column to write to. `formatUsing()` shapes the value on the way in, `dehydrateTo()` names the column, and `mutateUsing()` shapes the value on the way out:

```php
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use PandaPanel\Forms\Components\Toggle;

Toggle::make('verified')
    ->label('Email verified')
    ->helperText('Marks the address as verified without sending an email.')
    ->columnSpan(2)
    ->formatUsing(static fn (mixed $value, ?Model $record): bool => $record instanceof User
        && $record->email_verified_at !== null)
    ->dehydrateTo('email_verified_at')
    ->mutateUsing(static function (mixed $value, ?Model $record): mixed {
        if ($value !== true) {
            return null;
        }

        // Keep an existing timestamp rather than resetting it on every save.
        return $record instanceof User && $record->email_verified_at !== null
            ? $record->email_verified_at
            : Date::now();
    });
```

Three separable concerns doing three separate jobs: the field is named `verified`, validates as a boolean, and persists a nullable datetime to `email_verified_at`. Nothing had to invent a column so the names would line up.

## Reacting to a toggle

A toggle is the natural driver of a declarative condition, because its value is one of two things:

```php
use PandaPanel\Forms\Components\DateTimePicker;
use PandaPanel\Forms\Components\Toggle;
use PandaPanel\Forms\Enums\ConditionOperator;
use PandaPanel\Forms\FormSchema;

FormSchema::make()->schema([
    Toggle::make('schedule')->label('Publish later'),

    DateTimePicker::make('publish_at')
        ->visibleWhen('schedule', ConditionOperator::Truthy),
]);
```

`ConditionOperator::Truthy` is the default operator, so `->visibleWhen('schedule')` says the same thing. The comparison is re-evaluated in the browser as the switch moves, and no request is made. `live()` is not needed and would be a round trip for nothing — reach for it only when the server has to rebuild the schema, for instance to change another field's options. See [live fields](../live-fields.md).

Conditions compare as strings, and `ConditionOperator` maps `true` to `'1'` and `false` to `'0'`. So `->visibleWhen('schedule', ConditionOperator::Equals, true)` also works, and `Equals, '1'` is the same condition.

## Layout

`ToggleField.vue` renders through `FieldWrapper` with `inline` set, which puts the label and helper text to the right of the switch. That is deliberate and it overrides `inlineLabel()`: a switch's label belongs next to the switch whichever way the rest of the form is laid out.

Everything else about placement is the container's. A toggle takes one column by default; `columnSpan(2)` and `columnSpanFull()` work as they do on any field.

## Gotchas

- **`required()` does not force the switch on.** It adds Laravel's `required` rule, which passes for `false` — the value is present, and being present is all `required` asks. All it changes is the asterisk beside the label. To insist on a checked switch, use the rule that means that:

  ```php
  Toggle::make('accepted_terms')->rules(['accepted']);
  ```

  `accepted` passes for `true`, `1`, `"1"`, `"on"`, `"yes"`, `"true"` and fails for everything else.

- **The default is `false`, not `null`.** A create form opens with the switch off, and submits `false` if the user never touches it. A nullable column therefore receives `false` rather than staying null unless you say otherwise with `dehydrateWhen()` or `mutateUsing()`.
- **The stored value is whatever `boolean` accepted.** A `"1"` from a request is persisted as `"1"` unless the model casts the attribute. Cast it: `protected function casts(): array { return ['is_admin' => 'boolean']; }`.
- **`disabled()` means read-only, not absent.** The switch is still drawn and still shows the record's value, and it is not persisted from the browser. To remove it entirely use `hidden()` or `hiddenOn()`. See [disabled and hidden fields](../disabled-hidden.md).
- **Use one or the other consistently.** `Toggle` and `Checkbox` validate, hydrate and dehydrate identically, so switching between them is a rendering change with no migration behind it — but a form that mixes both for the same kind of question reads as though the difference means something.

## See also

- [Checkbox field](checkbox.md)
- [Radio field](radio.md)
- [Select field](select.md)
- [Text field](text.md)
- [Hydration and dehydration](../hydration.md)
- [Conditional visibility](../visibility.md)
- [Disabled and hidden fields](../disabled-hidden.md)
- [Validation](../validation.md)
- [Forms overview](../overview.md)
