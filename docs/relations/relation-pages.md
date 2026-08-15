# Relation Pages

A relation page is the same relation manager a record page shows inline, given a route and a place in the record's sub-navigation. Reach for one when a relation is big enough that somebody will page, search, and filter it — a table like that deserves a URL of its own. A handful of rows belongs beside the record.

## A minimal relation page

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Users\Pages;

use App\Panels\Admin\Resources\Users\RelationManagers\PostsRelationManager;
use App\Panels\Admin\Resources\Users\UserResource;
use PandaPanel\Resources\Pages\ManageRelatedRecords;

final class ManageUserPosts extends ManageRelatedRecords
{
    protected static string $resource = UserResource::class;

    protected static string $relationManager = PostsRelationManager::class;
}
```

Declared in `Resource::pages()` like every other page, so it is routed, named, and authorized by the same machinery:

```php
/**
 * @return array<string, class-string>
 */
public static function pages(): array
{
    return [
        'index' => ListUsers::class,
        'create' => CreateUser::class,
        'view' => ViewUser::class,
        'edit' => EditUser::class,
        'posts' => ManageUserPosts::class,
    ];
}
```

The manager still has to be in `relationManagers()`. The page list is not a second registration — it points into the first.

That produces:

```text
/admin/users/3/posts        route name: panel.admin.resources.users.posts
```

and a "Posts" tab in the record's sub-navigation, beside View and Edit.

## The declarations

| Property | Type | Default | Meaning |
| --- | --- | --- | --- |
| `$resource` | `class-string<Resource>` | required | The owning resource |
| `$relationManager` | `class-string<RelationManager>` | required | The manager this page shows |
| `$routePath` | `?string` | `{record}/{page key}` | Where the page registers, relative to the resource |
| `$title` | `?string` | `RelationManager::title()` | The document title |
| `$heading` | `?string` | follows the title | The heading above the content |
| `$subheading` | `?string` | the owner record's title | The line beneath it |
| `$component` | `string` | `panel/resources/ManageRelated` | The Vue page component |

```php
final class ManageUserPosts extends ManageRelatedRecords
{
    protected static string $resource = UserResource::class;

    protected static string $relationManager = PostsRelationManager::class;

    protected static ?string $routePath = '{record}/articles';

    protected static ?string $title = 'Articles';
}
```

Keep `{record}` in `$routePath`: the page is one record's relation, and the registrar passes that segment to `render()`. A path without it leaves `$record` null, which falls through to the resource's singular resolution — the first record in the query — and that is almost never what a relation page means.

## The methods

```php
public function render(Request $request, ?string $record = null): Response;

public static function relationManager(): string;      // class-string<RelationManager>
public static function relationPageKey(): string;      // the manager's key
public static function routePath(string $key): string; // '{record}/'.$key by default
```

```php
ManageUserPosts::relationManager();   // App\...\PostsRelationManager::class
ManageUserPosts::relationPageKey();   // 'posts'
ManageUserPosts::routePath('posts');  // '{record}/posts'
```

`relationPageKey()` is the manager's key rather than the page key, because that is the identity the sub-navigation marks active — the page key is whatever `pages()` chose, and two things naming the same relation must agree on one name.

Override the heading methods the way any resource page does:

```php
use Illuminate\Database\Eloquent\Model;

