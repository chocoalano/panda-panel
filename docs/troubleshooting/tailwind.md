# Tailwind 4

`resources/css/panda-panel.css` is a **Tailwind 4** stylesheet and nothing else will compile it. It
opens with `@import 'tailwindcss'`, declares the dark variant with `@custom-variant`, maps its
tokens in `@theme inline`, and adds two content roots with `@source` — four directives Tailwind 3
does not read. Reach for this page when the build fails inside that file, when a panel renders
unstyled, or when a class you set from PHP is in the DOM and changes nothing.

## Confirm the version first

```bash
npm ls tailwindcss @tailwindcss/vite
```

```text
├── @tailwindcss/vite@4.1.11
└── tailwindcss@4.1.11
```

Anything on `3.x` is the whole answer. The package declares both at `^4.1.0`, and
`php artisan panel:install` names them among the dependencies an application is missing:

```bash
npm install tailwindcss@^4.1.0 @tailwindcss/vite@^4.1.0 tw-animate-css@^1.2.0
npm run build
```

Node must be `>=20.19`.

## 1. The build fails inside `panda-panel.css`

**Symptom.** `npm run build` reports an unknown at-rule, an unexpected `@theme`, or simply produces
a stylesheet with none of the panel's colours in it.

**Cause.** Tailwind 3. The two versions are configured in different places and neither reads the
other's: v3 takes a `tailwind.config.js` and is included with `@tailwind base; @tailwind components;
@tailwind utilities;`, while v4 puts the theme, the variants and the content scan in the CSS itself.

The four directives the panel's stylesheet depends on:

| Directive | In `panda-panel.css` | What it does |
| --- | --- | --- |
| `@import 'tailwindcss'` | line 1 | pulls Tailwind in. v3 has no such import |
| `@source '…'` | two lines | adds a path to the content scan, relative to the stylesheet |
| `@custom-variant dark (&:is(.dark *))` | one line | defines the `dark:` variant as a class-descendant selector |
| `@theme inline { … }` | the token block | turns custom properties into Tailwind utilities |

```css
@import 'tailwindcss';

@import 'tw-animate-css';

@source '../../vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php';
@source '../../storage/framework/views/*.php';

