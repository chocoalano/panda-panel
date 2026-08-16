# Entry Reference

Every entry type, every option it adds, its default, and the exact shape it serializes. This is the page to keep open while writing an infolist; the shared API that all of them inherit is in [Entries](entries.md).

## The eleven types

| Class | `EntryType` | `type` | Serialized `value` | TypeScript |
| --- | --- | --- | --- | --- |
| `TextEntry` | `Text` | `text` | `string\|null` | `TextEntryDefinition` |
| `BadgeEntry` | `Badge` | `badge` | `{label, color}\|null` | `BadgeEntryDefinition` |
| `BooleanEntry` | `Boolean` | `boolean` | `bool` | `BooleanEntryDefinition` |
| `DateTimeEntry` | `DateTime` | `datetime` | `string\|null` | `DateTimeEntryDefinition` |
| `KeyValueEntry` | `KeyValue` | `key-value` | `list<{key, value}>` | `KeyValueEntryDefinition` |
| `IconEntry` | `Icon` | `icon` | `{icon, color, label}\|null` | `IconEntryDefinition` |
| `ImageEntry` | `Image` | `image` | `string\|null` | `ImageEntryDefinition` |
| `ColorEntry` | `Color` | `color` | `string\|null` | `ColorEntryDefinition` |
| `CodeEntry` | `Code` | `code` | `string\|null` | `CodeEntryDefinition` |
| `RepeatableEntry` | `Repeatable` | `repeatable` | `list<{label, schema}>` | `RepeatableEntryDefinition` |
| `CustomEntry` | `Custom` | `custom` | `unknown` | `CustomEntryDefinition` |

All live in `PandaPanel\Infolists\Components`. The cases are `PandaPanel\Infolists\Enums\EntryType`; the TypeScript union is in `resources/js/panel/types/infolist.ts`.

## One of each

```php
use PandaPanel\Forms\Enums\CodeLanguage;
use PandaPanel\Infolists\Components\BadgeEntry;
use PandaPanel\Infolists\Components\BooleanEntry;
use PandaPanel\Infolists\Components\CodeEntry;
use PandaPanel\Infolists\Components\ColorEntry;
use PandaPanel\Infolists\Components\DateTimeEntry;
use PandaPanel\Infolists\Components\IconEntry;
use PandaPanel\Infolists\Components\ImageEntry;
use PandaPanel\Infolists\Components\KeyValueEntry;
use PandaPanel\Infolists\Components\TextEntry;
use PandaPanel\Tables\Enums\BadgeColor;

return $schema->columns(2)->schema([
    TextEntry::make('name'),
    BadgeEntry::make('status')->colors(['live' => BadgeColor::Success]),
    BooleanEntry::make('is_admin')->labels('Administrator', 'Member'),
    DateTimeEntry::make('created_at')->format('Y-m-d'),
    KeyValueEntry::make('meta'),
    IconEntry::make('status')->icons(['live' => 'check', 'draft' => 'pencil']),
    ImageEntry::make('avatar')->disk('public')->circular(),
    ColorEntry::make('brand')->copyable(),
    CodeEntry::make('payload')->language(CodeLanguage::Json)->copyable(),
]);
```

## The base definition

Every entry serializes these keys before adding its own:

| Key | Type | From |
| --- | --- | --- |
| `component` | `'entry'` | Fixed — the node discriminant |
| `name` | `string` | `make()` |
| `label` | `string` | `label()`, or `Str::headline()` of the name |
| `type` | `EntryType` value | The class |
| `value` | varies | `toValue()` |
| `placeholder` | `string\|null` | `placeholder()` |
| `helperText` | `string\|null` | `helperText()` |
| `columnSpan` | `int\|'full'` | `columnSpan()` / `columnSpanFull()` |
| `action` | `ActionDefinition\|null` | `action()`, null when hidden, unauthorized, or the record does not exist |

`toArray()` returns null entirely when `visible()` said no.

---

## `TextEntry`

The default for anything that reads as words or a number.

| Method | Signature | Default |
| --- | --- | --- |
| `limit()` | `limit(int $characters): self` | `null` — no truncation |
| `prose()` | `prose(bool $prose = true): self` | `false` |

```php
use PandaPanel\Infolists\Components\TextEntry;

TextEntry::make('reference');

TextEntry::make('name')->limit(5);
// 'Grace Hopper' → 'Grace...'

TextEntry::make('body')->prose()->columnSpanFull();
```

`limit()` truncates with `Str::limit()` on the server, so the payload stays small rather than sending the whole value and hiding it with CSS. The ellipsis is part of the returned string.

`prose()` swaps the renderer's `truncate` class for `text-pretty`, so a sentence wraps instead of clipping to one line. It is presentation only and does not change the value.

