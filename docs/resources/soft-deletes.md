# Soft Deletes

A resource whose records are trashed rather than destroyed needs three things at once: a record page that can open a deleted record, actions that can bring it back or finish it off, and a filter that puts one on screen. One property turns on all three. Reach for it when the model uses `Illuminate\Database\Eloquent\SoftDeletes` *and* the panel is meant to expose that fact.

## A resource that soft deletes

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Posts;

use App\Models\Post;
use App\Panels\Admin\Resources\Posts\Pages\EditPost;
use App\Panels\Admin\Resources\Posts\Pages\ListPosts;
use PandaPanel\Actions\DeleteAction;
use PandaPanel\Actions\DeleteBulkAction;
use PandaPanel\Actions\ForceDeleteAction;
use PandaPanel\Actions\ForceDeleteBulkAction;
use PandaPanel\Actions\RestoreAction;
use PandaPanel\Actions\RestoreBulkAction;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
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

    public static function form(FormSchema $schema): FormSchema
    {
        return $schema->schema([TextInput::make('title')->required()]);
    }

    /**
     * @return array<string, class-string>
     */
    public static function pages(): array
    {
        return [
            'index' => ListPosts::class,
            'edit' => EditPost::class,
        ];
    }
}
```

The generator writes exactly this shape:

```bash
php artisan make:panel-resource Post --panel=Admin --soft-deletes
```

## The declaration

```php
protected static bool $softDeletes = false;

public static function usesSoftDeletes(): bool;
```

Declared *and* corroborated. `usesSoftDeletes()` returns true only when the resource says so and `class_uses_recursive()` finds `SoftDeletes` on the model:

```php
public static function usesSoftDeletes(): bool
{
    if (! static::$softDeletes) {
        return false;
    }

    return in_array(SoftDeletes::class, class_uses_recursive(static::getModel()), true);
}
```

Detecting it from the model alone would silently grow restore actions on a model that uses `SoftDeletes` for something the panel was never meant to expose. Two resources over one model can differ — one declaring it, one not — and each answers for itself.

## The three consequences

### 1. A record page can reach a trashed record

Every lookup goes through `recordQuery()`, which is `query()` with one narrow exception:

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

`SoftDeletingScope` and nothing else. Tenant, module, and permission scopes still apply exactly as they do to a live record, so a trashed record outside the resource scope is still a 404.

Without this a deleted record could never be opened, and so could never be restored: the only route to it is the one the default scope hides.

| Method | Signature | Trashed-aware |
| --- | --- | --- |
| `resolveRecord()` | `public static function resolveRecord(int\|string $key): Model` | yes |
| `findRecord()` | `public static function findRecord(int\|string $key): ?Model` | yes |
| `findRecords()` | `public static function findRecords(array $keys): Collection` | yes |
| `query()` | `public static function query(): Builder` | **no** — the index scope is untouched |

```php
use App\Panels\Admin\Resources\Posts\PostResource;

$post = PostResource::resolveRecord($trashedKey);   // resolves
PostResource::query()->find($trashedKey);           // null — the index does not see it
```

### 2. The restore and force-delete actions become answerable

The action endpoint looks records up through `Resource::findRecord()`, which is trashed-aware for the same reason. `findRecords()` matters for bulk operations: the endpoint compares the number of records it got back with the number of keys it was sent, so a lookup that could not see a trashed record would 404 the whole selection.

### 3. `TrashedFilter` has something to reveal

The index still hides trashed records until the filter asks. That is the whole difference between the list and the lookup: an index shows what is current, a record page was asked for one record by key and should answer about it.

## The trashed filter

`PandaPanel\Tables\Filters\TrashedFilter` is an ordinary select filter with a fixed vocabulary.

```php
use PandaPanel\Tables\Filters\TrashedFilter;

