# Color Picker

`PandaPanel\Forms\Components\ColorPicker` stores a colour as text and edits it with a native colour input, a text box, and an optional row of preset swatches. Reach for it when a record carries a colour that something will later render — a badge, a tag, a theme accent. The value ends up inside an inline `style`, which is why the field validates the syntax rather than trusting whatever the browser sent.

## The minimal example

```php
use PandaPanel\Forms\Components\ColorPicker;
use PandaPanel\Forms\FormSchema;

FormSchema::make()->schema([
    ColorPicker::make('accent_color')
        ->default('#4f46e5')
        ->swatches(['#4f46e5', '#059669', '#dc2626', '#d97706']),
]);
```

## The methods

```php
public function swatches(array $swatches): self          // list<string>
public static function isColor(string $value): bool
```

`ColorPicker` adds exactly these two. Everything else is inherited from `Field` — `label()`, `helperText()`, `placeholder()`, `default()`, `required()`, `columnSpan()`, the visibility methods, the lifecycle hooks.

### `swatches()`

One-click choices shown under the control. The list is filtered through `isColor()` when you set it, so an entry that is not a colour never reaches the browser and never becomes a `background-color`:

```php
use PandaPanel\Forms\Components\ColorPicker;

ColorPicker::make('accent_color')->swatches([
    '#0f172a',
    'rgb(79, 70, 229)',
    'hsl(160 84% 39%)',
    'red; background: url(x)',   // dropped
]);
```

Clicking a swatch sets the field to that exact string.

### `isColor()`

A static predicate, public because the infolist's `ColorEntry` asks the same question of the same values. It accepts three syntaxes:

| Syntax | Pattern | Examples |
| --- | --- | --- |
| Hex | `#` plus 3, 4, 6, or 8 hex digits | `#fff`, `#fff8`, `#4f46e5`, `#4f46e5cc` |
| `rgb()` / `rgba()` | digits, dots, spaces, commas, `%`, `/` | `rgb(79, 70, 229)`, `rgba(0 0 0 / 50%)` |
| `hsl()` / `hsla()` | the same plus `deg` | `hsl(160 84% 39%)` |

```php
use PandaPanel\Forms\Components\ColorPicker;

ColorPicker::isColor('#a1b2c3');                    // true
ColorPicker::isColor('rgb(1, 2, 3)');               // true
ColorPicker::isColor('red; background: url(x)');    // false
ColorPicker::isColor('expression(alert(1))');       // false
```

The last two are the cases that matter. A stored colour is interpolated into a `style` attribute; an unvalidated string there is arbitrary CSS coming out of a database row.

## Validation

```php
use PandaPanel\Forms\Components\ColorPicker;
use PandaPanel\Forms\FormSchema;

FormSchema::make()
    ->schema([ColorPicker::make('accent_color')->required()])
    ->validationRules();

// [
//     'accent_color' => [
//         'required',
//         'string',
//         'regex:/^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i',
//     ],
// ]
```

**A submitted value must be hex.** The rule is the hex pattern alone, not the wider set `isColor()` recognises. That is narrower on purpose — the native colour input only ever produces `#rrggbb`, so hex is what a form actually submits — but it means the display side and the write side accept different things. See the gotcha below.

## Hydration

```php
protected function castForForm(mixed $value): ?string
```

A stored value is passed through when it is a string that `isColor()` accepts, and becomes `null` otherwise. A row holding `red` or `var(--brand)` therefore arrives at the form empty rather than as text the picker cannot represent.

```php
use PandaPanel\Forms\Components\ColorPicker;

ColorPicker::make('accent_color')->formValue($record);   // '#4f46e5', or null
```

## What the control does

`resources/js/panel/forms/fields/ColorPickerField.vue` draws three things:

- a native `<input type="color">`, which must hold a valid six-digit hex. Anything unparseable shows as black there while the text box keeps exactly what was typed, so a half-finished `#ab` is not rewritten under the cursor;
- a text input bound to the raw value, so `rgb()` and `hsl()` values can be read and edited;
- the swatch row, when `swatches()` was given anything, with the current value ringed.

## What crosses the wire

```ts
interface ColorPickerFieldDefinition extends BaseFieldDefinition {
    type: 'color_picker';
    swatches: string[];
}
```

## Gotchas

**`swatches()` is wider than the validation rule.** An `rgb()` or `hsl()` swatch renders and can be clicked, and the resulting value will then be rejected by the `regex` rule on submit. Keep swatches hex unless you also relax the rule:

```php
use PandaPanel\Forms\Components\ColorPicker;

ColorPicker::make('accent_color')
    ->swatches(['rgb(79, 70, 229)'])
    ->rules(['regex:/^(#[0-9a-f]{3,8}|rgba?\(.+\)|hsla?\(.+\))$/i']);
```

Note that `rules()` **appends**; the built-in hex `regex` is still in the list and both must pass. To accept a non-hex syntax you have to widen the field's own pattern, which the package does not offer a setter for — the honest options are to keep to hex, or to store the colour in a plain `TextInput` and validate it yourself.

**An invalid stored value silently becomes empty.** `castForForm()` returns `null` for anything `isColor()` rejects, so a legacy column full of `red` and `blue` looks like a column full of blanks in the form. Migrate the data, or map it in `formatUsing()`.

**There is no alpha control.** The rule accepts 4- and 8-digit hex, but the native colour input cannot produce them. An alpha value has to be typed into the text box.

**The value is a string, always.** No cast is applied on the way out. Store it in a short `varchar` — nine characters covers every hex form.

## See also

- [Text](text.md) — when the colour format is your own
- [Visibility](../visibility.md)
- [Validation](../validation.md)
- [Table Columns](../../tables/columns.md) — `ColorColumn`, which shows what this field stores
- [Infolist Entries](../../infolists/entries.md) — `ColorEntry`, which reuses `isColor()`
- [Forms and Schemas](../overview.md)
