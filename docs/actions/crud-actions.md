# Create, Edit, View, And Delete Actions

Four of the built-in factories cover the operations a resource almost always needs: `CreateAction`, `ViewAction`, `EditAction`, and `DeleteAction` — plus `DeleteBulkAction` for a selection. You reach for them when declaring a resource's table; they are already wired to the resource's pages and its policy, so a working CRUD table is five lines rather than fifty.

Two of them are links to pages the resource already registers, one is a link *or* a dialog depending on how much form there is, and one is a callback that writes.

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
            ->bulkActions([DeleteBulkAction::make(PostResource::class)])
            ->emptyStateActions([CreateAction::make(PostResource::class)]);
    }
}
```

## Creating

```php
use PandaPanel\Actions\Action;

CreateAction::make(string $resource): Action    // links to the create page
CreateAction::modal(string $resource): Action   // opens the same form in a dialog
```

Two shapes, deliberately. Which one is right depends on the form — one with four fields does not deserve a page — so the resource chooses rather than the framework guessing.

```php
use App\Panels\Admin\Resources\Posts\PostResource;
use PandaPanel\Actions\CreateAction;

$table->headerActions([
    CreateAction::make(PostResource::class),                       // "New post" → /admin/posts/create
    CreateAction::modal(PostResource::class)->label('Quick add'),  // "Quick add" → dialog
]);
```

| | `make()` | `modal()` |
| --- | --- | --- |
| Name | `create` | `create` |
| Label | `New {lowercased resource label}` | same |
| Icon | `plus` | `plus` |
| Variant | `ActionVariant::Default` | `ActionVariant::Default` |
| Type | `link` | `form` |
| Visible when | `create` is in `Resource::pages()` | always |
| Authorized by | `Resource::canCreate()` | `Resource::canCreate()` |
| Modal heading | — | `New {lowercased resource label}` |
| Modal submit label | — | `Create` |
| Success message | — | `{Resource label} created.` |

Both go through `Resource::form()`, so the two cannot validate or persist differently. `modal()` builds it as:

```php
use PandaPanel\Forms\FormSchema;

$resource::form(FormSchema::make()->model($resource::getModel())->forPage('create'));
```

`forPage('create')` is what makes fields hidden on create actually hidden here too. The write is a table handler — the dialog carries no record, so authorization is asked with `null`, exactly as it is for a bulk action before anything is selected:

```php
$model = $resource::getModel();

(new $model)->forceFill($data)->save();
```

Through the model rather than through `query()`, because a scope describes what the list shows and a new record has not been written yet for a scope to have an opinion about. Override the write when creating is not a plain insert:

```php
use App\Models\Post;
use App\Panels\Admin\Resources\Posts\PostResource;
use PandaPanel\Actions\CreateAction;

CreateAction::modal(PostResource::class)
    ->tableAction(static function (array $data): void {
        $post = Post::query()->create($data);

        $post->author()->associate(auth()->user())->save();
    });
```

## Viewing and editing

```php
ViewAction::make(string $resource): Action
EditAction::make(string $resource): Action
```

Both are link actions built from `Resource::url()`:

```php
$resource::url('view', $record)
$resource::url('edit', $record)
```

| | `ViewAction` | `EditAction` |
| --- | --- | --- |
| Name | `view` | `edit` |
| Label | View | Edit |
| Icon | `eye` | `pencil` |
| Variant | ghost | ghost |
| Visible when | `view` is in `Resource::pages()` | `edit` is in `Resource::pages()` |
| Authorized by | `Resource::canView($record)` | `Resource::canEdit($record)` |

A link action has no handler, so there is nothing to post: `POST {panel}/actions/record` with `action: "view"` answers 400, because `isExecutable()` is false. The route the link leads to authorizes again on arrival, which is what actually protects the record.

The `visible()` check is not decoration. A resource that registers no `view` page would otherwise render a button pointing at a route that was never registered, which fails while rendering rather than when clicked.

```php
use App\Panels\Admin\Resources\Posts\PostResource;
use PandaPanel\Actions\EditAction;
use PandaPanel\Actions\ViewAction;

