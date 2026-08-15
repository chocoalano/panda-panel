# Render Hooks

Eight named points in the panel shell where a panel can inject a Vue component
of its own — an announcement bar above every page, a support link in the
sidebar, a status pill in the header. You reach for a render hook when
something belongs on *every* page of a panel (or every page of one resource)
and does not belong inside any single page component.

## A minimal working example

Register the hook on the panel:

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin;

use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelProvider;
use PandaPanel\Enums\RenderHook;

final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->path('admin')
            ->auth()
            ->renderHook(
                RenderHook::PageStart,
                'Panels/Admin/Hooks/Announcement',
                ['message' => 'Maintenance at 5pm'],
            );
    }
}
```

Write the component at the path the name spells out:

```vue
<!-- resources/js/pages/Panels/Admin/Hooks/Announcement.vue -->
<script setup lang="ts">
defineProps<{ message: string }>();
</script>

<template>
    <div class="rounded-md border border-amber-500/40 px-4 py-2 text-sm">
        {{ message }}
    </div>
</template>
```

Every page of the Admin panel now opens with that bar. Nothing else is wired
up: the panel ships the hook in its shared props and the shell renders it.

## The signature

```php
public function renderHook(
    RenderHook $hook,
    string $component,
    array $data = [],
    array $scopes = [],
): self
```

| Parameter | Type | Default | Meaning |
| --- | --- | --- | --- |
| `$hook` | `PandaPanel\Enums\RenderHook` | — | Which point in the shell |
| `$component` | `string` | — | A build-time registry key under `resources/js/pages/` |
| `$data` | `array<string, mixed>` | `[]` | Props, `v-bind`ed onto the component |
| `$scopes` | `list<class-string\|string>` | `[]` | Which pages it renders on; empty means all |

The enum is closed on purpose. Filament injects Blade at these points; nothing
renderable can cross the wire here, so a hook names a component the frontend
already holds and carries serializable props instead. A free string would let a
hook be registered against a name the shell does not render, which would do
nothing and say nothing about why.

## The eight points

| Case | Value | Rendered by | Position |
| --- | --- | --- | --- |
| `RenderHook::BodyStart` | `body.start` | `SidebarPanelLayout.vue`, `HeaderPanelLayout.vue` | First child of the shell, before the sidebar or top bar |
| `RenderHook::BodyEnd` | `body.end` | the same two layouts | Last child of the shell, before the toaster |
| `RenderHook::SidebarStart` | `sidebar.start` | `PanelSidebar.vue` | Inside the rail, above the navigation |
| `RenderHook::SidebarEnd` | `sidebar.end` | `PanelSidebar.vue` | Inside the rail, below the navigation |
| `RenderHook::HeaderStart` | `header.start` | `PanelHeader.vue` | After the sidebar trigger, before the breadcrumbs |
| `RenderHook::HeaderEnd` | `header.end` | `PanelHeader.vue` | Start of the right-hand cluster, before search and the bell |
| `RenderHook::PageStart` | `page.start` | both layouts | Top of the content column, above the page |
| `RenderHook::PageEnd` | `page.end` | both layouts | Bottom of the content column, below the page |

`body.*` and `page.*` are rendered by whichever layout the panel's
`sidebar(variant:)` selected, so they work in both shells. `sidebar.*` exist
only where a rail does, and `header.*` only while the panel keeps its top bar —
see [Notes](#notes).

## Where the component lives

`$component` is a key in a build-time registry, resolved by
`resources/js/panel/hooks/registry.ts`:

```ts
const modules = import.meta.glob<{ default: Component }>(
    '../../pages/Panels/**/Hooks/*.vue',
);
```

The key is the file's path under `resources/js/pages/`, without the extension.

| File | Key |
| --- | --- |
| `resources/js/pages/Panels/Admin/Hooks/Announcement.vue` | `Panels/Admin/Hooks/Announcement` |
| `resources/js/pages/Panels/App/Hooks/SupportLink.vue` | `Panels/App/Hooks/SupportLink` |

The glob is an allowlist by design: a component the build never saw cannot be
reached however its name arrives. Two functions are exported for anything that
needs to ask:

```ts
import { hasHookComponent, resolveHookComponent } from '@/panel/hooks/registry';

