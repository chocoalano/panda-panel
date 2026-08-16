# Tailwind Theme

The panel's stylesheet is Tailwind v4, and everything about its appearance is a CSS custom property. Reach for this page when a panel needs its own palette, when a colour you set is not applying, or when you need to know which properties the stylesheet actually reads.

Two separate mechanisms, kept separate on purpose: **colours are values**, set as custom properties and validated but open-ended; **[hooks are meanings](css-hooks.md)**, set as class names against a closed list of places.

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
            ->colors(
                light: ['primary' => '#4f46e5', 'sidebar' => 'oklch(0.98 0 0)'],
                dark: ['primary' => '#818cf8'],
            );
    }
}
```

No rebuild is needed. The light values land in a `style` attribute on the shell root as `--primary` and `--sidebar`, which is exactly what the stylesheet already reads:

```html
<div class="panel-shell" style="--primary: #4f46e5; --sidebar: oklch(0.98 0 0)">
```

## The stylesheet

`resources/css/panda-panel.css` is published into your application, so it is yours to edit. It has five parts.

### Imports and sources

```css
@import 'tailwindcss';
@import 'tw-animate-css';

@source '../../vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php';
@source '../../storage/framework/views/*.php';
```

Tailwind v4 puts the theme, the variants and the content scan in CSS rather than in a config file. The two `@source` lines add places Tailwind's automatic detection does not reach on its own.

### The dark variant

```css
@custom-variant dark (&:is(.dark *));
```

Dark mode is a class on `<html>`, driven by the host application's `useAppearance` composable — which is why the panel's header light/dark toggle works without the panel owning anything.

### The theme mapping

```css
@theme inline {
    --color-background: var(--background);
    --color-primary: var(--primary);
    --color-sidebar: var(--sidebar-background);
    /* … */
}
```

This is what turns a custom property into a Tailwind utility. `--color-primary: var(--primary)` is what makes `bg-primary` resolve to whatever `--primary` currently is — including a value set inline by a panel at runtime.

The mapped families: `background`, `foreground`, `card`, `popover`, `primary`, `secondary`, `muted`, `accent`, `destructive`, `border`, `input`, `ring`, `chart-1` through `chart-5`, and the eight `sidebar-*` colours. Plus `--font-sans` and the three radius steps derived from `--radius`.

### The palettes

```css
:root {
    --background: hsl(0 0% 100%);
    --primary: hsl(0 0% 9%);
    --radius: 0.5rem;
    --sidebar-background: hsl(0 0% 98%);
    /* … */
}

.dark {
    --background: hsl(0 0% 3.9%);
    --primary: hsl(0 0% 98%);
    --sidebar-background: hsl(0 0% 7%);
    /* … */
}
```

Editing these changes the whole application. A panel that wants its own palette should set it through `colors()` or a per-panel stylesheet instead, so the two panels sharing a build stay different.

### The base and component layers

```css
@layer base {
    * { @apply border-border outline-ring/50; }
    body { @apply bg-background text-foreground; }
}
```

Plus one component class the panel needs and Tailwind cannot express: `.panel-table-frozen-edge`, the seam where a frozen column group ends. It is drawn with a pseudo-element rather than a border, because a border would move the cell's content by a pixel the moment a table starts scrolling — and the whole point of the marker is that nothing else appears to move.

## Panel colours

```php
/**
 * @param  array<string, string>  $light
 * @param  array<string, string>  $dark
 */
public function colors(array $light, array $dark = []): self

