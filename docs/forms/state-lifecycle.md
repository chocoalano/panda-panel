# Field State Lifecycle

Every field value makes the same journey twice: out of the record and into the control, then back out of the request and into the record. Five hooks sit on that journey, and you reach for them whenever a form field and a database column are not the same thing. This page is the order the hooks run in and how to call each one; [Hydration and dehydration](hydration.md) covers the conversions themselves, including how each field type normalizes its value.

## A minimal example

A `verified` toggle that reads and writes an `email_verified_at` timestamp:

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
    ->mutateUsing(static fn (mixed $value, ?Model $record): mixed => $value === true
        ? ($record?->email_verified_at ?? Date::now())
        : null);
```

Three declarations, three separate questions: what the control shows, which column the value lands in, and what shape it lands in.

## The journey in

`Field::formValue(?Model $record): mixed` is the whole of it, and it runs in this order:

1. **Read.** `$record === null ? $this->default : data_get($record, $this->name)`. On a create page there is no record, so the value is the field's `default()`.
2. **Shape.** `formatUsing()` if the field declared one; otherwise the field type's own `castForForm()`.
3. **Observe.** `afterStateHydrated()`, whose return value is discarded.

`formatUsing()` *replaces* `castForForm()` rather than running after it. A `DatePicker` with a `formatUsing()` closure is responsible for producing the `Y-m-d` string the control binds to.

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\TextInput;

TextInput::make('name')
    ->default('Untitled')
    ->formatUsing(static fn (mixed $value, ?Model $record): string => mb_strtoupper((string) $value))
    ->afterStateHydrated(static function (mixed $value, ?Model $record): void {
        logger()->debug('name hydrated', ['value' => $value]);
    });
```

## The journey out

`FormSchema::dehydrate(array $validated, ?Model $record = null): array` walks the visible fields and asks each one a series of questions. A no at any point drops the field silently:

| Step | Asked of | Skips when |
| --- | --- | --- |
| 1 | `Field::isHiddenOn()` | The field is not visible on this page |
| 2 | `Field::isDehydrated($record)` | `dehydrated(false)` |
| 3 | The relation groups | The field belongs to a `Relationship`, written later |
| 4 | `$validated` | The key is absent from the validated data |
| 5 | `Field::shouldDehydrate($value)` | `dehydrateWhen()` returned false |
| 6 | `Select::writesToPivot()` | It is a many-to-many select, synced later |
| 7 | — | Otherwise `$attributes[$key] = $field->mutate($value, $record)` |

The key is `Field::getDehydrateKey()` — `dehydrateTo()` or the field name — except for a `BelongsTo` select, which resolves to the relation's foreign key.

## The five hooks

| Hook | Signature | Runs | Return |
| --- | --- | --- | --- |
| `formatUsing()` | `Closure(mixed $value, ?Model $record): mixed` | On the way in, instead of the type's cast | The value the control binds to |
| `afterStateHydrated()` | `Closure(mixed $value, ?Model $record): mixed` | On the way in, after the value is settled | Ignored |
| `afterStateUpdated()` | `Closure(mixed $new, mixed $old, ?Model $record): void` | When a `live()` field changes | Nothing |
| `dehydrateStateUsing()` | `Closure(mixed $value, ?Model $record): mixed` | On the way out | The value to write |
| `mutateUsing()` | `Closure(mixed $value, ?Model $record): mixed` | On the way out | The value to write |

`dehydrateStateUsing()` and `mutateUsing()` are the same idea under two names — Filament's and this framework's. They share one implementation, and when both are declared `dehydrateStateUsing()` wins.

### `formatUsing()`

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\TagsInput;

// A column storing "a,b,c" shown as three tags.
TagsInput::make('keywords')->formatUsing(
    static fn (mixed $value, ?Model $record): array => is_string($value)
        ? array_values(array_filter(explode(',', $value)))
        : [],
);
```

### `afterStateHydrated()`

An observer, not a transformer: what it returns is ignored, so a hook written for its side effect cannot blank the field by returning nothing.

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\TextInput;

TextInput::make('name')->afterStateHydrated(
    static function (mixed $value, ?Model $record): void {
        // Side effects only. Use formatUsing() to change the value.
    },
);
```

### `afterStateUpdated()`

Runs only for a field that declared itself `live()`, whatever the request claims changed, and only through the `form-state` endpoint. See [Live fields](live-fields.md).

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\Select;

Select::make('country')
    ->options(['id' => 'Indonesia', 'sg' => 'Singapore'])
    ->live()
    ->afterStateUpdated(
        static function (mixed $new, mixed $old, ?Model $record): void {
            // For side effects and for deciding what other fields become.
        },
    );
