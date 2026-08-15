# Restore And Force Delete

Two actions for a resource whose records are trashed rather than destroyed: `RestoreAction` brings a soft-deleted record back, and `ForceDeleteAction` finishes it off. Each has a bulk form and a relation counterpart. Reach for them the moment a resource declares `$softDeletes` — without them a deleted record can be revealed by the trashed filter and then nothing can be done with it.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Posts;

use App\Models\Post;
use PandaPanel\Actions\DeleteAction;
use PandaPanel\Actions\DeleteBulkAction;
use PandaPanel\Actions\ForceDeleteAction;
use PandaPanel\Actions\ForceDeleteBulkAction;
use PandaPanel\Actions\RestoreAction;
use PandaPanel\Actions\RestoreBulkAction;
use PandaPanel\Resources\Resource;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Filters\TrashedFilter;
use PandaPanel\Tables\TableSchema;

final class PostResource extends Resource
{
    protected static string $model = Post::class;

    protected static bool $softDeletes = true;

    public static function table(TableSchema $table): TableSchema
    {
        return $table
            ->columns([TextColumn::make('title')->searchable()->sortable()])
            ->filters([TrashedFilter::make('trashed')])
            ->recordActions([
                DeleteAction::make(self::class),
                RestoreAction::make(self::class),
                ForceDeleteAction::make(self::class),
            ])
            ->bulkActions([
                DeleteBulkAction::make(self::class),
                RestoreBulkAction::make(self::class),
                ForceDeleteBulkAction::make(self::class),
            ]);
    }
}
```

Three things have to be true together, and they only make sense together: the model uses `Illuminate\Database\Eloquent\SoftDeletes`, the resource declares `$softDeletes`, and the table carries a `TrashedFilter`. The generator writes all of it at once:

```bash
php artisan make:panel-resource Post --panel=Admin --soft-deletes
```

## The record actions

```php
use PandaPanel\Actions\ForceDeleteAction;
use PandaPanel\Actions\RestoreAction;

RestoreAction::make(string $resource): Action
ForceDeleteAction::make(string $resource): Action
```

Both take the resource class string, because that is what they ask for abilities. They return a configured `PandaPanel\Actions\Action`, so anything on that class can still be chained.

| | `RestoreAction` | `ForceDeleteAction` |
| --- | --- | --- |
| Name | `restore` | `forceDelete` |
| Label | Restore | Delete permanently |
| Icon | `rotate-ccw` | `trash-2` |
| Variant | `ActionVariant::Outline` | `ActionVariant::Destructive` |
| Confirms | no | yes |
| Visible when | `TrashedRecord::isTrashed($record)` | `TrashedRecord::isTrashed($record)` |
| Authorized by | `Resource::canRestore($record)` | `Resource::canForceDelete($record)` |
| Runs | `TrashedRecord::restore($record)` | `TrashedRecord::forceDelete($record)` |
| Success message | `Record restored.` | `Record permanently deleted.` |

Both are hidden for a record that is not trashed, so a row shows either restore or delete and never both. That is `visible()`, not `authorize()`: a live record is not one you are forbidden to restore, it is one there is nothing to restore.

`ForceDeleteAction` confirms with copy of its own:

```text
heading:     Delete this record permanently?
description: This cannot be undone and the record cannot be restored afterwards.
button:      Delete permanently
```

Replace any of it the usual way:

```php
use App\Panels\Admin\Resources\Posts\PostResource;
use PandaPanel\Actions\ForceDeleteAction;

ForceDeleteAction::make(PostResource::class)
    ->requiresConfirmation(
        heading: 'Erase this post?',
        description: 'The file attachments go with it.',
        button: 'Erase it',
    )
    ->successMessage('Post erased.');
```

`RestoreAction` does not confirm, because restoring is the reversible half of the pair. Add one if your records are noisy to bring back:

```php
RestoreAction::make(PostResource::class)->requiresConfirmation();
```

## The bulk actions

```php
use PandaPanel\Actions\ForceDeleteBulkAction;
use PandaPanel\Actions\RestoreBulkAction;

RestoreBulkAction::make(string $resource): Action
ForceDeleteBulkAction::make(string $resource): Action
```

| | `RestoreBulkAction` | `ForceDeleteBulkAction` |
| --- | --- | --- |
| Name | `restore` | `forceDelete` |
| Label | Restore selected | Delete selected permanently |
| Icon | `rotate-ccw` | `trash-2` |
| Variant | `ActionVariant::Outline` | `ActionVariant::Destructive` |
| Confirms | no | yes |
| Collective ability | `Resource::canRestoreAny()` | `Resource::canForceDeleteAny()` |
| Per-record ability | `Resource::canRestore($record)` | `Resource::canForceDelete($record)` |
| Success message | `Selected records restored.` | `Selected records permanently deleted.` |

Both authorize twice, and both steps are required:

```php
->authorize(static fn (?Model $record): bool => $record === null
    ? $resource::canRestoreAny()
    : $resource::canRestore($record))
