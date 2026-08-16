# Text Field

`PandaPanel\Forms\Components\TextInput` is the single-line text control, and the field every other one is a variation of. Reach for it for names, titles, slugs, email addresses, URLs — anything that is one line of characters. This page also covers the three fields that share its shape and have no page of their own: `Textarea`, `PasswordInput` and `HiddenInput`.

## A minimal form

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Posts\Forms;

use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;

final class PostForm
{
    public static function configure(FormSchema $schema): FormSchema
    {
        return $schema->schema([
            TextInput::make('title')
                ->required()
                ->maxLength(255)
                ->placeholder('How we shipped it'),
        ]);
    }
}
```

That renders one input, validates `title` as `required|string|max:255`, and persists it to the `title` column. Rendering, validating and persisting all derive from that one declaration, which is why they cannot disagree.

## What the field produces

`TextInput::make('title')->required()->maxLength(255)` serializes to this, and the browser renders from it:

| Key | Value | Source |
| --- | --- | --- |
| `type` | `'text'` | `FieldType::Text` |
| `name` | `'title'` | `make()` |
| `label` | `'Title'` | `Str::headline($name)` unless `label()` was called |
| `value` | the record's `title`, cast to string | `formValue()` |
| `inputType` | `'text'` or `'email'` | `email()` |
| `maxLength` | `255` | `maxLength()` |
| `validation` | `{ required: true, max: 255 }` | the subset of the rules a browser can check |

`maxLength` reaches the browser twice: once as the input's `maxlength` attribute so typing stops at the limit, and once as a validation hint. Neither is the check. The `max:255` rule on the server is.

## Methods

### `make(string $name): static`

The name is the attribute on the model, the key on the wire, and the key in the rules. An empty name raises `PandaPanel\Exceptions\PanelSchemaException`.

```php
use PandaPanel\Forms\Components\TextInput;

TextInput::make('slug');
```

### `email(bool $email = true): static`

Adds Laravel's `email` rule and renders `<input type="email">`.

```php
TextInput::make('email')
    ->label('Email address')
    ->email()
    ->required();
```

The rules become `required|string|email|max:255`. The browser also gets an `email` hint and checks it with a deliberately permissive pattern — looser than Laravel's, so it can never reject an address the server would have accepted.

### `maxLength(?int $length): static`

Default `255`. Adds `max:N`, and caps the control's `maxlength`. Pass `null` to remove the limit entirely.

```php
TextInput::make('summary')->maxLength(500);

// No length rule at all, for a column that is genuinely unbounded.
TextInput::make('anything')->maxLength(null);
```

This is the one field with a non-null default: an unbounded string field is almost always a mistake against a `varchar(255)` column, so the default is the column's limit rather than none.

### `minLength(?int $length): static`

Default `null`. Adds `min:N`.

```php
TextInput::make('code')->minLength(6)->maxLength(6);
```

### Rules the type produces

In order, `validationRules()` returns:

1. `required` or `nullable`, from `required()`.
2. `string`.
3. `email`, when `email()` was called.
4. `max:N`, when `maxLength` is not null.
5. `min:N`, when `minLength` is not null.
6. Whatever `rules()` and `rulesUsing()` added.

So anything Laravel can validate is one `rules()` call away:

```php
use Illuminate\Validation\Rule;
use Illuminate\Database\Eloquent\Model;

TextInput::make('website')
    ->rules(['url'])
    ->helperText('Include the scheme.');

TextInput::make('email')
    ->email()
    ->required()
    // Unique on create, unique-except-self on edit. Without the ignore,
    // saving a record without changing its email fails against itself.
    ->rulesUsing(static fn (?Model $record): array => [
        $record === null
            ? Rule::unique('users', 'email')
            : Rule::unique('users', 'email')->ignore($record->getKey()),
    ]);
```

`url` and `numeric` are among the hints the browser also checks. `unique` is not, and never will be: a frontend that guessed at it would be confidently wrong.

## Textarea

`PandaPanel\Forms\Components\Textarea` is the same field over several lines.

```php
use PandaPanel\Forms\Components\Textarea;

Textarea::make('bio')
    ->rows(6)
    ->maxLength(1000)
    ->columnSpanFull();
```

| Method | Default | Effect |
| --- | --- | --- |
| `rows(int $rows): self` | `4` | The control's height. Values below `1` are clamped to `1`. |
| `maxLength(?int $length): self` | `null` | Adds `max:N`. Unlike `TextInput`, there is no length limit unless you set one. |

Type rules are `string`, plus `max:N` when a length is set. The field type on the wire is `textarea`.

## PasswordInput

`PandaPanel\Forms\Components\PasswordInput` exists to be optional on edit without ever writing a blank password over a stored hash.

```php
use PandaPanel\Forms\Components\PasswordInput;
use PandaPanel\Forms\FormSchema;

