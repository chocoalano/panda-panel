# Sub Navigation

The links that move between the pages of one record — view, edit, and any relation page the resource declares. It is built by the server from the resource's own `pages()` map, authorized per record, and shipped with the page as `page.subNavigation`. Nothing is declared twice: a resource that has no view page has no link to one.

A cluster's bar is a different thing with a similar name; see [Clusters](clusters.md).

## A minimal working example

Nothing to switch on. A resource that declares both record pages gets the bar:

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Users;

use App\Panels\Admin\Resources\Users\Pages\CreateUser;
use App\Panels\Admin\Resources\Users\Pages\EditUser;
use App\Panels\Admin\Resources\Users\Pages\ListUsers;
use App\Panels\Admin\Resources\Users\Pages\ViewUser;
use PandaPanel\Resources\Resource;

final class UserResource extends Resource
{
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
        ];
    }
}
```

Opening `/admin/users/3` now renders a tab strip reading `View | Edit`, with `View` active. Opening `/admin/users/3/edit` renders the same strip with `Edit` active.

## `RecordSubNavigation`

```php
namespace PandaPanel\Support;

/**
 * @param  class-string<PanelResource>  $resource
 * @return list<array{key: string, label: string, href: string, icon: string|null, active: bool}>
 */
public static function for(string $resource, Model $record, string $currentPage): array;
```

`$currentPage` is the page key the caller is rendering — `'view'`, `'edit'`, or a relation manager's key.

```php
use App\Panels\Admin\Resources\Users\UserResource;
use PandaPanel\Support\RecordSubNavigation;

RecordSubNavigation::for(UserResource::class, $record, 'view');
```

```php
[
    ['key' => 'view', 'label' => 'View', 'href' => '/admin/users/3',      'icon' => 'search',   'active' => true],
    ['key' => 'edit', 'label' => 'Edit', 'href' => '/admin/users/3/edit', 'icon' => 'settings', 'active' => false],
]
```

## Which items exist

Only record pages take part. A page whose route carries no `{record}` — the index, the create page — is not somewhere this record can be seen.

### The two fixed keys

A private constant inside `RecordSubNavigation` holds them, and nothing else:

| Key | Included when | Ability | Icon | Label |
| --- | --- | --- | --- | --- |
| `view` | `pages()` has a `view` key | `Resource::canView($record)` | `search` | `View` |
| `edit` | `pages()` has an `edit` key | `Resource::canEdit($record)` | `settings` | `Edit` |

Labels come from `Str::headline($key)`, so they are `View` and `Edit` and are not configurable per resource.

### Relation pages

Every `PandaPanel\Resources\Pages\ManageRelatedRecords` page in `pages()` is discovered rather than listed, because its ability depends on the relation manager it names — a fixed map could not answer for it.

```php
/**
 * @return array<string, class-string>
 */
public static function pages(): array
{
    return [
        'index' => ListUsers::class,
        'view' => ViewUser::class,
        'edit' => EditUser::class,
        'posts' => ManageUserPosts::class,   // extends ManageRelatedRecords
    ];
}
```

Three tests decide whether a relation page appears:

1. the page is a subclass of `ManageRelatedRecords`;
2. the resource declares that manager — `Resource::relationManager($manager::key()) !== null`;
3. `RelationManager::canViewAny($record)` allows it.

The item is keyed by `$manager::key()`, labelled `$manager::title()`, and iconed `$manager::icon()`. It is keyed by the relation rather than by the page key because the current page can only report which relation it is showing, not which array key `pages()` happened to use.

Authorizing through the manager rather than the resource is deliberate: whether a user may read a record's posts is the posts' policy's answer, not the record's. See [Relation pages](../relations/relation-pages.md).

### The one-link rule

```php
return count($items) > 1 ? $items : [];
```

One link is not navigation — there is nowhere else to go, and a lone tab is noise on every record page in the panel. So a resource with only a view page, or a user whose policy refuses editing, gets an empty list rather than a bar with one item.

```php
Gate::policy(User::class, ViewOnlyUserPolicy::class);

RecordSubNavigation::for(UserResource::class, $record, 'view');   // []
```

## Position

```php
namespace PandaPanel\Enums;

enum SubNavigationPosition: string
{
    case Top = 'top';
    case Start = 'start';
    case End = 'end';
}
```

`Top` reads as a tab strip; `Start` and `End` read as a rail beside the content. The three values match Filament's, so the vocabulary transfers.

Declared on the panel for everything in it:

```php
use PandaPanel\Enums\SubNavigationPosition;

$panel->subNavigationPosition(SubNavigationPosition::Start);
$panel->getSubNavigationPosition();   // SubNavigationPosition::Start
```

The panel default is `SubNavigationPosition::Top`.

Overridden per resource:

```php
use PandaPanel\Enums\SubNavigationPosition;
use PandaPanel\Resources\Resource;

final class UserResource extends Resource
{
    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::End;
}

