# Relation Actions

`PandaPanel\Actions\Relations` holds the operations a relation manager offers: create a related record, attach or associate an existing one, edit it, detach or dissociate it, delete it, restore it. You reach for them when writing a `RelationManager::table()` — they are the relation counterparts of the resource actions, and they take the manager class and the owner record rather than a resource.

Three of them you never declare at all. Create, attach, and associate are resolved by `PandaPanel\Resources\RelationTable` from what the relation *is*, so a manager cannot accidentally offer an attach on a `hasMany`.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Orders\RelationManagers;

use App\Panels\Admin\Resources\Orders\OrderResource;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Relations\DeleteRelatedAction;
use PandaPanel\Actions\Relations\EditRelatedAction;
use PandaPanel\Resources\RelationManager;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

final class LineItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'lineItems';

    public static function table(TableSchema $table, Model $owner): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('description')->searchable(),
                TextColumn::make('quantity'),
            ])
            ->recordActions([
                EditRelatedAction::make(OrderResource::class, self::class, $owner),
                DeleteRelatedAction::make(self::class, $owner),
            ]);
    }
}
```

The relation table now edits and deletes its own rows. A "New line item" button appears above it without being declared, because `RelationTable` adds it.

## The catalogue

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;

// All of these live in PandaPanel\Actions\Relations.
CreateRelatedAction::make(string $resource, string $manager, Model $owner): Action
AttachAction::make(string $resource, string $manager, Model $owner): Action
AssociateAction::make(string $resource, string $manager, Model $owner): Action
EditRelatedAction::make(string $resource, string $manager, Model $owner): Action
DetachAction::make(string $manager, Model $owner): Action
DissociateAction::make(string $manager, Model $owner): Action
DeleteRelatedAction::make(string $manager, Model $owner): Action
RestoreAction::make(string $manager, Model $owner): Action
ForceDeleteAction::make(string $manager, Model $owner): Action
DetachBulkAction::make(string $manager, Model $owner): Action
RestoreBulkAction::make(string $manager, Model $owner): Action
ForceDeleteBulkAction::make(string $manager, Model $owner): Action
```

The four that open a dialog take the resource as well, because their form URL names the owning resource, the relation, and the operation. The rest do not need it.

| Factory | Name | Label | Icon | Variant | Type |
| --- | --- | --- | --- | --- | --- |
| `CreateRelatedAction` | `create` | `New {manager title}` | `plus` | default | form |
| `AttachAction` | `attach` | `Attach {manager title}` | `link` | outline | form |
| `AssociateAction` | `associate` | `Associate {manager title}` | `link` | outline | form |
| `EditRelatedAction` | `edit` | Edit | `pencil` | ghost | form |
| `DetachAction` | `detach` | Detach | `unlink` | ghost | callback |
| `DissociateAction` | `dissociate` | Dissociate | `unlink` | ghost | callback |
| `DeleteRelatedAction` | `delete` | Delete | `trash-2` | destructive | callback |
| `RestoreAction` | `restore` | Restore | `rotate-ccw` | ghost | callback |
| `ForceDeleteAction` | `forceDelete` | Delete permanently | `trash-2` | destructive | callback |
| `DetachBulkAction` | `detach` | Detach selected | `unlink` | destructive | callback |
| `RestoreBulkAction` | `restore` | Restore selected | `rotate-ccw` | outline | callback |
| `ForceDeleteBulkAction` | `forceDelete` | Delete selected permanently | `trash-2` | destructive | callback |

## Visibility and authorization

| Factory | Visible when | Authorized by |
| --- | --- | --- |
| `CreateRelatedAction` | always | `canCreate($owner)` |
| `AttachAction` | `isManyToMany($owner)` | `canAttach($owner)` |
| `AssociateAction` | `isOneToMany($owner)` | `canAssociate($owner)` |
| `EditRelatedAction` | always | `canEdit($owner, $record)` |
| `DetachAction` | `isManyToMany($owner)` | `canDetach($owner, $record)` |
| `DissociateAction` | `isOneToMany($owner)` | `canDissociate($owner, $record)` |
| `DeleteRelatedAction` | always | `canDelete($owner, $record)` |
| `RestoreAction` | the record is trashed | `canRestore($owner, $record)` |
| `ForceDeleteAction` | the record is trashed | `canForceDelete($owner, $record)` |
| `DetachBulkAction` | `isManyToMany($owner)` | `canAttach($owner)` with no record, `canDetach()` per record |
| `RestoreBulkAction` | `usesSoftDeletes($owner)` | `canRestore($owner, $record)` per record |
| `ForceDeleteBulkAction` | `usesSoftDeletes($owner)` | `canForceDelete($owner, $record)` per record |