$table->recordActions([
    ViewAction::make(PostResource::class)->icon('info'),
    EditAction::make(PostResource::class)->label('Change'),
]);
```

## Deleting

```php
DeleteAction::make(string $resource): Action
DeleteBulkAction::make(string $resource): Action
```

| | `DeleteAction` | `DeleteBulkAction` |
| --- | --- | --- |
| Name | `delete` | `delete` |
| Label | Delete | Delete selected |
| Icon | `trash-2` | `trash-2` |
| Variant | destructive | destructive |
| Confirmation heading | `Delete this record?` | `Delete the selected records?` |
| Confirmation button | Delete | Delete |
| Success message | `Record deleted.` | `Selected records deleted.` |
| Authorized by | `canDelete($record)` | `canDeleteAny()`, then `canDelete($record)` per record |

`DeleteAction` calls `$record->delete()`. On a model using `SoftDeletes` that is a soft delete — the record moves to the trash and `RestoreAction` can bring it back. See [Restore and force delete](restore-force-delete.md).

`DeleteBulkAction` re-checks every record inside its handler and throws a 403 before writing anything, then deletes inside one explicit `DB::transaction()`:

```php
// What DeleteBulkAction does, in outline.
foreach ($records as $record) {
    if (! $resource::canDelete($record)) {
        throw new HttpException(403, 'You may not delete every selected record.');
    }
}

DB::transaction(static function () use ($records): void {
    $records->each(static fn (Model $record) => $record->delete());
});
```

Explicitly transactional whatever the panel says: this action authorizes every record before deleting any, and all-or-nothing is the guarantee it advertises rather than a default it inherits. A selection containing one forbidden record deletes nothing.

## Which abilities are asked

Every `can*` on `PandaPanel\Resources\Resource` goes through `PandaPanel\Support\PolicyGate`, which is `Gate::allows()` plus the panel's strict-authorization check.

| Resource method | Policy ability | Argument |
| --- | --- | --- |
| `canViewAny()` | `viewAny` | the model class |
| `canView($record)` | `view` | the record |
| `canCreate()` | `create` | the model class |
| `canEdit($record)` | `update` | the record |
| `canDelete($record)` | `delete` | the record |
| `canDeleteAny()` | `deleteAny` | the model class |

`deleteAny` is asked before there is a record to ask about, which is why a bulk delete needs it on the policy in addition to `delete`.

```php
namespace App\Policies;

use App\Models\Post;
use App\Models\User;

final class PostPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('posts.read');
    }

    public function view(User $user, Post $post): bool
    {
        return $user->can('posts.read');
    }

    public function create(User $user): bool
    {
        return $user->can('posts.write');
    }

    public function update(User $user, Post $post): bool
    {
        return $user->can('posts.write') && ! $post->is_locked;
    }

    public function delete(User $user, Post $post): bool
    {
        return $user->can('posts.write') && ! $post->is_locked;
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('posts.write');
    }
}
```

## On a view page

The same four are useful in an infolist, where they act on the record the page is about:

```php
use App\Panels\Admin\Resources\Posts\PostResource;
use PandaPanel\Actions\DeleteAction;
use PandaPanel\Actions\EditAction;
use PandaPanel\Infolists\InfolistSchema;

public static function infolist(InfolistSchema $schema): InfolistSchema
{
    return $schema->actions([
        EditAction::make(PostResource::class),
        DeleteAction::make(PostResource::class),
    ]);
}
```

They resolve through the `infolist` scope, which is a different whitelist from the table's. See [Infolist actions](infolist-actions.md).

## Notes

- **A hidden button is not a permission.** All four are re-authorized by the endpoint before anything runs, and a link's destination authorizes on arrival.
- **`DeleteAction` and `DeleteBulkAction` are both named `delete`.** They sit in different sets, which are different whitelists, so nothing has to disambiguate them.
- **A delete on a soft-deleting model does not remove the row.** If the intention is permanent removal, that is `ForceDeleteAction`.
- **`CreateAction::modal()` is a table action.** It appears in `headerActions()`, `toolbarActions()`, or `emptyStateActions()`, never in `recordActions()` — it has no record to act on.
- **The create dialog has no options endpoint or live-field endpoint.** A relationship select that pages its options, or a field marked `live()`, works on the create *page* but not inside the dialog. See [Action forms](forms.md).
- **`Resource::label()` drives the create label.** Override it with `->label()` when "New post" is not the phrase the panel wants.

## See also

- [Built-in actions](built-in-actions.md)
- [Action basics](overview.md)
- [Restore and force delete](restore-force-delete.md)
- [Replicate](replicate.md)
- [Bulk actions](bulk-actions.md)
- [Action forms](forms.md) and [Action modals](modals.md)
- [Action authorization](authorization.md)
- [Record actions on a table](../tables/record-actions.md)
- [Resource CRUD pages](../resources/crud-pages.md)
- [Resource authorization](../resources/authorization.md)
