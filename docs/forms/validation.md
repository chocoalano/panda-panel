# Validation

A form's rules are Laravel's, derived from the same field declarations that render the controls. You reach for this page to add rules, to understand which ones a field already carries, and to know what the browser checks before a request is made. Nothing here replaces the server: the browser's checks are a courtesy, and everything is validated again.

## A minimal example

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;

$schema = FormSchema::make()->schema([
    TextInput::make('email')
        ->email()
        ->required()
        ->rulesUsing(static fn (?Model $record): array => [
            $record === null
                ? Rule::unique('users', 'email')
                : Rule::unique('users', 'email')->ignore($record->getKey()),
        ]),
]);

$schema->validationRules();
// ['email' => ['required', 'string', 'email', 'max:255', Illuminate\Validation\Rules\Unique]]
```

A resource page does this for you: `validator($input, $schema->validationRules($record))->validate()` is the whole of it.

## How a field's rule list is built

`Field::validationRules(?Model $record): list<mixed>` concatenates four things, in this order:

1. `'required'` or `'nullable'`, from `required()`.
2. The field type's own rules — `typeRules()`, the table below.
3. Whatever `rules()` was given.
4. Whatever `rulesUsing()` returns.

```php
use PandaPanel\Forms\Components\TextInput;

TextInput::make('slug')
    ->required()                    // required(bool $required = true): static
    ->rules(['alpha_dash']);        // rules(list<mixed> $rules): static
// ['required', 'string', 'max:255', 'alpha_dash']
```

`required(false)` is not a no-op: it puts `nullable` at the front, which is what stops a blank optional field failing every rule after it.

### `rulesUsing()`

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use PandaPanel\Forms\Components\TextInput;

TextInput::make('email')->rulesUsing(
    static fn (?Model $record): array => [
        Rule::unique('users', 'email')->ignore($record?->getKey()),
    ],
);
```

`rulesUsing(Closure(?Model): list<mixed> $callback): static`. The record is null on a create page. This is the hook for anything that needs to know which record is being edited — a unique check that ignores itself being the usual one.

## Rules each field type carries

Every list below is prefixed by `required` or `nullable`.

| Field | Rules |
| --- | --- |
| `TextInput` | `string`; `email` with `email()`; `max:{n}` from `maxLength()`, default 255; `min:{n}` from `minLength()` |
| `Textarea` | `string`; `max:{n}` from `maxLength()`, unset by default |
| `PasswordInput` | `string`; `confirmed` with `confirmed()` |
| `NumberInput` | `numeric`, or `integer` with `integer()`; `min:{n}`; `max:{n}` |
| `HiddenInput` | none of its own |
| `Checkbox`, `Toggle` | `boolean` |
| `Select` (single, static options) | `Rule::in()` over the option keys |
| `Select` (single, `existsIn()` or a relation) | `Rule::exists($table, $column)` |
| `Select` (multiple) | `array`, plus `elementRules()` under `field.*` |
| `Radio` | `Rule::in()` over the option keys |
| `CheckboxList` | `array`, plus `Rule::in()` under `field.*` |
| `ToggleButtons` | `Rule::in()`, or `array` plus `Rule::in()` under `field.*` with `multiple()` |
| `DatePicker`, `DateTimePicker` | `date`; `after_or_equal:{minDate}`; `before_or_equal:{maxDate}` |
| `TimePicker` | `date_format:H:i`, or `date_format:H:i:s` with `seconds()` |
| `ColorPicker` | `string`, `regex:` — a hex colour |
| `Slider` | `numeric`, `min:{min}`, `max:{max}` from `range()` |
| `TagsInput` | `array`; `max:{n}` from `maxTags()`; `string` and `max:{n}` under `field.*` from `maxLength()`, default 50 |
| `KeyValue` | `array`; `max:{n}` from `maxPairs()` |
| `RichEditor`, `MarkdownEditor` | `string`; `max:{n}` from `maxLength()` |
| `CodeEditor` | `string`; `json` when the language is `CodeLanguage::Json`; `max:{n}` |
| `FileUpload` | `string`, or `array` plus `max:{maxFiles}` and `string` under `field.*` with `multiple()` |
| `Repeater` | `array`; `min:{n}`; `max:{n}`; the item schema under `field.*.name` |
| `Builder` | `array`; `min:{n}`; `max:{n}`; entries validated separately |
| `CustomField` | none of its own — declare them with `rules()` |

## Rules for lists and for nested fields

Laravel will not infer element rules from a rule list on the parent key, so a field whose value is a list reports them separately.

```php
use PandaPanel\Forms\Components\CheckboxList;
use PandaPanel\Forms\Components\Repeater;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;

FormSchema::make()
    ->schema([CheckboxList::make('roles')->options(['a' => 'A', 'b' => 'B'])])
    ->validationRules();
// ['roles' => ['nullable', 'array'], 'roles.*' => [Rule::in(['a', 'b'])]]

FormSchema::make()
    ->schema([
        Repeater::make('items')->schema([TextInput::make('title')->required()]),
    ])
    ->validationRules();
// ['items' => [...], 'items.*.title' => ['required', 'string', 'max:255']]
```

Two overridable methods produce those keys:

| Method | Signature | Produces |
| --- | --- | --- |
| `elementRules()` | `elementRules(): list<mixed>` | `field.*` |
| `nestedRules()` | `nestedRules(?Model $record = null): array<string, list<mixed>>` | Full paths, keyed — a repeater's `items.*.title` |

