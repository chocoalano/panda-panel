# Component Registries

Panel metadata refers to Vue components and icons by name. Those names resolve
through six build-time registries and nothing else: a name that was not
compiled into the bundle renders a fallback, never a fetch. Reach for this
page when a custom column, field, widget, or icon is silently drawing nothing.

## The shape of the rule

A PHP class declares a name:

```php
use PandaPanel\Widgets\CustomWidget;

final class SystemInfo extends CustomWidget
{
    protected static string $component = 'Panels/Admin/Widgets/SystemInfo';
}
```

A Vue file exists at exactly that path under `resources/js/pages/`:

```
resources/js/pages/Panels/Admin/Widgets/SystemInfo.vue
```

And the registry resolves one to the other:

```ts
import { resolveWidgetComponent } from '@/panel/widgets/registry';

const loader = resolveWidgetComponent('Panels/Admin/Widgets/SystemInfo');
// () => Promise<{ default: Component }>  — or null
```

The name is **the path below `pages/`, without the `.vue` extension**. That is
the whole naming convention, and it is the same for all five component
registries.

## Why a registry rather than a dynamic import

Two reasons, and the second is the one that decides it.

Resolving an arbitrary server-supplied name to a component would let panel
metadata reach into the bundle. The name always originates from a registered
PHP class rather than from request input, so the registry is the second lock
rather than the first — but a second lock is worth having on the path between
data and code execution.

The other reason is that it has to work at all. A dynamic import keyed on a
runtime string is not statically analysable, so the bundler cannot know which
files to emit. `import.meta.glob` is: it is evaluated at build time, and every
file it matches is in the bundle.

## The five component registries

| Registry | Glob | Resolver |
| --- | --- | --- |
| `@/panel/tables/registry` | `pages/Panels/**/Columns/*.vue` | `resolveColumnComponent` |
| `@/panel/forms/registry` | `pages/Panels/**/{Fields,Schemas,Entries,Modals}/*.vue` | `resolveFormComponent` |
| `@/panel/widgets/registry` | `pages/Panels/**/Widgets/*.vue` | `resolveWidgetComponent` |
| `@/panel/hooks/registry` | `pages/Panels/**/Hooks/*.vue` | `resolveHookComponent` |
| `@/panel/shell/registry` | `pages/Panels/**/Shell/*.vue` | `resolveShellComponent` |

Every resolver has the same signature and the same contract: a loader for a
known name, `null` for an unknown one.

```ts
export function resolveColumnComponent(name: string): ColumnLoader | null;
export function resolveFormComponent(name: string): ComponentLoader | null;
export function resolveWidgetComponent(name: string): WidgetLoader | null;
export function resolveHookComponent(name: string): HookLoader | null;
export function resolveShellComponent(name: string): ComponentLoader | null;
```

Four of them also expose a membership test:

```ts
export function hasColumnComponent(name: string): boolean;
export function hasFormComponent(name: string): boolean;
export function hasWidgetComponent(name: string): boolean;
export function hasHookComponent(name: string): boolean;
```

`resolveShellComponent` has no `has*` counterpart; the layouts call the
resolver and keep their built-in bar when it answers `null`.

### What each is for, and what declares the name

| Registry | Directory | PHP side |
| --- | --- | --- |
| Columns | `Panels/{Panel}/Columns/` | `CustomColumn::component(string $component)` |
| Fields | `Panels/{Panel}/Fields/` | `CustomField::component(string $component)` |
| Layouts | `Panels/{Panel}/Schemas/` | `CustomComponent::make(string $component)` |
| Entries | `Panels/{Panel}/Entries/` | `CustomEntry::component(string $component)` |
| Modals | `Panels/{Panel}/Modals/` | `Modal::content(string $component, array $config = [])` |
| Widgets | `Panels/{Panel}/Widgets/` | `CustomWidget::$component` |
| Hooks | `Panels/{Panel}/Hooks/` | `Panel::renderHook(RenderHook, string, …)` |
| Shell | `Panels/{Panel}/Shell/` | `Panel::sidebarComponent()`, `Panel::topbarComponent()` |

Four of those share one registry. What they have in common is that a *name*
resolves to a component the build saw; where the name was declared changes
nothing about the rule.

### A custom column, end to end

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Tables\Columns\CustomColumn;

CustomColumn::make('accountAge')
    ->label('Account age')
    ->component('Panels/Admin/Columns/AccountAge')
    ->state(static fn (Model $record): array => [
        'days' => (int) $record->created_at->diffInDays(now()),
    ]);
```

```vue
<!-- resources/js/pages/Panels/Admin/Columns/AccountAge.vue -->
<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ state: unknown }>();

const days = computed(() => {
    const value = props.state;

    if (typeof value !== 'object' || value === null) {
        return null;
    }

    const { days } = value as { days?: unknown };

    return typeof days === 'number' ? days : null;
});
</script>

<template>
    <span v-if="days !== null">{{ days }} days</span>
    <span v-else class="text-muted-foreground">—</span>
