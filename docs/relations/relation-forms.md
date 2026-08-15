# Relation Forms

A relation form is the dialog behind one relation operation, and the write that follows it. It is two schemas standing side by side — the related record's own fields and the pivot's — merged for rendering and validation but never for persistence, because they are two rows in two tables. You declare the halves on the manager; `PandaPanel\Resources\RelationForm` assembles them.

## A minimal relation form

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Components\Textarea;
use PandaPanel\Forms\FormSchema;

public static function form(FormSchema $schema, Model $owner): FormSchema
{
    return $schema->schema([
        TextInput::make('title')->required()->maxLength(255),
        Textarea::make('body')->rows(6),
        Select::make('status')->options([
            'draft' => 'Draft',
            'published' => 'Published',
        ]),
    ]);
}
```

That is the whole declaration. The create dialog, the edit dialog, the validation rules, and the write all come from it. Note what is *not* there: no foreign key field. A created record is saved through the relation, so the owner's key is the relation's business.

## The four operations

`PandaPanel\Support\RelationOperation` is a closed set. The operation decides which schema is built, which ability is asked, and which write runs, so a value the server does not recognise is a 404 rather than a fallback.

| Case | Value | Schema | Write |
| --- | --- | --- | --- |
| `RelationOperation::Create` | `create` | `form()` + `pivotForm()` | New record saved through the relation |
| `RelationOperation::Edit` | `edit` | `form()` + `pivotForm()` | `forceFill()->save()`, plus `updateExistingPivot()` |
| `RelationOperation::Attach` | `attach` | a `related` select + `pivotForm()` | `BelongsToMany::attach()` |
| `RelationOperation::Associate` | `associate` | a `related` select | `HasOneOrMany::save()` on the named child |

```php
use PandaPanel\Support\RelationOperation;

RelationOperation::tryFromRequest('attach');      // RelationOperation::Attach
RelationOperation::tryFromRequest('obliterate');  // null → 404

RelationOperation::Edit->needsRelatedRecord();    // true; the others are false
RelationOperation::Attach->isAuthorized(LabelsRelationManager::class, $project);
```

`isAuthorized()` lives on the enum because three endpoints ask it — the relation form, that form's select options, and its file uploads — and three copies of the mapping would be three places for one of them to drift into being the permissive one.

## The two endpoints

```text
GET  /{panel}/relations/form?resource=&record=&relation=&operation=[&related=]
POST /{panel}/relations/form?resource=&record=&relation=&operation=[&related=]
```

Route names: `panel.{panel}.relations.form` and `panel.{panel}.relations.save`.

The context travels in the query string rather than in the body. The body is the form's values, and a field named `resource` must not be able to point the request somewhere else.

The GET answers JSON, because re-rendering the page the user is looking at to say "what does this form look like" would throw away the table state they arrived with:

| Key | Type | Meaning |
| --- | --- | --- |
| `title` | `string` | `New Posts`, `Edit Posts`, `Attach Labels`, `Associate Tasks` |
| `submitLabel` | `string` | `Create`, `Save`, `Attach`, `Associate` |
| `form` | `array` | `FormSchema::toArray()`, filled from the related record |
| `submitUrl` | `string` | Where the dialog posts back |
| `method` | `'post'` | Always a POST |
| `optionsUrl` | `string` | Where a searchable select fetches more options |
| `uploadUrl` | `string` | Where a file field on this form stores its file |

The POST redirects back rather than answering JSON: the relation table lives on a page, and the page has to re-render for the new row to appear. Validation failures come back the same way, so the dialog shows them beside the fields without a second error format.

```bash
curl -H 'Accept: application/json' \
  '/admin/relations/form?resource=users&record=3&relation=posts&operation=create'
```

## Building one by hand

```php
use PandaPanel\Resources\RelationForm;
use PandaPanel\Support\RelationOperation;

$form = RelationForm::for(
    PostsRelationManager::class,
    $user,
    RelationOperation::Edit,
    $post,
);
```

| Method | Signature | Returns |
| --- | --- | --- |
| `for()` | `static for(string $manager, Model $owner, RelationOperation $operation, ?Model $related = null): self` | The assembled form |
| `schema()` | `schema(): FormSchema` | Both halves, merged |
| `validationRules()` | `validationRules(?Model $related = null): array<string, list<mixed>>` | Rules keyed by field name |
| `toArray()` | `toArray(?Model $related = null): array` | The serialized form, filled |
| `title()` | `title(): string` | The dialog heading |
| `submitLabel()` | `submitLabel(): string` | The submit button's label |
| `relatedKey()` | `relatedKey(array $validated): int\|string\|null` | The key an attach or associate names |
| `save()` | `save(array $validated, ?Model $related = null): void` | Runs the operation |

```php
$rules = $form->validationRules($post);        // ['title' => [...], 'pivot.role' => [...]]
$validated = validator($request->all(), $rules)->validate();

