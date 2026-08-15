# Tags Field

`PandaPanel\Forms\Components\TagsInput` is a list of short strings the user types. Reach for it when the values are not known in advance — keywords, labels, aliases. When they are known in advance, the field you want is a [select](select.md) or a checkbox list, because those can validate against a whitelist and this one cannot.

## A minimal form

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Posts\Forms;

use PandaPanel\Forms\Components\TagsInput;
use PandaPanel\Forms\FormSchema;

final class PostForm
{
    public static function configure(FormSchema $schema): FormSchema
    {
        return $schema->schema([
            TagsInput::make('keywords')
                ->maxTags(10)
                ->maxLength(30)
                ->placeholder('Type and press Enter'),
        ]);
    }
}
```

Rules become `keywords => nullable|array|max:10` and `keywords.* => string|max:30`. The value is stored as a PHP array.

## What is bounded, and why

There is no whitelist here — the point is that the values are not known in advance. What is bounded instead is how many and how long, because an unbounded array from a form is an unbounded write.

| Bound | Method | Default | Rule produced |
| --- | --- | --- | --- |
| Number of tags | `maxTags()` | `null` (unbounded) | `max:N` on the field |
| Length of each tag | `maxLength()` | `50` | `max:N` on `field.*` |

The element rules always exist: `['string', 'max:'.$maxLength]`. Laravel will not infer them from a rule list on the field itself, so `FormSchema` emits them under `keywords.*`.

## Storing an array

The field's value is an array on the way in and on the way out. A model that keeps tags in a single column should cast the attribute:

```php
protected function casts(): array
{
    return ['keywords' => 'array'];
}
```

Storing them joined instead is a `dehydrateStateUsing()` away, and stays the schema's decision rather than the field's:

```php
TagsInput::make('keywords')
    ->separator(',')
    ->dehydrateStateUsing(static fn (mixed $value): string => is_array($value)
        ? implode(',', $value)
        : '');
```

With `separator(',')` set, the stored string is split back into tags when the form is hydrated, so the round trip closes.

## Methods

### `suggestions(array $suggestions): self`

`list<string>`, default `[]`. Rendered as a `<datalist>` on the text box: typed-ahead completions, not a constraint.

```php
use PandaPanel\Forms\Components\TagsInput;

TagsInput::make('keywords')->suggestions(['php', 'laravel', 'vue', 'inertia']);
```

Suggestions already added are not offered again. Nothing about them reaches validation — a user may type anything, and a suggestion they ignore is not an error. If the list is the constraint, this is the wrong field.

Suggestions are static: there is no callback and no lookup endpoint, so building them from the database is the schema's job:

```php
use App\Models\Post;

TagsInput::make('keywords')->suggestions(
    Post::query()->distinct()->pluck('primary_keyword')->all(),
);
```

### `maxTags(int $max): self`

Default `null`, meaning no limit. Adds `max:N` to the field's own rules and stops the browser accepting another tag once the count is reached — the input is disabled at the limit rather than silently dropping what was typed. Values below `1` are clamped to `1`.

```php
TagsInput::make('keywords')->maxTags(5);
```

### `maxLength(int $length): self`

Default `50`. The maximum length of one tag, in the element rules and as the input's `maxlength`. Values below `1` are clamped to `1`.

```php
TagsInput::make('keywords')->maxLength(24);
```

Unlike `TextInput::maxLength()`, this one does not accept `null`. There is always a per-tag length, because an unbounded string inside an unbounded array is two unbounded writes.

### `separator(string $separator): self`

Default `null`. Splits one typed string into several tags, which makes pasting a list work — `"red, green"` becomes two tags rather than one with a comma in it.

```php
TagsInput::make('keywords')->separator(',');
```

An empty separator would split every character into its own tag, so `separator('')` is refused: the property is set back to `null` rather than accepted and regretted.

The separator is also used when the form is hydrated. `castForForm()` explodes a string value by it, which is how a comma-joined column round-trips into tags.

### `type(): FieldType`

Returns `FieldType::TagsInput`, serialized as `'tags_input'`.

## Serialized shape

`TagsInput::make('keywords')->maxTags(10)->separator(',')->toArray(null, 'create')` adds four keys to the base field payload:

| Key | Type | Default |
| --- | --- | --- |
| `suggestions` | `string[]` | `[]` |
| `maxTags` | `number \| null` | `null` |
| `maxLength` | `number` | `50` |
| `separator` | `string \| null` | `null` |

The value is normalized by `castForForm()` before it is sent: a string is split when a separator is set, everything is mapped to strings, and empty entries are dropped. A non-array, non-string value becomes `[]`.

## How the control behaves

`TagsInputField.vue` is a text box with the committed tags rendered above it as removable chips.

- **Enter** commits the draft. So does blurring the box, so a tag typed and abandoned is not lost.
- **Backspace** on an empty box removes the last tag.
- A tag that is blank, already present, over `maxLength`, or past `maxTags` is refused in the browser rather than submitted to be told about.
- The chip's remove button is disabled along with the field when `disabled()` applies.

None of that is the check. Every one of those bounds is re-applied by the rules on the server.

## Gotchas

- **Duplicates are refused by the control, not by the rules.** The browser will not add a tag that is already in the list, but a request carrying `["a", "a"]` validates. Laravel's `distinct` rule belongs on the elements, and `elementRules()` here is fixed at `['string', 'max:N']` — `rules()` adds to the field, not to its elements. De-duplicate on the way out instead:

  ```php
  TagsInput::make('keywords')
      ->dehydrateStateUsing(static fn (mixed $value): array => is_array($value)
          ? array_values(array_unique(array_map(strval(...), $value)))
          : []);
  ```

- **`maxTags` is `max:N` on an array, which Laravel counts.** It is not a character length. `maxLength` is the character length, and it lives on the elements.
- **`required()` rejects an empty list.** Laravel's `required` fails a countable of length zero, so a required tags field insists on at least one tag. That is usually right; use `required(false)` when an empty set is meaningful.
- **The separator is a client-side convenience with a server-side consequence.** It splits pasted input in the browser *and* splits a string value during hydration. Setting it on a field whose column already holds an array changes nothing, because `castForForm()` only splits strings.
- **Suggestions are not options.** They do not restrict anything and they are not validated against. A field that must only accept known values is a [select](select.md) with `multiple()`, or a checkbox list.
- **Tags are not a relation.** This field writes an array to a column. To store tags as related records, use a [`Select`](select.md) with `->relationship(...)`, which syncs a pivot after the record is saved.

## See also

- [Select field](select.md)
- [Key-value field](key-value.md)
- [Repeater field](repeater.md)
- [Text field](text.md)
- [Validation](../validation.md)
- [Hydration and dehydration](../hydration.md)
- [Relationship forms](../relationships.md)
- [Forms overview](../overview.md)
