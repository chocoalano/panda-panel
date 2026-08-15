# Full Page URLs

Inertia turns every panel link into a client-side visit. Some destinations cannot survive that: a file download, a route that sets headers the SPA would discard, an application that is not this one. `fullPageUrls()` names those paths, and the navigation the server sends marks the matching links so the shell renders a plain anchor instead of an Inertia `<Link>`.

The decision is made on the server, with the link, rather than re-derived in Vue on every render.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin;

use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelProvider;

final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->path('admin')
            ->auth()
            ->fullPageUrls('/admin/exports/*', '/admin/reports/monthly');
    }
}
```

Any navigation item whose href matches one of those patterns is now sent with `fullPage: true`, and the sidebar renders it as `<a href>`. Everything else stays a client-side visit.

## The methods

```php
public function fullPageUrls(string ...$patterns): self;

/** @return list<string> */
public function getFullPageUrls(): array;

public function isFullPageUrl(string $url): bool;
```

```php
use PandaPanel\Core\Panel;

$panel = Panel::make('accumulates')
    ->fullPageUrls('/one')
    ->fullPageUrls('/two', '/three');

$panel->getFullPageUrls();   // ['/one', '/two', '/three']
```

Calls accumulate rather than replace, so a plugin can contribute a pattern without erasing the panel's. Duplicates collapse.

### Matching

Patterns are matched with `Illuminate\Support\Str::is()`, so `*` is the only wildcard and everything else is literal. An absolute URL is matched on its **path** as well as in full, so a pattern written as a path still catches a link the server generated with a host.

```php
$panel = Panel::make('patterns')->fullPageUrls('/reports/*');

$panel->isFullPageUrl('/reports/monthly');                       // true
$panel->isFullPageUrl('https://example.test/reports/monthly');   // true
$panel->isFullPageUrl('/reports');                               // false — `*` needs a segment
$panel->isFullPageUrl('/invoices/1');                            // false
```

A panel that declared no patterns short-circuits: `isFullPageUrl()` returns `false` without parsing anything.

```php
Panel::make('none')->isFullPageUrl('/anything');   // false
```

## Where the flag is applied

`PandaPanel\Support\NavigationBuilder::applyFullPage()` walks the sidebar items after authorization and active state, children included — a link's own href decides, not its parent's:

```php
$item
    ->withChildren($this->applyFullPage($panel, $item->children))
    ->withFullPage($panel->isFullPageUrl($item->href));
```

`NavigationItem::withFullPage(bool $fullPage): self` returns a new instance; the flag is serialized as `fullPage` on every item.

```php
it('marks no navigation item full page until the panel declares one', function (): void {
    expect($items->pluck('fullPage')->unique()->all())->toBe([false]);
});

it('marks only the declared paths as full page', function (): void {
    expect($items->firstWhere('label', 'Settings')['fullPage'])->toBeTrue();
});
```

The pass is skipped entirely when the panel declared no patterns, so the common case costs nothing.

## What the frontend does

```ts
export interface NavigationItem {
    label: string;
    href: string;
    icon: string | null;
    activeIcon: string | null;
    badge: string | number | null;
    active: boolean;
    sort: number;
    /** Declared by the panel: this destination needs a real browser navigation. */
    fullPage: boolean;
    children: NavigationItem[];
}
```

`PanelNavigationItem.vue` branches on it, for the item and for each child:

```vue
<a v-if="item.fullPage" :href="item.href" :aria-current="item.active ? 'page' : undefined">
    <component :is="icon" v-if="icon" />
    <span>{{ item.label }}</span>
</a>
<Link v-else :href="item.href" :prefetch="prefetch">
    <component :is="icon" v-if="icon" />
    <span>{{ item.label }}</span>
</Link>
```

A full-page destination is never prefetched: it has to leave the SPA, and prefetching it would fetch a document the client cannot use. See [Prefetching](prefetching.md).

## Building panel URLs

Everything the panel links to is built from a route name, never by string concatenation. A panel that changes its path moves every link at once, and a URL for a class that was never registered fails loudly instead of 404-ing later.

### Route names

```php
public function getRouteNamePrefix(): string;   // "panel.{id}."
public function routeName(string $name): string;
```

```php
panel('admin')->routeName('dashboard');   // 'panel.admin.dashboard'
panel('admin')->routeName('pages.settings');   // 'panel.admin.pages.settings'
```

### Pages

```php
public static function slug(): string;
public static function routePath(): string;
public static function routeName(Panel|string|null $panel = null): string;
public static function url(Panel|string|null $panel = null): string;
```

```php
use App\Panels\Admin\Pages\Settings;