**Value.** Null for null or `''`. A scalar is cast with `(string)`; anything else is `json_encode()`d, and a value that will not encode answers null.

```php
['type' => 'text', 'value' => 'Grace Hopper', 'prose' => false]
```

---

## `BadgeEntry`

A short status word in a coloured pill.

| Method | Signature | Default |
| --- | --- | --- |
| `colors()` | `colors(array $colors, BadgeColor $default = BadgeColor::Neutral): self` | `[]`, default `Neutral` |

```php
use PandaPanel\Infolists\Components\BadgeEntry;
use PandaPanel\Tables\Enums\BadgeColor;

BadgeEntry::make('status')->colors([
    'open' => BadgeColor::Info,
    'done' => BadgeColor::Success,
    'void' => BadgeColor::Danger,
], default: BadgeColor::Warning);
```

The array is keyed by the value the badge stands for — after `formatUsing()`, if one is set. The colour is resolved on the server so the frontend maps a colour *name* to a literal class rather than deciding what a value means.

`PandaPanel\Tables\Enums\BadgeColor` is a closed set: `Neutral`, `Success`, `Warning`, `Danger`, `Info`. It is closed because each case maps to literal Tailwind classes in `resources/js/panel/palette.ts`, shared with tables and forms — a status is the same colour wherever it is shown.

**Value.** Null for null or `''`. A non-scalar becomes an empty label, which then falls to the default colour.

```php
['type' => 'badge', 'value' => ['label' => 'open', 'color' => 'info']]
```

---

## `BooleanEntry`

A tick or a cross with a word beside it.

| Method | Signature | Default |
| --- | --- | --- |
| `labels()` | `labels(string $true, string $false): self` | `'Yes'` / `'No'` |

```php
use PandaPanel\Infolists\Components\BooleanEntry;

BooleanEntry::make('email_verified_at')->labels('Verified', 'Unverified');
```

Any value works, not only a boolean column: the value is cast with `(bool)`, so a nullable timestamp reads as "has it happened".

**Value.** Always a real boolean, never null — which is why `placeholder()` is never reached on this type.

```php
['type' => 'boolean', 'value' => true, 'trueLabel' => 'Verified', 'falseLabel' => 'Unverified']
```

---

## `DateTimeEntry`

| Method | Signature | Default |
| --- | --- | --- |
| `format()` | `format(string $format): self` | `'M j, Y g:ia'` |
| `since()` | `since(bool $since = true): self` | `false` |

```php
use PandaPanel\Infolists\Components\DateTimeEntry;

DateTimeEntry::make('created_at')->label('Joined');           // 'Aug 15, 2026 6:34pm'
DateTimeEntry::make('created_at')->format('Y-m-d');           // '2026-08-15'
DateTimeEntry::make('updated_at')->since();                   // '3 days ago'
```

`format()` is a PHP date format string, passed to `Carbon::format()`.

`since()` renders `diffForHumans()`, computed on the server — so the string does not drift while a page sits open. A refresh is what updates it. When both are set, `since()` wins.

**Value.** Null only for a null value. A `DateTimeInterface` is used directly; anything else is cast to a string and parsed with `Date::parse()`.

```php
['type' => 'datetime', 'value' => '3 days ago']
```

---

## `KeyValueEntry`

An array or JSON column rendered as pairs, in a three-column list.

It adds no methods. Everything it needs comes from `Entry`.

```php
use PandaPanel\Infolists\Components\KeyValueEntry;

KeyValueEntry::make('meta')->placeholder('Nothing recorded.');
```

**Value.** `[]` when the value is not an array. Keys are cast with `(string)`; a scalar item is cast, and anything nested is `json_encode()`d:

```php
['plan' => 'pro', 'seats' => 4, 'flags' => ['beta']]

// becomes
[
    ['key' => 'plan', 'value' => 'pro'],
    ['key' => 'seats', 'value' => '4'],
    ['key' => 'flags', 'value' => '["beta"]'],
]
```

Flattened here rather than in Vue, so a nested value cannot arrive as an object the renderer has no branch for.

```php
['type' => 'key-value', 'value' => [['key' => 'plan', 'value' => 'pro']]]
```

---

## `IconEntry`

A value shown as an icon rather than as its text.

| Method | Signature | Default |
| --- | --- | --- |
| `icons()` | `icons(array $icons): self` | `[]` |
| `colors()` | `colors(array $colors, BadgeColor $default = BadgeColor::Neutral): self` | `[]`, default `Neutral` |
| `iconUsing()` | `iconUsing(Closure $callback): self` | `null` |

