# Relation Managers

A relation manager is one owner record's related records: a table with its own schema, its own actions, and its own authorization, scoped to a single owner. Reach for one whenever a record has children that should be read and edited beside it rather than through a resource of their own.

## A minimal relation manager

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Users\RelationManagers;

use App\Panels\Admin\Resources\Users\UserResource;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Relations\DeleteRelatedAction;
use PandaPanel\Actions\Relations\EditRelatedAction;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\RelationManager;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

final class PostsRelationManager extends RelationManager
{
    protected static string $relationship = 'posts';

    protected static ?string $recordTitleAttribute = 'title';

    public static function table(TableSchema $table, Model $owner): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
            ])
            ->recordActions([
                EditRelatedAction::make(UserResource::class, self::class, $owner),
                DeleteRelatedAction::make(self::class, $owner),
            ]);
    }

    public static function form(FormSchema $schema, Model $owner): FormSchema
    {
        return $schema->schema([
            TextInput::make('title')->required()->maxLength(255),
        ]);
    }
}
```

Register it on the resource, and nowhere else:

```php
use PandaPanel\Resources\RelationManager;

/**
 * @return list<class-string<RelationManager>>
 */
public static function relationManagers(): array
{
    return [PostsRelationManager::class];
}
```

The table now appears beneath the user's view and edit pages. Nothing else is registered: `Resource::relationManagers()` is the only list, and every relation table, endpoint, and page resolves through it.

## The relation is the scope

`RelationManager::query()` starts from `$owner->{relationship}()` and is the only way to reach a related record — exactly the role `Resource::query()` plays for a resource.

```php
public static function query(Model $owner): Builder;
```

```php
PostsRelationManager::query($user);              // Builder over $user->posts()
PostsRelationManager::resolveRecord($user, 12);  // ?Model — null when post 12 is someone else's
```

A key belonging to another owner resolves to nothing rather than to somebody else's row, so no page or endpoint has to check for it:

```text
POST /admin/relations/action
{ "resource": "users", "record": 3, "relation": "posts", "action": "delete", "related": 99 }
→ 404 when post 99 belongs to user 4, and nothing is deleted
```

## Declarations

Everything a manager declares is a static property on the class.

| Property | Type | Default | Meaning |
| --- | --- | --- | --- |
| `$relationship` | `string` | required | The relation method on the owner model |
| `$key` | `?string` | `Str::kebab($relationship)` | The key this manager is addressed by, in URLs and action payloads |
| `$title` | `?string` | `Str::headline($relationship)` | The heading above the table |
| `$icon` | `?string` | `null` | The icon registry key, used in the record sub-navigation |
| `$recordTitleAttribute` | `?string` | `'name'` when reading a record's title | The attribute a record is named by in option lists and confirmations |
| `$with` | `list<string>` | `[]` | Relations eager loaded on every row |
| `$softDeletes` | `bool` | `false` | Offers the trashed filter and the restore/force-delete actions |

```php
final class BlogPostsRelationManager extends RelationManager
{
    protected static string $relationship = 'blogPosts';

    // Without this the key would be 'blog-posts'.
    protected static ?string $key = 'posts';

    protected static ?string $title = 'Articles';

    protected static ?string $icon = 'file-text';

    protected static ?string $recordTitleAttribute = 'title';

    /** @var list<string> */
    protected static array $with = ['author'];

    protected static bool $softDeletes = true;
}
```

`$with` exists for the same reason `Resource::$with` does: a column that serializes a related value would otherwise lazy load once per row. `$softDeletes` is declared rather than detected — a related model that uses `SoftDeletes` for something else should not silently grow a filter this manager never meant to offer. See [Soft deleted relations](soft-deletes.md).

## The two schemas

```php
abstract public static function table(TableSchema $table, Model $owner): TableSchema;

public static function form(FormSchema $schema, Model $owner): FormSchema;