hasHookComponent('Panels/Admin/Hooks/Announcement');      // boolean
resolveHookComponent('Panels/Admin/Hooks/Announcement');  // loader, or null
```

An unknown name resolves to `null` and `PanelRenderHook.vue` renders nothing.
A decorative injection must not be able to break the page it decorates, so a
typo costs you the decoration and nothing else. The components are loaded with
`defineAsyncComponent`, so a hook that is registered but never in scope costs no
bundle on the pages it does not appear on.

## Props

`$data` is spread onto the component with `v-bind`. It must survive JSON: it is
part of the panel's shared props, which means it is built once per request and
is visible in the page payload.

```php
use PandaPanel\Enums\RenderHook;

$panel->renderHook(RenderHook::SidebarEnd, 'Panels/Admin/Hooks/SupportLink', [
    'label' => 'Contact support',
    'url' => 'https://support.example.com',
]);
```

```vue
<!-- resources/js/pages/Panels/Admin/Hooks/SupportLink.vue -->
<script setup lang="ts">
defineProps<{ label: string; url: string }>();
</script>

<template>
    <a :href="url" class="px-2 py-1 text-sm text-muted-foreground">{{ label }}</a>
</template>
```

Values are fixed when the panel is configured, which happens during provider
boot — before there is a request, a user, or a URL. A hook that needs any of
those reads them in the component, from the props the panel already shares:

```vue
<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>
```

## More than one hook at a point

Hooks accumulate. Registration order is the render order:

```php
$panel
    ->renderHook(RenderHook::PageStart, 'Panels/Admin/Hooks/First')
    ->renderHook(RenderHook::PageStart, 'Panels/Admin/Hooks/Second');

array_column($panel->getRenderHooks()['page.start'], 'component');
// ['Panels/Admin/Hooks/First', 'Panels/Admin/Hooks/Second']
```

That is what lets a plugin add to a point the application already uses without
displacing what is there.

## Scoping a hook to some pages

`$scopes` narrows a hook to particular pages. Pass resource or page classes and
they are reduced to slugs at registration, so no class name is ever serialized:

```php
use App\Panels\Admin\Pages\Settings;
use App\Panels\Admin\Resources\Users\UserResource;
use PandaPanel\Enums\RenderHook;
use PandaPanel\Pages\Settings\ProfileSettings;

$panel->renderHook(
    RenderHook::PageEnd,
    'Panels/Admin/Hooks/Note',
    scopes: [UserResource::class, Settings::class, ProfileSettings::class],
);

$panel->getRenderHooks()['page.end'][0]['scopes'];
// ['resource:users', 'page:settings', 'page:settings-profile']
```

| Passed | Becomes |
| --- | --- |
| A `PandaPanel\Resources\Resource` subclass | `resource:{slug}` |
| A `PandaPanel\Pages\Page` subclass | `page:{slug}` |
| Anything else | taken as already being a scope, unchanged |

So a literal works too, which is the escape hatch when the class is not
importable from the provider:

```php
$panel->renderHook(RenderHook::PageEnd, 'Panels/Admin/Hooks/Note', scopes: ['page:custom']);
```

Every page reports the scope it answers to in its metadata, under `page.scope`:

| Page | `renderHookScope()` | Example |
| --- | --- | --- |
| `PandaPanel\Pages\Page` | `'page:'.static::slug()` | `page:settings` |
| `PandaPanel\Resources\Pages\ResourcePage` | `'resource:'.static::$resource::slug()` | `resource:users` |

A resource's list, create, view, edit and relation pages all report the same
scope, so scoping to `UserResource::class` covers the whole resource rather
than one screen of it. An empty scope list means every page in the panel.

The filtering happens in Vue, in `PanelRenderHook.vue`, and that is forced
rather than chosen: shared props are built in middleware, before the request
reaches a page, so the shell knows which page it is rendering and the
middleware does not.

## Reading them back

```php
/** @return array<string, list<array{component: string, data: array<string, mixed>, scopes: list<string>}>> */
public function getRenderHooks(): array
```

Keyed by the hook's string value, in registration order:

```php
$panel->getRenderHooks();
// [
//     'page.start' => [
//         [
//             'component' => 'Panels/Admin/Hooks/Announcement',
//             'data' => ['message' => 'Maintenance at 5pm'],
//             'scopes' => [],
//         ],
//     ],
// ]
```

The same array is shipped to the frontend as `panel.renderHooks` by
`Panel::toSharedArray()`, and it is an empty map — not a map of eight empty
lists — for a panel that registered none.

## From a plugin

A plugin configures a panel through the panel's own public API, so registering
a hook from one is the same call:

```php
<?php