$form->save($validated, $post);
```

`save()` assumes the caller has already authorized the operation and opened the transaction — which is what `PanelRelationController` does, wrapping the write in `PandaPanel\Support\DatabaseTransaction`.

Two constants name the parts of the form:

```php
RelationForm::RELATED_FIELD;   // 'related' — the select naming the record to join
RelationForm::PIVOT_PREFIX;    // 'pivot'   — the namespace pivot fields live under
```

## What each operation writes

**Create** builds a new related instance, `forceFill()`s the dehydrated attributes, and saves it *through the relation*:

```php
$relation->save($related);                                    // HasOneOrMany
$related->save() and $relation->attach($key, $pivot);         // BelongsToMany
```

Saving through the relation is what sets the foreign key — and the morph type when there is one — so no form has to declare it. A `project_id` in the request body is discarded, because the field never existed on the schema:

```text
POST .../relations/form?...&operation=create
{ "name": "Written", "project_id": 99 }
→ the record is created under the owner, not under 99
```

**Edit** writes the record and, when the relation is a many-to-many and the manager declared pivot fields, updates the pivot row too:

```php
$related->forceFill($attributes)->save();
$relation->updateExistingPivot($related->getKey(), $pivot);
```

**Attach** and **Associate** write nothing to the related record at all. The select naming it is addressing, not data: it says which row to join, never what to write into it. See [Attach and detach](attach-detach.md) and [Associate and dissociate](associate-dissociate.md).

## The `related` select

For an attach or an associate, `RelationForm` builds the select itself:

```php
Select::make('related')
    ->label($manager::title())
    ->required()
    ->searchable()
    ->options($manager::attachableOptions($owner))
    ->existsIn($related->getTable(), $related->getKeyName());
```

Its options are the records not already in the relation, and its validity is `exists` on the related table rather than membership of that list — the list is one bounded page, and a real key that sorted past the limit is still a real key. The controller checks separately that the record is not already in the relation, and answers 422 when it is:

```text
POST .../operation=attach   { "related": "5" }   → 422 "That record is already in this relation."
POST .../operation=attach   { "related": "999" } → validation error on `related`
```

## Searchable selects on a relation form

`optionsUrl` is built for the relation and sent with the form, so no panel URL is constructed in Vue:

```text
GET /{panel}/options?resource=users&record=3&relation=posts&operation=create&field=author&search=lee
```

The client appends only `field` and `search`. Everything else is the server's statement of what this form is, and a keystroke must not be able to change it. The endpoint:

1. Resolves the manager through `Resource::relationManager()` — 404 for one the resource never declared.
2. Resolves the operation — 404 for one the enum does not have.
3. Asks `RelationManager::canViewAny($owner)` and `RelationOperation::isAuthorized()` — 403 for either.
4. For `field=related`, answers `RelationManager::attachableOptions($owner, $search, 50)`.
5. Otherwise resolves the field out of this form's own schema — 404 for a field the schema does not declare, 400 for a field that is not a select.

The 50-result cap is a bound the request cannot raise. See [Options endpoints](../forms/options-endpoints.md).

## File uploads on a relation form

`uploadUrl` carries the same context as the options URL, so a file field on a relation form is authorized by the relation's own abilities rather than by the owning resource's. Nothing else about the field changes — see [File uploads](../forms/file-uploads.md).

## Validation

Rules come from the merged schema, so both halves are validated in one pass and errors arrive under the key the field renders with:

```php
$form->validationRules($post);
// ['title' => ['required', 'string', 'max:255'], 'pivot.role' => ['nullable', 'string', 'max:50']]
```

Only declared fields are validated and persisted. An extra key in the request body is discarded exactly as it is on a resource form:

```text
{ "title": "Hello", "is_admin": true }   →  is_admin never reaches the model
```

## Gotchas

- **A manager with no `form()` gets an empty create dialog.** `form()` returns the schema unchanged by default. That is correct for a manager that only attaches and detaches, and wrong for one that meant to declare fields — the symptom is a dialog with a submit button and nothing above it.
- **The record half of an attach form is not rendered.** An attach names an existing record; its own fields belong to whatever edits it.
- **`pivotForm()` is ignored for `Associate`, and for any relation that is not a many-to-many.** Only a join row can hold pivot columns. See [Pivot fields](pivot-fields.md).
- **Attributes are written with `forceFill()`.** The list came from the schema rather than from the request, which is what makes bypassing `$fillable` safe — the same reason a resource form does it.
- **The form is fetched when the dialog opens, not shipped with the row.** A page of twenty records carries twenty buttons, not twenty filled-in forms, and the form is always built from the record as it is now.
- **`method` is always `post`.** Both the fetch and the submit use the same path; the verb is what tells them apart.
- **Everything is one transaction.** The record and its pivot row are written together, so a failed pivot write does not leave a half-created record behind.

## See also

- [Relation managers](relation-managers.md)
- [Relation tables](relation-tables.md)
- [Pivot fields](pivot-fields.md)
- [Attach and detach](attach-detach.md)
- [Associate and dissociate](associate-dissociate.md)
- [Related record policies](policies.md)
- [Forms overview](../forms/overview.md)
- [Validation](../forms/validation.md)
- [Options endpoints](../forms/options-endpoints.md)
- [Relationship forms](../forms/relationships.md)
- [Action forms](../actions/forms.md)
