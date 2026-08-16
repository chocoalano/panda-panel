# Built-In Actions

Panda Panel ships the operations every resource ends up needing: view, edit, create, delete, restore, force delete, replicate, import, and export, plus a set for relations. Each one is a small static factory that returns a fully configured `PandaPanel\Actions\Action`, so you drop it into a schema and it already has a label, an icon, a variant, its confirmation copy, and its authorization wired to the resource's policy.

They are factories rather than subclasses because the returned object is an ordinary action: anything on it can be overridden by chaining, and nothing about it is special-cased by the endpoint.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Posts\Tables;

use App\Panels\Admin\Resources\Posts\PostResource;
use PandaPanel\Actions\CreateAction;
use PandaPanel\Actions\DeleteAction;
use PandaPanel\Actions\DeleteBulkAction;
use PandaPanel\Actions\EditAction;
use PandaPanel\Actions\ViewAction;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

final class PostsTable
{
    public static function configure(TableSchema $table): TableSchema
    {
        return $table
            ->columns([TextColumn::make('title')->searchable()->sortable()])
            ->headerActions([CreateAction::make(PostResource::class)])
            ->recordActions([
                ViewAction::make(PostResource::class),
                EditAction::make(PostResource::class),
                DeleteAction::make(PostResource::class),
            ])
            ->bulkActions([DeleteBulkAction::make(PostResource::class)]);
    }
}
```

That is a complete CRUD table. Nothing else is required to have working buttons that respect the policy.

## The catalogue

Every factory below lives in `PandaPanel\Actions` and takes the resource class it acts on.

```php
use Closure;
use PandaPanel\Actions\Action;

CreateAction::make(string $resource): Action
CreateAction::modal(string $resource): Action
ViewAction::make(string $resource): Action
EditAction::make(string $resource): Action
DeleteAction::make(string $resource): Action
DeleteBulkAction::make(string $resource): Action
RestoreAction::make(string $resource): Action
RestoreBulkAction::make(string $resource): Action
ForceDeleteAction::make(string $resource): Action
ForceDeleteBulkAction::make(string $resource): Action
ReplicateAction::make(string $resource, array $except = [], ?Closure $using = null): Action
ExportAction::make(string $exporter, string $resource): Action
ExportAction::bulk(string $exporter, string $resource): Action
ImportAction::make(string $importer, string $resource): Action
```

### Record actions

| Factory | Name | Label | Icon | Variant | Type |
| --- | --- | --- | --- | --- | --- |
| `ViewAction` | `view` | View | `eye` | ghost | link |
| `EditAction` | `edit` | Edit | `pencil` | ghost | link |
| `DeleteAction` | `delete` | Delete | `trash-2` | destructive | callback |
| `RestoreAction` | `restore` | Restore | `rotate-ccw` | outline | callback |
| `ForceDeleteAction` | `forceDelete` | Delete permanently | `trash-2` | destructive | callback |
| `ReplicateAction` | `replicate` | Replicate | `copy` | outline | callback |

| Factory | Visible when | Authorized by | Confirms | Success message |
| --- | --- | --- | --- | --- |
| `ViewAction` | `view` is in `Resource::pages()` | `canView($record)` | no | — |
| `EditAction` | `edit` is in `Resource::pages()` | `canEdit($record)` | no | — |
| `DeleteAction` | always | `canDelete($record)` | yes | `Record deleted.` |
| `RestoreAction` | the record is trashed | `canRestore($record)` | no | `Record restored.` |
| `ForceDeleteAction` | the record is trashed | `canForceDelete($record)` | yes | `Record permanently deleted.` |
| `ReplicateAction` | always | `canCreate()` **and** `canView($record)` | yes | `Record replicated.` |

`ViewAction` and `EditAction` are links, so there is nothing to post and nothing to execute — the route they lead to authorizes again on arrival. `RestoreAction` and `ForceDeleteAction` ask `PandaPanel\Support\TrashedRecord::isTrashed()`, so a row shows either restore or delete and never both.

### Table actions

| Factory | Name | Label | Icon | Variant | Type |
| --- | --- | --- | --- | --- | --- |
| `CreateAction::make()` | `create` | `New {resource label}` | `plus` | default | link |
| `CreateAction::modal()` | `create` | `New {resource label}` | `plus` | default | form |
| `ExportAction::make()` | `export` | Export | `download` | outline | form |
| `ImportAction::make()` | `import` | Import | `upload` | outline | form |

`CreateAction::make()` links to `Resource::url('create')` and is hidden when the resource declares no `create` page. `CreateAction::modal()` opens the resource's own form in a dialog and writes through a table handler. Both go through `Resource::form()`, so the two cannot validate or persist differently.

All three of the modal ones are declared in `headerActions()`, `toolbarActions()`, or `emptyStateActions()` and resolve through the `table` scope.

### Bulk actions

| Factory | Name | Label | Icon | Variant | Confirms |
| --- | --- | --- | --- | --- | --- |
| `DeleteBulkAction` | `delete` | Delete selected | `trash-2` | destructive | yes |
| `RestoreBulkAction` | `restore` | Restore selected | `rotate-ccw` | outline | no |
| `ForceDeleteBulkAction` | `forceDelete` | Delete selected permanently | `trash-2` | destructive | yes |
| `ExportAction::bulk()` | `export` | Export | `download` | outline | opens a form |

| Factory | Collective ability | Per-record ability | Success message |
| --- | --- | --- | --- |
| `DeleteBulkAction` | `canDeleteAny()` | `canDelete($record)` | `Selected records deleted.` |
| `RestoreBulkAction` | `canRestoreAny()` | `canRestore($record)` | `Selected records restored.` |
| `ForceDeleteBulkAction` | `canForceDeleteAny()` | `canForceDelete($record)` | `Selected records permanently deleted.` |
| `ExportAction::bulk()` | `canViewAny()` | — | `Your export is ready.` |

The delete, restore, and force-delete ones share a shape: the collective ability answers before there is a record to ask about, then the handler re-checks every record, then everything runs inside one explicit `DB::transaction()` — whatever the panel's transaction setting is, because "all or nothing" is what they advertise rather than a default they inherit.

## Overriding one

The factories return an `Action`, so chaining continues from where they left off.

```php
use App\Panels\Admin\Resources\Posts\PostResource;
use PandaPanel\Actions\DeleteAction;
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Actions\ViewAction;

