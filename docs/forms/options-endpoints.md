# Options Endpoints

A relation-backed `Select` renders one bounded page of a table that may have thousands of rows. The options endpoint is how the rest of them become reachable: the browser sends a search term and a field name, and the server answers with value/label pairs. You reach for this page when a select needs to be searchable, or when you want to know exactly what a search request may address.

## A minimal example

```php
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\FormSchema;

public static function form(FormSchema $schema): FormSchema
{
    return $schema->schema([
        Select::make('author')
            ->relationship('author', 'name')
            ->searchable()
            ->required(),
    ]);
}
```

That is the whole of it. The resource page builds the endpoint URL, sends it with the form, and the field asks it as the user types. Nothing about a table, a column, or a model ever crosses the wire.

## `Select`, method by method

`PandaPanel\Forms\Components\Select` is `final` and extends `Field`, so everything in [FormSchema basics](overview.md) applies as well.

| Method | Signature | Default |
| --- | --- | --- |
| `options()` | `options(array<array-key, string> $options): self` | `[]` |
| `relationship()` | `relationship(string $relation, string $titleAttribute): self` | none |
| `existsIn()` | `existsIn(string $table, string $column): self` | derived from the relation |
| `searchable()` | `searchable(bool $searchable = true): self` | `false` |
| `multiple()` | `multiple(bool $multiple = true): self` | `false`, forced true for many-to-many |
| `optionLimit()` | `optionLimit(int $limit): self` | `50`, floored at 1 |
| `getRelation()` | `getRelation(): ?string` | |
| `isMultiple()` | `isMultiple(): bool` | |
| `writesToPivot()` | `writesToPivot(string $modelClass): bool` | true for `BelongsToMany` and `MorphToMany` |
| `foreignKeyFor()` | `foreignKeyFor(string $modelClass): ?string` | non-null for `BelongsTo` |
| `relatedKeys()` | `relatedKeys(Model $record): list<string>` | the currently attached keys |
| `resolveOptions()` | `resolveOptions(?string $modelClass = null, ?string $search = null): list<array{value: string, label: string}>` | |
| `hydrateRelationship()` | `hydrateRelationship(string $modelClass): void` | called by the schema |

### Static options

```php
use PandaPanel\Forms\Components\Select;

Select::make('status')->options([
    'draft' => 'Draft',
    'published' => 'Published',
]);
```

The keys are the values and the whitelist: the submitted value must be one of them (`Rule::in`). Keys are compared as strings, so an integer key and the string the form sends are the same answer.

### Relation options

```php
use PandaPanel\Forms\Components\Select;

Select::make('author')->relationship('author', 'name');   // BelongsTo
Select::make('labels')->relationship('labels', 'name');   // BelongsToMany
```

`relationship(string $relation, string $titleAttribute)` names a relation method on the schema's model and the attribute to label rows with. `FormSchema` resolves it — it is the only layer that knows the model class — and does three things:

1. A `BelongsToMany` or `MorphToMany` turns the field into a multiple select.
2. `existsIn()` is filled in from the related table and key, unless you set it yourself.
3. The first page of options is resolved and serialized.

A `BelongsTo` is named after the relation and persisted to its foreign key, so no form has to write `->dehydrateTo('author_id')` beside `->relationship('author')`. A many-to-many has no column at all: it is excluded from `dehydrate()` and synced by `FormSchema::saveRelations()` after the record exists. See [Relationship forms](relationships.md).

A relation on a model that has no such method, or whose method does not return an Eloquent relation, throws `InvalidArgumentException` naming the field.

### `resolveOptions()`

```php
use App\Models\Post;
use PandaPanel\Forms\Components\Select;

$field = Select::make('author')->relationship('author', 'name')->optionLimit(20);

$field->resolveOptions(Post::class);              // first 20, ordered by name
$field->resolveOptions(Post::class, 'ada');       // those matching, still bounded
```

The query is `where({title}, 'like', '%term%')` with `\`, `%`, and `_` escaped, ordered by the title attribute, limited to `optionLimit()`, and plucked as `key => title` — the key becomes the option's `value` and the title its `label`. Calling it for a relation without a model class throws `InvalidArgumentException`; for a static option list the model class is not needed and the search term is ignored.

### `existsIn()`

```php
use PandaPanel\Forms\Components\Select;

Select::make('country_code')
    ->options(['id' => 'Indonesia'])
    ->existsIn('countries', 'code');