public static function pivotForm(FormSchema $schema, Model $owner): FormSchema;
```

`table()` is abstract: a manager without a table has nothing to show. `form()` and `pivotForm()` both return the schema unchanged by default — a manager that only lists, attaches, or detaches has no form, and inheriting one would offer a create button that saves nothing.

The owner travels with all three because a relation table's actions are about the pair, not about the related record alone: whether a row may be detached is a question with two subjects, and an action built without the owner could not ask it.

```php
public static function table(TableSchema $table, Model $owner): TableSchema
{
    return $table
        ->columns([TextColumn::make('title')])
        ->recordActions([
            // Both arguments are needed: the manager for the scope, the
            // owner for the ability.
            DeleteRelatedAction::make(self::class, $owner),
        ]);
}
```

See [Relation tables](relation-tables.md), [Relation forms](relation-forms.md), and [Pivot fields](pivot-fields.md).

## Identity and lookups

| Method | Signature | Returns |
| --- | --- | --- |
| `relationship()` | `static relationship(): string` | The declared relation name |
| `key()` | `static key(): string` | `$key`, or the kebab-cased relationship |
| `title()` | `static title(): string` | `$title`, or the headlined relationship |
| `icon()` | `static icon(): ?string` | `$icon` |
| `recordTitle()` | `static recordTitle(Model $record): string` | The record's title attribute, or its key when that is not a scalar |
| `withRelations()` | `static withRelations(): list<string>` | `$with` |

```php
PostsRelationManager::key();                  // 'posts'
PostsRelationManager::title();                // 'Posts'
PostsRelationManager::recordTitle($post);     // 'Hello world'
```

## Reading the relation

| Method | Signature | Notes |
| --- | --- | --- |
| `relation()` | `static relation(Model $owner): Relation` | Throws `PanelRegistrationException` when the name is not a relation on the owner |
| `query()` | `static query(Model $owner): Builder` | The relation's builder, with `$with` applied |
| `relationForTable()` | `static relationForTable(Model $owner): Relation` | The relation itself, with `$with` applied — what the table paginates |
| `resolveRecord()` | `static resolveRecord(Model $owner, int\|string $key): ?Model` | Null rather than an exception |
| `getRelatedModel()` | `static getRelatedModel(Model $owner): class-string<Model>` | The related model class |

```php
use PandaPanel\Resources\RelationManager;

$relation = PostsRelationManager::relation($user);        // HasMany
$builder  = PostsRelationManager::query($user);           // Builder
$related  = PostsRelationManager::getRelatedModel($user); // App\Models\Post::class
```

Tables paginate through `relationForTable()` rather than through `query()`: a many-to-many hydrates its pivot in `BelongsToMany::paginate()`, and a builder taken out of the relation produces rows whose pivot columns all read as null.

`resolveRecord()` returns null instead of aborting because the caller decides what a missing record means — a 404 for a row action, a skipped row for a bulk one. For a manager that soft deletes, it also drops `SoftDeletingScope`, because a lookup that could not see a trashed record could never restore it.

## Relation shape

The shape of the relation, not the manager, decides which operations exist.

| Method | Signature | True for |
| --- | --- | --- |
| `isManyToMany()` | `static isManyToMany(Model $owner): bool` | `BelongsToMany` and `MorphToMany` |
| `isOneToMany()` | `static isOneToMany(Model $owner): bool` | `HasMany`, `HasOne`, and their morph equivalents (`HasOneOrMany`) |
| `usesSoftDeletes()` | `static usesSoftDeletes(Model $owner): bool` | `$softDeletes` **and** the related model using the `SoftDeletes` trait |

```php
LabelsRelationManager::isManyToMany($project);  // true  — belongsToMany
TasksRelationManager::isManyToMany($project);   // false — hasMany
TasksRelationManager::isOneToMany($project);    // true
```

Attach and associate are mutually exclusive by construction: each is hidden for the shape the other belongs to, so a relation offers one way to bring in an existing record, never two.

## Operations

| Action class | Relation | What it does |
| --- | --- | --- |
| `CreateRelatedAction` | any | Creates through the relation, so no form declares a foreign key |
| `EditRelatedAction` | any | Edits the record, and its pivot row where there is one |
| `DeleteRelatedAction` | any | Deletes the record itself |
| `AttachAction` / `DetachAction` / `DetachBulkAction` | many-to-many | Adds and removes the join row, leaving both records |
| `AssociateAction` / `DissociateAction` | one-to-many | Writes and nulls the child's foreign key |
| `RestoreAction` / `ForceDeleteAction` and their bulk forms | soft-deleting | Undoes or completes a soft delete |

All of them live in `PandaPanel\Actions\Relations`. Create, attach, and associate are header actions, resolved by the framework rather than declared per manager — a manager that had to list them would be able to offer an attach on a `hasMany`. The rest go in `recordActions()` and `bulkActions()`.

See [Attach and detach](attach-detach.md), [Associate and dissociate](associate-dissociate.md), and [Soft deleted relations](soft-deletes.md).

## Attachable options

```php
public static function attachableOptions(
    Model $owner,
    ?string $search = null,
    int $limit = 50,
): array;   // list<array{value: string, label: string}>
```

Every related record **not** already in the relation, labelled with `recordTitle()` and ordered by the title attribute. The search is a `like` on that same attribute, with `\`, `%`, and `_` escaped so a search term cannot widen the match.

```php
LabelsRelationManager::attachableOptions($project);
// [['value' => '2', 'label' => 'Later'], ...]

