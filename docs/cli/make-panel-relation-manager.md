# `make:panel-relation-manager`

Generates a relation manager for a resource — the table of related records that
appears on a record's view or edit page — and, when asked, a page of its own for
it. Reach for it when a record owns a list: a user's posts, a post's tags, a
project's tasks.

```bash
php artisan make:panel-relation-manager posts --panel=Admin --resource=Users
```

```text
INFO  Add PostsRelationManager::class to UserResource::relationManagers(). Nothing is registered until you do.
INFO  Created [app/Panels/Admin/Resources/Users/RelationManagers/PostsRelationManager.php]
```

Then make that one edit:

```php
// app/Panels/Admin/Resources/Users/UserResource.php

use App\Panels\Admin\Resources\Users\RelationManagers\PostsRelationManager;

/**
 * @return list<class-string<\PandaPanel\Resources\RelationManager>>
 */
public static function relationManagers(): array
{
    return [PostsRelationManager::class];
}
```

## Signature

```text
make:panel-relation-manager
    {name : The relation name on the owner model, such as posts}
    {--panel= : The panel it belongs to}
    {--resource= : The resource that owns the relation}
    {--type=has-many : has-many or belongs-to-many}
    {--soft-deletes : Offer the trashed filter and the restore actions}
    {--page : Also generate a ManageRelatedRecords page for it}
    {--force}
```

| Argument / option | Default | Effect |
| --- | --- | --- |
| `name` | required | The relation method on the owner model. Camel-cased for `$relationship`, studly-cased for the class name. |
| `--panel=` | required | The panel to generate into, studly-cased. |
| `--resource=` | required | The owning resource. Singularized and studly-cased, so `Users` and `User` both mean `UserResource` in `Resources/Users/`. |
| `--type=` | `has-many` | `has-many` or `belongs-to-many`. Anything else fails and writes nothing. |
| `--soft-deletes` | off | Adds `TrashedFilter`, `RestoreAction` and `ForceDeleteAction`. |
| `--page` | off | Also generates a `ManageRelatedRecords` page. |
| `--force` | off | Overwrite files that already exist. |

```bash
php artisan make:panel-relation-manager posts --panel=Admin --resource=Users
php artisan make:panel-relation-manager tags --panel=Admin --resource=Posts --type=belongs-to-many
php artisan make:panel-relation-manager posts --panel=Admin --resource=Users --soft-deletes --page
```

## Why `--type` is an option

The relation's *shape* decides which operations belong on it, and the shape is
not something a generator can read off a name. A `hasMany` child is created and
deleted. A `belongsToMany` row is attached and detached, and it has a join row
with columns of its own.

Naming the wrong one produces a manager offering an operation the relation
cannot perform — visible the first time you click it — rather than one silently
missing.

## `--type=has-many` (the default)

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Users\RelationManagers;

use App\Panels\Admin\Resources\Users\UserResource;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Relations\DeleteRelatedAction;
use PandaPanel\Actions\Relations\EditRelatedAction;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\RelationManager;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

/**
 * The `posts` relation of Users.
 *
 * `query()` is the scope: every read and every write goes through the owner's
 * relation, so a record belonging to another owner is simply not reachable
 * here. Name this manager in `UserResource::relationManagers()` — a
 * manager the resource does not declare cannot be addressed by a request.
 */
final class PostsRelationManager extends RelationManager
{
    protected static string $relationship = 'posts';

    protected static ?string $recordTitleAttribute = 'name';

