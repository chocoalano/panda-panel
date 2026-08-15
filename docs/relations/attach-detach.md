# Attach And Detach

Attaching joins an existing record to a many-to-many relation by writing a pivot row; detaching removes that row and leaves both records alone. They are offered only where there is a join row to add and remove — a `BelongsToMany` or a `MorphToMany`. "Detaching" a `hasMany` child would mean nulling its foreign key, which is a different decision with a different name: see [Associate and dissociate](associate-dissociate.md).

## A manager that attaches and detaches

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Projects\RelationManagers;

use App\Panels\Admin\Resources\Projects\ProjectResource;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Relations\DetachAction;
use PandaPanel\Actions\Relations\DetachBulkAction;
use PandaPanel\Actions\Relations\EditRelatedAction;
use PandaPanel\Resources\RelationManager;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

final class LabelsRelationManager extends RelationManager
{
    protected static string $relationship = 'labels';

    protected static ?string $recordTitleAttribute = 'name';

    public static function table(TableSchema $table, Model $owner): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
            ])
            ->recordActions([
                EditRelatedAction::make(ProjectResource::class, self::class, $owner),
                DetachAction::make(self::class, $owner),
            ])
            ->bulkActions([
                DetachBulkAction::make(self::class, $owner),
            ]);
    }
}
```

with the owner model declaring the relation:

```php
public function labels(): BelongsToMany
{
    return $this->belongsToMany(Label::class);
}
```

The attach button appears above the table on its own: `AttachAction` is a header action resolved by `RelationTable`, not something the manager lists. The detach actions are yours to declare, because whether a row may be removed one at a time, in bulk, or not at all is a decision about this table.

## `AttachAction`

```php
use PandaPanel\Actions\Relations\AttachAction;

AttachAction::make(string $resource, string $manager, Model $owner): Action;
```

| Property | Value |
| --- | --- |
| Name | `attach` |
| Label | `Attach {manager title}` |
| Icon | `link` |
| Variant | `ActionVariant::Outline` |
| Type | form — opens a dialog fetched from the relation form endpoint |
| Visible when | `RelationManager::isManyToMany($owner)` |
| Authorized by | `RelationManager::canAttach($owner)` → `attachAny` on the **owner** |

The dialog is the `attach` relation form: a select naming the record to join, plus whatever pivot columns the manager declared.

```text
GET  /{panel}/relations/form?resource=projects&record=7&relation=labels&operation=attach
POST /{panel}/relations/form?resource=projects&record=7&relation=labels&operation=attach
{ "related": "4", "pivot": { "role": "primary" } }
```

Both halves are the server's: the option list is built from `RelationManager::attachableOptions()` and the pivot values are validated against `pivotForm()`, so a key the user was never offered and a pivot column the manager never declared are both refused rather than written.

### What may be attached

```php
public static function attachableOptions(
    Model $owner,
    ?string $search = null,
    int $limit = 50,
): array;   // list<array{value: string, label: string}>
```

Every related record **not** already in the relation, labelled with `recordTitle()`, ordered by the title attribute, and capped at `$limit`. The select is searchable, so the rest of the table stays reachable through the options endpoint:

```text
GET /{panel}/options?resource=projects&record=7&relation=labels&operation=attach&field=related&search=urg
{ "options": [ { "value": "12", "label": "Urgent" } ] }
```

A record already in the relation never appears in either list — offering it would mean an attach that gets refused the moment it is picked.

### Attaching something already attached

Checked in the controller rather than in the form's rules: the rule would have to name every currently related key, which is a list that grows with the relation. One indexed lookup says the same thing.

```text
POST .../operation=attach   { "related": "4" }
→ 422 "That record is already in this relation."
```

## `DetachAction`

```php
use PandaPanel\Actions\Relations\DetachAction;

DetachAction::make(string $manager, Model $owner): Action;
```

| Property | Value |
| --- | --- |
| Name | `detach` |
| Label | `Detach` |
| Icon | `unlink` |
| Variant | `ActionVariant::Ghost` |
| Confirmation | "Detach this record?" — "The record itself is kept; only the link to it is removed." |
| Success message | `Record detached.` |
| Visible when | `RelationManager::isManyToMany($owner)` |
| Authorized by | `RelationManager::canDetach($owner, $record)` → `detach` on the **owner**, with the record |

It runs `BelongsToMany::detach($record->getKey())` — the join row goes, both records stay.

```text
POST /{panel}/relations/action
{ "resource": "projects", "record": 7, "relation": "labels", "action": "detach", "related": 4 }
```

The confirmation copy says which of the two things is about to happen, because "remove" reads as either one. That is the whole difference from [`DeleteRelatedAction`](relation-managers.md#operations), which removes the record itself.

## `DetachBulkAction`

```php
use PandaPanel\Actions\Relations\DetachBulkAction;