Attach and associate are mutually exclusive by construction — each is hidden for the shape the other belongs to — so a relation offers one way to bring in an existing record, never two.

The abilities themselves land on two different policies:

| Manager method | Ability | Policy |
| --- | --- | --- |
| `canCreate($owner)` | `create` | the related model |
| `canEdit($owner, $record)` | `update` | the related record |
| `canDelete($owner, $record)` | `delete` | the related record |
| `canRestore($owner, $record)` | `restore` | the related record |
| `canForceDelete($owner, $record)` | `forceDelete` | the related record |
| `canAttach($owner)` | `attachAny` | the owner |
| `canDetach($owner, $record)` | `detach` | the owner, related record as a second argument |
| `canAssociate($owner)` | `associateAny` | the owner |
| `canDissociate($owner, $record)` | `dissociate` | the owner, related record as a second argument |

Reading and writing a related record are questions about that record. Membership is a question about the owner — whether this order may have that line item added to it.

## Detach, dissociate, and delete are three different things

```php
use PandaPanel\Actions\Relations\DeleteRelatedAction;
use PandaPanel\Actions\Relations\DetachAction;
use PandaPanel\Actions\Relations\DissociateAction;
```

| Action | Relation shape | What it writes | The record afterwards |
| --- | --- | --- | --- |
| `DetachAction` | many-to-many | removes the pivot row | untouched, no longer joined |
| `DissociateAction` | one-to-many, one-to-one | nulls the foreign key | untouched, belongs to nobody |
| `DeleteRelatedAction` | any | `$record->delete()` | gone (or trashed) |

`DetachAction` refuses to guess: it resolves `RelationManager::relation($owner)` and only calls `detach()` when it is a `BelongsToMany`. `DissociateAction` does the same for `HasOneOrMany` and writes `null` to `getForeignKeyName()` — which is why it is only honest on a nullable column. On a non-nullable one the write fails at the database and the honest operation is a delete.

The confirmation copy says which of the three is about to happen, because "remove" reads as any of them.

## The dialogs

`CreateRelatedAction`, `AttachAction`, `AssociateAction`, and `EditRelatedAction` are `form` actions, but they do not use `schema()`. They use `form()`, which gives the dialog a URL to fetch from:

```php
use PandaPanel\Support\RelationEndpoints;
use PandaPanel\Support\RelationOperation;

->form(static fn (): string => RelationEndpoints::form(
    $resource,
    $manager,
    $owner,
    RelationOperation::Attach->value,
))
```

That URL names an owner and an operation the panel's action-form endpoint knows nothing about, which is the whole reason `form()` exists beside `schema()`. `Action::toArray()` sends it as `formUrl`, and the frontend prefers it over the panel's own endpoint.

`PandaPanel\Support\RelationOperation` is a closed set — `Create`, `Edit`, `Attach`, `Associate` — because the operation decides which schema is built, which ability is asked, and which write runs. A value the server does not recognise is a 404, not a fallback.

Which schema each operation gets:

| Operation | Form | Pivot fields |
| --- | --- | --- |
| `Create` | `RelationManager::form()`, for page `create` | on a many-to-many |
| `Edit` | `RelationManager::form()`, for page `edit` | on a many-to-many |
| `Attach` | a required, searchable select of `RelationManager::attachableOptions()` | on a many-to-many |
| `Associate` | the same select | never |

`RelationForm::for()` builds all four. Pivot fields come from `RelationManager::pivotForm()` and are name-prefixed so they cannot collide with the related record's own columns; only fields declared there are validated and persisted to the join row, so an extra key in the request body is discarded exactly as it is on a resource form. Associate writes a foreign key rather than a join row, so it has no pivot to offer.

## The endpoints

Relation actions do not use the panel's action endpoints. They post to the relation set instead, because the request has to name an owner and a relation as well.

| Route name | Method and path | Used by |
| --- | --- | --- |
| `relations.form` | `GET {panel}/relations/form` | the four dialog actions |
| `relations.save` | `POST {panel}/relations/form` | their submit |
| `relations.action` | `POST {panel}/relations/action` | every record action |
| `relations.bulk` | `POST {panel}/relations/bulk` | every bulk action |

```json
POST /admin/relations/action
{ "resource": "orders", "record": 7, "relation": "line-items", "action": "detach", "related": 42 }
```