`FormSchema::validationRules()` asks both of every field, so a custom field type that returns them gets the same treatment as a built-in one.

## Confirmed passwords

`password_confirmation` is not a field. `PasswordInput::confirmed(bool $confirmed = true)` makes the renderer draw the second input and makes the schema add the matching rule, so the pair cannot drift:

```php
use PandaPanel\Forms\Components\PasswordInput;
use PandaPanel\Forms\FormSchema;

FormSchema::make()
    ->schema([PasswordInput::make('password')->confirmed()->required()])
    ->validationRules();
// [
//   'password' => ['required', 'string', 'confirmed'],
//   'password_confirmation' => ['nullable', 'string'],
// ]
```

The confirmation key has to exist in the rules, or `confirmed` has nothing to compare against.

## Builder entries

A builder's rules cannot be flat: which rules apply to entry three depends on what entry three says it is. `Builder::validateEntries(mixed $value, ?Model $record = null): array<string, list<string>>` validates each entry against the block it names and returns errors keyed by the path the frontend rendered with.

```php
use PandaPanel\Forms\Components\Builder;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Support\Block;

$field = Builder::make('content')->blocks([
    Block::make('paragraph')->schema([TextInput::make('body')->required()]),
]);

$field->validateEntries([
    ['type' => 'paragraph', 'data' => ['body' => '']],
    ['type' => 'nope', 'data' => []],
]);
// [
//   'content.0.data.body' => ['The body field is required.'],
//   'content.1.type' => ['This block is not one this field offers.'],
// ]
```

An entry naming a block the builder never declared is refused here and dropped in `mutate()`, so it can neither pass validation nor be written.

## Wizard steps

A wizard is validated whole on submit. Its per-step endpoint exists so the user is not sent to step four before step one is answerable:

```php
$schema->validationRulesForStep(0);     // the first step's fields only
$schema->validationRules();             // every step, in one rule set
```

`validationRulesForStep(int $step, ?Model $record = null): array` derives the subset from the whole form's rules — the step already knows which fields it holds, so a second definition could only disagree with the first. A confirmation key belongs to the step its password is in. A form with no wizard returns `[]`.

The endpoint is `POST {resource}/create/step` and `POST {resource}/{record}/edit/step`, route names `…validateCreateStep` and `…validateEditStep`. It answers `200 {"errors": []}` or `422 {"errors": {…}}`, authorizes with `canCreate()` / `canEdit($record)`, refuses an out-of-range step with 422, and refuses a form that has no wizard with 400. The URL is sent to the page as `validateStepUrl`, and is null when the form is flat.

## What the browser checks

Every field ships `validation` hints — the subset of its rules a browser can honestly check, derived from the same declarations the server validates with.

| Hint | Set by |
| --- | --- |
| `required` | `required()` |
| `email` | an `email` rule |
| `numeric` | a `numeric` or `integer` rule |
| `url` | a `url` rule |
| `min` | a `min:{n}` rule with a numeric argument |
| `max` | a `max:{n}` rule with a numeric argument |
| `confirmed` | a `confirmed` rule |

```php
TextInput::make('email')->required()->email()->maxLength(100)
    ->toArray(null, 'create')['validation'];
// ['required' => true, 'email' => true, 'max' => 100.0]
```

Rules needing the database are deliberately absent: a frontend that guessed at `unique` would be confidently wrong. Only string rules are read, so a `Rule` object contributes no hint at all. A field hidden by a condition is skipped on both sides.

## Where validation runs on a page

```php
$input = $this->beforeValidate($request->all());
$data = $this->afterValidate(
    validator($input, $schema->validationRules($record))->validate(),
);
```

`beforeValidate()` sees the raw request and `afterValidate()` the validated data; both are on `PandaPanel\Resources\Concerns\HasLifecycleHooks`. Everything downstream reads `$data`, never the request, so a key with no rule cannot reach the write.

## Notes

- **`required` on a field is a UX marker.** Removing it in the browser changes nothing: the rule is on the server and is what decides.
- **A hidden field is not validated.** `FormSchema::fields()` excludes it, so no rule is built for it and a request that sends it cannot make it exist. That includes `hiddenOn()`, `visibleOn()`, and a `visible()` / `hidden()` closure that said no.
- **A relation-backed select validates with `exists`, not `in`.** The rendered options are one bounded page of a table that may have thousands of rows; validating against the shown page would refuse a real key for having sorted too late.
- **A static option list is a whitelist.** `Select`, `Radio`, `CheckboxList`, and `ToggleButtons` all add `Rule::in()` over their declared keys, and an empty option list adds nothing.
- **`ColorPicker` validates hex only.** Its `swatches()` and its form cast also accept `rgb()` and `hsl()` syntax, but the submitted value must match the hex pattern.
- **Two fields with one name throws.** `validationRules()` asserts uniqueness and raises `PandaPanel\Exceptions\PanelSchemaException`, because only one rule would survive into the validator.
- **`TextInput` has a `max:255` you did not ask for.** `maxLength()` defaults to 255; pass `maxLength(null)` to remove it.
- **Validation is not authorization.** A user who may not create is refused before the rules are ever built.

## See also

- [FormSchema basics](overview.md)
- [Field state lifecycle](state-lifecycle.md)
- [Hydration and dehydration](hydration.md)
- [Field visibility](visibility.md)
- [Disabled and hidden fields](disabled-hidden.md)
- [Form layouts](layouts.md)
- [Options endpoints](options-endpoints.md)
- [File uploads](file-uploads.md)
- [Testing forms](../testing/forms.md)