@custom-variant dark (&:is(.dark *));
```

There is no v3 compatibility path and there will not be one. Tailwind 4 is a hard requirement, for
the same reason Laravel 12 is: the stylesheet is written against it and rewriting it for v3 would
mean maintaining two.

## 2. Tailwind 4 installed, and the build still does not process the CSS

**Cause.** A leftover v3 build pipeline. Tailwind 4 moved its PostCSS plugin into a separate package
(`@tailwindcss/postcss`), so a `postcss.config.js` still listing `tailwindcss` as a plugin is
configured for a version that is no longer installed.

The package's own dependency list names `@tailwindcss/vite`, which is the Vite-native path and needs
no PostCSS configuration at all:

```ts
// vite.config.ts
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [vue(), tailwindcss()],
});
```

A `tailwind.config.js` left in place is inert rather than harmful — v4 does not read it unless a
stylesheet explicitly asks with `@config`. Deleting it removes a file that looks load-bearing and is
not.

## 3. Every panel screen renders as unstyled HTML

**Cause, in order.** A build that has not run, or a stylesheet that is not in any entrypoint.

```bash
npm run build      # or npm run dev
```

`panda-panel.css` is published into the application, and there are two sensible arrangements for it.
A Laravel Vue starter kit ships an `app.css` that is nearly identical — the panel-specific parts are
the `--sidebar-*` tokens and the frozen-column component class:

- **Keep your `app.css`** and copy across anything it lacks. Nothing else is needed; the panel's
  components use ordinary theme utilities.
- **Build `panda-panel.css` as an entrypoint of its own**, declared on the panel so it loads on that
  panel's pages and nowhere else:

```php
$panel->assets('resources/css/panda-panel.css');
```

```ts
// vite.config.ts
input: ['resources/css/app.css', 'resources/js/app.ts', 'resources/css/panda-panel.css'],
```

| Member | Signature |
| --- | --- |
| `assets` | `assets(string ...$entrypoints): self` |
| `getAssets` | `getAssets(): list<string>` |

Two edits, deliberately. `Panel::assets()` takes **paths, not built files**, so an entry that is not
in Vite's `input` fails at request time with `Unable to locate file in Vite manifest`. That failure
is the right one — a declared asset that was never built is a mistake — but it is why this is not a
one-line change. Entrypoints accumulate across calls, and the list never crosses to the frontend:
the browser gets the tags, not what produced them.

## 4. A colour set with `colors()` does nothing

```php
$panel->colors(
    light: ['primary' => '#4f46e5', 'sidebar' => 'oklch(0.98 0 0)'],
    dark: ['primary' => '#818cf8'],
);
```

**No rebuild is needed for this**, which is the first thing to know: the light values land in a
`style` attribute on the shell root as `--primary` and `--sidebar`, and the stylesheet already reads
those properties.

```html
<div class="panel-shell" style="--primary: #4f46e5; --sidebar: oklch(0.98 0 0)">
```

That works because the theme block is `@theme **inline**`. With `inline`, a utility emits the
referenced variable directly — `bg-primary` resolves to `var(--primary)` — so a value set further
down the DOM tree takes effect. Without `inline` the utility would freeze the value at build time
and a runtime palette would be impossible.

**When it does not apply, one of two validations dropped it, silently.** A panel with one bad colour
should render with the rest of its theme rather than fail to render at all.

```php
$panel->getTheme();
// ['light' => ['primary' => '#4f46e5'], 'dark' => ['primary' => '#818cf8']]
```

| Reason | Example |
| --- | --- |
| The property is not one the stylesheet reads | `'primry' => '#fff'` |
| The value is not a recognised colour syntax | `'red; content: url(https://evil.test)'` |

The eighteen properties a panel may set — write them without the leading `--`, which is stripped:

| | | |
| --- | --- | --- |
| `primary` | `primary-foreground` | `secondary` |
| `secondary-foreground` | `accent` | `accent-foreground` |
| `background` | `foreground` | `muted` |
| `muted-foreground` | `destructive` | `border` |
| `ring` | `sidebar` | `sidebar-foreground` |
| `sidebar-primary` | `sidebar-accent` | `sidebar-border` |

The value syntaxes: 3-to-8-digit hex, `rgb()`/`rgba()`, `hsl()`/`hsla()`, and `oklch()`. Anything
else is dropped, because these end up inside a `style` attribute and an arbitrary string there is a
stylesheet rather than a colour.

| Member | Signature |
| --- | --- |
| `Panel::colors` | `colors(array $light, array $dark = []): self` |
| `Panel::getTheme` | `getTheme(): array{light: array<string, string>, dark: array<string, string>}` |
| `PanelTheme::light` / `dark` | `light(array $colors): self` / `dark(array $colors): self` |
| `PanelTheme::isEmpty` | `isEmpty(): bool` |
| `PanelTheme::toArray` | `toArray(): array` |

**The dark palette is serialized, not applied.** An inline style cannot express "only under
`.dark`", so the dark values travel to the frontend in `panel.theme.dark` and no shipped component
applies them. A theme that must differ by colour scheme belongs in a stylesheet:

```css
/* resources/css/panels/admin.css */
.panel-shell {
    --primary: #4f46e5;
}

.dark .panel-shell {
    --primary: #818cf8;
}
```

## 5. A class set from PHP is in the DOM and changes nothing

**Cause.** Tailwind scans *source files* for class names. A string written in a PHP panel provider
is not in any file it scans by default, so the class reaches the DOM and the rule it names was never
compiled. Nothing is logged, and the DOM inspector shows the class, which is what makes this
convincing.

```php
$panel->cssHooks([
    'topbar' => 'border-b-2 border-amber-500',
    'table-row' => 'hover:bg-amber-50',
]);
```

Three ways out, in order of preference:

**Add the provider to Tailwind's sources.** `@source` takes a path relative to the stylesheet:

```css
/* resources/css/app.css */
@import 'tailwindcss';

