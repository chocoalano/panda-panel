# Custom Shell Components

A panel may replace the shell's own sidebar or top bar with a component of your own. Reach for this when the navigation has to be drawn differently — a two-level rail, a tenant picker built into the brand block, a bar with a product switcher — and a [render hook](../panels/render-hooks.md) is not enough because you need to replace the thing rather than add to it.

A replacement is handed the same navigation the built-in one gets, so it is a different *drawing* of the panel rather than a second source of truth about it.

## A minimal working example

```php
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelProvider;

final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->path('admin')
            ->auth()
            ->sidebarComponent('Panels/Admin/Shell/Sidebar');
    }
}
```

```vue
<!-- resources/js/pages/Panels/Admin/Shell/Sidebar.vue -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useNavigation } from '@/panel/composables/useNavigation';
import { usePanel } from '@/panel/composables/usePanel';
import { usePanelStyling } from '@/panel/composables/usePanelStyling';
import { resolveIcon } from '@/panel/icons/registry';

const { panel } = usePanel();
const { groups } = useNavigation();
const { hook } = usePanelStyling();
</script>

<template>
    <aside
        class="w-64 shrink-0 border-r bg-sidebar text-sidebar-foreground"
        :class="hook('sidebar')"
    >
        <Link :href="`/${panel?.path}`" class="block p-4 font-semibold">
            {{ panel?.brandName }}
        </Link>

        <nav class="flex flex-col gap-4 p-2">
            <div v-for="group in groups" :key="group.label ?? 'root'">
                <p
                    v-if="group.label"
                    class="px-2 py-1 text-xs text-sidebar-foreground/60"
                >
                    {{ group.label }}
                </p>

                <Link
                    v-for="item in group.items"
                    :key="item.href"
                    :href="item.href"
                    class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm"
                    :class="item.active ? 'bg-sidebar-accent font-medium' : ''"
                >
                    <component
                        :is="resolveIcon(item.icon)"
                        v-if="resolveIcon(item.icon)"
                        class="size-4"
                    />
                    {{ item.label }}
                </Link>
            </div>
        </nav>
    </aside>
</template>
```

```bash
npm run build     # or: npm run dev
```

## The two methods

```php
public function sidebarComponent(?string $component): self
public function topbarComponent(?string $component): self
```

```php
$panel
    ->sidebarComponent('Panels/Admin/Shell/Sidebar')
    ->topbarComponent('Panels/Admin/Shell/Topbar');

$panel->sidebarComponent(null);   // back to the built-in rail
```

| Method | Read by | Serialized as | Default |
| --- | --- | --- | --- |
| `sidebarComponent` | `SidebarPanelLayout.vue` | `panel.sidebar.component` | `null` |
| `topbarComponent` | `HeaderPanelLayout.vue` | `panel.shell.topbarComponent` | `null` |

Both take a **build-time registry key**: the path below `resources/js/pages/`, without the `.vue` extension. Never markup, never a filesystem path, never a class.

Read them back through the panel's own accessors:

```php
$panel->getSidebar();
// ['collapsible' => true, 'defaultOpen' => true, 'variant' => 'sidebar',
//  'appearance' => 'inset', 'width' => '16rem', 'collapsedWidth' => '3rem',
//  'component' => 'Panels/Admin/Shell/Sidebar']

$panel->getShell();
// ['navigation' => true, 'topbar' => true, 'breadcrumbs' => true,
//  'topbarComponent' => null, 'userMenuItems' => []]
```

## Which shell honours which

The panel's `sidebar(variant: …)` decides which layout renders, and each layout honours only its own replacement:

| Panel setting | Layout | `sidebarComponent` | `topbarComponent` |
| --- | --- | --- | --- |
| `sidebar(variant: 'sidebar')`, the default | `SidebarPanelLayout` | replaces the rail | not read |
| `sidebar(variant: 'header')` / `topNavigation()` | `HeaderPanelLayout` | not read | replaces the navigation bar |

Setting the one the current variant does not read is not an error and not a warning — it is simply never resolved. If a replacement is not appearing, check the variant first.

Note also which bar `topbarComponent` replaces. `HeaderPanelLayout` draws two rows: the navigation bar at the top, and `PanelHeader` (breadcrumbs, search, switchers, notifications, theme toggle) below it. `topbarComponent` replaces the **navigation bar**. `PanelHeader` is governed by `topbar(false)`, which removes it entirely.

## What a replacement receives

| Replacement | Props |
| --- | --- |
| Sidebar | none |
| Topbar | `groups: NavigationGroup[]` |

```vue
<!-- resources/js/pages/Panels/Admin/Shell/Topbar.vue -->
<script setup lang="ts">
import type { NavigationGroup } from '@/panel/types/navigation';

defineProps<{ groups: NavigationGroup[] }>();
</script>
```

The sidebar gets nothing because it does not need to: everything the built-in rail draws is on the shared props, and a composable is a better seam than a prop list that has to grow every time the shell learns something.

```ts
import { useNavigation } from '@/panel/composables/useNavigation';
import { usePanel } from '@/panel/composables/usePanel';
import { usePanelShell } from '@/panel/composables/usePanelShell';
import { usePanelStyling } from '@/panel/composables/usePanelStyling';

const { groups, items, activeItem, isCollapsed, toggle } = useNavigation();
const { panel, shell, panels, canSwitchPanels, notifications, search, tenancy } =
    usePanel();
const { reloadNavigation } = usePanelShell();
const { hook } = usePanelStyling();
```