protected function defaultSubheading(?Model $record): ?string
{
    return $record === null ? null : $record->getAttribute('email');
}
```

## What `render()` does

1. Aborts 404 if `Resource::relationManager($key)` does not name this page's manager. A page for a manager the resource does not declare would be a way to reach a relation that was never registered.
2. Resolves the owner through the resource's `query()` — a key outside that scope is a 404.
3. Builds the relation through `RelationTable::forManager()`, and aborts 403 when it comes back null, which is what `RelationManager::canViewAny()` refusing looks like.
4. Renders `panel/resources/ManageRelated` with the props below.

| Prop | Contents |
| --- | --- |
| `page` | Title, heading, subheading, breadcrumbs, cluster, sub-navigation, render-hook scope |
| `resource` | The resource metadata every resource page sends |
| `recordKey` | The owner's key |
| `relation` | One serialized relation manager — see [Relation tables](relation-tables.md) |
| widget props | Whatever the page's widgets declare, in the record's page context |

`page.headerActions` is empty: the relation's own create, attach, and associate buttons live on the relation payload, above its table.

## Sub-navigation

A relation page joins the record's sub-navigation automatically. `PandaPanel\Support\RecordSubNavigation` walks `Resource::pages()`, picks out every `ManageRelatedRecords` subclass, and includes the ones the user may read:

```php
[
    ['key' => 'view',  'label' => 'View',  'href' => '/admin/users/3',       'icon' => 'search',   'active' => false],
    ['key' => 'edit',  'label' => 'Edit',  'href' => '/admin/users/3/edit',  'icon' => 'settings', 'active' => false],
    ['key' => 'posts', 'label' => 'Posts', 'href' => '/admin/users/3/posts', 'icon' => null,       'active' => true],
]
```

| Detail | Behaviour |
| --- | --- |
| Key | `RelationManager::key()`, not the page key |
| Label | `RelationManager::title()` |
| Icon | `RelationManager::icon()`, and an unregistered icon simply does not render |
| Included when | `RelationManager::canViewAny($record)` and the manager is in `relationManagers()` |
| Rendered at all | only when there is more than one item — one link is not navigation |

The item disappears the moment the manager's `viewAny` is refused, and the route refuses independently, so a hidden tab is not what protects the page.

## Breadcrumbs

```text
Dashboard  ›  Users  ›  Ada Lovelace  ›  Posts
```

The record crumb links to the view page when the resource declares one and the user may open it, and is plain text otherwise — never a link that would 403. The relation's own crumb is the current one.

## Inline and on a page

Declaring a relation page does not remove the manager from the record's other pages. `ViewRecord` and `EditRecord` serialize every manager the resource declares, because where a manager appears is the page's decision. To show it only on its own page, narrow the list:

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

// On ViewUser / EditUser
protected function relationTables(Request $request, Model $record): array
{
    return array_values(array_filter(
        parent::relationTables($request, $record),
        static fn (array $relation): bool => $relation['key'] !== 'posts',
    ));
}
```

## Path collisions

Two resources cannot claim one path, and registration refuses it at boot. A `ManageRelatedRecords` page at `projects/{record}/tasks` and a nested resource at `projects/{parentRecord}/tasks` are the same shape to the router — parameter names are not part of matching — so one of them would simply be unreachable:

```text
PanelRegistrationException: The path [projects/{parentRecord}/tasks] is registered by both
[App\...\ProjectResource] and [App\...\TaskResource]. Only the first would ever match.
```

`PanelRouteRegistrar` compares normalized path shapes per panel and throws rather than letting that be discovered as a page that renders the wrong thing. Give one of them a different slug or a different `$routePath`. See [Nested resource vs relation manager](nested-vs-relation-manager.md).

## Generating one

```bash
php artisan make:panel-relation-manager posts --panel=Admin --resource=Users --page
```

`--page` writes both the manager and a `ManageRelatedRecords` page for it, under `app/Panels/Admin/Resources/Users/`. The generated page carries a reminder of the one thing that is still manual: adding the key to `pages()` and the manager to `relationManagers()`.

## Gotchas

- **A relation page is a GET only.** Its writes go to the panel's relation endpoints, which the relation payload carries; the page itself registers no POST.
- **The page key and the relation key are different things.** `pages()` chooses the URL segment; `RelationManager::key()` chooses the sub-navigation key and the payload identity. They are usually the same word, and nothing requires it.
- **A page whose manager is missing from `relationManagers()` is a 404.** Not an exception at boot — the page exists, and the check runs at request time.
- **Sub-navigation needs two items to render.** A resource with only a relation page and no view or edit page shows no tabs at all: there is nowhere else to go.
- **One manager can have at most one page.** Two pages naming the same manager would both mark the same sub-navigation key active.
- **The manager's `canViewAny()` is the page's authorization.** The resource's `canView()` on the owner is asked first, when the record is resolved.

## See also

- [Relation managers](relation-managers.md)
- [Relation tables](relation-tables.md)
- [Nested resource vs relation manager](nested-vs-relation-manager.md)
- [Related record policies](policies.md)
- [Resource pages](../resources/resource-pages.md)
- [Sub-navigation](../pages-navigation/sub-navigation.md)
- [Breadcrumbs](../pages-navigation/breadcrumbs.md)
- [Nested resources](../resources/nested-resources.md)
- [Routing](../concepts/routing.md)