ViewAction::make(PostResource::class)->icon('info')->label('Details');

DeleteAction::make(PostResource::class)
    ->icon('trash')
    ->variant(ActionVariant::Ghost)
    ->requiresConfirmation(
        heading: 'Delete this post?',
        description: 'Comments on it are deleted too.',
        button: 'Delete it',
    )
    ->successMessage('Post deleted.');
```

Anything set later wins, including the handler:

```php
use Illuminate\Database\Eloquent\Model;

DeleteAction::make(PostResource::class)
    ->action(static function (Model $record): void {
        $record->forceFill(['status' => 'archived'])->save();
    });
```

The authorization closure the factory installed is replaced only if you call `authorize()` again. Chaining `->action()` alone keeps `canDelete()` guarding an operation that no longer deletes, which is usually wrong — say what the new operation needs.

## Import and export

```php
use App\Panels\Admin\Resources\Users\Exports\UserExporter;
use App\Panels\Admin\Resources\Users\Imports\UserImporter;
use App\Panels\Admin\Resources\Users\UserResource;
use PandaPanel\Actions\ExportAction;
use PandaPanel\Actions\ImportAction;

$table
    ->headerActions([
        ImportAction::make(UserImporter::class, UserResource::class),
        ExportAction::make(UserExporter::class, UserResource::class),   // the filtered list
    ])
    ->bulkActions([
        ExportAction::bulk(UserExporter::class, UserResource::class),   // the selection
    ]);
```

Both take a class name rather than a closure because a queued run happens in a different process from the request that asked for it, and only a class name crosses that gap. See [Import and export actions](import-export.md).

## Relation actions

`PandaPanel\Actions\Relations` holds the same idea for a relation manager. They take the manager class and the owner record instead of a resource.

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;

// All of these live in PandaPanel\Actions\Relations.
AssociateAction::make(string $resource, string $manager, Model $owner): Action
AttachAction::make(string $resource, string $manager, Model $owner): Action
CreateRelatedAction::make(string $resource, string $manager, Model $owner): Action
EditRelatedAction::make(string $resource, string $manager, Model $owner): Action
DeleteRelatedAction::make(string $manager, Model $owner): Action
DetachAction::make(string $manager, Model $owner): Action
DetachBulkAction::make(string $manager, Model $owner): Action
DissociateAction::make(string $manager, Model $owner): Action
RestoreAction::make(string $manager, Model $owner): Action
RestoreBulkAction::make(string $manager, Model $owner): Action
ForceDeleteAction::make(string $manager, Model $owner): Action
ForceDeleteBulkAction::make(string $manager, Model $owner): Action
```

Create, attach, and associate are resolved by `PandaPanel\Resources\RelationTable` rather than declared per manager — all three are answers to what the relation *is*, and a manager that had to list them would be able to offer an attach on a `hasMany`. See [Relation actions](relation-actions.md).

## Notes

- **Names collide by design.** `DeleteAction` and `DeleteBulkAction` are both called `delete`, and the resource and relation `RestoreAction` are both `restore`. They live in different sets, and the sets are different whitelists, so nothing has to disambiguate them. Two actions with one name *inside* one set throws `PanelSchemaException::duplicateActions()`.
- **`RestoreAction` needs two other things to be reachable.** The resource must declare soft deletes, or a trashed record cannot be resolved, and the table needs a `PandaPanel\Tables\Filters\TrashedFilter`, or no trashed row ever appears for the action to sit on.
- **`CreateAction::modal()` writes with `forceFill()`.** The values are already validated and dehydrated by the resource's form schema, which is the whitelist — but the model's `$fillable` is not consulted. Override the write with `->tableAction(...)` when creating is not a plain insert.
- **A factory's label reads from the resource.** `CreateAction` builds `New ` plus `mb_strtolower(Resource::label())`, so renaming the resource renames the button.
- **`ReplicateAction` asks two abilities.** A copy is a new record, and being allowed to see one is not being allowed to make another.

## See also

- [Action basics](overview.md)
- [CRUD actions](crud-actions.md)
- [Replicate](replicate.md)
- [Restore and force delete](restore-force-delete.md)
- [Import and export actions](import-export.md)
- [Relation actions](relation-actions.md)
- [Bulk actions](bulk-actions.md)
- [Custom actions](custom-actions.md)
- [Record actions on a table](../tables/record-actions.md)
- [Resource authorization](../resources/authorization.md)
- [Soft deletes](../resources/soft-deletes.md)
