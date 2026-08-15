# Key Value

`PandaPanel\Forms\Components\KeyValue` edits a flat map of name/value pairs. Both halves are free text, so what the field bounds is the shape rather than the content: how many pairs there may be, whether rows can be added or removed, and whether the keys themselves can be typed into. Reach for it when a record carries open-ended metadata — per-product attributes, SEO tags, a settings blob — and for anything with a known set of keys, declare real fields instead.

## The minimal example

```php
use PandaPanel\Forms\Components\KeyValue;
use PandaPanel\Forms\FormSchema;

FormSchema::make()->schema([
    KeyValue::make('meta')
        ->labels('Attribute', 'Value')
        ->columnSpanFull(),
]);
```

The value is an associative array, so cast the attribute:

```php
protected function casts(): array
{
    return ['meta' => 'array'];
}
```

## The methods

```php
public function labels(string $key, string $value): self  // defaults: 'Key', 'Value'
public function maxPairs(int $max): self                  // default: null, clamped to >= 1
public function addable(bool $addable = true): self       // default: true
public function deletable(bool $deletable = true): self   // default: true
public function editableKeys(bool $editable = true): self // default: true
```

| Method | Default | Effect |
| --- | --- | --- |
| `labels()` | `'Key'` / `'Value'` | the column headings above the two inputs |
| `maxPairs()` | `null` | adds `max:n` to the rules and stops the Add button above it |
| `addable()` | `true` | draws the "Add row" button |
| `deletable()` | `true` | draws the remove button on each row |
| `editableKeys()` | `true` | when false, the key inputs are disabled |

```php
use PandaPanel\Forms\Components\KeyValue;

KeyValue::make('meta')
    ->labels('Attribute', 'Value')
    ->maxPairs(20)
    ->helperText('Shown on the product page, in the order given.');
```

A fixed set of settings whose values may change but whose names may not:

```php
use PandaPanel\Forms\Components\KeyValue;

KeyValue::make('limits')
    ->labels('Limit', 'Value')
    ->default(['requests_per_minute' => '60', 'burst' => '10'])
    ->editableKeys(false)
    ->addable(false)
    ->deletable(false);
```

## Validation

```php
use PandaPanel\Forms\Components\KeyValue;
use PandaPanel\Forms\FormSchema;

FormSchema::make()
    ->schema([KeyValue::make('meta')->maxPairs(10)])
    ->validationRules();

// ['meta' => ['nullable', 'array', 'max:10']]
```

That is the whole rule set. `max:` on an array counts entries, so `maxPairs(10)` means ten pairs. There is no per-value rule: the field declares no `elementRules()`, so nothing is generated at `meta.*`.

If the values must be constrained, add a rule yourself. A closure rule sees the whole map:

```php
use Closure;
use PandaPanel\Forms\Components\KeyValue;

KeyValue::make('meta')->rules([
    static function (string $attribute, mixed $value, Closure $fail): void {
        foreach ((array) $value as $key => $entry) {
            if (! is_string($entry) || mb_strlen($entry) > 255) {
                $fail("The {$key} value must be a string of at most 255 characters.");
            }
        }
    },
]);
```

## Hydration

```php
protected function castForForm(mixed $value): array
```

The normalization on the way in is deliberate and slightly aggressive:

| Stored value | Result |
| --- | --- |
| an array | each entry kept as `(string) $key => (string) $entry` |
| a JSON string | decoded first, then the same |
| anything else | `[]` |
| an entry with an empty key | dropped — a map with an empty key has one entry nobody can address |
| an entry whose value is not scalar | dropped — the control has one text input per value |

```php
use PandaPanel\Forms\Components\KeyValue;

// $record->meta holds ['size' => 42, '' => 'orphan', 'tags' => ['a', 'b']]
KeyValue::make('meta')->formValue($record);

// ['size' => '42']
```

The JSON-string branch means the field works over a `text` column holding JSON without a cast, as well as over a cast `array` attribute.

## What the control does

`resources/js/panel/forms/fields/KeyValueField.vue` edits an **ordered list of pairs** and submits a **map**. The list is what makes editing possible at all: a map cannot hold two entries with the same key, and renaming one by typing goes through states where it briefly collides with another or is blank. Rebuilding the map on every keystroke would drop rows out from under the cursor.

The consequences are worth knowing:

- a row with a blank key stays on screen and is simply not submitted until it is typed into;
- two rows given the same key collapse into one on submit, last one winning;
- row order is the object's key order, which is preserved through JSON and through PHP's arrays.

## What crosses the wire

```ts
interface KeyValueFieldDefinition extends BaseFieldDefinition {
    type: 'key_value';
    keyLabel: string;
    valueLabel: string;
    maxPairs: number | null;
    addable: boolean;
    deletable: boolean;
    editableKeys: boolean;
}
```

## Gotchas

**Values arrive as strings on the way in, but not on the way out.** `castForForm()` casts every value to a string for the control; nothing casts them back. What is stored is whatever the request contained, which for this field's own control is always strings. If you need certainty, do it explicitly:

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\KeyValue;

KeyValue::make('meta')->mutateUsing(
    static fn (mixed $value, ?Model $record): array => array_map(
        strval(...),
        array_filter(is_array($value) ? $value : [], is_scalar(...)),
    ),
);
```

**`editableKeys(false)` is a control, not a constraint.** It disables the key inputs in the browser. The `array` rule does not care what the keys are, so a crafted request can still send different ones. Lock them on the server too — with the closure rule above, or by rebuilding the map in `mutateUsing()` from a known list.

**Nested structure does not survive a round trip.** A stored `['tags' => ['a', 'b']]` is dropped on hydration, so opening and saving the record removes it. Use a [Repeater](repeater.md) for structured entries, or a [Code editor](code-editor.md) with `CodeLanguage::Json` for a document that must keep its shape.

**`required()` on an empty map.** An empty array fails `required`, which is usually what you want for "at least one pair"; combine it with `maxPairs()` for an upper bound. Leave `required()` off and the rules start with `nullable`, and an empty map is accepted.

**Keys are strings, always.** PHP turns a numeric string key into an int in an array; `castForForm()` casts it back to a string for display, and JSON keys are strings on the wire. Do not rely on integer keys surviving as integers.

## See also

- [Repeater](repeater.md) — a list of structured entries rather than a flat map
- [Code Editor](code-editor.md) — a JSON document kept as text
- [Tags](tags.md) — a list of values with no keys
- [Validation](../validation.md)
- [State Lifecycle](../state-lifecycle.md) — `mutateUsing()` and `formatUsing()`
- [Forms and Schemas](../overview.md)
