# Builder

`PandaPanel\Forms\Components\Builder` holds a list of entries whose shapes differ. Each entry names one of the blocks the field declares, and that name is what decides which sub-schema edits it, which fields dehydrate it, and whether it survives at all. Reach for it when a record holds page content — a stack of paragraphs, quotes, and images — rather than a list of one repeated shape, which is what a [Repeater](repeater.md) is for.

## The minimal example

```php
use PandaPanel\Forms\Components\Builder;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Components\Textarea;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Forms\Support\Block;

FormSchema::make()->schema([
    Builder::make('content')->blocks([
        Block::make('paragraph')->schema([
            Textarea::make('body')->rows(4),
        ]),

        Block::make('quote')->schema([
            Textarea::make('body')->rows(2),
            TextInput::make('attribution'),
        ]),
    ]),
]);
```

The stored value is a list of `{type, data}` maps, so the model needs an array cast:

```php
protected function casts(): array
{
    return ['content' => 'array'];
}
```

## The value shape

```php
[
    ['type' => 'paragraph', 'data' => ['body' => 'Hello']],
    ['type' => 'quote', 'data' => ['body' => 'Well.', 'attribution' => 'Ada']],
]
```

`type` is the whole safety of the field. On the way in, `castForForm()` drops any entry that is not an array, has no string `type`, or names a block the builder never declared. On the way out, `mutate()` does the same and then dehydrates `data` through that block's own fields — so a key no block declared is discarded exactly as it is on a plain form:

```php
use PandaPanel\Forms\Components\Builder;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Support\Block;

$field = Builder::make('content')->blocks([
    Block::make('paragraph')->schema([TextInput::make('body')]),
]);

$field->mutate([
    ['type' => 'paragraph', 'data' => ['body' => 'Hello', 'injected' => 'nope']],
    ['type' => 'unknown', 'data' => ['body' => 'Hello']],
], null);

// [['type' => 'paragraph', 'data' => ['body' => 'Hello']]]
```

## Declaring blocks

```php
public function blocks(array $blocks): self
```

`Block` is `PandaPanel\Forms\Support\Block`. Its name is what a stored entry carries, so treat it as part of the data format: renaming a block orphans every entry that already used the old name.

```php
final class Block
{
    public static function make(string $name): self;

    /** @param array<array-key, FormComponent> $components */
    public function schema(array $components): self;

    public function label(string $label): self;
    public function icon(string $icon): self;

    public function getName(): string;
    public function getLabel(): string;

    /** @return list<Field> */
    public function fields(): array;

    /** @return array<string, mixed> */
    public function emptyData(): array;

    /** @param array<string, mixed> $data */
    public function dehydrate(array $data, ?Model $record): array;

    /** @return array<string, mixed> */
    public function toArray(): array;
}
```

```php
use PandaPanel\Forms\Components\FileUpload;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Layouts\Grid;
use PandaPanel\Forms\Support\Block;

Block::make('image')
    ->label('Image with caption')
    ->icon('photo')
    ->schema([
        Grid::make(2)->schema([
            FileUpload::make('path')->image()->directory('content'),
            TextInput::make('caption'),
        ]),
    ]);
```

`label()` defaults to `Str::headline($name)`, so `image_with_caption` becomes "Image With Caption" if you say nothing. `icon()` takes an icon **registry key**, never a path — the same registry table columns and navigation items use; an unregistered name draws nothing.

`schema()` takes any form components, including layouts. `Block::fields()` flattens them, which is what `emptyData()` and `dehydrate()` walk.

### `emptyData()`

The blank entry the frontend inserts when you pick a block from the picker. It is built here, from each field's own `formValue(null)`, rather than invented in Vue — so a field's `default()` applies inside a builder exactly as it does outside one.

```php
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Components\Toggle;
use PandaPanel\Forms\Support\Block;

Block::make('callout')
    ->schema([
        TextInput::make('body'),
        Toggle::make('dismissible')->default(true),
    ])
    ->emptyData();

// ['body' => null, 'dismissible' => true]
```

## Bounds and controls

```php
public function minItems(int $min): self       // clamped to >= 0
public function maxItems(int $max): self       // clamped to >= 1
public function reorderable(bool $reorderable = true): self
public function collapsible(bool $collapsible = true): self
public function addLabel(string $label): self
```

| Option | Default | Effect |
| --- | --- | --- |
| `minItems` | `null` | adds `min:n` to the field's rules; the frontend stops offering Remove below it |
| `maxItems` | `null` | adds `max:n`; the frontend stops offering Add above it |
| `reorderable` | `true` | draws the up/down buttons on each entry |
| `collapsible` | `true` | draws the collapse toggle on each entry |
| `addLabel` | `'Add block'` | the label on the button that opens the block picker |

```php
use PandaPanel\Forms\Components\Builder;

Builder::make('content')
    ->blocks([/* ... */])
    ->minItems(1)
    ->maxItems(20)
    ->reorderable(false)
    ->collapsible()
    ->addLabel('Add section');
```