```

You can drive it yourself in a test: `Field::handleStateUpdated(mixed $state, mixed $previous, ?Model $record = null): void` runs the hook unconditionally — the caller has already decided the value changed.

### `dehydrateStateUsing()` and `mutateUsing()`

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use PandaPanel\Forms\Components\TextInput;

TextInput::make('name')->dehydrateStateUsing(
    static fn (mixed $value, ?Model $record): string => mb_strtoupper((string) $value),
);

TextInput::make('api_token')->mutateUsing(
    static fn (mixed $value, ?Model $record): string => Hash::make((string) $value),
);
```

## Deciding whether a value is written at all

Three declarations, and they answer different questions.

| Method | Signature | Question |
| --- | --- | --- |
| `dehydrated()` | `dehydrated(Closure(?Model): bool\|bool $condition = true): static` | Does this field ever reach the record? |
| `dehydrateWhen()` | `dehydrateWhen(Closure(mixed): bool $callback): static` | Does *this value* reach the record? |
| `dehydrateTo()` | `dehydrateTo(string $attribute): static` | Which attribute does it reach? |

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\Checkbox;
use PandaPanel\Forms\Components\PasswordInput;
use PandaPanel\Forms\Components\TextInput;

// Rendered, validated, never written.
Checkbox::make('accepted_terms')->required()->dehydrated(false);

// Written only on create.
TextInput::make('reference')->dehydrated(
    static fn (?Model $record): bool => $record === null,
);

// Written only when the user typed something. This is exactly what
// PasswordInput::optionalWhenFilled() does.
PasswordInput::make('password')
    ->required(false)
    ->dehydrateWhen(static fn (mixed $value): bool => is_string($value) && $value !== '');

// A different column.
TextInput::make('slug')->dehydrateTo('url_slug');
```

`dehydrated(false)` and validation are separate questions: a field can be required and still never reach a column.

## Where the page hooks fit

`PandaPanel\Resources\Concerns\HasLifecycleHooks` wraps the field hooks with page-level ones. On a create page the full order is:

```text
beforeFill()
  FormSchema::toArray()          ← formatUsing, afterStateHydrated run here
mutateFormDataBeforeFill($data)
afterFill($data)
--- the user fills the form in and submits ---
beforeValidate($input)
  validator($input, $schema->validationRules())->validate()
afterValidate($data)
beforeCreate()
mutateFormDataBeforeCreate($data)
mutateFormDataBeforeSave($data, null)
beforeSave(null)
  FormSchema::dehydrate($data)   ← dehydrateWhen, mutateUsing run here
  handleRecordCreation($attributes)
  FormSchema::saveRelations($record, $data)
afterCreate($record)
afterSave($record)
```

The edit page is the same without `beforeCreate()`, `mutateFormDataBeforeCreate()`, and `afterCreate()`, and with `handleRecordUpdate()` in place of `handleRecordCreation()`. Both wrap the write, the relation writes, and the after-hooks in one transaction when `$hasDatabaseTransactions` is on.

The distinction worth keeping: the field hooks operate on one value and know about the field, the page hooks operate on the whole array and know about the request. A hook that needs both — the record and the field's own value — is a field hook.

## Notes

- **`formatUsing()` replaces the type cast.** A `DatePicker`, `DateTimePicker`, or `TimePicker` with a format hook must return the string its control binds to.
- **`afterStateHydrated()`'s return value is discarded.** A hook that computes a value and forgets to use `formatUsing()` will look like it did nothing.
- **`afterStateUpdated()` never runs on submit.** It belongs to the `form-state` endpoint, which validates nothing and writes nothing.
- **`PasswordInput::formValue()` always returns null.** The stored hash is never rendered into a form field.
- **A `FileUpload` overrides `mutate()`.** It drops any path the field could not have produced before your `mutateUsing()` hook sees the value.
- **A `RichEditor` overrides `mutate()` too**, sanitizing on the way to the record so every later read of the stored value is safe.
- **A `Repeater` and a `Builder` dehydrate their children in `mutate()`**, so a key the sub-schema never declared is discarded exactly as it is at the top level.

## See also

- [FormSchema basics](overview.md)
- [Hydration and dehydration](hydration.md)
- [Live fields](live-fields.md)
- [Validation](validation.md)
- [Relationship forms](relationships.md)
- [Resource lifecycle hooks](../resources/lifecycle-hooks.md)
- [CRUD pages](../resources/crud-pages.md)