```

Validity becomes a row existing in that table rather than membership of the shown options. Set automatically for a relation; useful by hand when the options are a curated shortlist over a much larger table.

## The endpoint

Route name `panel.{panel_id}.options`, one per panel, handled by `PandaPanel\Http\Controllers\PanelFormOptionsController`. It is a `GET` and answers JSON:

```json
{ "options": [ { "value": "1", "label": "Ada Lovelace" } ] }
```

| Query parameter | Meaning |
| --- | --- |
| `resource` | The resource slug in this panel. 422 if missing, 404 if unknown |
| `field` | The field name. 422 if missing, 404 if the schema has no such field |
| `search` | Trimmed and cut to 255 characters. Absent means no filter |
| `page` | `create` or `edit`. Anything else is 422 |
| `relation` | Present for a relation form: the relation manager's key |
| `operation` | The relation operation. 404 if unrecognised |
| `record` | The edited resource record for `page=edit`, or the owner's key for a relation form |
| `related` | The related record, for the operations that need one |

The context — resource, page, relation, operation, owner — is built by the server in `PandaPanel\Support\FormEndpoints` and travels in the URL. The browser appends only `field` and `search`. That split is what stops a keystroke changing which form is being asked about.

```php
use PandaPanel\Support\FormEndpoints;

FormEndpoints::forResource(PostResource::class, 'edit', $post);
// /admin/options?resource=posts&page=edit&record=1

FormEndpoints::forRelation(PostResource::class, CommentsRelationManager::class, $post, 'attach');
// /admin/options?resource=posts&record=1&relation=comments&operation=attach
```

### What it refuses

| Situation | Answer |
| --- | --- |
| No `resource` or no `field` | 422 |
| Unknown resource, unknown relation, unknown operation | 404 |
| A field the schema does not declare | 404 |
| A field that is not a `Select` | 400 |
| A resource create form the user may not create (`canCreate()`) | 403 |
| A resource edit form with no `record` | 422 |
| A resource edit form the user may not edit (`canEdit($record)`) | 403 |
| A relation the user may not read (`RelationManager::canViewAny($owner)`) | 403 |
| An operation the user may not perform | 403 |
| An owner record the user may not view (`canView()`) | 403 |

The field is resolved out of the schema that declared it, so a request can only search a field that exists on a form the user can already open. It never names a column, a table, or a model.

### The attach select

A relation form's `related` field — the select that names the record being attached or associated — is not backed by a column, so its options come from the relation itself:

```php
RelationManager::attachableOptions(Model $owner, ?string $search = null, int $limit = 50): array
```

The controller calls it with a hard limit of 50 that the request cannot raise. The default implementation excludes records already in the relation. See [Attach and detach](../relations/attach-detach.md).

## What the browser does

`resources/js/panel/forms/fields/SelectField.vue` shows a search box when the field is `searchable` **and** the form provided an options URL.

- Typing debounces 250ms, then requests.
- An empty term clears the result rather than asking for an unfiltered page the field already has.
- An answer for a term that is no longer what is typed is discarded.
- A failed request leaves the list as it was. An empty list would read as "nothing matches", which is a different and wrong answer.
- Options already selected are kept in the list whatever the search returned, or choosing one and then typing would blank the control's own label.

The serialized field carries `options`, `searchable`, `multiple`, and `usesRelationship`.

## Where the endpoint is available

`optionsUrl` is provided by the resource create and edit pages and by relation forms. A form rendered anywhere else — a widget filter, for instance — has no URL to ask, and a searchable select there simply shows the options it was given.

## Notes

- **`searchable()` is for relation-backed selects.** A static option list ignores the search term and answers with the same list, so the box is a round trip that changes nothing. Leave it off and let the whole list render.
- **`optionLimit()` bounds the first page only.** The endpoint applies the same limit to a search, so a term matching hundreds of rows still answers with at most `optionLimit()` of them.
- **A relation validates with `exists`, a static list with `in`.** Validating a relation against the rendered page would refuse a perfectly real key for having sorted too late.
- **A relation with no resolvable table adds no rule at all.** A `Select` with `relationship()` but no model on the schema contributes nothing to validation rather than inventing a rule from the page it rendered.
- **The label attribute is also the sort and the search column.** `relationship('author', 'name')` orders by `name` and searches `name`; there is no separate ordering hook.
- **Options are always strings on the wire.** Both `value` and `label` are serialized as strings, which is why conditions compare as strings too.
- **A many-to-many select fills its value from the pivot**, not from an attribute — `FormSchema::toArray()` does that with `relatedKeys()` before serializing.

## See also

- [Select field](fields/select.md)
- [Relationship forms](relationships.md)
- [Validation](validation.md)
- [Live fields](live-fields.md)
- [FormSchema basics](overview.md)
- [Relation forms](../relations/relation-forms.md)
- [Attach and detach](../relations/attach-detach.md)
- [Table relationships](../tables/relationships.md)
