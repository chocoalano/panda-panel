# Breadcrumbs

The trail above a page's heading, built on the server and shipped as part of the page's metadata. Every panel screen gets one without asking: a standalone page starts from the dashboard and walks through its navigation group, a resource page walks from the dashboard through the resource index to the record. You reach for the API below when a page's trail should say something the default cannot work out.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Pages;

use PandaPanel\Pages\Page;
use PandaPanel\Support\Breadcrumb;

final class Throughput extends Page
{
    protected static ?string $title = 'Throughput';

    /**
     * @return list<Breadcrumb>
     */
    public function breadcrumbs(): array
    {
        return [
            Breadcrumb::make('Dashboard')->url($this->dashboardUrl()),
            Breadcrumb::make('Reports')->url('/admin/reports'),
            Breadcrumb::make('Throughput')->current(),
        ];
    }
}
```

The header now reads `Dashboard / Reports / Throughput`, with the first two linked and the last one plain.

## The `Breadcrumb` class

`PandaPanel\Support\Breadcrumb` is a final readonly value object. Every method returns a new instance.

```php
public function __construct(
    public string $label,
    public ?string $href = null,
    public bool $current = false,
);

public static function make(string $label): self;
public function url(?string $href): self;
public function current(bool $current = true): self;

/** @return array{label: string, href: string|null, current: bool} */
public function toArray(): array;
```

| Call | Result |
| --- | --- |
| `Breadcrumb::make('Users')` | plain text, not a link, not current |
| `Breadcrumb::make('Users')->url('/admin/users')` | a link |
| `Breadcrumb::make('Users')->current()` | the page being looked at |
| `Breadcrumb::make('Users')->url('/admin/users')->current()` | linked *and* marked current |

```php
use PandaPanel\Support\Breadcrumb;

Breadcrumb::make('Users')->url('/admin/users')->toArray();
// ['label' => 'Users', 'href' => '/admin/users', 'current' => false]

Breadcrumb::make('Ada Lovelace')->current()->toArray();
// ['label' => 'Ada Lovelace', 'href' => null, 'current' => true]

Breadcrumb::make('Users')->url(null);   // drops the link again
```

Labels are plain text. Vue renders them as text, so a label containing markup shows the markup.

## The default trail on a standalone page

```php
/** @return list<Breadcrumb> */
public function breadcrumbs(): array;
```

`PandaPanel\Pages\Page::breadcrumbs()` builds three parts, the middle one only when the page names a navigation group:

```php
$crumbs = [Breadcrumb::make('Dashboard')->url($this->dashboardUrl())];

$group = NavigationGroupName::resolve(static::$navigationGroup);

if ($group !== null) {
    $crumbs[] = Breadcrumb::make($group);
}

$crumbs[] = Breadcrumb::make(static::title())->current();
```

So the example Admin `Settings` page, which declares `$navigationGroup = 'System'`, ships:

```php
[
    ['label' => 'Dashboard', 'href' => '/admin', 'current' => false],
    ['label' => 'System',    'href' => null,     'current' => false],
    ['label' => 'Settings',  'href' => null,     'current' => true],
]
```

The group crumb has no `href` on purpose: a navigation group is a heading in the sidebar, not a page, so there is nowhere for it to lead. The group name is resolved through `PandaPanel\Support\NavigationGroupName`, so an enum-named group contributes its `value` (or its case `name` for a pure enum).

`dashboardUrl()` is `route($this->panel()->routeName('dashboard'), absolute: false)`, which is why the first crumb points at the panel root rather than at `/`.

The dashboard itself overrides the whole thing, because it is the root:

```php
public function breadcrumbs(): array
{
    return [Breadcrumb::make('Dashboard')->current()];
}
```

## The default trail on a resource page

`PandaPanel\Resources\Pages\ResourcePage` composes its trail from helpers rather than building it inline, so every page of a resource agrees about the first half.

```php
/** @return list<Breadcrumb> */
protected function baseBreadcrumbs(): array;

/** @return list<Breadcrumb> */
protected function parentBreadcrumbs(): array;

protected function recordCrumb(Model $record, string $title): Breadcrumb;

/** @return list<array{label: string, href: string|null, current: bool}> */
protected function serializeBreadcrumbs(array $crumbs): array;
```

`baseBreadcrumbs()` is dashboard → parent trail → resource index:

```php
return [
    Breadcrumb::make('Dashboard')->url($this->dashboardUrl()),
    ...$this->parentBreadcrumbs(),
    Breadcrumb::make($resource::pluralLabel())->url($resource::url()),
];
```

Each page then appends its own last crumb:

| Page | Last crumb |
| --- | --- |
| `ListRecords` | `Breadcrumb::make($resource::pluralLabel())->current()` — and no base, since the index *is* the base |
| `CreateRecord` | `Breadcrumb::make('New')->current()` |
| `ViewRecord` | `Breadcrumb::make($recordTitle)->current()` |
| `EditRecord` | the record crumb, then `Breadcrumb::make('Edit')->current()` |
| `ManageRelatedRecords` | the record crumb, then `Breadcrumb::make($manager::title())->current()` |

`parentBreadcrumbs()` returns `[]` for every resource that is not nested, which is what lets the trail be built the same way on every page. For a nested resource it contributes two crumbs — the parent's plural label linked to its index, and the parent record:

```php
Breadcrumb::make($parentResource::pluralLabel())->url($parentResource::url()),
$canView
    ? Breadcrumb::make($title)->url($parentResource::url('view', $parent))
    : Breadcrumb::make($title),
