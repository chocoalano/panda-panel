# Entries

An entry is one thing an infolist shows about a record: how it describes itself to the frontend, and how a record becomes a serializable value. You reach for an entry type by what the value *is*, because the type is the discriminant a Vue renderer switches on.

Every type extends `PandaPanel\Infolists\Components\Entry`, so everything on this page is available on all of them. The per-type options live in the [Entry reference](entry-reference.md).

## A minimal set of entries

```php
use PandaPanel\Infolists\Components\BadgeEntry;
use PandaPanel\Infolists\Components\BooleanEntry;
use PandaPanel\Infolists\Components\DateTimeEntry;
use PandaPanel\Infolists\Components\TextEntry;
use PandaPanel\Infolists\InfolistSchema;
use PandaPanel\Tables\Enums\BadgeColor;

return $schema->columns(2)->schema([
    TextEntry::make('reference'),
    TextEntry::make('customer.name')->label('Customer'),
    BadgeEntry::make('status')->colors([
        'open' => BadgeColor::Info,
        'done' => BadgeColor::Success,
    ]),
    BooleanEntry::make('paid')->labels('Paid', 'Unpaid'),
    DateTimeEntry::make('created_at')->label('Placed')->since(),
]);
```

## `make()` and the name

```php
public static function make(string $name): static
```

The name is an attribute on the record, read with `data_get()`. Dot notation walks relations and nested arrays, so `author.name` is an entry rather than a reason to write a formatter:

```php
TextEntry::make('author.name');       // $record->author->name
TextEntry::make('meta.plan');         // a key inside a JSON column
```

The constructor is `final public function __construct(string $name)`, so `new TextEntry('name')` works too. `make()` is the form every example uses because it chains.

An entry that reads through a relation reads it per record. On a view page that is one record and one extra query; a table column doing the same across fifty rows is what `$with` exists for.

## Labels

```php
public function label(string $label): static
public function getLabel(): string
```

The default is `Str::headline()` of the name with dots turned into spaces:

```php
TextEntry::make('email_address')->getLabel();          // 'Email Address'
TextEntry::make('author.name')->getLabel();            // 'Author Name'
TextEntry::make('name')->label('Full name')->getLabel();  // 'Full name'
```

## The shared API

| Method | Signature | Default |
| --- | --- | --- |
| `make()` | `static make(string $name): static` | — |
| `getName()` | `getName(): string` | The name given to `make()` |
| `label()` | `label(string $label): static` | `Str::headline()` of the name |
| `getLabel()` | `getLabel(): string` | — |
| `placeholder()` | `placeholder(string $placeholder): static` | `null` — the renderer shows `—` |
| `helperText()` | `helperText(string $helperText): static` | `null` |
| `columnSpan()` | `columnSpan(int $columnSpan): static` | `1`, floored at 1 |
| `columnSpanFull()` | `columnSpanFull(): static` | Sets the span to `'full'` |
| `formatUsing()` | `formatUsing(Closure $callback): static` | `null` |
| `visible()` | `visible(Closure $callback): static` | `null` — always visible |
| `isVisible()` | `isVisible(Model $record): bool` | `true` |
| `action()` | `action(Action $action): static` | `null` |
| `getAction()` | `getAction(): ?Action` | — |
| `type()` | `type(): EntryType` | Abstract; each class returns its own case |
| `toValue()` | `toValue(Model $record): mixed` | Abstract; the serialized value |
| `entries()` | `entries(): list<Entry>` | `[$this]` |
| `toArray()` | `toArray(Model $record): ?array` | The definition, or null when hidden |

## Shaping the value

`formatUsing()` sits between the attribute and the type's own handling:

```php
public function formatUsing(Closure $callback): static
// Closure(mixed $value, Model $record): mixed
```

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Infolists\Components\TextEntry;

TextEntry::make('total')
    ->formatUsing(static fn (mixed $value, Model $record): string => number_format((float) $value, 2));
```

The closure receives the resolved attribute and the record, and returns whatever the type should work with next — not the finished HTML. What "next" means depends on the type, and that is the useful part:

| Type | What `formatUsing()` should return |
| --- | --- |
| `TextEntry` | The string (or anything scalar) to show, before `limit()` |
| `BadgeEntry` | The label, which is also the key `colors()` is looked up by |
| `IconEntry` | The key `icons()` and `colors()` are looked up by |
| `BooleanEntry` | Anything; it is cast with `(bool)` |
| `DateTimeEntry` | A `DateTimeInterface` or a parseable string |
| `KeyValueEntry` | An array; anything else renders no pairs |
| `CodeEntry` | A scalar, or a structure to pretty-print |
| `RepeatableEntry` | The items — models or rows |
| `ImageEntry`, `ColorEntry` | A string; both validate it before using it |

So a badge whose colour depends on a relation is a format callback and nothing else:

```php
use PandaPanel\Infolists\Components\BadgeEntry;
use PandaPanel\Tables\Enums\BadgeColor;

BadgeEntry::make('role')
    ->formatUsing(static fn (mixed $value, Model $record): string => $record->is_admin
        ? 'Administrator'
        : 'Member')
    ->colors(['Administrator' => BadgeColor::Info]);
