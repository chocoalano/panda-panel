# Soft Deleted Relations

A relation whose records are trashed rather than destroyed needs three things at once: a filter that puts a deleted record on screen, actions that bring it back or finish it off, and a lookup that can see it. `protected static bool $softDeletes = true` opts a manager into the actions being meaningful; the filter and the actions are still yours to declare. Reach for this when the related model uses `Illuminate\Database\Eloquent\SoftDeletes` *and* this relation is meant to expose that fact.

## A manager that soft deletes

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Projects\RelationManagers;

use App\Panels\Admin\Resources\Projects\ProjectResource;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Relations\DeleteRelatedAction;
use PandaPanel\Actions\Relations\EditRelatedAction;
use PandaPanel\Actions\Relations\ForceDeleteAction;
use PandaPanel\Actions\Relations\ForceDeleteBulkAction;
use PandaPanel\Actions\Relations\RestoreAction;
use PandaPanel\Actions\Relations\RestoreBulkAction;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\RelationManager;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Filters\TrashedFilter;
use PandaPanel\Tables\TableSchema;

final class TasksRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $softDeletes = true;

    public static function table(TableSchema $table, Model $owner): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
            ])
            ->filters([
                TrashedFilter::make('trashed'),
            ])
            ->recordActions([
                EditRelatedAction::make(ProjectResource::class, self::class, $owner),
                RestoreAction::make(self::class, $owner),
                ForceDeleteAction::make(self::class, $owner),
                DeleteRelatedAction::make(self::class, $owner),
            ])
            ->bulkActions([
                RestoreBulkAction::make(self::class, $owner),
                ForceDeleteBulkAction::make(self::class, $owner),
            ]);
    }

    public static function form(FormSchema $schema, Model $owner): FormSchema
    {
        return $schema->schema([
            TextInput::make('name')->required()->maxLength(255),
        ]);
    }
}
```

## The declaration

```php
protected static bool $softDeletes = false;
```

Declared rather than detected: a related model that uses `SoftDeletes` for something else should not silently grow a filter this manager never meant to offer.

```php
public static function usesSoftDeletes(Model $owner): bool;
```

Both halves have to agree — the property **and** the related model actually using the trait:

```php
TasksRelationManager::usesSoftDeletes($project);   // true when Task uses SoftDeletes
```

`usesSoftDeletes()` is what the bulk restore and bulk force-delete actions check for visibility, so declaring `$softDeletes` against a model without the trait renders no bulk buttons rather than buttons that throw.

## The filter is what puts a deleted record on screen

```php
use PandaPanel\Tables\Filters\TrashedFilter;

->filters([TrashedFilter::make('trashed')])
```

| Constant | Value | Query |
| --- | --- | --- |
| `TrashedFilter::WITHOUT` | `without` | Eloquent's own `withoutTrashed` scope, unchanged |
| `TrashedFilter::WITH` | `with` | drops `SoftDeletingScope` |
| `TrashedFilter::ONLY` | `only` | drops the scope, then `whereNotNull(deleted_at)` |

The default state is `without`, so a table that declares the filter still hides deleted rows until somebody asks for them. In a relation table the state lives under the relation's own namespace:

```text
/admin/projects/7?relations[tasks][filters][trashed]=only
```

Those three values are the whole vocabulary — `sanitize()` rejects everything else, so the query can never be widened by a value the filter did not define.

Without the filter, `RestoreAction` and `ForceDeleteAction` have nothing to appear on: both are hidden for a record that is not trashed, and a table that never shows a trashed record never shows either button. The generator adds the filter alongside the actions for exactly this reason.

## `RestoreAction`

```php
use PandaPanel\Actions\Relations\RestoreAction;

RestoreAction::make(string $manager, Model $owner): Action;
```

| Property | Value |
| --- | --- |
| Name | `restore` |
| Label | `Restore` |
| Icon | `rotate-ccw` |
| Variant | `ActionVariant::Ghost` |
| Success message | `Record restored.` |
| Visible when | the record is trashed |
| Authorized by | `RelationManager::canRestore($owner, $record)` → `restore` on the related record |

```text
POST /{panel}/relations/action
{ "resource": "projects", "record": 7, "relation": "tasks", "action": "restore", "related": 12 }
```

Hidden for a live record, so the row shows either restore or delete and never both.

## `ForceDeleteAction`

```php
use PandaPanel\Actions\Relations\ForceDeleteAction;