```php
use PandaPanel\Infolists\Components\IconEntry;
use PandaPanel\Tables\Enums\BadgeColor;

IconEntry::make('two_factor_confirmed_at')
    ->label('Two-factor')
    ->formatUsing(static fn (mixed $value): string => $value === null ? 'off' : 'on')
    ->icons(['on' => 'shield', 'off' => 'x'])
    ->colors(['on' => BadgeColor::Success, 'off' => BadgeColor::Neutral]);
```

`icons()` and `colors()` are both keyed by the value they stand for. `iconUsing()` is for an icon that depends on more than the value itself:

```php
use Illuminate\Database\Eloquent\Model;

IconEntry::make('status')
    ->iconUsing(static fn (mixed $value, Model $record): ?string => $record->is_locked ? 'shield' : 'check');
```

When `iconUsing()` is set it replaces the `icons()` lookup entirely, but the *colour* is still looked up by the value.

The icon is a registry key resolved through `resources/js/panel/icons/registry.ts`, never markup: an unregistered name renders nothing rather than something arbitrary. Run `php artisan panel:icons` after adding one.

**Value.** Null when the resolved icon is not a non-empty string — so a value with no entry in `icons()` renders the placeholder. The label travels with the icon because an icon alone reads as empty to a screen reader; it is the value key, cast to a string.

```php
['type' => 'icon', 'value' => ['icon' => 'shield', 'color' => 'success', 'label' => 'on']]
```

---

## `ImageEntry`

A stored path shown as a picture.

| Method | Signature | Default |
| --- | --- | --- |
| `disk()` | `disk(string $disk): self` | `null` — the value is used as-is |
| `size()` | `size(int $pixels): self` | `96` |
| `circular()` | `circular(bool $circular = true): self` | `false` |

```php
use PandaPanel\Infolists\Components\ImageEntry;

ImageEntry::make('avatar')->disk('public')->size(64)->circular();
```

The URL is built on the server, so the browser never turns a disk name into a link:

| Value | Result |
| --- | --- |
| Not a string, or `''` | `null` |
| Starts with `http://` or `https://` | Used unchanged — an absolute URL is already an answer |
| A path, no `disk()` set | Used unchanged |
| A path, `disk()` set | `Storage::disk($disk)->url($value)` |
| A path on a disk with no public URL | `null` — the driver throws `RuntimeException` and it is caught |

A private disk answering null rather than a URL that 404s is the honest outcome; the renderer then shows the placeholder.

`size()` sets the rendered `width` and `height` in pixels. It does not resize the file.

```php
['type' => 'image', 'value' => 'https://…/avatars/one.png', 'size' => 64, 'circular' => true]
```

---

## `ColorEntry`

A stored colour shown as a swatch beside its value.

| Method | Signature | Default |
| --- | --- | --- |
| `copyable()` | `copyable(bool $copyable = true): self` | `false` |

```php
use PandaPanel\Infolists\Components\ColorEntry;

ColorEntry::make('brand')->copyable();
```

**Value.** Validated against `PandaPanel\Forms\Components\ColorPicker::isColor()` — the same pattern the colour field accepts — and null when it fails. That value ends up inside a `style` attribute, so a stored string that is a stylesheet rather than a colour would otherwise be rendered as one:

```php
ColorEntry::make('brand')->toValue(new Project(['brand' => '#ff0000']));               // '#ff0000'
ColorEntry::make('brand')->toValue(new Project(['brand' => 'red; background: url(x)'])); // null
```

Accepted syntaxes are hex (`#rgb`, `#rgba`, `#rrggbb`, `#rrggbbaa`), `rgb()`/`rgba()`, and `hsl()`/`hsla()`. A named colour like `red` is *not* accepted.

`copyable()` adds a Copy button that writes the value to the clipboard; a refused clipboard is silent, because the value is on screen and can be selected by hand.

```php
['type' => 'color', 'value' => '#ff0000', 'copyable' => true]
```

---

## `CodeEntry`

A value shown as preformatted text in a monospace block.

| Method | Signature | Default |
| --- | --- | --- |
| `language()` | `language(CodeLanguage $language): self` | `CodeLanguage::Plain` |
| `copyable()` | `copyable(bool $copyable = true): self` | `false` |

```php
use PandaPanel\Forms\Enums\CodeLanguage;
use PandaPanel\Infolists\Components\CodeEntry;

CodeEntry::make('payload')->language(CodeLanguage::Json)->copyable();
```

`PandaPanel\Forms\Enums\CodeLanguage` is closed: `Plain`, `Json`, `Html`, `Css`, `JavaScript`, `Php`, `Sql`, `Yaml`, `Markdown`. It is the same enum the code editor field uses, and it is closed because the frontend maps each case to a fixed label rather than accepting a free string. Nothing highlights the value: `language` crosses the wire and the renderer draws the block as plain monospace text, because syntax highlighting would mean a highlighter dependency in every panel bundle.

