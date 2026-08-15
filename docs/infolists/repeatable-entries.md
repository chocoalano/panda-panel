# Repeatable Entries

`PandaPanel\Infolists\Components\RepeatableEntry` renders one sub-schema once per item. You reach for it when a record holds a list — a relation's records, or the rows of a JSON column — and each item needs more than one value shown.

The children are ordinary entries. Nothing about them changes because they sit inside a repeatable, which is what keeps it from becoming a second way to describe a value.

## A minimal repeatable

```php
use PandaPanel\Infolists\Components\DateTimeEntry;
use PandaPanel\Infolists\Components\RepeatableEntry;
use PandaPanel\Infolists\Components\TextEntry;
use PandaPanel\Infolists\Layouts\Grid;
use PandaPanel\Infolists\Layouts\Section;

return $schema->schema([
    Section::make('Lines')->schema([
        RepeatableEntry::make('lines')
            ->itemLabel('Line')
            ->placeholder('No lines on this order.')
            ->schema([
                Grid::make(3)->schema([
                    TextEntry::make('product'),
                    TextEntry::make('quantity'),
                    DateTimeEntry::make('shipped_at')->placeholder('Not shipped'),
                ]),
            ]),
    ]),
]);
```

Each item is drawn in its own bordered box, with the numbered label above it.

## The API

`RepeatableEntry` extends `Entry`, so `label()`, `placeholder()`, `helperText()`, `columnSpan()`, `columnSpanFull()`, `formatUsing()`, `visible()` and `action()` are all available. It adds three:

| Method | Signature | Default |
| --- | --- | --- |
| `schema()` | `schema(array $components): self` | `[]` — the sub-schema rendered per item |
| `columns()` | `columns(int $columns): self` | `1`, clamped to 1–4 |
| `itemLabel()` | `itemLabel(string $label): self` | `null` — no heading per item |
| `entries()` | `entries(): list<Entry>` | `[$this]` — itself, not its children |
| `toValue()` | `toValue(Model $record): list<array{label, schema}>` | `[]` when the value is not a list |
| `type()` | `type(): EntryType` | `EntryType::Repeatable` |

### `schema()`

Takes anything an infolist can contain — entries, sections, grids, and nested repeatables:

```php
RepeatableEntry::make('passkeys')
    ->schema([
        TextEntry::make('name'),
        DateTimeEntry::make('last_used_at')->since()->placeholder('Never'),
    ]);
```

Each child's `toArray()` is called with the *item* as its record, so `TextEntry::make('name')` reads the item's name rather than the parent record's.

### `columns()`

The grid each item's children are laid into:

```php
RepeatableEntry::make('lines')->columns(3)->schema([
    TextEntry::make('product'),
    TextEntry::make('quantity'),
    TextEntry::make('total'),
]);
```

It is passed down as the container width, so a child's `columnSpan()` counts these columns. A `Grid` inside the schema overrides it for its own children, which is the usual way to give one row of an item a different shape from another.

### `itemLabel()`

A heading each item wears, numbered by position:

```php
RepeatableEntry::make('lines')->itemLabel('Line');
// 'Line 1', 'Line 2', 'Line 3'
```

The number is `sprintf('%s %d', $label, $index + 1)` — the position in the rendered list, not a database key. Without `itemLabel()` the item's `label` is null and no heading is drawn.

## What counts as an item

`toValue()` resolves the attribute (through `formatUsing()`, if one is set), unwraps a collection, and then keeps only what a child could read:

| Value | Result |
| --- | --- |
| `Illuminate\Database\Eloquent\Collection` | Unwrapped with `all()`, then as below |
| `Illuminate\Support\Collection` | Unwrapped with `all()`, then as below |
| `array` of `Model` | Used directly |
| `array` of `array` | Each row wrapped in an `InfolistRow` |
| Anything else (a string, an int, null) | Dropped — `toValue()` answers `[]` |

So both of these work, with the same children:

```php
// A relation.
RepeatableEntry::make('passkeys')->schema([TextEntry::make('name')]);

// A JSON column holding [['title' => 'First'], ['title' => 'Second']].
RepeatableEntry::make('lines')->schema([TextEntry::make('title')]);
```

A scalar mixed into the list is dropped rather than rendered, because handing a child a string it would read nothing out of produces an item full of em dashes and no explanation.

## `InfolistRow`

A row that is not a record is wrapped in `PandaPanel\Infolists\Support\InfolistRow`, a `Model` with no table, no timestamps, and nothing guarded:

```php
use PandaPanel\Infolists\Support\InfolistRow;

$row = InfolistRow::wrap(['title' => 'First', 'quantity' => 2]);
$row->getAttribute('title');    // 'First'
$row->exists;                   // false
```

It exists so the children are always handed a model. Widening every entry, closure, and signature in the infolist to accept `Model|array` was the other option and it was worse: it would have broken every `formatUsing(fn (mixed $value, Model $record) => …)` a panel has already written, to describe a case that only exists inside one component.

Keys are cast to strings and the row is filled with `forceFill()` — these attributes are the panel's own data, not request input, and there is no table to guard. The row is never saved, never queried, and lives only for the length of one `toArray()`.

## A repeatable is one entry

```php
$entry = RepeatableEntry::make('lines')->schema([TextEntry::make('title')]);

$entry->entries();      // [$entry]
```

Its children belong to an item, not to the record, so counting them among the record's entries would claim a value exists at the top level that does not. `InfolistSchema::entries()` and `panelInfolistLabels()` both see the repeatable and stop there.

## No actions inside an item

An entry inside a repeatable carries no action. `Entry::toArray()` checks `$record->exists` before serializing one, and a wrapped row has no key:

```php
$entry = RepeatableEntry::make('lines')->schema([
    TextEntry::make('title')->action(Action::make('rename')),
]);

$value = $entry->toValue($record);
$value[0]['schema'][0]['action'];    // null
```

An action pointing at a row would name a record the endpoint could never find. The same applies to a `Section::headerActions()` inside a repeatable.

A relation-backed repeatable holds real, saved models, so `$record->exists` is true and an action on a child *is* serialized — but the infolist endpoint resolves the record through `Resource::findRecord()`, which looks it up in the *resource's* model. An action on a related record is a relation manager's job. See [Relation managers](../relations/relation-managers.md).

## What crosses the wire

```php
[
    'component' => 'entry',
    'name' => 'lines',
    'label' => 'Lines',
    'type' => 'repeatable',
    'columns' => 3,
    'value' => [
        ['label' => 'Line 1', 'schema' => [/* the children, serialized against item 1 */]],
        ['label' => 'Line 2', 'schema' => [/* item 2 */]],
    ],
    'placeholder' => 'No lines on this order.',
    'helperText' => null,
    'columnSpan' => 1,
    'action' => null,
]
```

The children go back through `InfolistNode.vue`, the same renderer the top-level tree uses — which is why a `Grid` inside a repeatable lays out exactly as it would anywhere else. An empty `value` renders the placeholder.

## Testing

`toValue()` needs nothing but a record:

```php
use PandaPanel\Infolists\Components\RepeatableEntry;
use PandaPanel\Infolists\Components\TextEntry;

it('renders one item per line', function (): void {
    $entry = RepeatableEntry::make('lines')
        ->itemLabel('Line')
        ->schema([TextEntry::make('title')]);

    $value = $entry->toValue(new Order(['lines' => [
        ['title' => 'First'],
        ['title' => 'Second'],
    ]]));

    expect($value)->toHaveCount(2)
        ->and($value[0]['label'])->toBe('Line 1')
        ->and($value[0]['schema'][0]['value'])->toBe('First');
});
```

## Notes

- **Eager load the relation.** A repeatable over `passkeys` reads it once, but so does every other entry that touches it. `protected static array $with = ['passkeys'];` on the resource is what keeps the view page to one query for it — and `Model::shouldBeStrict()` outside production turns a forgotten one into a failure rather than a cost.
- **There is no pagination, no limit, and no ordering.** A repeatable renders every item the value holds, in the order it holds them. Order it in the relation, or in `formatUsing()`, if the order matters.
- **`formatUsing()` supplies the items.** It receives the raw attribute and must return models, rows, or a collection of either — returning a formatted string produces an empty repeatable.
- **A nested repeatable works and is rarely right.** Items inside items read as a table nobody can scan. Two sections, or a relation manager, usually say it better.
- **The placeholder covers "no items" and "not a list" alike.** Both answer `[]`, so a typo in the attribute name looks the same as an empty relation.

## See also

- [Entries](entries.md)
- [Entry reference](entry-reference.md)
- [Layouts](layouts.md)
- [InfolistSchema basics](overview.md)
- [Actions in infolists](actions.md)
- [Relation managers](../relations/relation-managers.md)
- [Resource queries](../resources/queries.md)