->bulkAction(static function (Collection $records) use ($resource): void {
    foreach ($records as $record) {
        if (! $resource::canRestore($record)) {
            throw new HttpException(403, 'You may not restore every selected record.');
        }
    }

    DB::transaction(static function () use ($records): void {
        $records->each(static fn (Model $record) => TrashedRecord::restore($record));
    });
});
```

The collective ability answers before there is a record to ask about, which is what decides whether the button is drawn at all — a user without `restoreAny` never sees "Restore selected". Every record is then authorized individually before any is written, so a selection containing one forbidden record writes nothing:

```php
it('restores nothing when one of a selection is refused', function (): void {
    TaskPolicy::$restorable = false;

    bulkOn('restore', [$this->trashed->getKey(), $second->getKey()])->assertForbidden();

    expect($this->trashed->fresh()->trashed())->toBeTrue()
        ->and($second->fresh()->trashed())->toBeTrue();
});
```

Both open `DB::transaction()` explicitly, whatever the panel's transaction setting says. "All or nothing" is what these actions authorized for, not a default they inherit. See [Transactions](transactions.md).

Neither carries a `visible()` closure, unlike its record counterpart. A selection is a mix of trashed and live rows in general, and a bulk bar that appeared and disappeared as rows were ticked would be worse than one that stays put.

## Trashed rows in a mixed selection

A record in a restore selection that is not trashed is left alone rather than refused. `TrashedRecord::restore()` is a no-op on a live record:

```php
public static function restore(Model $record): void
{
    if (self::isTrashed($record) && method_exists($record, 'restore')) {
        $record->restore();
    }
}
```

The user selected rows and asked for them to be restored; the ones already live are in the state that was asked for. Force delete has no such guard — `forceDelete()` is meaningful on any model — so a live record in a force-delete selection is destroyed. That is why the record action hides itself for a live row and the confirmation copy is as blunt as it is.

## The helper both go through

```php
use PandaPanel\Support\TrashedRecord;

TrashedRecord::supports(Model $record): bool       // does it answer trashed() at all
TrashedRecord::isTrashed(Model $record): bool      // false for a model without the trait
TrashedRecord::restore(Model $record): void        // no-op unless trashed and restorable
TrashedRecord::forceDelete(Model $record): void    // always safe
```

`trashed()`, `restore()` and `forceDelete()` come from the `SoftDeletes` trait, so calling them on a plain model is a fatal error rather than a false. Everything soft-delete-related goes through this class, so a resource that declared `$softDeletes` against a model that does not use the trait renders an action that never appears, instead of one that 500s when clicked.

The checks are per method rather than one `class_uses_recursive()` on the trait: a model may implement soft deletion its own way, and asking whether it can answer the question is more honest than asking whose code it copied.

## The abilities

```php
use PandaPanel\Resources\Resource;

Resource::canRestore(Model $record): bool        // 'restore'
Resource::canRestoreAny(): bool                  // 'restoreAny'
Resource::canForceDelete(Model $record): bool    // 'forceDelete'
Resource::canForceDeleteAny(): bool              // 'forceDeleteAny'
```

They resolve through an ordinary Laravel policy; nothing in it knows a panel exists.

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Post;
use App\Models\User;

final class PostPolicy
{
    public function restore(User $user, Post $post): bool
    {
        return $user->is_admin;
    }

    public function restoreAny(User $user): bool
    {
        return $user->is_admin;
    }

    public function forceDelete(User $user, Post $post): bool
    {
        return $user->is_admin;
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->is_admin;
    }
}
```

`restoreAny` and `forceDeleteAny` are not Laravel conventions — they are the collective form the bulk actions ask for. Under strict authorization a policy missing one throws `PanelAuthorizationException` naming the ability, rather than reading as a working deny. See [Resource authorization](../resources/authorization.md).

## How a trashed record is reachable at all

The action endpoint resolves records through `Resource::findRecord()` and `Resource::findRecords()`, not through `Resource::query()`:

```php
protected static function recordQuery(): Builder
{
    $query = static::query();

    if (static::usesSoftDeletes()) {
        $query->withoutGlobalScope(SoftDeletingScope::class);
    }

    return $query;
}
```

`SoftDeletingScope` and nothing else. Tenant, panel and nested-parent scopes still apply exactly as they do to a live record, so a trashed record outside the resource scope is still a 404. This is the only reason a restore works: a lookup that could not see a deleted record could never bring one back.

For a bulk operation it matters twice, because the endpoint compares the number of records it got back with the number of keys it was sent and 404s the whole request on a mismatch:

```php
it('finds trashed records for a bulk operation', function (): void {
    expect(TaskSoftDeleteResource::findRecords([$this->trashed->getKey()])->count())->toBe(1);
});
```

The index is untouched by all of this. It still hides trashed records until `TrashedFilter` asks — see [Soft deletes](../resources/soft-deletes.md).

## Running them

