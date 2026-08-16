# Select Field

`PandaPanel\Forms\Components\Select` is the choice field: one value out of a list, or a set of them. The list is either static — spelled out in the schema — or resolved from an Eloquent relation on the server. Reach for it whenever the acceptable values are a closed set, or whenever a field points at another record.

## A minimal form

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Posts\Forms;

use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\FormSchema;

final class PostForm
{
    public static function configure(FormSchema $schema): FormSchema
    {
        return $schema->schema([
            Select::make('status')
                ->options([
                    'draft' => 'Draft',
                    'review' => 'In review',
                    'published' => 'Published',
                ])
                ->required(),
        ]);
    }
}
```

Rules become `required|in:"draft","review","published"`. The option list is the whitelist, so a value the schema never offered is not merely unexpected — it is invalid.

## Static options and relation options are validated differently

This is the one thing to understand before anything else on the page.

| | Static `options()` | `relationship()` |
| --- | --- | --- |
| Where the list comes from | the array you wrote | a query against the related table |
| Rule for the value | `Rule::in(...)` over the keys | `Rule::exists($table, $key)` |
| Bounded by | nothing; the whole array is sent | `optionLimit()`, default 50 |
| Searchable | the box appears, but the server returns the same list | yes, filtered server-side |

A static list is a whitelist and is small enough to send whole. A relation is not: the rendered list is one bounded page of a table that may have thousands of rows, so validity is the database's answer and the options are only what the browser was able to show. Validating a relation against the shown page would refuse a perfectly real key for having sorted too late.

## Methods

### `options(array $options): self`

`array<array-key, string>`, keyed by the value that is stored. Default `[]`.

```php
use PandaPanel\Forms\Components\Select;

Select::make('locale')->options([
    'en' => 'English',
    'id' => 'Bahasa Indonesia',
]);
```

Keys are compared as strings when the `in` rule is built, so an integer-keyed array works and validates against `in:"1","2"`.

With no options and no relation the field emits no `in` rule at all, because an empty whitelist is a rule no value can satisfy. That leaves the field validating only `required` or `nullable`, so an empty option list is not a safe default — build the list rather than leaving it blank:

```php
use App\Enums\PostStatus;

Select::make('status')->options(
    collect(PostStatus::cases())
        ->mapWithKeys(static fn (PostStatus $case): array => [$case->value => $case->name])
        ->all(),
);
```

### `relationship(string $relation, string $titleAttribute): self`

The relation is a method on the schema's model; the title attribute is the column shown as the label.

```php
use PandaPanel\Forms\Components\Select;

// The resource has already called $schema->model(Post::class), which is what
// lets the field find out what `author` points at.
Select::make('author')
    ->relationship('author', 'name')
    ->searchable()
    ->required();
```

What happens depends on the relation type:

| Relation | Field becomes | Written by |
| --- | --- | --- |
| `BelongsTo` | a single select | `FormSchema::dehydrate()`, to the foreign key |
| `BelongsToMany` / `MorphToMany` | a multiple select | `FormSchema::saveRelations()`, as a `sync()` |

For a `BelongsTo` the field is named after the relation and persists to its foreign key, so no form has to spell out `->dehydrateTo('author_id')` beside `->relationship('author')`. For a many-to-many there is no column to write to at all: `dehydrate()` skips the field, and the pivot is synced after the record is saved and inside the same transaction — a relation that does not exist yet cannot be synced before the row does.

The relation name, the related model class, and the query never reach the browser. What crosses the wire is a list of `{value, label}` pairs.

### `existsIn(string $table, string $column): self`

Validity becomes a row existing in that table rather than membership of the shown options.

```php
Select::make('country_code')
    ->searchable()
    ->existsIn('countries', 'code');
```

A `relationship()` fills this in for you from the related model's table and key, unless you set it first — `existsIn()` wins, which is the escape hatch for a relation whose real constraint is narrower than "any row".

### `multiple(bool $multiple = true): self`

Default `false`. The value becomes an array, and the renderer draws a checkbox list rather than a dropdown.

```php
Select::make('tags')
    ->options(['php' => 'PHP', 'vue' => 'Vue'])
    ->multiple();
```

Rules split in two: the field itself validates as `array`, and each element validates under `tags.*` against the `in` or `exists` rule. Laravel will not infer the second from the first, so the schema emits it explicitly.

`hydrateRelationship()` sets this to `true` on its own for a `BelongsToMany`, so calling it there is redundant.

### `searchable(bool $searchable = true): self`

Default `false`. Draws a search box above the control, debounced at 250 ms, which asks the panel's `options` endpoint for a filtered list.

```php
Select::make('author')->relationship('author', 'name')->searchable();
```

The search runs on the server: `resolveOptions()` applies `where($title, 'like', '%term%')` with `\`, `%` and `_` escaped, orders by the title attribute, and limits to `optionLimit()`. The already-selected options are kept in the list whatever the search returns, or choosing one and then typing would blank the control's own label.

A failed request leaves the list as it was rather than emptying it — an empty list reads as "nothing matches", which is a different and wrong answer.

### `optionLimit(int $limit): self`

Default `50`. How many rows a relation-backed list resolves, both on first render and on each search. Values below `1` are clamped to `1`.

```php
Select::make('author')
    ->relationship('author', 'name')
    ->searchable()
    ->optionLimit(20);