UserResource::subNavigationPosition();   // SubNavigationPosition::End
```

`null` on the resource — the default — means "take the panel's".

## On a resource page

```php
/** @return array{items: list<array<string, mixed>>, position: string} */
protected function subNavigation(?Model $record, string $currentPage): array;

protected function subNavigationPosition(): SubNavigationPosition;
```

```php
protected function subNavigation(?Model $record, string $currentPage): array
{
    return [
        'items' => $record === null
            ? []
            : RecordSubNavigation::for(static::$resource, $record, $currentPage),
        'position' => $this->subNavigationPosition()->value,
    ];
}
```

The four built-in record pages already call it with the right key. A custom record page passes its own:

```php
use Illuminate\Database\Eloquent\Model;

/**
 * @return array<string, mixed>
 */
protected function pageMetadata(Model $record): array
{
    return [
        ...$this->headingMetadata($record),
        'breadcrumbs' => $this->serializeBreadcrumbs([/* … */]),
        'headerActions' => [],
        'scope' => static::renderHookScope(),
        'cluster' => $this->clusterNavigation(),
        'subNavigation' => $this->subNavigation($record, 'audit'),
    ];
}
```

Passing a key that is not in the list means nothing is active — `'audit'` matches no item, so the strip renders with no highlight. A custom page is not itself a sub-navigation item; see the notes below.

`subNavigationPosition()` resolves the resource's declaration first and the panel's second:

```php
return static::$resource::subNavigationPosition()
    ?? $this->panel()->getSubNavigationPosition();
```

## What crosses the wire

```php
'subNavigation' => [
    'position' => 'top',
    'items' => [
        ['key' => 'view', 'label' => 'View', 'href' => '/admin/users/3', 'icon' => 'search', 'active' => true],
        ['key' => 'edit', 'label' => 'Edit', 'href' => '/admin/users/3/edit', 'icon' => 'settings', 'active' => false],
    ],
],
```

```ts
export type SubNavigationPosition = 'top' | 'start' | 'end';

export interface SubNavigationItem {
    key: string;
    label: string;
    href: string;
    icon: string | null;
    active: boolean;
}

export interface PageSubNavigation {
    items: SubNavigationItem[];
    position: SubNavigationPosition;
}
```

The key is absent on pages that have no record — a list page, a create page, and every standalone page — and `normalizePageMetadata()` treats that as `{ items: [], position: 'top' }` rather than as a shape error. A position outside the three cases falls back to `top`.

```php
it('sends no sub-navigation on a page with no record', function (): void {
    foreach (['/admin/users', '/admin/users/create'] as $url) {
        $this->get($url)->assertInertia(fn (AssertableInertia $page) => $page->missing('page.subNavigation'));
    }
});
```

## Rendering

`resources/js/panel/components/PanelSubNavigation.vue` takes the items and the position and nothing else:

```vue
<script setup lang="ts">
import PanelSubNavigation from '@/panel/components/PanelSubNavigation.vue';
import { usePanelPage } from '@/panel/composables/usePanelPage';

const page = usePanelPage();
</script>

<template>
    <PanelSubNavigation
        v-if="page && page.subNavigation.items.length > 0"
        :items="page.subNavigation.items"
        :position="page.subNavigation.position"
    />
</template>
```

`top` draws a bordered tab strip; `start` and `end` draw a stacked column. Only the direction differs — the items and their active state are the server's either way. Each link is an Inertia `<Link>` carrying the panel's prefetch mode, so hovering a tab warms the next page. See [Prefetching](prefetching.md).

Icons are registry keys resolved through `resolveIcon()`; an unregistered key renders no icon rather than failing. See [Icons](../frontend/icons.md).

## Gotchas

- **The item list is fixed at `view`, `edit`, and relation pages.** A custom record page cannot join it: the map inside `RecordSubNavigation` holds two keys, and a relation page is found through the manager it names. A custom page has no ability the map could ask about. Link to it from a header action or a row action instead.
- **A refused page is absent, not disabled.** An unauthorized item never renders, and the route enforces the same rule independently, so removing it from the strip is a convenience rather than the control.
- **One remaining link means no bar at all.** Denying `edit` on a resource whose only other record page is `view` empties the strip entirely.
- **Labels and icons for `view` and `edit` are not configurable.** They come from `Str::headline($key)` and a private constant.
- **The active flag is server-side.** It is `$key === $currentPage`, so a page that passes the wrong key highlights nothing — there is no client-side URL matching to fall back on.
- **`href` comes from `Resource::url($key, $record)`.** A nested resource resolves its parent from the current request, so building the strip outside a request for a nested resource needs the parent bound.

## See also

- [Clusters](clusters.md)
- [Breadcrumbs](breadcrumbs.md), [Page headings](headings.md)
- [Custom pages](custom-pages.md)
- [Resource pages](../resources/resource-pages.md), [CRUD pages](../resources/crud-pages.md)
- [Relation pages](../relations/relation-pages.md), [Relation managers](../relations/relation-managers.md)
- [Resource authorization](../resources/authorization.md)
- [Prefetching](prefetching.md)
- [Icons](../frontend/icons.md)
