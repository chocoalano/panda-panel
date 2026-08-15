# Checkbox

`PandaPanel\Forms\Components\Checkbox` is a single boolean, rendered as a box. `PandaPanel\Forms\Components\Toggle` is the same field rendered as a switch — it extends `Checkbox` and changes nothing but the control. Reach for either when the answer is yes or no; for several answers out of a list use `CheckboxList`, and for one answer out of several use [Radio](radio.md).

## The minimal example

```php
use PandaPanel\Forms\Components\Checkbox;
use PandaPanel\Forms\Components\Toggle;
use PandaPanel\Forms\FormSchema;

FormSchema::make()->schema([
    Checkbox::make('is_featured'),

    Toggle::make('is_admin')
        ->label('Administrator')
        ->helperText('Administrators can reach the Admin panel and manage users.'),
]);
```

## The value

A checkbox has no field-specific options at all. What makes it a checkbox is three overrides on `Field`:

| Override | Value |
| --- | --- |
| `type()` | `FieldType::Checkbox` (`'checkbox'`), or `FieldType::Toggle` (`'toggle'`) |
| `$default` | `false` — not `null`, so a create form starts unchecked rather than empty |
| `typeRules()` | `['boolean']` |
| `castForForm()` | `(bool) $value` |

The cast is why a `tinyint(1)` column, a `0`/`1` string, and a real bool all arrive at the control as `true` or `false`:

```php
use PandaPanel\Forms\Components\Checkbox;

Checkbox::make('is_featured')->formValue($record);   // always bool
```

The Vue control emits `value === true`, so what comes back is a real JSON boolean rather than `'on'`. There is no unchecked-value trickery to configure: the field is present in the payload either way.

## Everything you can set

`Checkbox` adds no methods of its own. These are the inherited ones that matter for a boolean:

```php
use PandaPanel\Forms\Components\Toggle;

Toggle::make('is_admin')
    ->label('Administrator')                 // default: Str::headline('is_admin')
    ->helperText('Grants access to the Admin panel.')
    ->default(true)                          // overrides the field's false
    ->inlineLabel()                          // label beside the switch
    ->columnSpan(2)
    ->disabled()
    ->rules(['accepted']);                   // see Gotchas
```

| Method | Signature | Notes |
| --- | --- | --- |
| `label()` | `(string $label): static` | defaults to `Str::headline($name)` |
| `helperText()` | `(string $text): static` | a line under the control |
| `default()` | `(mixed $default): static` | `false` unless set |
| `disabled()` | `(bool $disabled = true): static` | still validated and still dehydrated |
| `inlineLabel()` | `(bool $inline = true): static` | label beside rather than above |
| `columnSpan()` / `columnSpanFull()` | `(int $span): static` / `(): static` | resolved against the container |
| `rules()` | `(list<mixed> $rules): static` | appended after `boolean` |
| `required()` | `(bool $required = true): static` | see Gotchas — rarely what you want |

`placeholder()` exists on `Field` but a checkbox has no text input to place it in; the definition carries it and the control ignores it.

## Mapping a checkbox onto a different column

The classic case is a nullable timestamp presented as a switch. Three hooks do it, and they stay separate because they answer different questions:

```php
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use PandaPanel\Forms\Components\Toggle;

Toggle::make('verified')
    ->label('Email verified')
    ->formatUsing(static fn (mixed $value, ?Model $record): bool => $record instanceof User
        && $record->email_verified_at !== null)
    ->dehydrateTo('email_verified_at')
    ->mutateUsing(static function (mixed $value, ?Model $record): mixed {
        if ($value !== true) {
            return null;
        }

        return $record instanceof User && $record->email_verified_at !== null
            ? $record->email_verified_at
            : Date::now();
    });
```

`formatUsing()` shapes the value on the way in, `dehydrateTo()` names the column, `mutateUsing()` shapes the value on the way out. The field is called `verified` on the wire and in the rules; nothing had to be renamed and no column had to be invented.

Note the `formatUsing()` closure returns a bool rather than reading `data_get($record, 'verified')` — there is no such attribute, and without the hook the field would read `null` and render unchecked for a verified user.

## Driving other fields

A checkbox is the natural subject of a declarative condition, because `Truthy` is the default operator:

```php
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Components\Toggle;

Toggle::make('notify'),
TextInput::make('notify_email')->visibleWhen('notify'),
```

No request is made: the browser re-evaluates the condition as the switch flips. See [Visibility](../visibility.md).

If something has to be *rebuilt* by the server when the box changes — options that depend on it, a computed total — mark it `live()`:

```php
use PandaPanel\Forms\Components\Toggle;

Toggle::make('use_custom_pricing')->live();
```

## Gotchas

**`required()` on a checkbox rejects an unchecked box.** `Field::validationRules()` puts `required` first, and `false` fails `required`. A box that must be ticked — terms and conditions — wants Laravel's `accepted` rule instead:

```php
use PandaPanel\Forms\Components\Checkbox;

Checkbox::make('accepts_terms')->rules(['accepted']);   // ['nullable', 'boolean', 'accepted']
```

**The default is `false`, not `null`.** A create form starts unchecked and submits `false`, so a non-nullable boolean column is safe without a database default.

**`Toggle` is a subclass, not a flag.** `Toggle::make()` returns a `Toggle`; `instanceof Checkbox` is true for it. If a page hook branches on field class, remember that.

**`Checkbox` is not `final`.** It is the one field class in the package designed to be extended, which is how `Toggle` exists. Override `type()` to reach a different control, and remember that the frontend union is closed — a new `FieldType` case needs a Vue renderer too.

**A disabled checkbox still dehydrates.** Disabling is a browser state. Use `dehydrated(false)` for a box whose value must never reach a column.

## See also

- [Toggle](toggle.md) — the same field as a switch
- [Radio](radio.md) — one choice from several
- [Visibility](../visibility.md) — `visibleWhen()` and the condition operators
- [Live Fields](../live-fields.md) — `live()` and the form-state endpoint
- [State Lifecycle](../state-lifecycle.md) — `formatUsing()`, `mutateUsing()`, `dehydrateTo()`
- [Validation](../validation.md)
- [Forms and Schemas](../overview.md)