</template>
```

Whatever `state()` returns is what the component receives, and it must
serialize to scalars and arrays like every other cell. It arrives as untyped
JSON, so it is narrowed rather than asserted — a shape that does not match
renders an empty cell instead of throwing inside the table.

## Failure is a fallback, not a throw

Every resolver returns `null` rather than throwing, and every caller renders
something neutral:

| Registry | On an unknown name |
| --- | --- |
| Columns | the cell renders its placeholder — one mistyped component cannot take a table down |
| Forms | the renderer shows its placeholder, and the fields around it stay editable |
| Widgets | `WidgetFallback` — one mistyped component cannot take the dashboard down |
| Hooks | nothing at all — a decorative injection must not break the page it decorates |
| Shell | the built-in sidebar or topbar — a typo must not strand somebody on a page they cannot leave |

The form and widget registries say so out loud in development:

```
[panel] The widget component [Panels/Admin/Widgets/SystemInfo] is not in the
build-time registry, so a fallback is drawn instead. It has to live under
resources/js/pages/Panels/{Panel}/Widgets/ — check the path and the spelling,
then rebuild.
```

Once per name, `import.meta.env.DEV` only. In production the fallback is the
whole answer: this is a build problem, and a console message on a live panel
helps nobody.

## The icon registry

Icons work the same way but resolve to components rather than loaders, and the
allowlist is generated rather than globbed.

```ts
import { resolveIcon, isPanelIconName } from '@/panel/icons/registry';
import type { PanelIconName } from '@/panel/icons/registry';

resolveIcon('shield');       // Component
resolveIcon('not-an-icon');  // null
resolveIcon(null);           // null
isPanelIconName('shield');   // true
```

```ts
export type PanelIconName = keyof typeof ICONS;
export function isPanelIconName(name: string): name is PanelIconName;
export function resolveIcon(name: string | null | undefined): Component | null;
```

`resources/js/panel/icons/registry.ts` is generated:

```bash
php artisan panel:icons          # rewrite the registry from the source
php artisan panel:icons --check  # fail if it is out of date, for CI
```

The command scans two trees — the application's `app/` and the package's own
source — for every shape an icon name is declared in: `->icon('x')`,
`$navigationIcon = 'x'`, the `icon:` named argument, `'icon' => 'x'` in a
serialized array, `Icon::make('x')`, and every string literal inside a method
named `icon()`, which is where an enum keeps its per-case icons. It checks each
against the icons Lucide actually ships and rewrites the map. Lucide ships over
a thousand icons; a panel uses a couple of dozen, and only those belong in the
bundle.

The package's own source is not optional in that scan: half the icons a panel
renders belong to actions the framework ships — delete, edit, export — and a
scan of `app/` alone would rewrite the registry without them, leaving every
built-in action with no icon and no error.

A name Lucide does not have fails the command by name, which is the only
warning you get: an unregistered icon renders nothing at all, with no error.
An unknown name logs the same development-only warning the component
registries do.

## Adding a component

1. Create the file under
   `resources/js/pages/Panels/{Panel}/{Kind}/{Name}.vue`. The directory name
   is what the glob matches; nothing else is scanned.
2. Declare the name in PHP as the path below `pages/`, without `.vue`.
3. Rebuild — `npm run dev` picks it up, `npm run build` for production.

If the panel frontend lives somewhere other than `resources/js/pages/Panels`,
change `panda-panel.frontend.pages_path` **and** the globs, which are written
as literal relative paths in each registry file. They are published into your
application, so editing them is expected.

## Gotchas

- **The glob patterns are relative, never the `@` alias.** Vite's dev server
  resolves an aliased glob to nothing at all — `Object.assign({})` — while the
  production build resolves it normally. An aliased pattern means every custom
  component renders the fallback in development and works once built, which is
  the worst possible failure mode.
- **Keys are derived from real paths, not reconstructed from names.** Vite's
  key format follows the pattern as written and differs between the dev server
  and the build. Each registry finds the `/pages/` segment in the emitted key
  and slices from there.
- **Only direct children of the kind directory match.** The globs end in
  `*.vue`, not `**/*.vue`, so `Widgets/Charts/Revenue.vue` is not registered.
- **A new file needs a rebuild.** `import.meta.glob` is evaluated at build
  time. The dev server handles new files, but a production bundle built before
  the file existed does not contain it.
- **The name is a registry key, never a path or a class.** `./Widgets/X.vue`,
  `@/pages/Panels/…`, and `App\Panels\Admin\Widgets\SystemInfo` all resolve to
  nothing.
- **Case matters.** `Panels/Admin/Widgets/systemInfo` and
  `Panels/Admin/Widgets/SystemInfo` are different keys, and on a
  case-insensitive filesystem the mistake will not show up until CI.
- **`panel:icons` is a build-time allowlist, not a lookup.** Adding an icon
  name in PHP and skipping the command means the icon is absent.
  `--check` in CI is what turns that into a failed build.

## See also

- [Server Metadata to Vue](metadata-to-vue.md)
- [Frontend Assets](frontend-assets.md)
- [Panels](panels.md)
- [Custom Columns](../frontend/custom-columns.md)
- [Custom Fields](../frontend/custom-fields.md)
- [Custom Widgets](../frontend/custom-widgets.md)
- [Custom Shell](../frontend/custom-shell.md)
- [Icons](../frontend/icons.md)
- [Render Hooks](../panels/render-hooks.md)
- [panel:icons](../cli/panel-icons.md)