```

It has no effect on a static list, which is sent whole.

### `resolveOptions(?string $modelClass = null, ?string $search = null): array`

The value/label pairs the browser receives, as `list<array{value: string, label: string}>`. Called by the schema and by the options endpoint; useful directly in a test.

```php
use App\Models\Post;
use PandaPanel\Forms\Components\Select;

$options = Select::make('author')
    ->relationship('author', 'name')
    ->resolveOptions(Post::class, 'ada');
```

With a static list it ignores both arguments and returns the mapped options. With a relation and no `$modelClass` it throws `InvalidArgumentException` naming the field.

### `relatedKeys(Model $record): array`

The keys currently selected for a many-to-many, as `list<string>`. `FormSchema::toArray()` calls it to fill the field's value from the pivot rather than from an attribute the field could not read. Returns `[]` for anything that is not a `BelongsToMany`.

### `foreignKeyFor(string $modelClass): ?string`

The column a `BelongsTo` select writes. `null` for any other relation, and for a field with no relation. This is how `FormSchema` turns `author` into `author_id` at dehydration time.

### `writesToPivot(string $modelClass): bool`

Whether the value belongs to a related table rather than to a column on the record. `true` for `BelongsToMany` and `MorphToMany`, which extends it.

### `hydrateRelationship(string $modelClass): void`

Resolves the relation: sets `multiple` for a many-to-many, fills `existsIn` from the related model, and loads the options. `FormSchema` calls it, idempotently, before rules, serialization and dehydration — each of those needs it and none of them can assume it has already run. The schema is the only layer that knows the model class, which is why the call lives there and not in the field.

### `getRelation(): ?string` and `isMultiple(): bool`

Read-only accessors, used by the schema and by the options endpoint.

## The options endpoint

A searchable select needs somewhere to ask. The URL is built on the server by `PandaPanel\Support\FormEndpoints` and sent with the form as `optionsUrl`; the client appends only `field` and `search`, so a keystroke can never change which form is being asked about.

```
GET {panel}/options?resource={slug}&page=create&field=author&search=ada
GET {panel}/options?resource={slug}&page=edit&record=42&field=author&search=ada
```

The route is named `panel.{panelId}.options` and handled by `PandaPanel\Http\Controllers\PanelFormOptionsController`. It:

- resolves the resource by slug and aborts 404 if the panel does not have it,
- checks `canCreate()` for a create form, `canEdit($record)` for an edit form, or `canViewAny($owner)` plus the operation's own ability on a relation form,
- looks the field up in the schema and aborts 404 when it is not declared, 400 when it is not a `Select`,
- truncates the search term to 255 characters.

A field the schema does not declare does not exist, however the request spells it — the same rule that governs sorting and filtering. See [options endpoints](../options-endpoints.md).

## Gotchas

- **`searchable()` needs a form that provided an endpoint.** Resource create and edit pages and relation form dialogs do. An action's form and a widget filter do not, so the search box is not drawn there at all — the field shows the options it was given and nothing more.
- **`searchable()` on a static list does not filter.** The box appears when an endpoint exists, but `resolveOptions()` ignores the search term for a static list and returns the same array. Use a relation, or leave the list short enough not to need searching.
- **A relationship select needs `FormSchema::model()`.** Resources set it. A schema built by hand without `->model(Post::class)` renders the relation field with no options and no `exists` rule, silently — there is nothing at that layer that could say what the relation points at.
- **Values arrive as strings.** `castForForm()` keeps a single value only when it is a string or an int, and maps a multiple value to `list<string>`. Compare with `==` or cast when a `visibleWhen()` condition depends on a select — conditions compare as strings for exactly this reason.
- **A multiple select's own rules are only `array`.** The `in` / `exists` check lives under `name.*`. Adding `Rule::in(...)` to `rules()` on a multiple select would apply it to the array, not to its elements.
- **`required()` on a multiple select rejects an empty array**, because Laravel's `required` fails a countable of length zero. That is usually what you want; use `required(false)` for an optional set.
- **The title attribute must be a real column.** It is used in `orderBy()`, in the `like` search and in `pluck()`, so a purely virtual accessor fails at the database. A cast or mutator *on* an existing column does apply to the label, because Eloquent's `pluck()` runs each value back through the model. There is no callback for composing a label out of two columns — add a stored column for that.

## See also

- [Forms overview](../overview.md)
- [Radio field](radio.md)
- [Checkbox field](checkbox.md)
- [Tags field](tags.md)
- [Relationship forms](../relationships.md)
- [Options endpoints](../options-endpoints.md)
- [Live fields](../live-fields.md)
- [Validation](../validation.md)
- [Relation managers](../../relations/relation-managers.md)
