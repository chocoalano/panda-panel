# Nested Resource Vs Relation Manager

Both show one record's children, and both scope every read to that one record. They differ in what the children get: a relation manager gives them a table beside the owner and dialogs to edit them in; a nested resource gives them full pages of their own, under the parent's URL. This page is the decision, and the mechanism behind each half of it.

## The same relation, both ways

A project's tasks, as a relation manager:

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Projects\RelationManagers;

use App\Panels\Admin\Resources\Projects\ProjectResource;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Relations\DeleteRelatedAction;
use PandaPanel\Actions\Relations\EditRelatedAction;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\RelationManager;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

final class TasksRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    public static function table(TableSchema $table, Model $owner): TableSchema
    {
        return $table
            ->columns([TextColumn::make('name')->searchable()->sortable()])
            ->recordActions([
                EditRelatedAction::make(ProjectResource::class, self::class, $owner),
                DeleteRelatedAction::make(self::class, $owner),
            ]);
    }

    public static function form(FormSchema $schema, Model $owner): FormSchema
    {
        return $schema->schema([TextInput::make('name')->required()]);
    }
}
```

```php
// On ProjectResource
public static function relationManagers(): array
{
    return [TasksRelationManager::class];
}
```

and as a nested resource:

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Tasks;

use App\Models\Task;
use App\Panels\Admin\Resources\Projects\ProjectResource;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\Resource;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

final class TaskResource extends Resource
{
    protected static string $model = Task::class;

    protected static ?string $parentResource = ProjectResource::class;

    protected static ?string $parentRelationship = 'tasks';

    public static function table(TableSchema $table): TableSchema
    {
        return $table->columns([TextColumn::make('name')->searchable()->sortable()]);
    }

    public static function form(FormSchema $schema): FormSchema
    {
        return $schema->schema([TextInput::make('name')->required()]);
    }

    /**
     * @return array<string, class-string>
     */
    public static function pages(): array
    {
        return [
            'index' => ListTasks::class,
            'create' => CreateTask::class,
            'edit' => EditTask::class,
        ];
    }
}
```

The first produces a table on `/admin/projects/7`. The second produces `/admin/projects/7/tasks`, `/admin/projects/7/tasks/create`, and `/admin/projects/7/tasks/12/edit`.

## Side by side

| | Relation manager | Nested resource |
| --- | --- | --- |
| Declared by | `relationManagers()` on the owner | `$parentResource` on the child |
| Class | `RelationManager` | `Resource` |
| Where it appears | Beneath the owner's view and edit pages, and optionally on a `ManageRelatedRecords` page | Pages of its own under the parent's URL |
| Editing | A dialog fetched per record | A full edit page |
| Creating | A dialog, saved through the relation | A create page, scoped by the parent |
| Scope comes from | `RelationManager::query()` → `$owner->{relation}()` | `Resource::query()` → `$parent->{relation}()` |
| Owner/parent resolved by | The relation endpoint, through the owner resource's `query()` | `ResolveParentRecord` middleware, through the parent resource's `query()` |
| Sidebar | Absent | Absent |
| Membership abilities | `attachAny`, `detach`, `associateAny`, `dissociate` on the owner's policy | none — a child is created and deleted, not joined |
| Writes post to | `{panel}/relations/*` | The resource's own page routes and `{panel}/actions/*` with a `parent` key |
| Pivot columns | Supported through `pivotForm()` | Not supported |
| Attach / associate | Supported | Not supported |
| Table tabs, toolbar actions, editable cells, drag reordering | Not wired | Supported, as on any resource index |

## Two ways of saying "scoped"

They arrive at the same guarantee by different routes.

A relation manager starts from the owner's relation and never leaves it:

```php
public static function query(Model $owner): Builder
{
    return static::relation($owner)->getQuery();   // $owner->tasks()
}
```

A nested resource starts from the *parent's* relation, resolved from a bound route parameter:

```text
projects/{parentRecord}/tasks
```

```php
// Resource::query() for a nested resource, roughly:
return static::parentRelation()->getQuery();   // ParentRecord::require() → $parent->tasks()
```

In both cases a record under another owner is simply not in the builder, so it 404s without any page checking:

```text
POST /admin/relations/action  { relation: 'tasks', record: 7, related: 12 }  → 404 when task 12 is project 8's
GET  /admin/projects/7/tasks/12/edit                                          → 404, same reason
```

The middleware also authorizes the parent with the parent resource's `canView()`, which is the nested equivalent of the relation endpoints asking `Resource::canView($owner)` before anything else.

## Two ways of asking "may I"

