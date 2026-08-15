# Nested Resources

A nested resource has no index of its own: every one of its pages sits beneath a parent record, and its query is scoped to that parent's relation. Reach for one when a model only makes sense in the context of its owner — a project's tasks, an order's line items — and the child needs full pages of its own rather than a table beside the parent.

## The minimal nested resource

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Tasks;

use App\Models\Task;
use App\Panels\Admin\Resources\Projects\ProjectResource;
use App\Panels\Admin\Resources\Tasks\Pages\EditTask;
use App\Panels\Admin\Resources\Tasks\Pages\ListTasks;
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

    protected static ?string $recordTitleAttribute = 'name';

    public static function table(TableSchema $table): TableSchema
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
        ]);
    }

    public static function form(FormSchema $schema): FormSchema
    {
        return $schema->schema([
            TextInput::make('name')->required()->maxLength(255),
        ]);
    }

    /**
     * @return array<string, class-string>
     */
    public static function pages(): array
    {
        return [
            'index' => ListTasks::class,
            'edit' => EditTask::class,
        ];
    }
}
```

The pages are ordinary `ListRecords` and `EditRecord` subclasses — nothing about them knows they are nested. With both resources registered in the panel, this exists:

```text
/admin/projects/7/tasks
/admin/projects/7/tasks/12/edit
```

and `/admin/tasks` does not.

## The two declarations

```php
protected static ?string $parentResource = ProjectResource::class;

protected static ?string $parentRelationship = 'tasks';
```

| Property | Type | Default | Meaning |
| --- | --- | --- | --- |
| `$parentResource` | `?class-string<Resource>` | `null` | The resource this one is nested under. Declaring it is the whole opt-in |
| `$parentRelationship` | `?string` | camel case of this resource's default slug | The relation on the parent model holding these records |

```php
public static function isNested(): bool;
public static function parentResource(): ?string;
public static function parentRelationship(): string;
```

The relationship default is derived from the child's own slug, so `TaskResource` looks for `$project->tasks()`. State it when the relation is named something else — that is what lets a `PostResource` sit under `author` rather than `posts`.

## The parent is the scope

```php
public static function query(): Builder
{
    // for a nested resource, roughly:
    return static::parentRelation()->getQuery()->with(static::$with);
}
```

The query starts from `$parent->{relationship}()`, never from the model. Because every page, action, and lookup already goes through `query()`, a record under another parent is a 404 without any page having to check:

```text
GET  /admin/projects/7/tasks/12/edit    →  404 when task 12 belongs to project 8
PUT  /admin/projects/7/tasks/12/edit    →  404, and nothing is written
```

A nested resource needs no `query()` override to be scoped. Adding one still works, and still has to call `parent::query()`.

## Routing

The route group is prefixed with the parent's slug, a `{parentRecord}` wildcard, and this resource's slug:

```text
projects/{parentRecord}/tasks
```

Route **names are unchanged**: `panel.admin.resources.tasks.index` is still the name, and `{parentRecord}` is simply a parameter it takes.

```php
route('panel.admin.resources.tasks.index', ['parentRecord' => 7], absolute: false);
// /admin/projects/7/tasks
```

`PandaPanel\Http\Middleware\ResolveParentRecord` is attached to the whole group rather than left to the pages, because every route in it needs the scope and a page that forgot would query unscoped. The middleware:

1. Reads the `{parentRecord}` segment.
2. Resolves it through the **parent** resource's `query()`.
3. Authorizes it with the parent's `canView()`.
4. Binds it for the request, or aborts 404.

Steps 2 and 3 are why `/admin/projects/7/tasks` cannot be a way to read project 7's children while `/admin/projects/7` itself is refused.

The bound record is available through `PandaPanel\Support\ParentRecord`:

```php
use PandaPanel\Support\ParentRecord;