    public static function table(TableSchema $table, Model $owner): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->toggleable(false),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditRelatedAction::make(UserResource::class, self::class, $owner),
                DeleteRelatedAction::make(self::class, $owner),
            ])
            ->bulkActions([
                //
            ])
            ->emptyState(
                heading: 'Nothing here yet',
                description: 'Related records will appear here once there are some.',
            );
    }

    public static function form(FormSchema $schema, Model $owner): FormSchema
    {
        return $schema->schema([
            //
        ]);
    }
}
```

Two things need your attention before it is useful: the table has only an `id`
column, and `form()` has no fields, so create and edit save nothing.

## `--type=belongs-to-many`

Three differences, all of them consequences of the join row:

```php
use PandaPanel\Actions\Relations\DetachAction;
use PandaPanel\Actions\Relations\DetachBulkAction;

            ->recordActions([
                EditRelatedAction::make(PostResource::class, self::class, $owner),
                DetachAction::make(self::class, $owner),
                DeleteRelatedAction::make(self::class, $owner),
            ])
            ->bulkActions([
                DetachBulkAction::make(self::class, $owner),
            ])
```

and a fourth method on the class:

```php
    /**
     * The pivot columns an attach or an edit may write. Only fields declared
     * here are validated and persisted to the join row.
     */
    public static function pivotForm(FormSchema $schema, Model $owner): FormSchema
    {
        return $schema->schema([
            //
        ]);
    }
```

Detach removes the join row and leaves both records. Delete removes the related
record itself. Both are generated because both are meaningful on a
many-to-many, and which one a user should have is your policy's answer.

## `--soft-deletes`

```bash
php artisan make:panel-relation-manager posts --panel=Admin --resource=Users --soft-deletes
```

```php
use PandaPanel\Actions\Relations\ForceDeleteAction;
use PandaPanel\Actions\Relations\RestoreAction;
use PandaPanel\Tables\Filters\TrashedFilter;

            ->filters([
                TrashedFilter::make('trashed'),
            ])
            ->recordActions([
                EditRelatedAction::make(UserResource::class, self::class, $owner),
                RestoreAction::make(self::class, $owner),
                ForceDeleteAction::make(self::class, $owner),
                DeleteRelatedAction::make(self::class, $owner),
            ])
```

The filter is what puts a deleted record on screen; restore and force-delete
without it would be two buttons that can never appear.

**Add the declaration yourself.** Unlike `make:panel-resource`, this generator
does not write the property that turns the behaviour on:

```php
final class PostsRelationManager extends RelationManager
{
    protected static string $relationship = 'posts';