$table->filters([TrashedFilter::make('trashed')]);
```

| Constant | Value | Option label | Query |
| --- | --- | --- | --- |
| `TrashedFilter::WITHOUT` | `'without'` | Hidden | the default scope, unchanged |
| `TrashedFilter::WITH` | `'with'` | Included | `withoutGlobalScope(SoftDeletingScope::class)` |
| `TrashedFilter::ONLY` | `'only'` | Only deleted | the scope lifted, plus `whereNotNull(deleted_at)` |

```
/admin/posts?filters[trashed]=only
```

The default label is `Deleted records` and the placeholder is `Hidden`; change the label with `->label('Trash')` like any other filter.

Three behaviours worth knowing:

- **`sanitize()` rejects everything else.** An unrecognised `?filters[trashed]=` value is a no-op rather than a widened query.
- **It lifts the scope by hand** rather than through the `withTrashed()` macro, because those macros only exist on a builder the trait extended.
- **On a model that does not soft delete it does nothing.** `constrain()` checks for `getQualifiedDeletedAtColumn()` first, so a filter declared by mistake is inert rather than a 500.

See [Filters](../tables/filters.md).

## The actions

Each is a factory returning a configured `PandaPanel\Actions\Action`, built against the resource so it can ask that resource's abilities.

### Record actions

```php
use PandaPanel\Actions\DeleteAction;
use PandaPanel\Actions\ForceDeleteAction;
use PandaPanel\Actions\RestoreAction;

DeleteAction::make(PostResource::class);
RestoreAction::make(PostResource::class);
ForceDeleteAction::make(PostResource::class);
```

| Action | Name | Label | Visible when | Authorized by | Runs |
| --- | --- | --- | --- | --- | --- |
| `DeleteAction` | `delete` | Delete | always | `Resource::canDelete()` | `$record->delete()` |
| `RestoreAction` | `restore` | Restore | the record is trashed | `Resource::canRestore()` | `TrashedRecord::restore()` |
| `ForceDeleteAction` | `forceDelete` | Delete permanently | the record is trashed | `Resource::canForceDelete()` | `TrashedRecord::forceDelete()` |

Restore and force delete are hidden for a record that is not trashed, so a row shows either restore or delete and never both. `DeleteAction` and `ForceDeleteAction` both require confirmation; `RestoreAction` does not.

### Bulk actions

```php
use PandaPanel\Actions\DeleteBulkAction;
use PandaPanel\Actions\ForceDeleteBulkAction;
use PandaPanel\Actions\RestoreBulkAction;

DeleteBulkAction::make(PostResource::class);
RestoreBulkAction::make(PostResource::class);
ForceDeleteBulkAction::make(PostResource::class);
```

| Action | Name | Collective ability | Per-record ability |
| --- | --- | --- | --- |
| `DeleteBulkAction` | `delete` | `canDeleteAny()` | `canDelete()` |
| `RestoreBulkAction` | `restore` | `canRestoreAny()` | `canRestore()` |
| `ForceDeleteBulkAction` | `forceDelete` | `canForceDeleteAny()` | `canForceDelete()` |

Two steps, and both are required. The collective ability answers before there is a record to ask about — which is what hides the button — and every record is then authorized individually before any is written. A selection containing one forbidden record writes nothing: the loop throws 403 before the transaction opens.

Each bulk action opens its own `DB::transaction()` regardless of the panel's transaction setting. "All or nothing" is what these actions authorized for, not a default they inherit.

A record in a restore selection that is not trashed is left alone rather than refused. The user asked for the selection to be restored; the rows already live are in the state that was asked for.

## Abilities

Six methods on the resource, each delegating to the Gate through `PandaPanel\Support\PolicyGate`:

```php
public static function canDelete(Model $record): bool;        // 'delete'
public static function canDeleteAny(): bool;                  // 'deleteAny'
public static function canRestore(Model $record): bool;       // 'restore'
public static function canRestoreAny(): bool;                 // 'restoreAny'
public static function canForceDelete(Model $record): bool;   // 'forceDelete'
public static function canForceDeleteAny(): bool;             // 'forceDeleteAny'
```

The policy is a plain Laravel policy; nothing in it knows a panel exists.

```php
final class PostPolicy
{
    public function delete(User $user, Post $post): bool
    {
        return $user->is_admin;
    }

