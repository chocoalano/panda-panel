# Hydration And Dehydration

Hydration is turning a model into form values; dehydration is turning validated input back into attributes to write. You reach for this page when a form field and a database column disagree — about their name, their type, or whether the field should be written at all. [Field state lifecycle](state-lifecycle.md) covers the hook order; this page covers the conversions themselves.

## A minimal example

```php
use App\Models\Post;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;

$post = Post::query()->find(1);

$schema = FormSchema::make()
    ->model(Post::class)
    ->forPage('edit')
    ->schema([
        TextInput::make('title'),
        TextInput::make('slug')->dehydrateTo('url_slug'),
    ]);

// In: the record becomes field values.
$form = $schema->toArray($post);
$form['schema'][0]['value'];    // 'Hello world'

// Out: validated input becomes attributes.
$schema->dehydrate(['title' => 'Renamed', 'slug' => 'renamed'], $post);
// ['title' => 'Renamed', 'url_slug' => 'renamed']
```

## Hydration

`Field::formValue(?Model $record): mixed` produces one field's value:

- `$record === null` — a create page — so the value is `default()`.
- Otherwise `data_get($record, $name)`, so an ordinary column, an accessor, and a cast attribute all resolve without a hook.
- Then `formatUsing()`, or the field's `castForForm()` when there is none.
- Then `afterStateHydrated()`, whose return value is ignored.

### What each type casts a model value to

`castForForm()` is `protected`, so this is behaviour rather than API — but it is the reason a `datetime` column arrives in a `datetime-local` control without anybody writing a hook.

| Field | Input it accepts | Value the control receives |
| --- | --- | --- |
| `TextInput`, `Textarea` | anything | `?string` — `null` stays null, everything else is cast |
| `PasswordInput` | anything | always `null` |
| `NumberInput`, `Slider` | numeric | `int\|float`, otherwise `null` |
| `Checkbox`, `Toggle` | anything | `bool` |
| `Select` (single) | `string\|int` | as given, otherwise `null` |
| `Select` (multiple) | `array` | `list<string>`, otherwise `[]` |
| `Radio` | `string\|int` | as given, otherwise `null` |
| `CheckboxList` | `array` | `list<string>`, otherwise `[]` |
| `ToggleButtons` | scalar or array | as for `Select` |
| `DatePicker` | `CarbonInterface` or string | `Y-m-d`, otherwise `null` |
| `DateTimePicker` | `CarbonInterface` or string | `Y-m-d\TH:i` (`:s` with `seconds()`) |
| `TimePicker` | `CarbonInterface` or string | `H:i` (`H:i:s` with `seconds()`) |
| `ColorPicker` | string | the string when it parses as a colour, otherwise `null` |
| `TagsInput` | array, or string with `separator()` | `list<string>`, blanks dropped |
| `KeyValue` | array or JSON string | `array<string, string>`, blank keys dropped |
| `MarkdownEditor` | string | `?string` |
| `CodeEditor` | string or array | the string, or the array pretty-printed as JSON |
| `FileUpload` | string, or array with `multiple()` | the path, or `list<string>` |
| `Repeater` | array | `list<array>`, non-array entries dropped |
| `Builder` | array | `list<array{type, data}>`, entries naming an undeclared block dropped |
| `HiddenInput`, `RichEditor`, `CustomField` | anything | untouched |

A many-to-many `Select` is the exception that cannot read an attribute at all: its value lives in a pivot table. `FormSchema::toArray()` fills it from `Select::relatedKeys($record)` before serializing.

### Page-level fill hooks

`fillForm()` on a resource page wraps the schema's own hydration:

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Resources\Pages\EditRecord;

final class EditPost extends EditRecord
{
    protected static string $resource = PostResource::class;