@source '../../app/Panels';
```

**Use classes that already appear in the application's own templates.** `hover:bg-muted`,
`border-b-2`, `shadow-sm` are almost certainly already compiled.

**Write plain CSS against the stable class instead.** Every part of the shell already carries a
`panel-*` class, written into the component rather than generated, so it is there whether or not a
panel says anything:

```css
.panel-topbar {
    border-bottom: 2px solid #f59e0b;
}
```

| Member | Signature | Notes |
| --- | --- | --- |
| `Panel::cssHooks` | `cssHooks(array $classes): self` | keyed by hook name; calls accumulate |
| `Panel::getCssHooks` | `getCssHooks(): array<string, string>` | `[]` for a panel that declared nothing |
| `CssHooks::HOOKS` | `public const HOOKS` | the closed allowlist of hook names |

An unknown hook name is dropped without a word, because the allowlist is what the guarantee rests
on. Check the spelling against `PandaPanel\Support\CssHooks::HOOKS` before suspecting the build.

## 6. A class built by interpolation is missing from the bundle

**Cause.** `bg-${color}-100` is invisible to the compiler, so the class simply does not exist. The
failure is silent, which is the worst kind — and it is why the panel writes every Tailwind class out
in full and maps tokens to literals in four places.

| Value | Mapped in | The literal record |
| --- | --- | --- |
| Badge colours | `@/panel/palette` | `BADGE_CLASSES`, `ICON_CLASSES`, `SELECTED_CLASSES` |
| Grid columns and spans | `@/panel/lib/grid` | `GRID_CLASSES`, `MD_SPAN_CLASSES`, `LG_SPAN_CLASSES` |
| Widget column spans | `panel/widgets/WidgetGrid.vue` | `SPAN_CLASSES` |
| Content width | `panel/composables/usePanel.ts` | `MAX_WIDTH_CLASSES` |

```ts
import { BADGE_CLASSES, ICON_CLASSES, SELECTED_CLASSES } from '@/panel/palette';
import type { BadgeColorName } from '@/panel/palette';

// 'neutral' | 'success' | 'warning' | 'danger' | 'info'
BADGE_CLASSES.success;
// 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
```

```ts
// usePanel.ts
const MAX_WIDTH_CLASSES = {
    full: 'max-w-full',
    '7xl': 'max-w-7xl',
    '6xl': 'max-w-6xl',
    '5xl': 'max-w-5xl',
    '4xl': 'max-w-4xl',
    '3xl': 'max-w-3xl',
} as const;
```

That is why `maxContentWidth('5xl')` is a **token**, not a class, and why `BadgeColor` is a closed
enum on the server. A value outside the record falls back — `max-w-full` for the width, the neutral
badge for a colour — rather than emitting a class that does not exist.

**Do the same in your own components.** A custom column or widget that builds a class from a server
value needs the same literal record:

```ts
const STATUS_CLASSES = {
    draft: 'bg-muted text-muted-foreground',
    live: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
} as const;