Note the defaults: a builder is reorderable **and** collapsible unless you say otherwise. A repeater is reorderable but not collapsible.

## Looking a block up

```php
public function block(string $name): ?Block
```

Returns the declared block by name, or `null`. This is the lookup `mutate()` and `validateEntries()` use, and it is public so a page or a test can ask the same question:

```php
use PandaPanel\Forms\Components\Builder;
use PandaPanel\Forms\Support\Block;

$builder = Builder::make('content')->blocks([Block::make('quote')]);

$builder->block('quote')?->getLabel();   // 'Quote'
$builder->block('missing');              // null
```

## Validation

The field's own rules come from `typeRules()`:

```php
use PandaPanel\Forms\Components\Builder;
use PandaPanel\Forms\FormSchema;

FormSchema::make()
    ->schema([Builder::make('content')->minItems(1)->maxItems(10)])
    ->validationRules();

// ['content' => ['nullable', 'array', 'min:1', 'max:10']]
```

That is the whole of what the schema generates. **A builder contributes no nested rules**: which rules apply to entry three depends on what entry three says it is, and that cannot be expressed as a flat Laravel rule set. `Builder` deliberately does not implement `nestedRules()`, so `content.*.data.body` is never in `FormSchema::validationRules()`.

What the field gives you instead is a method that validates the entries itself:

```php
/** @return array<string, list<string>> errors keyed by path */
public function validateEntries(mixed $value, ?Model $record = null): array
```

It walks the submitted list, finds each entry's block, validates that entry's `data` against that block's fields, and returns messages under the same dotted keys the frontend rendered with — `content.0.data.body`. An entry that is not an array, has no string `type`, or names an undeclared block produces one error at `content.0.type`.

```php
use PandaPanel\Forms\Components\Builder;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Support\Block;

$builder = Builder::make('content')->blocks([
    Block::make('quote')->schema([TextInput::make('body')->required()]),
]);

$builder->validateEntries([
    ['type' => 'quote', 'data' => ['body' => '']],
    ['type' => 'nope', 'data' => []],
]);

// [
//     'content.0.data.body' => ['The body field is required.'],
//     'content.1.type' => ['This block is not one this field offers.'],
// ]
```

Nothing calls it for you. Wire it into a page's `afterValidate()` hook when block contents must be validated:

```php
use Illuminate\Validation\ValidationException;
use PandaPanel\Forms\Components\Builder;
use PandaPanel\Resources\Pages\CreateRecord;

final class CreatePost extends CreateRecord
{
    protected static string $resource = PostResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function afterValidate(array $data): array
    {
        $field = $this->schema()->field('content');

        if ($field instanceof Builder) {
            $errors = $field->validateEntries($data['content'] ?? []);

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
        }

        return $data;
    }
}
```

The keys match what `BuilderField.vue` renders errors against, so the messages land on the field that produced them rather than on the builder as a whole.

## What crosses the wire

`extraArray()` adds these to the standard field definition:

| Key | Type | Notes |
| --- | --- | --- |
| `blocks` | `BlockDefinition[]` | each with `name`, `label`, `icon`, `schema`, `emptyData` |
| `minItems` | `number \| null` | |
| `maxItems` | `number \| null` | |
| `reorderable` | `boolean` | |
| `collapsible` | `boolean` | |
| `addLabel` | `string` | `'Add block'` when unset |

Each block's `schema` is serialized with `toArray(null, 'create')` — against no record and on the create page — because an entry is a plain map rather than a model, so a field inside a block reads its `default()` rather than a model attribute.

## Gotchas

**Blocks are serialized in full, per block, on every render.** A builder with twelve blocks sends twelve sub-schemas whether or not any entry uses them, because the picker has to be able to offer all of them. Keep block schemas small.

**A block name is data.** It is stored in every entry. Changing `Block::make('quote')` to `Block::make('quotation')` makes every existing `quote` entry unknown, and unknown entries are dropped silently on the next save. Migrate the stored rows first.

**`hiddenOn()` and `visibleOn()` inside a block do nothing useful.** Block schemas are always serialized for the `create` page, whatever page the builder is on. Page-aware visibility belongs on the builder itself.

**`live()` inside a block is not wired.** The form-state endpoint rebuilds the top-level schema from the form's flat values; a field nested in a block entry is not part of that map.

**Column spans inside a block resolve against the block's own container.** A block has no `columns()` of its own — wrap its fields in a `Grid` when you want more than one column.

## See also

- [Repeater](repeater.md) — one repeated shape rather than several
- [File Upload](file-upload.md) — the field a media block usually holds
- [Forms and Schemas](../overview.md)
- [Validation](../validation.md)
- [Layouts](../layouts.md) — `Grid` and `Section` inside a block
- [Component Registries](../../concepts/component-registries.md) — where an icon name resolves
- [Resource Lifecycle Hooks](../../resources/lifecycle-hooks.md) — where `afterValidate()` runs