    protected function beforeFill(): void
    {
        // Before the schema is serialized. Throw Halt to abandon the page.
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Every field's value, keyed by name, after the field hooks ran.
        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function afterFill(array $data): void
    {
        //
    }
}
```

`mutateFormDataBeforeFill()` sees the flattened values from every layout depth and its result is applied back onto the serialized components. Use it when a value depends on more than one field; use `formatUsing()` when it does not.

## Dehydration

`FormSchema::dehydrate(array $validated, ?Model $record = null): array` turns validated input into the attributes a page writes. Six questions decide whether a field contributes anything, in this order:

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\Checkbox;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;

$schema = FormSchema::make()->schema([
    TextInput::make('name'),
    Checkbox::make('confirmation')->dehydrated(false),
]);

$schema->dehydrate(['name' => 'Apollo', 'confirmation' => true]);
// ['name' => 'Apollo']
```

1. The field is visible on this page (`isHiddenOn()`).
2. The field dehydrates at all (`isDehydrated($record)`).
3. The field does not belong to a `Relationship` group — those are written afterwards.
4. The key exists in `$validated`.
5. `shouldDehydrate($value)` — `dehydrateWhen()`'s answer.
6. It is not a many-to-many `Select`, which has no column to write to.

What survives is stored under `getDehydrateKey()` after passing through `mutate()`.

### Choosing the attribute

```php
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\Components\TextInput;

TextInput::make('slug')->dehydrateTo('url_slug');   // explicit

Select::make('author')->relationship('author', 'name');
// Named after the relation, persisted to `author_id` — the schema resolves
// the foreign key from the model, so no form has to spell it out.
```

`FormSchema::dehydrate()` asks `Select::foreignKeyFor($modelClass)` first, which answers non-null only for a `BelongsTo`. Everything else uses `getDehydrateKey()`.

### What is written after the record

`FormSchema::saveRelations(Model $record, array $validated): void` handles the two things that cannot be written with the record itself. A resource page calls it inside the same transaction as the write:

```php
$attributes = $schema->dehydrate($data, $record);

DB::transaction(function () use ($schema, $attributes, $data): void {
    $post = Post::query()->create($attributes);

    // Related records (HasOne, MorphOne, BelongsTo) and pivot rows.
    $schema->saveRelations($post, $data);
});
```

A `HasOne` child and a pivot row both need a key that does not exist until the record does, which is why the order is forced. See [Relationship forms](relationships.md).

### Page-level save hooks

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Resources\Pages\CreateRecord;

final class CreatePost extends CreateRecord
{
    protected static string $resource = PostResource::class;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function beforeValidate(array $input): array
    {
        return $input;      // the raw request, before rules run
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function afterValidate(array $data): array
    {
        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data;       // create only
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data, ?Model $record): array
    {
        return $data;       // create and edit
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function handleRecordCreation(array $attributes): Model
    {
        // The write itself, once dehydration has produced the attributes.
        return parent::handleRecordCreation($attributes);
    }
}
```

These operate on the array; the field hooks operate on one value. `mutateFormDataBeforeSave()` runs *before* `dehydrate()`, so it sees form names — `slug`, not `url_slug`.

## Rebuilding against submitted values

`FormSchema::toArrayWithState(?Model $record, array $state): array` serializes the schema and then overwrites each field's value from `$state`, walking into `schema`, `steps`, and `tabs`. Only fields the schema declares are read out of the state, so a key that was never a field is discarded exactly as it is on submit.

```php
$form = $schema->toArrayWithState(null, ['name' => 'Apollo']);
$form['schema'][0]['value'];    // 'Apollo'
$form['schema'][1]['value'];    // whatever the schema gave it
```

This is what the `form-state` endpoint answers with. See [Live fields](live-fields.md).

## Notes

- **`dehydrate()` never reads the request.** It reads validated data, so a key the rules did not produce cannot reach it.
- **A missing key and a null value are different.** `dehydrate()` skips a field whose key is absent from `$validated` rather than writing null. A `Relationship` group makes the same distinction with its own path check, because `data_get()` cannot tell the two apart.
- **`forceFill()` is what writes.** `handleRecordCreation()` and `handleRecordUpdate()` bypass `$fillable`, which is safe precisely because the attribute list came from the schema rather than from the request. Override either method to write through a service instead.
- **A `Checkbox` defaults to `false`, not null.** A checkbox is always present in the payload as true or false, so `required` would reject an unchecked box; `boolean` is the real rule.
- **A relation group's fields never reach the owner's attributes.** They are excluded by name in step 3 above, which is what stops `profile.bio` being written to a `bio` column on the owner.
- **Hydration runs on every render, including the `form-state` endpoint.** A `formatUsing()` closure that queries should expect to be called more than once per page.

## See also

- [FormSchema basics](overview.md)
- [Field state lifecycle](state-lifecycle.md)
- [Validation](validation.md)
- [Relationship forms](relationships.md)
- [Live fields](live-fields.md)
- [Resource lifecycle hooks](../resources/lifecycle-hooks.md)
- [CRUD pages](../resources/crud-pages.md)