```

The closure is evaluated on the server. Only its outcome is serialized; the closure itself never crosses.

## Empty values and placeholders

```php
public function placeholder(string $placeholder): static
```

Most types answer `null` for a value that is null or an empty string, and the renderer then draws the placeholder — or an em dash when none was given. A blank space reads as a rendering bug, which is what the placeholder exists to prevent:

```php
TextEntry::make('email_verified_at')->placeholder('Never');
DateTimeEntry::make('last_used_at')->since()->placeholder('Never used');
```

`BooleanEntry` is the exception: it casts and always answers a real boolean, so its placeholder is never reached. Use `labels()` instead.

## Helper text

```php
public function helperText(string $helperText): static
```

A line of explanation below the value, in the muted style:

```php
TextEntry::make('api_key')->helperText('Rotate this if it has ever been shared.');
```

## Width

```php
public function columnSpan(int $columnSpan): static     // floored at 1
public function columnSpanFull(): static                // the whole row
```

The span counts the columns of the container the entry sits in — the schema's root grid, a section's, a grid's, or a tab's:

```php
use PandaPanel\Infolists\Layouts\Section;

Section::make('Notes')->columns(3)->schema([
    TextEntry::make('title'),
    TextEntry::make('body')->prose()->columnSpanFull(),
]);
```

`'full'` is serialized as the string `'full'`, not as a number, because the number would be different at each breakpoint. It maps to `col-span-full`, which is the whole row whatever the grid turns out to be divided into.

A span wider than its container is clamped at render time rather than overflowing it: `columnSpan(3)` inside `columns(4)` is two columns at `md`, where the grid is two wide, and three at `lg`.

## Visibility

```php
public function visible(Closure $callback): static      // Closure(Model): bool
public function isVisible(Model $record): bool
```

Evaluated on the server per record. A hidden entry's `toArray()` returns null and the schema drops it, so the value is not merely hidden by CSS — it was never serialized:

```php
use Illuminate\Database\Eloquent\Model;

TextEntry::make('internal_note')
    ->visible(static fn (Model $record): bool => auth()->user()?->is_admin === true);
```

A section, grid, or tab left empty by hidden entries renders nothing rather than a bare heading. See [Layouts](layouts.md).

There is no `hidden()` counterpart and no `visibleOn()`: an infolist has no notion of a page it is hidden on, because it *is* the page.

## Actions beside a value

```php
public function action(Action $action): static
public function getAction(): ?Action
```

```php
use PandaPanel\Actions\Action;

TextEntry::make('email')->action(
    Action::make('verify')
        ->icon('check')
        ->requiresConfirmation()
        ->action(static fn (Model $record) => $record->markEmailAsVerified()),
);
```

The same `Action` a table row or a header uses, serialized against this record — so an action the user may not run is absent rather than a button that answers 403. See [Actions in infolists](actions.md).

## The entry types

| Class | `type()` | Serialized value |
| --- | --- | --- |
| `TextEntry` | `text` | `string\|null` |
| `BadgeEntry` | `badge` | `{label, color}\|null` |
| `BooleanEntry` | `boolean` | `bool` |
| `DateTimeEntry` | `datetime` | `string\|null` |
| `KeyValueEntry` | `key-value` | `list<{key, value}>` |
| `IconEntry` | `icon` | `{icon, color, label}\|null` |
| `ImageEntry` | `image` | `string\|null` |
| `ColorEntry` | `color` | `string\|null` |
| `CodeEntry` | `code` | `string\|null` |
| `RepeatableEntry` | `repeatable` | `list<{label, schema}>` |
| `CustomEntry` | `custom` | whatever `state()` or the attribute returns |

The values are the cases of `PandaPanel\Infolists\Enums\EntryType`. Every option each type adds is in the [Entry reference](entry-reference.md).

## What crosses the wire

`toArray()` returns null for a hidden entry and otherwise:

```php
[
    'component' => 'entry',
    'name' => 'email',
    'label' => 'Email',
    'type' => 'text',
    'value' => 'grace@example.com',
    'placeholder' => null,
    'helperText' => null,
    'columnSpan' => 1,
    'action' => null,
    // plus whatever the type adds — 'prose' here
]
```

Scalars, arrays and nulls only. A model, a query, or a closure never appears in it — the same boundary a table column keeps.

## Gotchas

- **`formatUsing()` runs before the type does its work.** Returning finished markup from it does not help: `TextEntry` will cast it to a string and the renderer will escape it.
- **An entry with no matching attribute is not an error.** `data_get()` answers null, the type answers null, and the placeholder shows. A typo in a name looks exactly like an empty column.
- **`toValue()` is public and takes a record.** It is the cheapest way to assert on an entry in a test, with no page and no request: `expect(TextEntry::make('name')->toValue($record))->toBe('Grace Hopper')`.
- **The action is null on a repeatable row.** A wrapped row has no key, so an action pointing at one would name a record the endpoint could never find. `Entry::toArray()` checks `$record->exists` before serializing it — which also means an unsaved model shows no actions.
- **`columnSpan()` is floored, not clamped.** `columnSpan(0)` becomes 1; `columnSpan(9)` stays 9 in the payload and is clamped to the container's width at render time.
- **There is no `sortable()`, `searchable()`, or `toggleable()`.** Those are table columns. An infolist shows one record and has nothing to sort.

## See also

- [Entry reference](entry-reference.md)
- [InfolistSchema basics](overview.md)
- [Layouts](layouts.md)
- [Repeatable entries](repeatable-entries.md)
- [Custom entries](custom-entries.md)
- [Actions in infolists](actions.md)
- [Table columns](../tables/columns.md)
- [Form fields](../forms/overview.md)