```text
POST {panel path}/actions/record    { "resource": "posts", "action": "restore", "record": 42 }
POST {panel path}/actions/record    { "resource": "posts", "action": "forceDelete", "record": 42 }
POST {panel path}/actions/bulk      { "resource": "posts", "action": "restore", "records": [42, 43] }
POST {panel path}/actions/bulk      { "resource": "posts", "action": "forceDelete", "records": [42, 43] }
```

Both scopes re-check authorization on execution; the button having been drawn is never what permits the operation.

```php
it('refuses a restore the policy does not allow', function (): void {
    TaskPolicy::$restorable = false;

    actionOn('restore', $this->trashed->getKey())->assertForbidden();

    expect($this->trashed->fresh()->trashed())->toBeTrue();
});
```

See [Action scopes](scopes.md).

## The relation counterparts

A relation manager gets its own four, in `PandaPanel\Actions\Relations`. They take the manager class and the owner record, because whether a related row may be restored is a question with two subjects:

```php
use PandaPanel\Actions\Relations\ForceDeleteAction;
use PandaPanel\Actions\Relations\ForceDeleteBulkAction;
use PandaPanel\Actions\Relations\RestoreAction;
use PandaPanel\Actions\Relations\RestoreBulkAction;

RestoreAction::make(string $manager, Model $owner): Action
ForceDeleteAction::make(string $manager, Model $owner): Action
RestoreBulkAction::make(string $manager, Model $owner): Action
ForceDeleteBulkAction::make(string $manager, Model $owner): Action
```

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Users\Relations;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Relations\ForceDeleteAction;
use PandaPanel\Actions\Relations\RestoreAction;
use PandaPanel\Resources\RelationManager;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Filters\TrashedFilter;
use PandaPanel\Tables\TableSchema;

final class PostsRelationManager extends RelationManager
{
    protected static string $relationship = 'posts';

    protected static bool $softDeletes = true;

    public static function table(TableSchema $table, Model $owner): TableSchema
    {
        return $table
            ->columns([TextColumn::make('title')])
            ->filters([TrashedFilter::make('trashed')])
            ->recordActions([
                RestoreAction::make(self::class, $owner),
                ForceDeleteAction::make(self::class, $owner),
            ]);
    }
}
```

Where they differ from the resource versions:

| | Resource version | Relation version |
| --- | --- | --- |
| Factory arguments | the resource class | the manager class and the owner |
| Abilities | `Resource::canRestore()` / `canForceDelete()` | `RelationManager::canRestore($owner, $record)` / `canForceDelete($owner, $record)` |
| `RestoreAction` variant | `Outline` | `Ghost` |
| Bulk `visible()` | none | `$manager::usesSoftDeletes($owner)` |
| Bulk `authorize()` with `null` | `canRestoreAny()` / `canForceDeleteAny()` | allowed — there is no collective form |
| Endpoint | `actions/record`, `actions/bulk` | `relations/action`, `relations/bulk` |

A relation manager declares soft deletion for itself on the same corroborated terms — `protected static bool $softDeletes` plus the trait on the related model — and `usesSoftDeletes(Model $owner)` answers for the pair. See [Relation actions](relation-actions.md) and [Soft deleted relations](../relations/soft-deletes.md).

## Notes

- **All three pieces are needed.** `$softDeletes` without a `TrashedFilter` means no trashed row ever reaches the table for the actions to sit on; a filter without the actions means rows you can look at and not touch; either without the trait on the model means `usesSoftDeletes()` is false and nothing happens at all. `--soft-deletes` generates the set.
- **`RestoreAction` and `DeleteAction` are never both offered on a row.** Restore is visible only when the record is trashed; delete has no such condition, but a trashed record is not on the list unless the filter asked for it.
- **`forceDelete()` on a plain model is `delete()` under another name.** `TrashedRecord::forceDelete()` calls it unconditionally, which is correct either way — but a resource that does not soft delete should be offering `DeleteAction`, not this one.
- **A restored record leaves an "only deleted" view immediately.** The action redirects `back()`, the filter is part of the URL, and the same query runs again against the record's new state.
- **The bulk actions are unconditionally transactional.** Setting `->databaseTransaction(false)` on one does not remove the `DB::transaction()` inside its handler; that call is not the one `DatabaseTransaction` decides.
- **Deletion and restoration have no page lifecycle hooks.** The action endpoint runs without a page instance. Use `Action::before()` and `Action::after()`, which share the action's transaction.
- **Trashed records stay out of global search.** Search starts from `Resource::query()` rather than from the record lookup.

## See also

- [Action basics](overview.md)
- [Action scopes](scopes.md)
- [Row actions](row-actions.md)
- [Bulk actions](bulk-actions.md)
- [Built-in actions](built-in-actions.md)
- [Create, edit, view, and delete actions](crud-actions.md)
- [Transactions](transactions.md)
- [Relation actions](relation-actions.md)
- [Soft deletes](../resources/soft-deletes.md)
- [Soft deleted relations](../relations/soft-deletes.md)
- [Filters](../tables/filters.md)