LabelsRelationManager::attachableOptions($project, 'urg', limit: 10);
```

The browser receives value/label pairs and never the query. The attach dialog is filled from this, and the searchable select goes back to the same method through the options endpoint. See [Relation forms](relation-forms.md).

## Authorization

Two questions with different subjects, kept apart.

| Ability | Asked of | Manager method |
| --- | --- | --- |
| `viewAny` | the related model | `canViewAny(Model $owner): bool` |
| `view` | the related record | `canView(Model $owner, Model $record): bool` |
| `create` | the related model | `canCreate(Model $owner): bool` |
| `update` | the related record | `canEdit(Model $owner, Model $record): bool` |
| `delete` | the related record | `canDelete(Model $owner, Model $record): bool` |
| `restore` | the related record | `canRestore(Model $owner, Model $record): bool` |
| `forceDelete` | the related record | `canForceDelete(Model $owner, Model $record): bool` |
| `attachAny` | the **owner** | `canAttach(Model $owner): bool` |
| `detach` | the **owner**, with the record | `canDetach(Model $owner, Model $record): bool` |
| `associateAny` | the **owner** | `canAssociate(Model $owner): bool` |
| `dissociate` | the **owner**, with the record | `canDissociate(Model $owner, Model $record): bool` |

Whether a tag may be pinned to a post is the post's business, not the tag's. Reaching a relation at all also requires `Resource::canView()` on the owner: without it the relation endpoint would be a way around a refused view. `canViewAny()` runs before the query, so a refused manager is absent from the page *and* costs nothing.

Every ability goes through `RelationManager::authorize()`, which delegates to `PandaPanel\Support\PolicyGate`, so `Panel::strictAuthorization()` covers the relation abilities as well as the record ones. Full detail in [Related record policies](policies.md).

## Where a manager appears

| Surface | What it shows |
| --- | --- |
| `ViewRecord` and `EditRecord` pages | Every manager the resource declares, as `relations` in the page props |
| A `ManageRelatedRecords` page | One named manager, as `relation`, on a URL of its own |
| `{panel}/relations/*` | The four endpoints a manager's forms and writes go to |

A resource with a relation page for a manager still gets that manager inline on its record pages. Where a manager appears is the page's decision: override `ResourcePage::relationTables()` on a page that wants only some of them.

## Generating one

```bash
php artisan make:panel-relation-manager posts --panel=Admin --resource=Users
php artisan make:panel-relation-manager labels --panel=Admin --resource=Projects --type=belongs-to-many
php artisan make:panel-relation-manager tasks --panel=Admin --resource=Projects --soft-deletes --page
```

| Option | Default | Effect |
| --- | --- | --- |
| `--panel=` | required | The panel directory the manager is written into |
| `--resource=` | required | The owning resource; taken singular or plural, both give the same answer |
| `--type=` | `has-many` | `has-many` or `belongs-to-many`; decides whether detach actions and a `pivotForm()` are generated |
| `--soft-deletes` | off | Adds `TrashedFilter`, `RestoreAction`, and `ForceDeleteAction` |
| `--page` | off | Also writes a `ManageRelatedRecords` page for it |
| `--force` | off | Overwrites an existing file |

The relation's shape is an option rather than something the generator reads off a class name, so picking the wrong one produces a manager offering an operation the relation cannot perform rather than one silently missing it. The command prints the one thing it cannot do for you: add the class to `relationManagers()`.

## Gotchas

- **A manager the resource does not name does not exist.** The relation endpoints resolve the key through `Resource::relationManager()`, so a request naming an unregistered manager is a 404 however it spells it.
- **Two managers cannot share a key.** `Resource::relationManager()` throws `PanelRegistrationException` rather than letting the answer depend on declaration order. Give one of them an explicit `$key`.
- **`$relationship` is checked at call time.** A name that is not a method on the owner model, or that returns something other than a `Relation`, throws `PanelRegistrationException` naming the owner, the relation, and the manager.
- **`table()` and `form()` are static.** They receive the owner as an argument; there is no `$this->getOwnerRecord()`.
- **Relation managers are not discovered.** Unlike resources, pages, and widgets, they are only ever found through `relationManagers()`.
- **`$recordTitleAttribute` defaults to `name` at the point it is read.** A related model without a `name` column falls back to the record's key rather than erroring, which looks like a bug in the option list. Declare the attribute.
- **The owner is loaded through `Resource::query()`.** A record the owning resource's scope excludes is a 404 for its relations too.

## See also

- [Relation tables](relation-tables.md)
- [Relation forms](relation-forms.md)
- [Relation pages](relation-pages.md)
- [Attach and detach](attach-detach.md)
- [Associate and dissociate](associate-dissociate.md)
- [Pivot fields](pivot-fields.md)
- [Related record policies](policies.md)
- [Soft deleted relations](soft-deletes.md)
- [Nested resource vs relation manager](nested-vs-relation-manager.md)
- [Creating resources](../resources/creating-resources.md)
- [Tables overview](../tables/overview.md)