`PanelRelationController::action()` resolves the manager through `Resource::relationManager()`, finds the action with `RelationTable::actionFor()` — which is `RelationManager::table(TableSchema::make(), $owner)->getRecordAction($name)` — checks `isExecutable()`, resolves the related record through `RelationManager::resolveRecord()`, authorizes, and runs. `bulk()` does the same through `RelationTable::bulkActionFor()` and loads the selection with `RelationManager::query($owner)->whereKey($keys)`, comparing counts so a key belonging to another owner is a visible 404 rather than a partial run.

The context travels in the query string for the form endpoints rather than the body: the body is the form's values, and a field named `resource` must not be able to point the request somewhere else.

## The header actions you do not declare

```php
// PandaPanel\Resources\RelationTable::headerActions(), in outline.
CreateRelatedAction::make($this->resource, $this->manager, $this->owner);
AttachAction::make($this->resource, $this->manager, $this->owner);
AssociateAction::make($this->resource, $this->manager, $this->owner);
```

All three are built for every relation table and serialized with `Action::toArray()`, which drops the ones whose `visible()` or `authorize()` says no. A `belongsToMany` shows create and attach; a `hasMany` shows create and associate; a manager whose policy refuses `create` shows neither.

There is no hook for adding a fourth. A custom header action on a relation belongs on the page that hosts the relation — see [Relation pages](../relations/relation-pages.md).

## A full manager

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Projects\RelationManagers;

use App\Panels\Admin\Resources\Projects\ProjectResource;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Relations\DetachAction;
use PandaPanel\Actions\Relations\DetachBulkAction;
use PandaPanel\Actions\Relations\EditRelatedAction;
use PandaPanel\Actions\Relations\ForceDeleteAction;
use PandaPanel\Actions\Relations\RestoreAction;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\RelationManager;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Filters\TrashedFilter;
use PandaPanel\Tables\TableSchema;

final class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    public static function table(TableSchema $table, Model $owner): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('pivot.role')->label('Role'),
            ])
            ->filters([TrashedFilter::make('trashed')])
            ->recordActions([
                EditRelatedAction::make(ProjectResource::class, self::class, $owner),
                DetachAction::make(self::class, $owner),
                RestoreAction::make(self::class, $owner),
                ForceDeleteAction::make(self::class, $owner),
            ])
            ->bulkActions([
                DetachBulkAction::make(self::class, $owner),
            ]);
    }

    public static function pivotForm(FormSchema $schema, Model $owner): FormSchema
    {
        return $schema->schema([
            TextInput::make('role')->required()->maxLength(50),
        ]);
    }
}
```

`make:panel-relation-manager` generates this shape, including the `TrashedFilter` when soft deletes are asked for — a manager with restore actions and no filter would offer two buttons that can never appear.

## Notes

- **The owner is captured at build time.** These are factories taking `Model $owner`, so the closures they install close over that record. A manager's `table()` is called per request with the owner in hand, which is why the signature has it.
- **Names collide with the resource actions on purpose.** `edit`, `delete`, `restore`, and `forceDelete` exist in both sets. They are resolved through different endpoints against different schemas, so nothing has to disambiguate them.
- **A relation bulk action's collective check is loose by design.** `RestoreBulkAction` and `ForceDeleteBulkAction` answer `true` when asked with no record and then check every record inside the handler; `DetachBulkAction` asks `canAttach($owner)` with none. The per-record check is the one that decides.
- **`DetachAction` and `DissociateAction` do nothing silently on the wrong relation shape.** Both check the relation type and return without writing if it is not what they expect, which is why `visible()` keeps them off the wrong table in the first place.
- **`RestoreAction` needs a trashed filter to be reachable.** Without one no deleted row ever appears for it to sit on.
- **These are ordinary actions.** Chaining works: `DetachAction::make(self::class, $owner)->label('Remove')->icon('x')`.

## See also

- [Action basics](overview.md)
- [Built-in actions](built-in-actions.md)
- [Action authorization](authorization.md)
- [Action forms](forms.md)
- [Bulk actions](bulk-actions.md)
- [Relation managers](../relations/relation-managers.md)
- [Relation tables](../relations/relation-tables.md)
- [Relation forms](../relations/relation-forms.md)
- [Attach and detach](../relations/attach-detach.md)
- [Associate and dissociate](../relations/associate-dissociate.md)
- [Pivot fields](../relations/pivot-fields.md)
- [Relation policies](../relations/policies.md)
- [Relation soft deletes](../relations/soft-deletes.md)