| Composable | Gives a replacement |
| --- | --- |
| `useNavigation()` | the groups, the flattened items, the active item, and per-group collapse state persisted under `panel:{id}:collapsed-groups` |
| `usePanel()` | brand name, path, icon, sidebar widths and appearance, shell flags, panel and tenant switchers' data, the notification counts |
| `usePanelStyling()` | `hook('sidebar')` / `hook('topbar')` so the panel's own `cssHooks()` still reach your component |
| `usePanelShell()` | `reloadNavigation()` after something that changes what the navigation says |

## Reusing the shipped pieces

A replacement rarely needs to redraw everything. Every panel component is importable:

```vue
<script setup lang="ts">
import PanelNavigation from '@/panel/components/PanelNavigation.vue';
import PanelNotifications from '@/panel/components/PanelNotifications.vue';
import PanelRenderHook from '@/panel/components/PanelRenderHook.vue';
import PanelSearch from '@/panel/components/PanelSearch.vue';
import PanelSwitcher from '@/panel/components/PanelSwitcher.vue';
import PanelTenantSwitcher from '@/panel/components/PanelTenantSwitcher.vue';
import { usePanelStyling } from '@/panel/composables/usePanelStyling';

const { hook } = usePanelStyling();
</script>

<template>
    <aside class="w-72 border-r" :class="hook('sidebar')">
        <div class="p-3"><PanelSearch /></div>

        <PanelRenderHook name="sidebar.start" />
        <PanelNavigation />
        <PanelRenderHook name="sidebar.end" />

        <div class="flex items-center gap-1 p-3">
            <PanelTenantSwitcher />
            <PanelSwitcher />
            <PanelNotifications />
        </div>
    </aside>
</template>
```

`PanelNavigation` takes no props and reads the navigation itself, so a replacement that only wants a different frame around the same links is a few lines.

Note that render hooks are not automatic. `sidebar.start` and `sidebar.end` are emitted by `PanelSidebar.vue`; a replacement that does not place `PanelRenderHook` does not render them. The same is true of `header.start` and `header.end`, which belong to `PanelHeader.vue`.

## Where the file must live

```text
resources/js/pages/Panels/**/Shell/*.vue
```

```ts
import { resolveShellComponent } from '@/panel/shell/registry';

resolveShellComponent('Panels/Admin/Shell/Sidebar');
// () => Promise<{ default: Component }>   — or null
```

| Function | Signature |
| --- | --- |
| `resolveShellComponent` | `(name: string) => (() => Promise<{ default: Component }>) \| null` |

There is no `hasShellComponent()`: the layouts call the resolver and keep their built-in bar when it answers `null`.

The pattern ends in `*.vue`, so only direct children of a `Shell/` directory are registered. `Shell/Parts/Rail.vue` is not.

## When a name does not resolve

The shell keeps its built-in bar. That is the one place in the frontend where the fallback is not a neutral placeholder, and the reason is specific: a mistyped component must not be able to strand somebody on a page they cannot leave.

There is no console warning from this registry. If a replacement is not appearing:

1. check the panel's `sidebar(variant: …)` against the table above;
2. check the spelling and the case of the registry key against the file path;
3. check the file is a direct child of a `Shell/` directory under `resources/js/pages/Panels/`;
4. rebuild — the glob is evaluated at build time.

## The lighter alternatives

Replacing a bar is the largest of four ways to change the shell, and usually not the one you want:

| Want | Reach for |
| --- | --- |
| Different colours | [`colors()`](tailwind-theme.md) |
| Extra classes on a part | [`cssHooks()`](css-hooks.md) |
| Something *added* at a named point | [`renderHook()`](../panels/render-hooks.md) |
| A different rail or bar entirely | `sidebarComponent()` / `topbarComponent()` |

A render hook keeps every guarantee the built-in bar makes — navigation, notifications, the switchers, the collapse state — and adds your component beside them. A replacement takes all of that on.

## Gotchas

- **A replacement is not authorization.** The navigation you are handed has already been filtered to what the user may see; drawing it differently changes nothing about what is reachable. A link you invent is a link the route still authorizes for itself.
- **Render hooks live in the components you replaced.** Place `PanelRenderHook` yourself, or a panel's registered hooks silently stop rendering.
- **`navigation(false)` and the two shells behave differently.** The sidebar shell renders no rail at all when navigation is off, replacement or not. The header shell checks the replacement first, so a `topbarComponent` still renders and only the *built-in* bar is removed.
- **The sidebar widths are custom properties, not classes.** `panel.sidebar.width` and `panel.sidebar.collapsedWidth` are CSS lengths; apply them with `:style`, because a class built by interpolation would not exist in the bundle.
- **`panel` can be null.** The shell renders during a navigation that leaves the panel, so every read must tolerate it — which is why the examples use `panel?.brandName`.
- **The account menu is the host application's.** `panel.shell.userMenuItems` is serialized for you to render; nothing shipped with the package draws those entries, because `UserMenuContent.vue` belongs to the starter kit. See [Host Modules](host-modules.md).
- **A new file needs a rebuild.** `import.meta.glob` is a build-time allowlist.

## See also

- [Sidebar and Header Layouts](../panels/layouts.md)
- [Render Hooks](../panels/render-hooks.md)
- [Navigation Groups](../panels/navigation-groups.md), [Panel Switcher](../panels/panel-switcher.md)
- [CSS Hooks](css-hooks.md), [Tailwind Theme](tailwind-theme.md)
- [Vue Component Tree](component-tree.md)
- [Custom Page Components](custom-pages.md)
- [Component Registries](../concepts/component-registries.md)
- [Host Modules](host-modules.md)
