# Relationship Forms

Two things in a form can belong to another table: a **related record** edited alongside the one being saved, and a **choice of related records** stored as a foreign key or a set of pivot rows. The first is `PandaPanel\Forms\Layouts\Relationship`; the second is `PandaPanel\Forms\Components\Select` with `relationship()`. You reach for either when a resource's form is not confined to one row.

## A minimal example

```php
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Forms\Layouts\Relationship;

public static function form(FormSchema $schema): FormSchema
{
    return $schema->schema([
        TextInput::make('name')->required(),

        // A foreign key on this record.
        Select::make('author')->relationship('author', 'name'),

        // A set of pivot rows.
        Select::make('labels')->relationship('labels', 'name'),

        // Another record's own fields.
        Relationship::make('brief')
            ->heading('Project brief')
            ->schema([TextInput::make('summary')->required()]),
    ]);
}
```

The related record's fields are named `brief.summary` on the wire and in the rules. Laravel validates nested keys natively, so the errors come back under the same dotted key the field renders with, and a `summary` column on the owner could coexist with one on the related record.

## `Relationship`

A group of fields belonging to a single related record — a `BelongsTo`, a `HasOne`, or a `MorphOne` — edited inside the owner's form.

| Method | Signature | Default |
| --- | --- | --- |
| `make()` | `static make(string $relation): self` | |
| `schema()` | `schema(array<array-key, FormComponent> $components): self` | `[]` |
| `heading()` | `heading(string $heading): self` | `Str::headline($relation)` |
| `description()` | `description(string $description): self` | `null` |
| `columns()` | `columns(int $columns): self` | `1`, clamped to 1–4 |
| `createsMissing()` | `createsMissing(bool $createsMissing = true): self` | `true` |
| `getRelation()` | `getRelation(): string` | |
| `shouldCreateMissing()` | `shouldCreateMissing(): bool` | |
| `children()` | `children(): list<FormComponent>` | |
| `fields()` | `fields(): list<Field>` | every field, recursively |
| `save()` | `save(Model $owner, array $validated): void` | called by the schema |
| `toArray()` | `toArray(?Model $record, string $page): array` | |

```php
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Components\Textarea;
use PandaPanel\Forms\Layouts\Relationship;

Relationship::make('profile')
    ->heading('Profile')
    ->description('Shown on the public author page.')
    ->columns(2)
    ->schema([
        TextInput::make('headline'),
        Textarea::make('bio')->columnSpanFull(),
    ]);
```

### Names, values, and rules

`schema()` prefixes every field it holds with the relation name, once, when the components are set. Two readers keep the two questions apart:

```php
$field->getName();        // 'profile.bio'  — the wire, the rules, the errors
$field->getAttribute();   // 'bio'          — the attribute on the related record
```

Values are read from the related record, not from the owner: the group serializes its children against `$owner->getRelationValue($relation)`, or against nothing when there is no related record yet.

```php
use App\Models\Project;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Forms\Layouts\Relationship;

$schema = FormSchema::make()
    ->model(Project::class)
    ->schema([
        Relationship::make('brief')->schema([TextInput::make('summary')]),
    ]);

array_keys($schema->validationRules());   // ['brief.summary']
$schema->dehydrate(['brief' => ['summary' => 'x']]);   // [] — not the owner's
```

The browser submits `{"brief": {"summary": "x"}}`: the renderer expands dotted names into the nested shape Laravel validates, while keeping the flat key internally so an error keyed `brief.summary` lands on the field that produced it.

### The write

`FormSchema::saveRelations(Model $record, array $validated)` calls `Relationship::save()` for each group, after the owner has been saved and inside the same transaction. That order is forced — a `HasOne` or `MorphOne` cannot carry a foreign key to a row that does not exist yet — and keeping `BelongsTo` on the same path means there is one place where a related record is written rather than two that can drift.

What `save()` does:

1. Resolves `$owner->{$relation}()`. Anything that is not an Eloquent relation is left alone.
2. Collects the attributes its fields dehydrate, keyed by `getDehydrateKey()` and passed through `mutate()`. A path missing from the validated data is skipped — missing and null are different answers.
3. If nothing dehydrated, returns. A group whose values are all absent leaves the relation alone rather than creating an empty row.
4. If a related record exists, `forceFill($attributes)->save()`.
5. If not and `createsMissing()` is on, creates one. For a `BelongsTo` the new record is saved first and the owner's foreign key is written afterwards, because the owner cannot point at a row that does not exist.