    public function deleteAny(User $user): bool
    {
        return $user->is_admin;
    }

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

`deleteAny`, `restoreAny` and `forceDeleteAny` are not Laravel conventions — they are the collective form the bulk actions ask for. Under [strict authorization](authorization.md) a policy missing one of them throws `PanelAuthorizationException` naming the ability, rather than reading as a working deny.

## Asking about a record

`PandaPanel\Support\TrashedRecord` answers soft-delete questions of a model that may not soft delete at all. Both the resource actions and the relation ones go through it, so there is one answer rather than two that can drift.

```php
use PandaPanel\Support\TrashedRecord;

TrashedRecord::supports($record);     // bool — does it answer trashed() at all
TrashedRecord::isTrashed($record);    // bool — false for a model without the trait
TrashedRecord::restore($record);      // no-op unless trashed and restorable
TrashedRecord::forceDelete($record);  // always safe: plain delete on a plain model
```

The checks are per method rather than one `class_uses_recursive()`: a model may implement soft deletion its own way, and asking whether it can answer the question is more honest than asking whose code it copied.

## The generator flag

```bash
php artisan make:panel-resource Post --panel=Admin --soft-deletes
```

`--soft-deletes` writes the declaration, the filter and the actions together, which is the point:

| File | What it adds |
| --- | --- |
| `PostResource.php` | `protected static bool $softDeletes = true;` |
| `Tables/PostsTable.php` | `TrashedFilter::make('trashed')` in `filters()` |
| `Tables/PostsTable.php` | `RestoreAction` and `ForceDeleteAction` in `recordActions()` |
| `Tables/PostsTable.php` | `RestoreBulkAction` and `ForceDeleteBulkAction` in `bulkActions()` |

Generating the restore actions without the filter would produce two buttons that can never appear, because a trashed record never reaches the table for them to sit on. See [make:panel-resource](../cli/make-panel-resource.md).

## Relation managers

A relation manager declares soft deletion for itself, on the same terms:

```php
protected static bool $softDeletes = false;

public static function usesSoftDeletes(Model $owner): bool;
```

The related model has to use the trait too, and the manager's own `resolveRecord()` lifts `SoftDeletingScope` when both agree. Its restore and force-delete bulk actions are visible only when `usesSoftDeletes($owner)` is true. See [Soft deleted relations](../relations/soft-deletes.md).

## Notes

- **Declaring `$softDeletes` on a model without the trait is inert, not an error.** `usesSoftDeletes()` is false, the lookup is unchanged, and the restore actions never become visible because no record ever reports itself trashed.
- **The filter alone reveals trashed rows with nothing to do to them; the actions alone never appear.** Both are needed, which is why one flag generates both.
- **The index is never widened by the declaration.** Only the filter widens it, and only to the three values it defines.
- **`DeleteAction`'s confirmation text says the removal cannot be undone.** It is the same action for both kinds of resource; pass your own copy with `->requiresConfirmation(heading: ..., description: ..., button: ...)` when it should read differently for a soft-deleting model.
- **Deletion has no page lifecycle hooks.** It runs through the action endpoint, which executes without a page instance. Use `Action::before()` and `Action::after()`, which share the action's transaction. See [Lifecycle hooks](lifecycle-hooks.md).
- **A restored record leaves an "only deleted" view immediately.** An action redirects with `back()`, and the filter is part of the URL, so the same query runs again against the record's new state.
- **Trashed records are still excluded from global search**, because search starts from `Resource::query()` rather than from `recordQuery()`.

## See also

- [Model binding](model-binding.md)
- [Resource queries](queries.md)
- [Resource authorization](authorization.md)
- [Creating resources](creating-resources.md)
- [Filters](../tables/filters.md)
- [Restore and force delete](../actions/restore-force-delete.md)
- [Bulk actions](../actions/bulk-actions.md)
- [Soft deleted relations](../relations/soft-deletes.md)
- [make:panel-resource](../cli/make-panel-resource.md)
