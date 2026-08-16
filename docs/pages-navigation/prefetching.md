# Prefetching

Panel navigation links fetch the page they point at before it is clicked. On by default, on hover, which costs a request only for the links a pointer actually rests on and makes the following navigation instant.

Inertia is already a single-page application, so there is no SPA mode to switch on here. Prefetching is the part of Filament's `spa()` that carries over, and it is one panel setting.

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
            ->prefetch('hover');   // the default; write it to be explicit
    }
}
```

Hovering `Users` in the sidebar fetches `/admin/users`; clicking it renders from what was already fetched.

## The method

```php
/**
 * @param  bool|'hover'|'mount'|'click'  $prefetch
 */
public function prefetch(bool|string $prefetch = 'hover'): self;

/** @return 'hover'|'mount'|'click'|null */
public function getPrefetch(): ?string;
```

The argument is normalized once, on the panel:

```php
$this->prefetch = match ($prefetch) {
    false => null,
    true => 'hover',
    default => $prefetch,
};
```

| Call | `getPrefetch()` | Behaviour |
| --- | --- | --- |
| *(nothing)* | `'hover'` | The panel default |
| `prefetch()` | `'hover'` | Fetch when the pointer rests on a link |
| `prefetch(true)` | `'hover'` | Same |
| `prefetch('hover')` | `'hover'` | Same |
| `prefetch('mount')` | `'mount'` | Fetch every visible link as soon as the page renders |
| `prefetch('click')` | `'click'` | Fetch on mousedown, before the click completes |
| `prefetch(false)` | `null` | Off |

```php
use PandaPanel\Core\Panel;

Panel::make('a')->prefetch('mount')->getPrefetch();   // 'mount'
Panel::make('b')->prefetch(false)->getPrefetch();     // null
Panel::make('c')->prefetch(true)->getPrefetch();      // 'hover'
Panel::make('d')->prefetch('click')->getPrefetch();   // 'click'
```

The three modes are Inertia's own `LinkPrefetchOption` values, passed straight through to `<Link :prefetch>`.

## What crosses the wire

The mode is part of the panel's shared props, so every component in the shell reads one value:

```php
panel('admin')->toSharedArray()['prefetch'];   // 'hover'
```

```ts
/** Matches Inertia's own `LinkPrefetchOption`. */
export type PanelPrefetchMode = 'hover' | 'mount' | 'click';

export interface PanelDefinition {
    // …
    prefetch: PanelPrefetchMode | null;
}
```

`null` means off, and every consumer coerces it:

```ts
const prefetch = computed(() => panel.value?.prefetch ?? false);
```

The same fallback covers the case where no panel is present at all — the shell can render during a navigation that leaves the panel.

## Where it applies

Two components read the setting, and they are the two that render server-built navigation:

| Component | Links |
| --- | --- |
| `PanelNavigationItem.vue` | every sidebar item and every child item |
| `PanelSubNavigation.vue` | the links between one record's pages |

```vue
<Link
    :href="item.href"
    :prefetch="prefetch"
    :aria-current="item.active ? 'page' : undefined"
>
    <component :is="icon" v-if="icon" />
    <span>{{ item.label }}</span>
</Link>
```

Not applied anywhere else. `PanelClusterBar.vue` renders plain `<Link>` elements with no prefetch prop, and a link you write in your own page component is yours to configure.

## Prefetching your own links

Read the panel setting rather than hardcoding a mode, so a panel that turned prefetching off turns it off everywhere:

```vue
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { usePanel } from '@/panel/composables/usePanel';

const { panel } = usePanel();

const prefetch = computed(() => panel.value?.prefetch ?? false);
</script>

<template>
    <Link href="/admin/users" :prefetch="prefetch">Users</Link>
</template>
```

## Full-page links are never prefetched

An item the panel declared as a full-page destination renders as a plain `<a>`, with no prefetch at all — it has to leave the SPA, and prefetching it would fetch a document the client cannot use.

```php
$panel->fullPageUrls('/admin/exports/*');
```

See [Full page URLs](urls.md).

## Choosing a mode

- **`hover`** is the default because it is the cheapest useful one: one request per link the user was already considering.
- **`mount`** suits a small panel where every sidebar destination is likely to be visited, and where the extra requests at page load are affordable. On a large panel it means one request per visible navigation item, per page view.
- **`click`** buys the least, and costs the least: the fetch starts on mousedown rather than on the click event.
- **`false`** for a panel where pages are expensive to render, or where a prefetch would have side effects. A GET route in this framework never writes, but application middleware in the panel stack might — a "last seen" stamp, an audit record.

## Gotchas

- **Prefetching is per panel, not per link.** There is no per-item override in the server-side navigation model; `NavigationItem` carries no prefetch field.
- **A prefetch is a real request.** It runs the panel middleware stack, resolves the panel, authorizes, and renders. `mount` on a panel with thirty navigation items is thirty renders.
- **`prefetch(false)` is not the same as an absent panel.** Both end up as `false` on the frontend, but only the panel setting is deliberate; the composable's `?? false` is a guard for the frame of a navigation that leaves the panel.
- **The setting does not change what is cached.** Inertia owns the prefetch cache and its lifetime; the panel only chooses when the fetch starts.
- **Turning it off does not disable client-side navigation.** Links are still Inertia visits; the fetch starts on click instead of before it.

## See also

- [Full page URLs](urls.md)
- [Sub navigation](sub-navigation.md), [Clusters](clusters.md)
- [Sidebar and header layouts](../panels/layouts.md)
- [Navigation groups](../panels/navigation-groups.md)
- [Panel API reference](../panels/api.md)
- [Inertia and Vue approach](../introduction/inertia-vue.md)
- [Error notifications](error-notifications.md)