Settings::routeName('admin');   // 'panel.admin.pages.settings'
Settings::url('admin');         // '/admin/settings'
Settings::url();                // same, using the panel resolved for this request
Settings::url(panel('admin'));  // same, given the object
```

`url()` returns a **relative** URL — `route(..., absolute: false)` — which is what an Inertia visit wants.

`slug()` and `routePath()` are separate: the slug is the route name and the registry key, the path is what the address bar shows. A cluster member's `routePath()` carries the cluster prefix while its route name does not.

```php
ClusteredReportPage::slug();        // 'throughput'
ClusteredReportPage::routePath();   // 'ops/throughput'
ClusteredReportPage::url($panel);   // '/cluster-host/ops/throughput'
```

### Resources

```php
public static function routeName(string $page = 'index', Panel|string|null $panel = null): string;

public static function url(
    string $page = 'index',
    Model|int|string|null $record = null,
    Panel|string|null $panel = null,
    Model|int|string|null $parent = null,
): string;
```

```php
use App\Panels\Admin\Resources\Users\UserResource;

UserResource::url();                        // '/admin/users'
UserResource::url('create');                // '/admin/users/create'
UserResource::url('view', $record);         // '/admin/users/3'
UserResource::url('edit', $record, 'admin');// '/admin/users/3/edit'
```

`url()` asserts the resource is registered in the resolved panel before building anything, and a nested resource takes its parent from the current request unless one is passed. See [URLs and routes](../resources/urls-routes.md).

### Resolving the panel

All three helpers take `Panel|string|null`:

| Argument | Behaviour |
| --- | --- |
| `Panel` | used as given |
| `string` | looked up by id; an unknown id throws `PanelRegistrationException` |
| `null` | the panel resolved for the current request; throws `noCurrentPanel()` outside one |

### The `panel()` helper

```php
panel();          // the current panel, or null outside one
panel('admin');   // that panel, or PanelRegistrationException
```

## Using a full-page URL outside navigation

`fullPageUrls()` marks *navigation items*. A link you write yourself in a Vue component is yours to render:

```vue
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <!-- Stays inside the SPA. -->
    <Link href="/admin/users">Users</Link>

    <!-- Leaves it: a download, or anything that sets its own headers. -->
    <a href="/admin/exports/users.csv">Download CSV</a>
</template>
```

A header action of type `link` is rendered by `ActionButton` as an ordinary anchor, so an action pointing at a download already behaves correctly without any pattern being declared.

## Gotchas

- **Only sidebar navigation is marked.** `NavigationBuilder` applies the flag; the cluster bar (`ClusterNavigation::for()`) and the record sub-navigation (`RecordSubNavigation::for()`) do not carry `fullPage` and always render `<Link>`. A full-page destination that must appear in one of those needs its own component.
- **`*` does not match an empty segment.** `/reports/*` matches `/reports/monthly` and not `/reports`. Declare both if you need both.
- **Patterns are matched against the href the server generated.** Panel URLs are relative, so patterns are normally written as paths beginning with the panel path — `/admin/exports/*`, not `exports/*`.
- **A full-page link reloads the whole application.** Shared props, the panel shell, and any client state are rebuilt. That is the point, and it is also the cost.
- **`url()` is relative on purpose.** Passing it to something that expects an absolute URL — a mail template, a webhook payload — needs `url()` or `route()` with `absolute: true` instead.
- **`Resource::url()` throws for an unregistered resource.** That is deliberate: a dead link that renders is worse than an exception at the line that built it.

## See also

- [Prefetching](prefetching.md)
- [Clusters](clusters.md), [Sub navigation](sub-navigation.md)
- [Custom pages](custom-pages.md)
- [URLs and routes](../resources/urls-routes.md)
- [Routing](../concepts/routing.md)
- [Navigation groups](../panels/navigation-groups.md), [Sidebar and header layouts](../panels/layouts.md)
- [Panel API reference](../panels/api.md)
- [Wayfinder](../frontend/wayfinder.md)