public static function configure(FormSchema $schema): FormSchema
{
    $isCreate = $schema->getPage() === 'create';

    return $schema->schema([
        PasswordInput::make('password')
            ->confirmed()
            ->rules(['min:8'])
            // Required on create, optional on edit, and never written back
            // as an empty string.
            ->when(
                $isCreate,
                static fn (PasswordInput $field): PasswordInput => $field->required(),
                static fn (PasswordInput $field): PasswordInput => $field->optionalWhenFilled(),
            ),
    ]);
}
```

| Method | Default | Effect |
| --- | --- | --- |
| `confirmed(bool $confirmed = true): self` | `false` | Adds Laravel's `confirmed` rule and tells the renderer to draw the second input, labelled `Confirm {label}` with the label lower-cased. |
| `revealable(bool $revealable = true): self` | `true` | Serialized as `revealable`. The bundled renderer draws the show/hide button unconditionally, so this flag currently only carries the intent to a custom one. |
| `isConfirmed(): bool` | — | Read by `FormSchema`, which adds the `{name}_confirmation` rule. |
| `optionalWhenFilled(): self` | — | `required(false)` plus a `dehydrateWhen()` that persists only a non-empty string. |

Three things follow from that:

- `password_confirmation` is not a field you declare. `confirmed()` makes the renderer draw it *and* makes the schema add `['nullable', 'string']` for it, so the pair cannot drift.
- `formValue()` returns `null` unconditionally. A stored hash is never sent back to the browser, on any page.
- The field does not hash anything. Hashing belongs to the model, and Laravel already has a cast for it:

```php
protected function casts(): array
{
    return ['password' => 'hashed'];
}
```

## HiddenInput

`PandaPanel\Forms\Components\HiddenInput` carries a value through the form without showing it. It has no methods of its own.

```php
use PandaPanel\Forms\Components\HiddenInput;

HiddenInput::make('source')->default('admin-panel');
```

Hidden is a rendering choice, not a trust boundary: the value still arrives from the browser and is still validated by whatever `rules()` you declare on it. If a value must not be settable by the user, do not put it in a hidden field — inject it in a page's `beforeValidate()` hook instead. See [lifecycle hooks](../../resources/lifecycle-hooks.md).

`Field::hidden()` is a different thing entirely: it removes a field from the page. See [visibility](../visibility.md).

## Inherited from `Field`

Every method below is on `PandaPanel\Forms\Components\Field`, so it works on all four fields on this page and on every other field type.

| Method | Default | Notes |
| --- | --- | --- |
| `label(string $label): static` | `Str::headline($name)` | |
| `placeholder(string $placeholder): static` | `null` | |
| `helperText(string $helperText): static` | `null` | Rendered under the control. |
| `required(bool $required = true): static` | `false` | Adds `required`, or `nullable` when false. |
| `disabled(bool $disabled = true): static` | `false` | Rendered, not editable, not persisted from the browser. |
| `default(mixed $default): static` | `null` | Used when there is no record. |
| `columnSpan(int $span): static` | `1` | Clamped to at least 1. |
| `columnSpanFull(): static` | — | The whole row, whatever the container is divided into. |
| `inlineLabel(bool $inline = true): static` | `false` | Label beside the control rather than above it. |
| `rules(array $rules): static` | `[]` | |
| `rulesUsing(Closure $callback): static` | `null` | Receives `?Model $record`. |
| `live(bool $onBlur = false, ?int $debounce = null): static` | off, `500` ms | See [live fields](../live-fields.md). |
| `visibleOn` / `hiddenOn` / `disabledOn(array $pages): static` | — | See [visibility](../visibility.md). |
| `visible` / `hidden(Closure\|bool $condition = true): static` | — | Evaluated once, on the server. |
| `visibleWhen` / `hiddenWhen(string $field, ConditionOperator $operator, mixed $value): static` | — | Re-evaluated in the browser. |
| `formatUsing` / `mutateUsing` / `dehydrateStateUsing(Closure $callback): static` | — | See [hydration](../hydration.md). |
| `dehydrated(Closure\|bool $condition = true): static` | dehydrates | |
| `dehydrateTo(string $attribute): static` | the field name | Persist under a different column. |
| `dehydrateWhen(Closure $callback): static` | — | Decide per submitted value. |
| `when` / `unless(...)` | — | From `Illuminate\Support\Traits\Conditionable`. |

## Gotchas

- **`maxLength()` defaults to 255, and it is a rule.** A `TextInput` bound to a `text` column silently rejects anything longer until you call `maxLength(null)` or raise it.
- **`minLength()` and `maxLength()` measure characters, not numbers.** The browser's hint follows Laravel: `min`/`max` measure a string's length, a number's value, and a collection's count. A `TextInput` is always the first of those. For a number, use [`NumberInput`](number.md).
- **`email()` changes the input type as well as the rule.** Mobile keyboards change with it, which is the point; if you want the rule without the keyboard, use `->rules(['email'])`.
- **`default()` only applies when there is no record.** On an edit page the value comes from `data_get($record, $name)`, so a default is not a fallback for a null column. `formatUsing()` is.
- **A password value is never round-tripped.** Reading `$field->formValue($record)` on a `PasswordInput` returns `null` even for a saved record. That is deliberate.
- **Two fields may not share a name.** `FormSchema` throws `PanelSchemaException::duplicateFields()` — only one rule and one value would survive, and the other field would be filled in and discarded without a word.

## See also

- [Forms overview](../overview.md)
- [Number field](number.md)
- [Select field](select.md)
- [Rich editor](rich-editor.md)
- [Validation](../validation.md)
- [Hydration and dehydration](../hydration.md)
- [Conditional visibility](../visibility.md)
- [Live fields](../live-fields.md)
- [Lifecycle hooks](../../resources/lifecycle-hooks.md)