**Value.** Null for null or `''`. A scalar is cast. Anything else is pretty-printed with `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES` — here rather than in Vue, so what arrives is already the string that will be shown.

That makes `formatUsing()` returning an array a clean way to build a summary block:

```php
use Illuminate\Database\Eloquent\Model;

CodeEntry::make('summary')
    ->copyable()
    ->formatUsing(static fn (mixed $value, Model $record): array => [
        'id' => $record->getKey(),
        'admin' => (bool) $record->getAttribute('is_admin'),
        'verified' => $record->getAttribute('email_verified_at') !== null,
    ]);
```

```php
['type' => 'code', 'value' => "{\n    \"a\": 1\n}", 'language' => 'json', 'copyable' => true]
```

---

## `RepeatableEntry`

One sub-schema rendered once per item.

| Method | Signature | Default |
| --- | --- | --- |
| `schema()` | `schema(array $components): self` | `[]` |
| `columns()` | `columns(int $columns): self` | `1`, clamped to 1–4 |
| `itemLabel()` | `itemLabel(string $label): self` | `null` |

```php
use PandaPanel\Infolists\Components\RepeatableEntry;
use PandaPanel\Infolists\Components\TextEntry;

RepeatableEntry::make('lines')->itemLabel('Line')->schema([TextEntry::make('product')]);
```

Full treatment in [Repeatable entries](repeatable-entries.md).

```php
['type' => 'repeatable', 'columns' => 3, 'value' => [['label' => 'Line 1', 'schema' => [...]]]]
```

---

## `CustomEntry`

A value drawn by a Vue component of your own.

| Method | Signature | Default |
| --- | --- | --- |
| `component()` | `component(string $component): self` | `''` |
| `config()` | `config(array $config): self` | `[]` |
| `state()` | `state(Closure $callback): self` | `null` |

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Infolists\Components\CustomEntry;

CustomEntry::make('score')
    ->component('Panels/Admin/Entries/Gauge')
    ->config(['max' => 10])
    ->state(static fn (Model $record): int => 7);
```

Full treatment in [Custom entries](custom-entries.md).

```php
['type' => 'custom', 'value' => 7, 'componentName' => 'Panels/Admin/Entries/Gauge', 'config' => ['max' => 10]]
```

---

## Which type for which value

| The value is | Use |
| --- | --- |
| A name, a reference, a number | `TextEntry` |
| A paragraph | `TextEntry` with `prose()` and `columnSpanFull()` |
| One of a few statuses | `BadgeEntry`, or `IconEntry` when the icon says it faster |
| A flag, or a nullable "has it happened" timestamp | `BooleanEntry` |
| A date or a timestamp | `DateTimeEntry` |
| A flat JSON object | `KeyValueEntry` |
| A stored file path | `ImageEntry` |
| A colour string | `ColorEntry` |
| A payload, a log line, a config blob | `CodeEntry` |
| A list of records or rows | `RepeatableEntry` |
| None of the above | `CustomEntry` |

## Gotchas

- **`DateTimeEntry` only null-checks.** An empty string is not null, so it reaches `Date::parse('')` — which answers *now*. Cast the column, or guard it in `formatUsing()`, when the value can be `''`.
- **`BooleanEntry` never shows a placeholder.** `(bool) null` is false, so a missing value reads as the false label. Say so in the labels: `labels('Verified', 'Unverified')` rather than `labels('Yes', 'No')`.
- **`ImageEntry` with no `disk()` passes the raw value through.** That is intentional for a column already holding a URL, and confusing for one holding a path — the browser resolves it relative to the panel URL. Name the disk.
- **`ColorEntry` rejects named colours.** `red` is not one of the three accepted syntaxes and serializes as null.
- **`BadgeEntry` and `IconEntry` key on the *formatted* value.** If `formatUsing()` returns `'Administrator'`, the `colors()` key is `'Administrator'`, not the stored `1`.
- **`CustomEntry` sends `componentName`, not `component`.** The `component` key is the node discriminant and is `'entry'` for every entry type.
- **A type with no `extraArray()` sends nothing extra.** `BadgeEntry`, `DateTimeEntry`, `KeyValueEntry` and `IconEntry` put everything they resolved inside `value`, which is why their options do not appear as top-level keys.

## See also

- [Entries](entries.md)
- [Layouts](layouts.md)
- [Repeatable entries](repeatable-entries.md)
- [Custom entries](custom-entries.md)
- [Actions in infolists](actions.md)
- [InfolistSchema basics](overview.md)
- [Table columns](../tables/columns.md)
- [Icons](../frontend/icons.md)