/** @return array{light: array<string, string>, dark: array<string, string>} */
public function getTheme(): array
```

Values, not meanings. A colour never becomes a Tailwind class, so the set is open — but both the property name and the value are validated by `PandaPanel\Support\PanelTheme`, and anything that fails is **dropped rather than refused**. A panel with one bad colour should render with the rest of its theme, not fail to render at all.

### The properties a panel may set

An allowlist, because a typo would otherwise be a custom property nothing reads — a theme that silently does not apply. Every name here is one the stylesheet consumes:

| | | |
| --- | --- | --- |
| `primary` | `primary-foreground` | `secondary` |
| `secondary-foreground` | `accent` | `accent-foreground` |
| `background` | `foreground` | `muted` |
| `muted-foreground` | `destructive` | `border` |
| `ring` | `sidebar` | `sidebar-foreground` |
| `sidebar-primary` | `sidebar-accent` | `sidebar-border` |

Write them without the leading `--`; a leading dash is stripped.

```php
Panel::make('admin')->colors([
    'primary' => '#4f46e5',
    'primry' => '#ffffff',    // dropped: not a property the stylesheet reads
])->getTheme();
// ['light' => ['primary' => '#4f46e5'], 'dark' => []]
```

### The value syntaxes

```php
$panel->colors([
    'primary' => '#fff',                 // 3 to 8 hex digits
    'secondary' => 'rgb(10, 20, 30)',    // rgb() and rgba()
    'accent' => 'hsl(210 40% 96%)',      // hsl() and hsla()
    'muted' => 'oklch(0.97 0 0)',        // oklch(), which the stylesheet uses
]);
```

Anything else is dropped. These end up inside a `style` attribute, and `red; content: url(https://…)` is a stylesheet rather than a colour:

```php
Panel::make('admin')->colors([
    'primary' => '#4f46e5',
    'background' => 'red; content: url(https://evil.test)',   // dropped
    'accent' => 'expression(alert(1))',                       // dropped
])->getTheme()['light'];
// ['primary' => '#4f46e5']
```

Two calls merge rather than replace, so a plugin can contribute a colour without displacing the panel's.

### `PandaPanel\Support\PanelTheme`

The class behind the panel method:

```php
use PandaPanel\Support\PanelTheme;

$theme = new PanelTheme;

$theme->light(['primary' => '#4f46e5']);
$theme->dark(['primary' => '#818cf8']);

$theme->isEmpty();   // false
$theme->toArray();
// ['light' => ['primary' => '#4f46e5'], 'dark' => ['primary' => '#818cf8']]
```

| Member | Signature | Notes |
| --- | --- | --- |
| `light` | `light(array $colors): self` | merges; sanitized |
| `dark` | `dark(array $colors): self` | merges; sanitized |
| `isEmpty` | `isEmpty(): bool` | true when neither palette has anything |
| `toArray` | `toArray(): array` | `array{light: …, dark: …}` |

`Panel::colors()` calls `dark()` only when the second argument is not empty, so `colors(['primary' => '#fff'])` leaves the dark palette untouched.

## How the colours are applied

```ts
import { usePanelStyling } from '@/panel/composables/usePanelStyling';

const { themeStyle } = usePanelStyling();
// { '--primary': '#4f46e5', '--sidebar': 'oklch(0.98 0 0)' }
```

Both layouts bind it to the shell root, which is also where `hook('shell')` lands:

```vue
<AppShell variant="sidebar" :class="hook('shell')" :style="themeStyle">
```

Set there so every custom property is in scope for everything the panel draws — and for nothing outside it.

### The dark palette is serialized, not applied

`themeStyle` contains the **light** values only. An inline style cannot express "only under `.dark`", so the dark values travel in `panel.theme.dark` for a component or a stylesheet to use.

A theme that must differ by colour scheme belongs in a stylesheet, which is what `Panel::assets()` is for:

```css
/* resources/css/panels/admin.css */
.panel-shell {
    --primary: #4f46e5;
}

.dark .panel-shell {
    --primary: #818cf8;
}
```

```php
$panel->assets('resources/css/panels/admin.css');
```

Two edits: the path must also appear in `vite.config.ts`'s `input`, or Vite has nothing to serve and the page fails with a manifest error. See [Panel Assets](../panels/assets.md).

## Colours that *are* meanings

Not every colour in the panel is a value. A status shown as a badge is a *meaning*, and each meaning maps to a literal Tailwind class the build must have seen. That set is closed, on the server as `PandaPanel\Tables\Enums\BadgeColor` and on the frontend as one palette module:

```ts
import {
    BADGE_CLASSES,
    ICON_CLASSES,
    SELECTED_CLASSES,
} from '@/panel/palette';
import type { BadgeColorName } from '@/panel/palette';

// 'neutral' | 'success' | 'warning' | 'danger' | 'info'
BADGE_CLASSES.success;
// 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
```

| Export | For |
| --- | --- |
| `BadgeColorName` | the five names, mirroring `BadgeColor` |
| `BADGE_CLASSES` | badges in tables, forms and infolists |
| `ICON_CLASSES` | icons, reusing the badge palette so a status is one colour |
| `SELECTED_CLASSES` | the border and background of a pressed control |

Defined once and shared, so a status shown as a badge in a table and as a toggle button in a form is the same colour — because it is the same map, rather than two that agree today.

## The literal-class rule

Every Tailwind class in the panel is written out in full. An interpolated class is invisible to the compiler, so it would simply not exist in the bundle — and the failure is silent, which is the worst kind.

The four places this shows up:

| Value | Mapped by | Written as |
| --- | --- | --- |
| Badge colours | `@/panel/palette` | `BADGE_CLASSES` |
| Grid columns and spans | `@/panel/lib/grid` | `GRID_CLASSES`, `MD_SPAN_CLASSES`, `LG_SPAN_CLASSES` |
| Widget column spans | `WidgetGrid.vue` | `SPAN_CLASSES` |
| Content width | `usePanel.ts` | `MAX_WIDTH_CLASSES` |

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

`maxContentWidth('5xl')` is a token, not a class, for exactly this reason. Anything outside the record falls back to `max-w-full`.

Two values that would look like candidates for classes and are deliberately not: the sidebar's `width` and `collapsedWidth`. Those are CSS lengths a panel states in `rem`, applied as `--sidebar-width` and `--sidebar-width-icon` custom properties. A column's `width()` is inline for the same reason.

## Making your own classes survive the build

Classes written in a PHP panel provider — through `cssHooks()` — are not in any file Tailwind scans:

```css
/* resources/css/app.css */
@import 'tailwindcss';

@source '../../app/Panels';
```

Or use classes that already appear elsewhere in the application, or write plain CSS against the stable `panel-*` class. See [CSS Hooks](css-hooks.md).

## Gotchas

- **A dropped colour is silent.** Neither an unknown property nor an unparseable value produces a warning; the theme simply arrives without it. Check `getTheme()` when a colour is not applying.
- **The dark palette does nothing on its own.** It crosses the wire and no shipped component applies it. Use a per-panel stylesheet.
- **`colors()` sets values, not utilities.** There is no `--color-brand`; the property must be one of the eighteen the stylesheet reads.
- **`--sidebar` and `--sidebar-background` are different properties.** The theme block maps `--color-sidebar` to `--sidebar-background`, while `:root` defines both. `colors(['sidebar' => …])` sets `--sidebar`.
- **Editing `:root` in `panda-panel.css` changes the whole application,** including the starter kit's own screens. Per-panel colours belong in `colors()` or a panel stylesheet.
- **The stylesheet is a published file.** `panel:assets` will report your edits as `modified` and leave them alone on an upgrade — which also means an upstream improvement to it will never arrive automatically.
- **`tw-animate-css` is a dependency of the stylesheet.** It is in the package's `dependencies`, so `panel:install` names it if the application has not declared it.

## See also

- [CSS Hooks](css-hooks.md)
- [Published Asset Structure](assets.md)
- [Vue Component Tree](component-tree.md)
- [Branding, Logo, Icon, Favicon](../panels/branding.md)
- [Panel Assets](../panels/assets.md)
- [Sidebar and Header Layouts](../panels/layouts.md)
- [Appearance settings](../authentication/appearance.md)
- [Frontend build](../deployment/frontend-build.md)
- [Tailwind troubleshooting](../troubleshooting/tailwind.md)