    protected static bool $softDeletes = true;   // add this
```

`RelationManager::$softDeletes` defaults to `false`, and
`usesSoftDeletes()` reads it before it will resolve a trashed record. Without
the property the trashed filter still shows deleted rows, and clicking Restore
on one 404s — the lookup that would restore it cannot see it.

The property is declared rather than detected so that a related model using
`SoftDeletes` for something else does not silently grow a filter the manager
never meant to offer. `usesSoftDeletes()` also checks that the related model
actually uses the trait.

## `--page`

```bash
php artisan make:panel-relation-manager posts --panel=Admin --resource=Users --page
```

```text
INFO  Created [app/Panels/Admin/Resources/Users/RelationManagers/PostsRelationManager.php]
INFO  Created [app/Panels/Admin/Resources/Users/Pages/ManageUsersPosts.php]
```

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Users\Pages;

use App\Panels\Admin\Resources\Users\RelationManagers\PostsRelationManager;
use App\Panels\Admin\Resources\Users\UserResource;
use PandaPanel\Resources\Pages\ManageRelatedRecords;

/**
 * The `posts` relation on a page of its own.
 *
 * Register it in `UserResource::pages()` under the relation's key:
 *
 *     'posts' => ManageUsersPosts::class,
 *
 * It routes to `{record}/posts` and joins the record's
 * sub-navigation automatically.
 */
final class ManageUsersPosts extends ManageRelatedRecords
{
    protected static string $resource = UserResource::class;

    protected static string $relationManager = PostsRelationManager::class;
}
```

Two registrations, not one — the manager in `relationManagers()` and the page in
`pages()`:

```php
public static function pages(): array
{
    return [
        'index' => ListUsers::class,
        'create' => CreateUser::class,
        'view' => ViewUser::class,
        'edit' => EditUser::class,
        'posts' => ManageUsersPosts::class,
    ];
}
```

The `pages()` key becomes the route segment: `'posts'` routes to
`{record}/posts`. The page's place in the record's sub-navigation is keyed by
the manager's own `key()` — kebab of `$relationship` — rather than by the page
key, so a camel-cased relation like `orderItems` gives a sub-navigation key of
`order-items` and a URL segment of whatever you wrote in `pages()`.

Inline manager or page of its own is a question about the relation: a handful
of rows belongs beside the record, and a table somebody will page and search
through deserves a URL.

## What it never generates

| | Why |
| --- | --- |
| The `relationManagers()` entry | Registration is a deliberate edit; the command prints the exact line. |
| The `pages()` entry for `--page` | Same, and the key is yours to choose. |
| The relation method on the model | The generator does not read your models. `$relationship` must name a real relation, or the first request throws. |
| Columns and form fields | An `id` column and an empty schema, as a shape to fill in. |
| `protected static bool $softDeletes` | See above — add it when you pass `--soft-deletes`. |

## Custom stubs

```bash
php artisan vendor:publish --tag=panda-panel-stubs
```

| Stub | Written to | Placeholders |
| --- | --- | --- |
| `stubs/panel/relation-manager.stub` | `{Plural}/RelationManagers/{Class}RelationManager.php` | `panel`, `plural`, `resource`, `class`, `relationship`, `imports`, `filters`, `recordActions`, `bulkActions`, `pivotForm` |
| `stubs/panel/relation-page.stub` | `{Plural}/Pages/Manage{Plural}{Class}.php` | `panel`, `plural`, `resource`, `class`, `relationship` |

## Exit codes

| Outcome | Code |
| --- | --- |
| At least one file created | `0` |
| Every file already existed and was skipped | `1` |
| `--panel` or `--resource` missing | `1`, with `The --panel and --resource options are both required.` |
| `--type` unknown | `1`, with `Unknown relation type [x]. Valid types are: has-many, belongs-to-many.` |

## Gotchas

- **Nothing is registered until you edit the resource.** A manager the resource
  does not declare cannot be addressed by a request — that check is in
  `ManageRelatedRecords::render()` and in the relation endpoints, so an
  unregistered manager is a 404 rather than an unstyled table.
- **`--soft-deletes` does not set `$softDeletes`.** Add
  `protected static bool $softDeletes = true;` yourself.
- **`--resource` is a resource name, not a path.** It is singularized and then
  pluralized again, so `--resource=Users`, `--resource=User` and
  `--resource=users` all target `Resources/Users/UserResource`. A resource whose
  directory does not follow that convention needs the generated namespace fixed
  by hand.
- **The owning resource is not verified to exist.** A typo generates a manager
  importing a class that is not there.
- **There is no `--type=morph-many`.** Only `has-many` and `belongs-to-many` are
  accepted, because those are the two shapes the generated action sets are
  correct for. A polymorphic relation works at runtime — write the manager from
  the `has-many` output and adjust the actions.
- **`recordTitleAttribute` defaults to `'name'`.** A related model without a
  `name` column shows a blank title in modals and confirmations until you change
  it.

## See also

- [make:panel-resource](make-panel-resource.md) — the resource that owns the relation
- [Relation managers](../relations/relation-managers.md)
- [Relation pages](../relations/relation-pages.md)
- [Relation tables](../relations/relation-tables.md), [Relation forms](../relations/relation-forms.md)
- [Attach and detach](../relations/attach-detach.md), [Associate and dissociate](../relations/associate-dissociate.md)
- [Pivot fields](../relations/pivot-fields.md)
- [Relation soft deletes](../relations/soft-deletes.md), [Relation policies](../relations/policies.md)
- [Nested resources vs relation managers](../relations/nested-vs-relation-manager.md)
- [Relation actions](../actions/relation-actions.md)
- [Publish tags](publish-tags.md)