ParentRecord::current();                  // ?Model
ParentRecord::require(TaskResource::class); // Model, or PanelRegistrationException
ParentRecord::routeParameter();           // 'parentRecord'
```

Reading the parent in `query()` and nowhere else is what makes the scope impossible to forget. A page reaching for it directly would be a second place the scope could go missing.

## URLs

`Resource::url()` fills the parent in from the request, so links between a nested resource's own pages need no extra argument:

```php
TaskResource::url();                       // /admin/projects/7/tasks
TaskResource::url('edit', $task);          // /admin/projects/7/tasks/12/edit
TaskResource::url(parent: $otherProject);  // /admin/projects/8/tasks
```

```php
public static function url(
    string $page = 'index',
    Model|int|string|null $record = null,
    Panel|string|null $panel = null,
    Model|int|string|null $parent = null,
): string
```

The `$parent` argument takes a model or a key, and is required whenever there is no bound parent to fall back on — a console command, a queued job, a link built from another part of the panel. Without one, `ParentRecord::require()` throws rather than producing a URL missing the scope it exists for.

## Navigation and breadcrumbs

A nested resource has **no sidebar entry**. `navigationItem()` returns `null` for it, because its pages only exist beneath a parent record and the sidebar has no parent in hand: there is no "all tasks" to open.

Its breadcrumbs carry the parent's trail instead:

```text
Dashboard  ›  Projects  ›  Apollo  ›  Tasks
```

The parent's own crumb links to its view page when the resource declares one and the user may open it, and is plain text otherwise — never a link that would answer 403.

## Actions

The action endpoints are one per panel and carry no parent segment, so a nested resource's table sends the parent with every action it posts. The page ships it as `resource.parentKey`, and the endpoint resolves and authorizes it exactly as the route middleware would.

```text
POST /admin/actions/record
{ "resource": "nested-tasks", "action": "delete", "record": 12, "parent": 7 }
```

| Payload | Answer |
| --- | --- |
| `parent` missing on a nested resource | 422 |
| `parent` naming a record the user may not view | 404 |
| `record` belonging to a different parent | 404 |

## Path collisions

Laravel matches the first route for a path and silently ignores the rest, so two resources claiming one shape means one of them is simply unreachable. A `ManageRelatedRecords` page at `projects/{record}/tasks` and a nested resource at `projects/{parentRecord}/tasks` are the same path as far as matching is concerned — parameter names are erased before shapes are compared.

Registration refuses:

```text
PanelRegistrationException: The path [projects/{parentRecord}/tasks] is registered by both
[App\...\ProjectResource] and [App\...\TaskResource]. Only the first would ever match.
Give one of them a different slug or route path.
```

Give one of them a different slug. The framework's own fixture does exactly that: the nested resource is `nested-tasks` so it cannot shadow the relation page at `projects/{record}/tasks`.

The parent must also be registered in the same panel. A parent from another panel would produce a path built from its default slug, pointing at a route that does not exist here, so it throws at boot rather than shipping dead links.

## Nested resource or relation manager

Both show one record's children. They differ in what the children get.

| | Nested resource | Relation manager |
| --- | --- | --- |
| Pages | Its own list, create, view, edit | A table beside the owner, with modals |
| URL | `/projects/7/tasks/12/edit` | The owner's URL |
| Declared by | `$parentResource` on the child | `relationManagers()` on the owner |
| Sidebar | Absent | Absent |
| Best for | A child with real depth — its own filters, its own form, its own actions | A handful of rows read and edited in place |

They are not exclusive: a resource can be nested *and* declare relation managers of its own. See [Nested vs relation manager](../relations/nested-vs-relation-manager.md).

## Notes

- **A nested resource has no index of its own, by design.** `/admin/tasks` is a 404, and that is the point: there is no "all tasks" that would be meaningful outside a project.
- **`$parentRelationship` is checked at query time, not at boot.** A name that is not a method on the parent model, or that returns something other than a `Relation`, throws `PanelRegistrationException` naming the parent model, the relation, and this resource.
- **The parent resource's scope applies too.** A project the parent resource's `query()` excludes is a 404 for every task beneath it.
- **`Resource::url()` outside a request needs an explicit parent.** There is nothing bound to fall back on.
- **The child's policy still governs the child.** The parent's `canView()` decides whether the URL can be entered at all; the child's own abilities decide what may be done inside it.

## See also

- [Creating resources](creating-resources.md)
- [Resource queries](queries.md)
- [URLs and route names](urls-routes.md)
- [Model binding](model-binding.md)
- [Resource authorization](authorization.md)
- [Labels and navigation](labels-navigation.md)
- [Relation managers](../relations/relation-managers.md)
- [Nested vs relation manager](../relations/nested-vs-relation-manager.md)
- [Relation pages](../relations/relation-pages.md)
- [Routing](../concepts/routing.md)