const classes = STATUS_CLASSES[status] ?? STATUS_CLASSES.draft;
```

Two values that look like candidates for classes and deliberately are not: the sidebar's `width` and
`collapsedWidth`, which are CSS lengths applied as `--sidebar-width` and `--sidebar-width-icon`, and
a table column's `width()`, which is inline for the same reason.

## 7. `dark:` utilities do nothing

**Cause.** The variant is defined as a **class descendant**, not a media query:

```css
@custom-variant dark (&:is(.dark *));
```

Three consequences, in the order they bite:

- **`.dark` has to be on an ancestor.** The starter kit's `useAppearance` composable puts it on
  `<html>`, which is why the panel's own light/dark toggle works without the panel owning anything.
  An application that removed that composable, or that sets a `data-theme` attribute instead, gets
  no dark styling in the panel.
- **The element carrying `.dark` is not itself matched.** `&:is(.dark *)` is a descendant selector.
  Putting `.dark` directly on the element you are styling does nothing to that element.
- **`prefers-color-scheme` is not consulted.** The class is the only signal, so the operating
  system's setting reaches the panel only through whatever writes that class.

The two palettes it switches between are plain custom-property blocks in the same stylesheet —
`:root` for light and `.dark` for dark — including every `--sidebar-*` token the shell reads.

## 8. The frozen-column seam is missing

**Symptom.** A table with pinned columns scrolls and the unpinned columns slide under the frozen
group with no visible edge.

**Cause.** `.panel-table-frozen-edge` is a component class in `panda-panel.css`, and an application
that kept its own `app.css` without copying that block has the class in the markup and no rule
behind it.

```css
@layer components {
    .panel-table-frozen-edge {
        position: relative;
    }

    .panel-table-frozen-edge::after {
        content: '';
        position: absolute;
        top: 0;
        bottom: 0;
        width: 0.75rem;
        pointer-events: none;
    }

    /* Pinned left: the seam sits on the right of the cell. */
    .panel-table-frozen-edge:not([style*='right'])::after {
        left: 100%;
        border-left: 1px solid var(--border);
        background: linear-gradient(to right, rgb(0 0 0 / 0.06), transparent);
    }

    /* Pinned right: mirrored. */
    .panel-table-frozen-edge[style*='right']::after {
        right: 100%;
        border-right: 1px solid var(--border);
        background: linear-gradient(to left, rgb(0 0 0 / 0.06), transparent);
    }
}
```

It is a pseudo-element rather than a border because a border would move the cell's content by a
pixel the moment a table starts scrolling — and the whole point of the marker is that nothing else
appears to move.

## What is in the stylesheet

Five parts, in file order. All of it is published, so all of it is yours to edit.

| Part | Contents |
| --- | --- |
| Imports and sources | `@import 'tailwindcss'`, `@import 'tw-animate-css'`, two `@source` lines |
| The dark variant | `@custom-variant dark (&:is(.dark *))` |
| The theme mapping | `@theme inline { … }` — the token-to-utility map |
| The palettes | `:root { … }` and `.dark { … }`, plus a `@layer base` border-colour compatibility block and a `@layer utilities` font block |
| Component layer | `.panel-table-frozen-edge` |

The mapped families: `background`, `foreground`, `card`, `popover`, `primary`, `secondary`, `muted`,
`accent`, `destructive`, `border`, `input`, `ring`, `chart-1` through `chart-5`, and the eight
`sidebar-*` colours, plus `--font-sans` and the three radius steps derived from `--radius`.

The two `@source` lines add what Tailwind's automatic detection does not reach: Laravel's own
pagination views inside `vendor/`, and the compiled Blade views under `storage/framework/views`.
Both paths are relative to the stylesheet, so moving the file means fixing them.

## After a package upgrade

The published copy does not update itself, and the stylesheet is the file most likely to have been
edited:

```bash
php artisan panel:assets            # what is behind, what you changed, what conflicts
php artisan panel:assets --update   # write only the files you have never touched
npm run build
```

A stylesheet you edited is reported as `yours` and left alone, which also means an upstream
improvement to it never arrives automatically. One edited on both sides is a `CONFLICT` and is not
written at all — see [Asset conflicts](asset-conflicts.md).

## Notes

- **A dropped colour is silent, and so is an unknown hook name.** Neither produces a warning. Check
  `getTheme()` and `getCssHooks()` before suspecting the build.
- **`colors()` needs no rebuild; `cssHooks()` usually does.** One sets custom property values, the
  other sets class names — and a class name has to exist in the bundle.
- **`--sidebar` and `--sidebar-background` are different properties.** The theme block maps
  `--color-sidebar` to `--sidebar-background`, while `:root` defines both.
  `colors(['sidebar' => …])` sets `--sidebar`.
- **Editing `:root` in `panda-panel.css` changes the whole application**, including the starter
  kit's own screens. Per-panel colours belong in `colors()` or a panel stylesheet.
- **`tw-animate-css` is a dependency of the stylesheet**, not an optional extra. `panel:install`
  names it if the application has not declared it.
- **The `shell` hook and the theme land on the same element.** A hook class there can rely on the
  panel's custom properties being in scope.
- **This package ships no compiled CSS.** `vendor/chocoalano/panel` contains sources only; every
  panel screen depends on the application's own build.
- **The package's own `vite.config.ts` is not yours.** It builds a generated glob over the whole
  tree into `build/frontend`, unminified, and nothing consumes the output — it is a compile check,
  not a deliverable.

## See also

- [Tailwind theme](../frontend/tailwind-theme.md), [CSS hooks](../frontend/css-hooks.md)
- [Published asset structure](../frontend/assets.md),
  [updating published assets](../frontend/updating-assets.md),
  [component tree](../frontend/component-tree.md)
- [Panel assets](../panels/assets.md), [branding](../panels/branding.md),
  [layouts](../panels/layouts.md)
- [Frontend requirements](../getting-started/frontend-requirements.md),
  [compatibility matrix](../getting-started/compatibility.md)
- [Frontend build](../deployment/frontend-build.md),
  [production checklist](../deployment/production-checklist.md)
- [Frontend assets](../concepts/frontend-assets.md),
  [component registries](../concepts/component-registries.md)
- [Vite build errors](vite.md), [asset conflicts](asset-conflicts.md),
  [missing host modules](host-modules.md), [icons that render nothing](icons.md)
- [`panel:assets`](../cli/panel-assets.md), [`panel:install`](../cli/panel-install.md)