ForceDeleteAction::make(string $manager, Model $owner): Action;
```

| Property | Value |
| --- | --- |
| Name | `forceDelete` |
| Label | `Delete permanently` |
| Icon | `trash-2` |
| Variant | `ActionVariant::Destructive` |
| Confirmation | "Delete this record permanently?" — "This cannot be undone and the record cannot be restored afterwards." |
| Success message | `Record permanently deleted.` |
| Visible when | the record is trashed |
| Authorized by | `RelationManager::canForceDelete($owner, $record)` → `forceDelete` on the related record |

Only offered on a record that is already deleted: force-deleting a live record would skip the recoverable step the model went to the trouble of having.

## The bulk forms

```php
use PandaPanel\Actions\Relations\ForceDeleteBulkAction;
use PandaPanel\Actions\Relations\RestoreBulkAction;

RestoreBulkAction::make(string $manager, Model $owner): Action;
ForceDeleteBulkAction::make(string $manager, Model $owner): Action;
```

| | `RestoreBulkAction` | `ForceDeleteBulkAction` |
| --- | --- | --- |
| Name | `restore` | `forceDelete` |
| Label | `Restore selected` | `Delete selected permanently` |
| Variant | `ActionVariant::Outline` | `ActionVariant::Destructive` |
| Confirmation | none | "Delete the selected records permanently?" |
| Visible when | `usesSoftDeletes($owner)` | `usesSoftDeletes($owner)` |
| Per-record ability | `canRestore` | `canForceDelete` |

Both authorize every record before writing any of them and run the set in one `DB::transaction()`:

```text
POST /{panel}/relations/bulk
{ "resource": "projects", "record": 7, "relation": "tasks", "action": "restore", "records": [12, 13] }
```

A selection containing one forbidden record restores nothing and answers 403, rather than restoring the permitted ones and failing halfway.

## Resolving a trashed record

```php
public static function resolveRecord(Model $owner, int|string $key): ?Model;
```

For a manager that soft deletes, this drops `SoftDeletingScope` before the lookup: restoring a record is an operation on something the default scope hides, and a lookup that could not see it could never restore it.

```php
$task->delete();

TasksRelationManager::resolveRecord($project, $task->getKey());   // the trashed Task
```

Which operations are then allowed is still each action's own authorization question. A trashed record can be resolved by the endpoint and still be refused by the ability, and `RestoreAction` remains hidden for a record that is not trashed.

## `TrashedRecord`

The soft-delete questions are asked through one helper, shared by the resource actions and the relation ones:

```php
use PandaPanel\Support\TrashedRecord;

TrashedRecord::supports($record);     // bool — does this model answer `trashed()` at all
TrashedRecord::isTrashed($record);    // bool — false for a model without the trait
TrashedRecord::restore($record);      // no-op unless the record is trashed and can restore
TrashedRecord::forceDelete($record);  // always safe: on a plain model it is delete()
```

The checks are per method rather than one `class_uses_recursive`: a model may implement soft deletion its own way, and asking whether it can answer the question is more honest than asking whose code it copied. This is what makes a manager that declared `$softDeletes` against a model without the trait render an action that never appears, instead of one that 500s when clicked.

## Gotchas

- **The filter is not added for you.** `$softDeletes = true` makes the actions meaningful; `TrashedFilter::make('trashed')` is what makes a trashed record visible. Declare both or the restore actions can never appear.
- **`$softDeletes` alone is not enough.** `usesSoftDeletes()` also checks the related model for the `SoftDeletes` trait, and the bulk actions are hidden when it says no.
- **`DeleteRelatedAction` soft deletes when the model does.** It calls `$record->delete()`, so on a soft-deleting model it trashes rather than destroys — which is why the force-delete action exists beside it.
- **Restore is idempotent, not a no-op endpoint.** Posting a restore for a live record succeeds and changes nothing: the handler restores only a trashed record.
- **Trashed rows are excluded from `attachableOptions()`.** The option query is a plain `newQuery()` on the related model, so the default scope applies — a trashed record cannot be attached back into the relation without being restored first.
- **The owner's own soft deletes are a different question.** A trashed owner is reached — or not — through `Resource::query()` and `Resource::$softDeletes`. See [Resource soft deletes](../resources/soft-deletes.md).

## See also

- [Relation managers](relation-managers.md)
- [Relation tables](relation-tables.md)
- [Related record policies](policies.md)
- [Attach and detach](attach-detach.md)
- [Resource soft deletes](../resources/soft-deletes.md)
- [Restore and force delete actions](../actions/restore-force-delete.md)
- [Filters](../tables/filters.md)
- [Bulk actions](../actions/bulk-actions.md)
