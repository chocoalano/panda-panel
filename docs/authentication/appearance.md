# Appearance Settings

The account page where a user picks light, dark, or follow-the-system, rendered
inside whichever panel they are in. It is the one settings page with no server
state at all: the choice lives in the browser. You reach for this page to know
where the preference is stored, what reads it, and how a panel's own colours
interact with it.

## A minimal working example

Nothing to register:

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
        return $panel->path('admin')->auth();
    }
}
```

```bash
php artisan route:list --name=panel.admin.pages.settings-appearance
```

```text
GET  admin/settings/appearance  panel.admin.pages.settings-appearance
```

## The page class

`PandaPanel\Pages\Settings\AppearanceSettings` extends `PandaPanel\Pages\Page`
and overrides one method.

| Member | Value |
| --- | --- |
| `$title` | `'Appearance'` |
| `$subheading` | `'Choose how the interface looks on this device.'` |
| `$slug` | `'settings-appearance'` |
| `$component` | `'panel/settings/Appearance'` |
| `$navigationIcon` | `'palette'` |
| `$navigationGroup` | `'Account'` |
| `$navigationSort` | `30` |
| `$middleware` | none |
| `routePath()` | `'settings/appearance'` |

```php
use PandaPanel\Pages\Settings\AppearanceSettings;

AppearanceSettings::routeName('admin');   // 'panel.admin.pages.settings-appearance'
AppearanceSettings::url('admin');         // '/admin/settings/appearance'
AppearanceSettings::url('app');           // '/app/settings/appearance'
```

```php
public static function routeName(PandaPanel\Core\Panel|string|null $panel = null): string
public static function url(PandaPanel\Core\Panel|string|null $panel = null): string
```

The class does **not** override `props()`. The base implementation returns
`[]`, so the only props the component receives are the `page` metadata every
panel page ships and the shared `panel` prop. There is no `appearance` prop, no
saved value, and nothing to submit.

## What the screen renders

`resources/js/pages/panel/settings/Appearance.vue`, inside `PanelLayout`, is a
heading and one component:

```vue
<script setup lang="ts">
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import { Card, CardContent } from '@/components/ui/card';
import PanelLayout from '@/panel/layouts/PanelLayout.vue';

defineOptions({ layout: PanelLayout });

defineProps<{ page: PageMetadata }>();
</script>

<template>
    <Card>
        <CardContent>
            <AppearanceTabs />
        </CardContent>
    </Card>
</template>
```

`AppearanceTabs.vue` draws three buttons — Light, Dark, System — and calls
`updateAppearance(value)` on click. Nothing else happens: no request, no flash,
no reload.

## The composable

`resources/js/composables/useAppearance.ts` ships with the package and is
published into `resources/js/composables/useAppearance.ts`. It exports three
things.

```ts
export type Appearance = 'light' | 'dark' | 'system';
export type ResolvedAppearance = 'light' | 'dark';

export type UseAppearanceReturn = {
    appearance: Ref<Appearance>;
    resolvedAppearance: ComputedRef<ResolvedAppearance>;
    updateAppearance: (value: Appearance) => void;
};

export function useAppearance(): UseAppearanceReturn;
export function updateTheme(value: Appearance): void;
export function initializeTheme(): void;
```

`Appearance` and `ResolvedAppearance` are re-exported from `@/types`, which is
the application's own shared type module.

| Export | What it does |
| --- | --- |
| `useAppearance()` | Reads the stored value on mount, and returns the ref, the resolved value, and the setter |
| `updateAppearance(value)` | Writes local storage, writes the cookie, and applies the class |
| `resolvedAppearance` | `'system'` resolved through `matchMedia('(prefers-color-scheme: dark)')` |
| `updateTheme(value)` | Toggles `dark` on `document.documentElement`. Used internally, exported for a custom shell |
| `initializeTheme()` | Applies the stored value at boot and registers the system-theme listener |

Using it in a component of your own:

```vue
<script setup lang="ts">
import { useAppearance } from '@/composables/useAppearance';

const { appearance, resolvedAppearance, updateAppearance } = useAppearance();
</script>

<template>
    <button @click="updateAppearance('dark')">Dark</button>
    <p>Currently {{ appearance }}, rendering as {{ resolvedAppearance }}.</p>
</template>
```

`initializeTheme()` belongs in the application's Inertia entry, before the app
is mounted. Without it the stored choice is applied only once a component
calling `useAppearance()` mounts, which is a visible flash of the wrong theme —
and the `matchMedia` listener that makes `system` react to an OS change is never
registered:

```ts
// resources/js/app.ts
import { initializeTheme } from '@/composables/useAppearance';

createInertiaApp({
    // …
});