DetachBulkAction::make(string $manager, Model $owner): Action;
```

| Property | Value |
| --- | --- |
| Name | `detach` |
| Label | `Detach selected` |
| Icon | `unlink` |
| Variant | `ActionVariant::Destructive` |
| Confirmation | "Detach the selected records?" |
| Success message | `Selected records detached.` |
| Visible when | `RelationManager::isManyToMany($owner)` |
| Authorized (no record) | `RelationManager::canAttach($owner)` — the collective gate the endpoint asks first |
| Authorized (per record) | `RelationManager::canDetach($owner, $record)` inside the handler |

```text
POST /{panel}/relations/bulk
{ "resource": "projects", "record": 7, "relation": "labels", "action": "detach", "records": [4, 5] }
```

Every record is authorized before any pivot row is removed, and the whole set runs in one `DB::transaction()`: a selection containing one forbidden record detaches nothing rather than detaching the permitted ones and failing halfway. A refused record answers 403 for the request, not for that row.

The transaction here is explicit whatever the panel's own setting says — "all or nothing" is what this action authorized for, not a default it inherits.

## Pivot columns on an attach

```php
public static function pivotForm(FormSchema $schema, Model $owner): FormSchema
{
    return $schema->schema([
        TextInput::make('role')->maxLength(50),
    ]);
}
```

Pivot fields render, validate, and submit under `pivot.` and are written with the `attach()` call. Only declared fields are persisted:

```text
{ "related": "4", "pivot": { "role": "primary", "smuggled": "value" } }
→ the join row has role = primary, and no smuggled column is written
```

Full detail in [Pivot fields](pivot-fields.md).

## Customizing the actions

Each `make()` returns a `PandaPanel\Actions\Action`, so the usual builder methods apply:

```php
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Actions\Relations\DetachAction;

DetachAction::make(self::class, $owner)
    ->label('Remove label')
    ->icon('x')
    ->variant(ActionVariant::Outline)
    ->requiresConfirmation(
        heading: 'Remove this label?',
        description: 'The label stays in the library.',
        button: 'Remove',
    )
    ->successMessage('Label removed.');
```

`AttachAction` cannot be customized this way, because the manager never constructs it — it is built by `RelationTable::headerActions()`. What you can change is what it offers: `$recordTitleAttribute` decides the labels, and `attachableOptions()` can be overridden on the manager.

```php
/**
 * @return list<array{value: string, label: string}>
 */
public static function attachableOptions(Model $owner, ?string $search = null, int $limit = 50): array
{
    return array_values(array_filter(
        parent::attachableOptions($owner, $search, $limit),
        static fn (array $option): bool => $option['label'] !== 'Internal',
    ));
}
```

## Gotchas

- **`->authorize()` replaces, it does not add.** Chaining it onto `DetachAction::make()` throws away the `canDetach()` check. Call the manager's method inside your own closure if you narrow it.
- **`attachAny` and `detach` live on the *owner's* policy.** Whether a label may be pinned to a project is the project's business, not the label's. Missing them is the usual cause of an attach button that never appears. See [Related record policies](policies.md).
- **`MorphToMany` counts as many-to-many.** It extends `BelongsToMany`, so one check answers for both.
- **Hiding an action is not what protects it.** `isManyToMany()` guards the visibility *and* the endpoint: an attach posted against a `hasMany` is a 403 even though no button was ever rendered.
- **Detaching is not deleting.** If the related record has no life outside the relation, `DeleteRelatedAction` is the honest action.
- **A pivot column must be declared on the relation too.** Without `->withPivot('role')` on the owner model's relation, the column is written but never read back into `$record->pivot`.
- **The bulk endpoint 404s the whole request for a key outside the relation.** Keys outside the relation silently disappear from the query, so the count check is what turns that into a visible failure rather than a partial operation.

## See also

- [Relation managers](relation-managers.md)
- [Relation forms](relation-forms.md)
- [Pivot fields](pivot-fields.md)
- [Associate and dissociate](associate-dissociate.md)
- [Related record policies](policies.md)
- [Relation tables](relation-tables.md)
- [Bulk actions](../actions/bulk-actions.md)
- [Action authorization](../actions/authorization.md)