```php
use PandaPanel\Forms\Layouts\Relationship;

// Leaves the relation alone when there is no related record yet.
Relationship::make('brief')->createsMissing(false)->schema([...]);
```

`createsMissing()` defaults to on: a form that renders empty inputs for a profile the user does not have yet and then silently discards what they typed is worse than one that creates it.

## Relation-backed selects

```php
use PandaPanel\Forms\Components\Select;

Select::make('author')->relationship('author', 'name');   // BelongsTo
Select::make('labels')->relationship('labels', 'name');   // BelongsToMany
```

| Relation | Rendered as | Validated with | Written by |
| --- | --- | --- | --- |
| `BelongsTo` | single select | `exists` on the related table | `dehydrate()`, under the foreign key |
| `BelongsToMany`, `MorphToMany` | multiple select | `array` + `exists` per element | `saveRelations()`, with `sync()` |

The schema resolves all of that from the model class, so a form never spells out `->dehydrateTo('author_id')` beside `->relationship('author')`, and never has to remember that a pivot cannot be written before the record exists.

```php
$schema->dehydrate(['author' => '3']);        // ['author_id' => '3']
$schema->dehydrate(['labels' => ['1', '2']]); // [] — synced afterwards
```

A many-to-many select is also *filled* from the pivot: `FormSchema::toArray()` reads `Select::relatedKeys($record)` and puts the currently attached keys in the field's value.

`sync()` replaces the whole set, so an empty submission detaches everything. A submitted value that is not an array is treated as an empty set.

Options, searching, and limits are covered in [Options endpoints](options-endpoints.md).

## Reading the groups back

```php
$schema->relationshipGroups();   // list<Relationship>, found at any depth
```

`FormSchema` walks the whole component tree for these, so a group nested inside a section or a tab is still found and still written. It deliberately does not descend into a group it has already found: a relation group inside a relation group would mean two records written from one nesting level, with no way to say which owns which.

## Pivot fields on a relation form

Relation managers have a second kind of relationship form: the related record's own fields and the pivot's, side by side. `PandaPanel\Resources\RelationForm` merges the two schemas for rendering and validation and keeps them apart for persistence, namespacing the pivot half under `pivot.` so a `role` column on the join table cannot overwrite a `role` column on the record. See [Pivot fields](../relations/pivot-fields.md).

## What is not supported here

- **A to-many relation in a `Relationship` group.** The layout writes one related record. A list of related records is a [relation manager](../relations/relation-managers.md), a [nested resource](../resources/nested-resources.md), or — when the rows are plain data rather than records — a [`Repeater`](fields/repeater.md).
- **Nested relation groups.** A group's children are fields, not another group.
- **Deleting the related record.** A group creates and updates; removing a related record is an [action](../actions/overview.md).
- **Creating the related record for a `MorphTo`.** The supported single-record relations are `BelongsTo`, `HasOne`, and `MorphOne`.

## Notes

- **The related record is written with `forceFill()`.** The attribute list came from the schema rather than from the request, which is what makes bypassing `$fillable` safe — and is the same reason the owner is written that way.
- **A relation group's fields never reach the owner's attributes.** `dehydrate()` excludes them by name, so `brief.summary` cannot be written to a `summary` column on projects.
- **Everything is one transaction.** The owner, the related records, and the pivot rows are written together when the page has `$hasDatabaseTransactions` on, so a form never half-saves.
- **`getRelationValue()` is what "existing" means.** A relation the owner has not loaded is loaded by that call; one that resolves to something other than a model counts as absent.
- **Two fields with the same full name still throws.** The prefix is applied before the uniqueness check, so `profile.bio` and `bio` are two names — but two `bio` fields inside one group are not.
- **A group renders even when empty.** Layouts always render; only fields disappear per page.

## See also

- [Options endpoints](options-endpoints.md)
- [Select field](fields/select.md)
- [Form layouts](layouts.md)
- [Hydration and dehydration](hydration.md)
- [Validation](validation.md)
- [Relation managers](../relations/relation-managers.md)
- [Relation forms](../relations/relation-forms.md)
- [Pivot fields](../relations/pivot-fields.md)
- [Nested resources](../resources/nested-resources.md)
