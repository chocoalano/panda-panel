# CSS Hooks

Named places in the panel shell that carry a stable class, and that a panel may add its own classes to. Reach for this when two panels share one build and have to look different, or when you want a stylesheet to target a part of the panel without shipping a replacement component for it.

Hooks are *meanings* — a hook name is a place in the layout. That is why the set of names is closed, and why it is a different mechanism from [`colors()`](tailwind-theme.md#panel-colours), which sets *values*.

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
            ->cssHooks([
                'topbar' => 'border-b-2 border-amber-500',
                'table-row' => 'hover:bg-amber-50',
            ]);
    }
}
```

```bash
npm run build
```

The topbar now renders with `class="panel-topbar border-b-2 border-amber-500"`, and every table row with `class="… panel-table-row hover:bg-amber-50"`. Nothing else changed, and no component was replaced.

## The stable classes come first

Every part the framework renders already carries a `panel-{name}` class, written into the Vue component rather than generated. It is there whether or not a panel says anything, which is what makes it a reliable target for a stylesheet:

```css
/* resources/css/panels/admin.css */
.panel-topbar {
    backdrop-filter: blur(6px);
}

.panel-table-row:nth-child(even) {
    background-color: var(--muted);
}
```

`cssHooks()` adds to that class rather than replacing it. A panel that declares nothing still emits `panel-topbar`.

## The hook names

All of `PandaPanel\Support\CssHooks::HOOKS`, and the component that emits each:

| Hook | Emitted by | Element |
| --- | --- | --- |
| `shell` | `SidebarPanelLayout.vue`, `HeaderPanelLayout.vue` | the shell root, which also carries the theme custom properties |
| `sidebar` | `PanelSidebar.vue` | the rail |
| `topbar` | `PanelHeader.vue` | the header row |
| `page` | both layouts | the content column |
| `page-header` | `PageHeader.vue` | the heading block |
| `table` | `DataTable.vue` | the table wrapper |
| `table-row` | `DataTable.vue` | every body row |
| `form` | `FormRenderer.vue` | the form element |
| `infolist` | `InfolistRenderer.vue` | the infolist wrapper |
| `widget` | `WidgetShell.vue` | every widget, including custom ones |
| `modal` | `ActionModal.vue` | the action dialog |

A name outside that list is dropped. That is deliberate: a class registered against a part the shell does not render would silently do nothing, and finding out why is a bad afternoon.

`StylingTest` asserts that every name in the allowlist is actually emitted by some component, so adding a name without wiring it up fails the suite.

## The PHP API

### `Panel::cssHooks()`

```php
/**
 * @param  array<string, string>  $classes  keyed by hook name
 */
public function cssHooks(array $classes): self
```

```php
use PandaPanel\Core\Panel;

Panel::make('admin')->cssHooks([
    'shell' => 'font-mono',
    'widget' => 'shadow-sm',
]);
```

Calls accumulate rather than replace, because two calls that both target the topbar both meant it:

```php
$panel
    ->cssHooks(['topbar' => 'border-amber-500'])
    ->cssHooks(['topbar' => 'border-b-2']);

$panel->getCssHooks();
// ['topbar' => 'border-amber-500 border-b-2']
```

That is also what lets a plugin contribute a class without displacing the panel's own.

### `Panel::getCssHooks()`

```php
/**
 * @return array<string, string>
 */
public function getCssHooks(): array
```

```php
Panel::make('plain')->getCssHooks();   // []
```

Always present in `toSharedArray()` under `cssHooks`, as `[]` for a panel that declared nothing — the frontend reads it unconditionally.

### `PandaPanel\Support\CssHooks`

The class behind the panel method. You rarely construct one, but it is the definition of the allowlist:

```php
use PandaPanel\Support\CssHooks;

CssHooks::HOOKS;
// ['shell', 'sidebar', 'topbar', 'page', 'page-header', 'table',
//  'table-row', 'form', 'infolist', 'widget', 'modal']

$hooks = new CssHooks;

$hooks->add(['topbar' => 'border-b-2', 'nonsense' => 'dropped']);
$hooks->add(['topbar' => 'border-amber-500']);

$hooks->toArray();
// ['topbar' => 'border-b-2 border-amber-500']
```

| Member | Signature | Notes |
| --- | --- | --- |
| `HOOKS` | `public const HOOKS` | `list<string>` — the closed allowlist |
| `add` | `add(array $classes): self` | keyed by hook name; unknown names dropped, known ones appended |
| `toArray` | `toArray(): array` | `array<string, string>`, only the hooks that have classes |

## The Vue side

One composable reads both the theme and the hooks from the shared panel props:

```ts
import { usePanelStyling } from '@/panel/composables/usePanelStyling';