initializeTheme();
```

## Where the choice is stored

Two places, for two different readers:

| Store | Key | Written by | Read by |
| --- | --- | --- | --- |
| `localStorage` | `appearance` | `updateAppearance()` | `useAppearance()` and `initializeTheme()` on the next load |
| Cookie | `appearance`, `path=/`, `max-age` 365 days, `SameSite=Lax` | `updateAppearance()` | Server-side rendering, so the first paint is not the wrong colour |

Nothing is written to the database, and nothing is sent to a panel route. The
choice therefore belongs to a browser rather than to an account: the same user
on a second machine starts at `system` again.

The applied result is a single class:

```ts
document.documentElement.classList.toggle('dark', systemTheme === 'dark');
```

which is what Tailwind's dark variant keys off.

## The toggle in the panel header

`PanelHeader.vue` draws a sun/moon button beside the notification bell, using the
same composable:

```ts
const { appearance, updateAppearance } = useAppearance();

const isDark = computed(() => appearance.value === 'dark');

function toggleAppearance(): void {
    updateAppearance(isDark.value ? 'light' : 'dark');
}
```

Note what it cannot do: it flips between `light` and `dark` only. A user who
wants `system` back has to come to this page, which is one of the reasons the
page exists.

## A panel's own colours

Appearance decides light or dark. The palette inside that is the panel's, and
the two are set separately:

```php
public function colors(array $light, array $dark = []): self
public function getTheme(): array   // ['light' => [...], 'dark' => [...]]
```

```php
$panel->colors(
    light: ['primary' => 'oklch(0.55 0.18 265)', 'sidebar' => '#f8fafc'],
    dark: ['primary' => 'oklch(0.72 0.15 265)'],
);
```

Values are CSS custom properties, validated by
`PandaPanel\Support\PanelTheme` against an allowlist of eighteen property names
and four value shapes (hex, `rgb()`, `hsl()`, `oklch()`). Anything else is
dropped rather than refused, so one bad colour does not stop a panel rendering.

Both maps cross the wire as `panel.theme`, and `usePanelStyling()` writes the
**light** map onto the shell root as inline custom properties:

```ts
for (const [property, value] of Object.entries(theme.light ?? {})) {
    style[`--${property}`] = value;
}
```

The dark map is sent but not applied inline, because an inline style has no
media query to hang off. A theme that must differ by scheme belongs in a
stylesheet the panel loads with `Panel::assets()`.

## The dark mode flag

```php
public function darkMode(bool $darkMode = true): self
public function hasDarkMode(): bool     // true by default
```

It crosses as `panel.darkMode`. The shipped shell does not branch on it — the
header toggle is rendered unconditionally — so `darkMode(false)` is currently a
declaration for your own components to read, such as a replacement topbar that
hides the toggle, rather than a switch that removes it.

## Turning the page off

```php
$panel->settings(false);
```

All-or-nothing: profile, security and appearance go together. A kiosk panel that
should not offer a theme choice at all turns the three off and registers its own
account pages if it needs any.

## Testing it

There is no state to assert, so the test is that the page renders in the right
shell:

```php
use Inertia\Testing\AssertableInertia;

it('renders the appearance settings page with no server state', function (): void {
    $this->actingAs($user)
        ->get('/app/settings/appearance')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('panel/settings/Appearance')
            ->where('panel.id', 'app')
        );
});
```

That is `tests/Feature/Panel/PanelSettingsTest.php`.

## Gotchas

- **The choice is per device, not per user.** Local storage and a cookie, both
  in one browser. It does not follow a user to another machine, and an
  administrator cannot set it for somebody.
- **`vendor:publish` does not overwrite.** A Laravel Vue starter kit already
  ships its own `useAppearance.ts`; the package's copy is skipped unless you
  pass `--force`. Whichever file is on disk is the one both the panel header and
  this page use, which is deliberate — one preference, one place.
- **Without `initializeTheme()` the first paint can be wrong.** The composable
  reads local storage in `onMounted`, which is after the first render.
- **The header toggle never selects `system`.** It is a two-state flip. Only
  this page can set the third state.
- **`panel.theme.dark` is sent but not applied inline.** Dark values need a
  stylesheet; see [Tailwind Theme](../frontend/tailwind-theme.md).
- **The route is GET only.** A POST answers 405. There is nothing to save.
- **Panel access still applies.** A user the panel refuses gets 403 here like
  anywhere else, and a guest is redirected to the login.

## See also

- [Profile Settings](profile.md), [Security Settings](security.md)
- [Settings Pages](../panels/settings-pages.md)
- [Panel Branding](../panels/branding.md)
- [Tailwind Theme](../frontend/tailwind-theme.md), [CSS Hooks](../frontend/css-hooks.md)
- [Custom Shell](../frontend/custom-shell.md)
- [Frontend Assets](../frontend/assets.md)