```

`recordCrumb()` follows the same rule for the record itself: a link to the view page when the resource declares one *and* `canView()` allows it, plain text otherwise. A crumb that would 403 is never rendered as a link.

### Building a custom trail on a resource page

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Resources\Pages\ResourcePage;
use PandaPanel\Support\Breadcrumb;

/**
 * @return array<string, mixed>
 */
protected function pageMetadata(Model $record): array
{
    return [
        ...$this->headingMetadata($record),
        'breadcrumbs' => $this->serializeBreadcrumbs([
            ...$this->baseBreadcrumbs(),
            $this->recordCrumb($record, $this->recordTitle($record)),
            Breadcrumb::make('Audit')->current(),
        ]),
        'headerActions' => [],
        'scope' => static::renderHookScope(),
        'cluster' => $this->clusterNavigation(),
        'subNavigation' => $this->subNavigation($record, 'audit'),
    ];
}
```

`serializeBreadcrumbs()` is the only step that turns objects into arrays; a standalone `Page` does that inside `metadata()` instead, so `breadcrumbs()` there returns objects.

## Turning them off

```php
$panel->breadcrumbs(false);
public function hasBreadcrumbs(): bool;
```

This removes the trail from the shell rather than hiding it — a kiosk or a single-page panel has no use for one. The pages still compute their crumbs; the topbar does not draw them.

See [Sidebar and header layouts](../panels/layouts.md).

## On the frontend

```ts
export interface PanelBreadcrumbItem {
    label: string;
    href: string | null;
    current: boolean;
}
```

`PanelLayout` takes the trail from the page's own metadata by default, so a page never wires it up. An explicit prop wins, for the rare page that builds its trail client-side:

```vue
<script setup lang="ts">
import PanelLayout from '@/panel/layouts/PanelLayout.vue';
import type { PanelBreadcrumbItem } from '@/panel/types/breadcrumb';

const breadcrumbs: PanelBreadcrumbItem[] = [
    { label: 'Dashboard', href: '/admin', current: false },
    { label: 'Live', href: null, current: true },
];
</script>

<template>
    <PanelLayout :breadcrumbs="breadcrumbs">
        <slot />
    </PanelLayout>
</template>
```

`PanelBreadcrumb.vue` renders each item as a `BreadcrumbLink` wrapping an Inertia `<Link>`, except where `current` is true **or** `href` is null — both of which render as `BreadcrumbPage`, plain text. Separators go between items and never after the last. An empty array renders nothing at all, not an empty bar.

`normalizePageMetadata()` validates each crumb as it crosses: an entry without a string `label` is dropped, and a non-string `href` becomes `null`. A malformed trail degrades to a shorter one rather than throwing inside the layout.

## Gotchas

- **Crumbs are objects on a `Page` and arrays on a `ResourcePage`.** `Page::breadcrumbs()` returns `list<Breadcrumb>` and `metadata()` serializes them; a resource page serializes inside `pageMetadata()` with `serializeBreadcrumbs()`. Returning arrays from `Page::breadcrumbs()` fails when `toArray()` is called on them.
- **`current()` does not imply the last position.** It is a flag, not an index. Marking two crumbs current renders two plain-text crumbs.
- **A crumb with no `href` renders as text even without `current`.** That is how the navigation-group crumb works, and it is the right shape for any step that is not a page.
- **Labels are never HTML.** Interpolating a record's name is fine; interpolating markup is not.
- **`dashboardUrl()` needs a current panel.** A page instantiated outside a panel request throws `PanelRegistrationException::noCurrentPanel()` from `panel()` before it can build a trail.
- **Turning breadcrumbs off does not save the work.** The crumbs are still computed and still shipped; only the rendering stops.

## See also

- [Custom pages](custom-pages.md)
- [Page headings](headings.md)
- [Sub navigation](sub-navigation.md)
- [Clusters](clusters.md)
- [Resource pages](../resources/resource-pages.md), [Nested resources](../resources/nested-resources.md)
- [Sidebar and header layouts](../panels/layouts.md)
- [Navigation groups](../panels/navigation-groups.md)
- [Server metadata to Vue](../concepts/metadata-to-vue.md)