const { themeStyle, hook } = usePanelStyling();
```

| Member | Type | What it is |
| --- | --- | --- |
| `themeStyle` | `ComputedRef<Record<string, string>>` | the panel's light palette as inline custom properties |
| `hook` | `(name: string) => string` | the classes for one named part |

```ts
hook('sidebar');   // 'panel-sidebar' when the panel added nothing
hook('topbar');    // 'panel-topbar border-b-2 border-amber-500'
```

Applied the way every shipped component applies it:

```vue
<script setup lang="ts">
import { usePanelStyling } from '@/panel/composables/usePanelStyling';

const { hook } = usePanelStyling();
</script>

<template>
    <div class="rounded-lg border p-4" :class="hook('widget')">
        <slot />
    </div>
</template>
```

Use it in your own components — a [custom shell](custom-shell.md) replacement, a [custom page](custom-pages.md) — so a panel's hooks reach what you drew as well as what the framework did. `hook()` accepts any string and returns `panel-{name}` plus whatever the panel registered for that name, so a replacement sidebar calling `hook('sidebar')` behaves exactly like the built-in one.

## Making the classes survive the build

This is the one part that catches people out. Tailwind v4 scans source files for class names, and a string written in a PHP panel provider is not in any file it scans. The class ends up in the DOM and not in the bundle.

Three ways out, in order of preference:

**Use classes that already appear in the application's own templates.** `hover:bg-muted`, `border-b-2`, `shadow-sm` are almost certainly already compiled.

**Add the provider to Tailwind's sources.** `@source` in the stylesheet takes a path relative to the file:

```css
/* resources/css/app.css */
@import 'tailwindcss';

@source '../../app/Panels';
```

**Write plain CSS against the stable class instead.** No compiler involved, and no risk of a class disappearing when somebody deletes an unrelated template:

```css
.panel-topbar {
    border-bottom: 2px solid #f59e0b;
}
```

The third is what a per-panel stylesheet is for. Declare one with `assets()`, and it loads on that panel's pages and nowhere else:

```php
$panel->assets('resources/css/panels/admin.css');
```

See [Panel Assets](../panels/assets.md) — it is two edits, because the path must also be in `vite.config.ts`'s `input`.

## Hooks against a theme

The two mechanisms are complementary, and the split is worth stating once more:

| | `colors()` | `cssHooks()` |
| --- | --- | --- |
| What it sets | CSS custom property values | class names |
| Name set | open, validated against `PanelTheme::PROPERTIES` | closed, `CssHooks::HOOKS` |
| Where it lands | `style` attribute on the shell root | `class` attribute on one named part |
| Needs a Tailwind rebuild | no | yes, unless the class already exists |
| Invalid input | dropped silently | dropped silently |

A colour is a value, and a value never becomes a class — which is why the set is open. A hook is a place, and a place nothing renders is a class you can set and never see — which is why the set is closed.

## Gotchas

- **A class that Tailwind never compiled is invisible and silent.** The DOM shows it, the page does not change, and nothing is logged. Check the built CSS before assuming the hook did not fire.
- **`page` is emitted by both layouts** — the sidebar shell and the header shell — so a class set there applies whichever variant the panel uses.
- **`table-row` is on every body row, including a relation manager's and a table widget's.** They all render through `DataTable.vue`.
- **`shell` is also where the theme lands.** The same element carries `style="--primary: …"`, so a hook class there can rely on the panel's custom properties being in scope.
- **An unknown hook name is dropped without a word.** There is no warning, because the allowlist is what the guarantee rests on. If a hook seems to do nothing, check the spelling against `CssHooks::HOOKS` first.
- **Hooks are per panel, not per page.** They come from the shared panel props, so they apply to every page of the panel and to none outside it. For something page-specific, use a [render hook](../panels/render-hooks.md) or draw it in the page's own component.

## See also

- [Tailwind Theme](tailwind-theme.md)
- [Vue Component Tree](component-tree.md)
- [Custom Shell Components](custom-shell.md)
- [Custom Page Components](custom-pages.md)
- [Branding, Logo, Icon, Favicon](../panels/branding.md)
- [Panel Assets](../panels/assets.md)
- [Sidebar and Header Layouts](../panels/layouts.md)
- [Render Hooks](../panels/render-hooks.md)
- [Tailwind troubleshooting](../troubleshooting/tailwind.md)