declare(strict_types=1);

namespace App\Panels\Plugins;

use PandaPanel\Core\Panel;
use PandaPanel\Enums\RenderHook;
use PandaPanel\Plugins\Plugin;

final class AnnouncementPlugin extends Plugin
{
    public function register(Panel $panel): void
    {
        $panel->renderHook(
            RenderHook::BodyStart,
            'Panels/Admin/Hooks/Announcement',
            ['message' => 'Maintenance at 5pm'],
        );
    }
}
```

```php
$panel->plugins([new AnnouncementPlugin]);
```

A plugin shipped as a package must publish its component into the application's
`resources/js/pages/` tree — the registry is an `import.meta.glob` over that
tree, so a component that stays in the package cannot be resolved. See
[Plugin Assets](../plugins/assets.md).

## Rendering hooks in a replacement shell

A panel that replaces the sidebar or top bar draws its own hook points. The
component takes one prop, typed to the closed set of names:

```vue
<!-- resources/js/pages/Panels/Admin/Shell/Sidebar.vue -->
<script setup lang="ts">
import PanelRenderHook from '@/panel/components/PanelRenderHook.vue';
import { useNavigation } from '@/panel/composables/useNavigation';

const { groups } = useNavigation();
</script>

<template>
    <aside>
        <PanelRenderHook name="sidebar.start" />

        <nav v-for="group in groups" :key="group.label ?? ''">
            <!-- your navigation -->
        </nav>

        <PanelRenderHook name="sidebar.end" />
    </aside>
</template>
```

`PanelRenderHookName` in `resources/js/panel/types/panel.ts` mirrors the PHP
enum, so a name the shell does not know fails the type check rather than
rendering nothing.

## Notes

- **A hook renders only where its host renders.** `sidebar.start` and
  `sidebar.end` live in `PanelSidebar.vue`, so they are absent from a panel
  using `topNavigation()` (or `sidebar(variant: 'header')`), absent from one
  that called `navigation(false)`, and absent from one whose
  `sidebarComponent()` replacement does not draw them. `header.start` and
  `header.end` live in `PanelHeader.vue`, which both shells skip when a panel
  calls `topbar(false)`.
- **An unknown component name is silent.** Nothing is thrown, nothing is
  logged, and the page renders without it. When a hook does not appear, check
  the key against the file path first.
- **The registry is a glob over your application's tree.** A newly added
  `Hooks/*.vue` file is picked up by the Vite dev server, but a production
  build has to be re-run before the name resolves.
- **A hook is not run through the icon or action registries.** It is a plain
  component with plain props; anything it needs to do it does itself.
- **Class-based scopes use the resource's own default slug.** Scopes are
  reduced during provider boot, when there is no current panel to ask, while a
  page reports the slug the panel gave it. A resource re-slugged for one panel
  with `ResourceConfiguration::slug()` therefore needs the literal scope —
  `'resource:people'` — rather than the class.
- **A page with no scope matches unscoped hooks only.** `page.scope` is
  validated on the way in; when it is missing, scoped hooks are filtered out
  rather than shown everywhere.
- **`panel:cache` does not cache hooks.** The manifest caches discovered
  resource, page and widget classes. Panel configuration is rebuilt from the
  provider on every boot, so a changed hook takes effect without clearing
  anything.
- The behaviour above is pinned by `tests/Feature/Panel/RenderHookTest.php`.

## See also

- [Sidebar and Header Layouts](layouts.md)
- [Custom Shell Components](../frontend/custom-shell.md)
- [CSS Hooks](../frontend/css-hooks.md)
- [Component Registries](../concepts/component-registries.md)
- [Server Metadata to Vue](../concepts/metadata-to-vue.md)
- [Custom Pages](../pages-navigation/custom-pages.md)
- [Plugin Concepts](../plugins/concepts.md)
- [Panel API Reference](api.md)