| Question | Relation manager | Nested resource |
| --- | --- | --- |
| May I reach the owner/parent at all | `Resource::canView($owner)` on the owner resource | `canView()` on the parent resource, in the middleware |
| May I read the children | `RelationManager::canViewAny($owner)` → `viewAny` on the related model | `Resource::canViewAny()` on the child resource |
| May I edit one | `canEdit($owner, $record)` → `update` on the record | `canEdit($record)` on the child resource |
| May I join an existing one | `attachAny` / `associateAny` on the **owner's** policy | not a question a nested resource has |

A relation manager has membership abilities because a relation can gain and lose members without either record changing. A nested resource has no such operation: its records are created under a parent and deleted, and moving one is an edit of its foreign key like any other field.

Details in [Related record policies](policies.md) and [Resource authorization](../resources/authorization.md).

## Choosing

Reach for a **relation manager** when:

- The child is read and edited in place — a handful of fields, a dialog's worth.
- The relation is many-to-many, or has pivot columns.
- Existing records are attached and detached rather than created and destroyed.
- The child never needs a URL somebody would bookmark or share.

Reach for a **nested resource** when:

- The child has real depth: its own filters, its own tabs, its own form, its own actions.
- Someone will link to a single child, or open one in a new tab.
- The child's form is long enough that a dialog is the wrong container.
- You want an index with tabs, editable cells, or drag reordering — a relation table does not wire those up.

Reach for **both** when the child deserves pages *and* a summary beside the parent. Nothing prevents a resource from being nested and declaring relation managers of its own.

## Both at once

A `ManageRelatedRecords` page and a nested resource can coexist, as long as their paths differ:

```text
/admin/projects/7/tasks          ManageProjectTasks  (relation page)
/admin/projects/7/nested-tasks   TaskResource        (nested resource)
```

Two resources cannot claim one path. `projects/{record}/tasks` and `projects/{parentRecord}/tasks` are the same shape to the router — parameter names are erased before shapes are compared — so Laravel would match whichever registered first and silently ignore the other. `PanelRouteRegistrar` compares normalized shapes per panel and throws at boot:

```text
PanelRegistrationException: The path [projects/{parentRecord}/tasks] is registered by both
[App\...\ProjectResource] and [App\...\TaskResource]. Only the first would ever match.
Give one of them a different slug or route path.
```

Give the nested resource a different slug, or the relation page a different `$routePath`.

## Migrating between them

Going from a relation manager to a nested resource:

1. Create the child resource with `$parentResource` and `$parentRelationship`.
2. Move `RelationManager::table()`'s columns and filters to `Resource::table()`, dropping the `$owner` parameter.
3. Move `RelationManager::form()`'s fields to `Resource::form()`, again without `$owner`.
4. Replace the relation actions with the resource ones: `EditRelatedAction` becomes a page, `DeleteRelatedAction` becomes `PandaPanel\Actions\DeleteAction`.
5. Give it a slug that does not collide with the relation page.
6. Remove the manager from `relationManagers()`, or keep it for the inline summary.

Going the other way, the fields move back and pick up the `Model $owner` parameter, and the foreign key field — if the nested resource's form declared one — is dropped: a relation manager saves through the relation, so the owner's key is never a form field.

## Gotchas

- **Neither appears in the sidebar.** A nested resource has no "all tasks" to open, and a relation manager is not a resource at all. Navigation to both goes through the parent record.
- **A nested resource still needs its own policy.** The parent's `canView()` decides whether the URL can be entered; the child's abilities decide what may be done inside it.
- **`Resource::url()` for a nested resource needs a parent outside a request.** There is nothing bound to fall back on — pass `parent:`.
- **A nested resource's actions carry `parent` in the payload.** The action endpoints are one per panel and have no parent segment, so the table sends `resource.parentKey` with every action it posts. A missing `parent` is a 422.
- **Pivot columns rule out a nested resource.** A nested resource is scoped by a foreign key; there is no place for join-row attributes in its form.
- **Relation managers are not discovered.** Nested resources are found by discovery like any other resource; a relation manager exists only where `relationManagers()` names it.

## See also

- [Relation managers](relation-managers.md)
- [Relation pages](relation-pages.md)
- [Relation tables](relation-tables.md)
- [Attach and detach](attach-detach.md)
- [Pivot fields](pivot-fields.md)
- [Related record policies](policies.md)
- [Nested resources](../resources/nested-resources.md)
- [Resource queries](../resources/queries.md)
- [URLs and route names](../resources/urls-routes.md)
- [Routing](../concepts/routing.md)
