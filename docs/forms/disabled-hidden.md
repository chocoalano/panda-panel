# Disabled And Hidden Fields

Four separate questions, deliberately not one: is this field shown on this page, is it shown for this record, is it editable, and does it carry a value the user never sees. You reach for these when a form differs between create and edit, or when part of it only applies to some records. Everything on this page is answered on the server, once, while the schema is built — for conditions that react as somebody types, see [Field visibility](visibility.md).

## A minimal example

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\HiddenInput;
use PandaPanel\Forms\Components\TextInput;

TextInput::make('slug')->visibleOn(['edit']);          // which page
TextInput::make('reference')->hiddenOn(['create']);
TextInput::make('email')->disabledOn(['edit']);        // shown, not editable

TextInput::make('reason')->visible(                    // the record
    static fn (?Model $record): bool => $record?->getAttribute('status') === 'rejected',
);

HiddenInput::make('source')->default('panel');         // carried, not shown
```

A hidden field is not merely invisible: it is absent from the payload, absent from the rules, and absent from what dehydrates — so a request that sends it cannot make it exist.

## Hiding by page

The page comes from `FormSchema::forPage()`. A resource create page is `'create'` and an edit page is `'edit'`; a relation form uses the same two.

| Method | Signature | Effect |
| --- | --- | --- |
| `hiddenOn()` | `hiddenOn(list<string> $pages): static` | Hidden on the pages named |
| `visibleOn()` | `visibleOn(list<string> $pages): static` | Hidden on every page *not* named |

```php
use PandaPanel\Forms\Components\TextInput;

TextInput::make('slug')->hiddenOn(['create']);
TextInput::make('slug')->visibleOn(['edit']);
```

Both exist because they read differently: a field that belongs to creation alone is clearer as `visibleOn(['create'])` than as a list of every other page.

You can branch on the page directly too, which is what the example resource does for its password field:

```php
use PandaPanel\Forms\Components\PasswordInput;
use PandaPanel\Forms\FormSchema;

public static function form(FormSchema $schema): FormSchema
{
    return $schema->schema([
        PasswordInput::make('password')->when(
            $schema->getPage() === 'create',
            static fn (PasswordInput $field): PasswordInput => $field->required(),
            static fn (PasswordInput $field): PasswordInput => $field->optionalWhenFilled(),
        ),
    ]);
}
```

## Hiding by record

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\TextInput;

TextInput::make('rejection_reason')->visible(
    static fn (?Model $record): bool => $record !== null && $record->getAttribute('status') === 'rejected',
);

TextInput::make('legacy_id')->hidden(
    static fn (?Model $record): bool => $record === null,
);

TextInput::make('debug')->hidden(app()->isProduction());
```

| Method | Signature |
| --- | --- |
| `hidden()` | `hidden(Closure(?Model): bool\|bool $condition = true): static` |
| `visible()` | `visible(Closure(?Model): bool\|bool $condition = true): static` |

A bare `hidden()` means hidden always; a bare `visible()` means visible always. Passing a plain bool is the same as passing a closure that returns it.

The closure receives the record the form is being built for — null on a create page — and is evaluated once, when the schema is serialized. It cannot react to what is being typed, and that honesty is the point: a condition that must react is a `visibleWhen()`.

## The order the answers are combined

`Field::isHiddenOn(string $page, ?Model $record = null): bool` checks four sources, strictest first:

1. `hidden()` said yes → hidden.
2. `visible()` said no → hidden.
3. `visibleOn()` was set and does not name this page → hidden.
4. `hiddenOn()` names this page → hidden.

Otherwise the field is shown. One decision drives all three consequences — rendering, validation, and persistence — rather than three that could disagree.

```php
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;

$schema = FormSchema::make()->forPage('create')->schema([
    TextInput::make('name'),
    TextInput::make('slug')->visibleOn(['edit']),
]);

array_column($schema->toArray()['schema'], 'name');   // ['name']
$schema->validationRules();                           // no 'slug' key
$schema->dehydrate(['slug' => 'injected']);           // []
```

A layout containing only hidden fields still renders — a container is not a field, and it disappears only if you remove it. Its hidden children drop out of `schema`, so it renders as an empty container rather than as gaps.

## Disabling

A disabled field is drawn, shows its value, and cannot be edited in the browser.

| Method | Signature |
| --- | --- |
| `disabled()` | `disabled(bool $disabled = true): static` |
| `disabledOn()` | `disabledOn(list<string> $pages): static` |
| `isDisabledOn()` | `isDisabledOn(string $page, ?Model $record = null): bool` |

```php
use PandaPanel\Forms\Components\TextInput;

TextInput::make('email')->disabledOn(['edit']);
TextInput::make('reference')->disabled();
```

`disabled()` takes a bool only. There is no closure form and no `disabledWhen()`: per-record read-only rendering is not supported, and the honest alternatives are hiding the field with a `hidden()` closure, or showing the value in the [infolist](../infolists/overview.md) instead.

**Disabling is presentation, not a guarantee.** The field stays in the schema, so its value is submitted, validated, and persisted like any other. A user who edits the request can change it. When the value must not be writable, say so where it is decided:

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\TextInput;

TextInput::make('reference')
    ->disabled()
    ->dehydrated(false);                    // never written

TextInput::make('created_by')
    ->disabled()
    ->dehydrated(static fn (?Model $record): bool => $record === null);  // written on create only
```

## Carrying a value without showing it

`PandaPanel\Forms\Components\HiddenInput` renders no control but is a field in every other respect: it is validated by the rules you declare and persisted like any other.

```php
use PandaPanel\Forms\Components\HiddenInput;

HiddenInput::make('source')
    ->default('panel')
    ->rules(['in:panel,api']);
```

Hidden is a rendering choice, not a trust boundary: the value still arrives from the browser. If it must not come from the browser at all, leave it out of the schema and set it in `mutateFormDataBeforeSave()` or `handleRecordCreation()`.

`HiddenInput` and `hidden()` are opposites, despite the name: `HiddenInput` is a field that exists and submits, `hidden()` removes a field from the form entirely.

## What is not supported

- **No `disabled()` closure and no `disabledWhen()`.** Read-only is a page-level or always-on declaration.
- **No `readOnly()`.** Use `disabled()`.
- **No `hiddenJs()` or any other way to ship executable code from the server.** Reactive visibility is a closed set of described comparisons — see [Field visibility](visibility.md).
- **No per-user visibility helper.** Read the user yourself inside a `visible()` closure, or authorize the page. Hiding a field is not authorization.

## Notes

- **A hidden field's default is not written.** Absent means absent everywhere, including from `dehydrate()`.
- **`visibleOn()` defaults to null, not to the empty list.** A field that never calls it is visible on every page; `visibleOn([])` hides it on all of them.
- **Custom page keys work.** `forPage('review')` plus `visibleOn(['review'])` is a valid pair; the page string is not restricted to `create` and `edit`.
- **The `form-state` endpoint rebuilds with the same page.** A field hidden by a `visible()` closure stays hidden across a live rebuild, because the closure is re-evaluated on every serialization.
- **`PasswordInput` never sends a value back**, disabled or not. Its `formValue()` returns null so the stored hash cannot reach the page payload.

## See also

- [Field visibility](visibility.md)
- [FormSchema basics](overview.md)
- [Validation](validation.md)
- [Field state lifecycle](state-lifecycle.md)
- [Hydration and dehydration](hydration.md)
- [Live fields](live-fields.md)
- [Resource authorization](../resources/authorization.md)
- [Infolists](../infolists/overview.md)
