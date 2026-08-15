# Sidebar and Header Layouts

The shell a panel is drawn in: a side rail or a top bar, how wide it is, which parts exist at all, and how wide the content column may grow. All of it is panel configuration — pages never choose a layout — and it arrives on the frontend as `panel.sidebar` and `panel.shell`.

## Switching to top navigation

```php
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelProvider;

final class KioskPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->path('kiosk')
            ->auth()
            ->topNavigation()          // a top bar instead of a side rail
            ->breadcrumbs(false)
            ->maxContentWidth('5xl');
    }
}
```

`PanelLayout.vue` reads `panel.sidebar.variant` and renders either `SidebarPanelLayout` or `HeaderPanelLayout`. Nothing in a page or in `app.ts` selects it.

## The sidebar call

```php
/**
 * @param  'sidebar'|'header'  $variant
 * @param  'sidebar'|'floating'|'inset'  $appearance
 */
public function sidebar(
    bool $collapsible = true,
    bool $defaultOpen = true,
    string $variant = 'sidebar',
    string $appearance = 'inset',
): self
```

Every argument has a default, so it is normally called with named arguments:

```php
$panel->sidebar(appearance: 'floating');
$panel->sidebar(collapsible: false, defaultOpen: false);
$panel->sidebar(variant: 'header');
```

| Argument | Values | Default | Effect |
| --- | --- | --- | --- |
| `collapsible` | `bool` | `true` | `false` renders a fixed rail (shadcn `collapsible="none"`) and also makes every navigation group non-collapsible. |
| `defaultOpen` | `bool` | `true` | Serialized as `panel.sidebar.defaultOpen`. See the note below. |
| `variant` | `'sidebar'`, `'header'` | `'sidebar'` | Which shell renders. Matches the starter kit's own `AppVariant`. |
| `appearance` | `'sidebar'`, `'floating'`, `'inset'` | `'inset'` | How the rail is drawn. Ignored by the header shell. |

The three appearances: `inset` floats the content pane inside the sidebar background, `floating` detaches the rail as a rounded card, `sidebar` keeps it flush against the edge with a border. An unknown value falls back to `inset` on the frontend rather than reaching the shadcn component as an unhandled variant.

`topNavigation()` is the same thing said the way people think about it:

```php
public function topNavigation(bool $topNavigation = true): self
```

```php
$panel->topNavigation();        // identical to sidebar(variant: 'header')
$panel->topNavigation(false);   // back to the rail
```

Note that `sidebar()` sets all four values, so calling it after `topNavigation()` resets the variant unless you pass it.

## Widths

```php
public function sidebarWidth(string $width, ?string $collapsedWidth = null): self
public function collapsedSidebarWidth(string $width): self
```

```php
$panel->sidebarWidth('18rem', '4rem');
$panel->collapsedSidebarWidth('3.5rem');
```

Defaults are `16rem` and `3rem`. These are **CSS lengths, not size tokens**: they become the `--sidebar-width` and `--sidebar-width-icon` custom properties on the rail. A number would have to become a class, and a class built by interpolation would not exist in the bundle.

## Turning parts off

```php
public function navigation(bool $navigation = true): self   // true
public function topbar(bool $topbar = true): self           // true
public function breadcrumbs(bool $breadcrumbs = true): self // true

public function hasNavigation(): bool
public function hasTopbar(): bool
public function hasBreadcrumbs(): bool
```

```php
$panel->navigation(false)    // no rail, and no top nav bar either
      ->topbar(false)        // no header row: no breadcrumbs, search, switcher, bell, theme toggle
      ->breadcrumbs(false);  // header stays, breadcrumb trail goes
```

Each removes the part rather than hiding it: nothing is rendered and, for navigation, nothing is drawn from the shared prop. A panel with one page has nothing to navigate; a kiosk has no use for breadcrumbs.

Turning the topbar off also removes global search, the panel switcher, the tenant switcher and the notification bell, because all four live in it.

## Content width

```php
public function maxContentWidth(?string $maxContentWidth): self   // null by default
public function getMaxContentWidth(): ?string
```

The value is a **token**, mapped on the frontend to a literal Tailwind class, because a class built by interpolation would not survive the compiler:

| Token | Class |
| --- | --- |
| `full` | `max-w-full` |
| `7xl` | `max-w-7xl` |
| `6xl` | `max-w-6xl` |
| `5xl` | `max-w-5xl` |
| `4xl` | `max-w-4xl` |
| `3xl` | `max-w-3xl` |

```php
$panel->maxContentWidth('5xl');
```

`null`, or any token outside the table, resolves to `max-w-full`.

## Replacing the sidebar or the topbar

```php
public function sidebarComponent(?string $component): self
public function topbarComponent(?string $component): self
```

```php
$panel
    ->sidebarComponent('Panels/Admin/Shell/Sidebar')
    ->topbarComponent('Panels/Admin/Shell/Topbar');
```

The argument is a **build-time registry key**, never markup and never a path. The component must live under `resources/js/pages/Panels/{Panel}/Shell/*.vue`, which is what `resolveShellComponent()` globs. A name that was not compiled in cannot be reached, however it arrives.

```vue
<!-- resources/js/pages/Panels/Admin/Shell/Sidebar.vue -->
<script setup lang="ts">
import { useNavigation } from '@/panel/composables/useNavigation';
import { usePanel } from '@/panel/composables/usePanel';

const { groups } = useNavigation();
const { panel } = usePanel();
</script>
```

A replacement is handed the same navigation the built-in one gets, so it is a different *drawing* of the panel rather than a second source of truth about it. An unregistered name falls back to the built-in rail — a mistyped component must not strand somebody on a page they cannot leave. Run `npm run build` after adding one: the glob is evaluated at build time.

The replacement topbar is only honoured by the header shell (`HeaderPanelLayout`), and it is handed the navigation groups as a `groups` prop.

## The account menu

```php
/** @param  array<array-key, array{label: string, url: string, icon?: string|null}>  $items */
public function userMenuItems(array $items): self

/** @return list<array<string, mixed>> */
public function getUserMenuItems(): array
```

```php
$panel->userMenuItems([
    ['label' => 'Support', 'url' => '/support', 'icon' => 'info'],
    ['label' => 'Status', 'url' => 'https://status.example.com'],
]);
```

Entries accumulate, `icon` defaults to `null`, and every entry is a **link the server produced** rather than an action name — this menu is rendered on every page of the panel, and whatever an entry points at authorizes for itself when it is followed.

They arrive at `panel.shell.userMenuItems`. The account menu's contents are the host application's component (`UserMenuContent.vue`, part of the starter kit seam), so **nothing shipped with the package renders these entries**. Read them where you want them:

```ts
import { usePanel } from '@/panel/composables/usePanel';

const { shell } = usePanel();   // shell.userMenuItems
```

## What the frontend receives

```php
panel('admin')->getSidebar();
// [
//   'collapsible' => true,
//   'defaultOpen' => true,
//   'variant' => 'sidebar',
//   'appearance' => 'inset',
//   'width' => '16rem',
//   'collapsedWidth' => '3rem',
//   'component' => null,
// ]

panel('admin')->getShell();
// [
//   'navigation' => true,
//   'topbar' => true,
//   'breadcrumbs' => true,
//   'topbarComponent' => null,
//   'userMenuItems' => [],
// ]
```

Both are inside `toSharedArray()`, so they are available on every panel page:

```ts
import { usePanel } from '@/panel/composables/usePanel';

const { panel, shell, maxContentWidthClass } = usePanel();
// panel.value.sidebar.variant, shell.value.breadcrumbs, ...
```

## Refreshing the shell

There is no endpoint that answers "what does the sidebar look like now" — it would have to re-resolve the panel, the user and the URL to say anything true, which is what a request already does. Refetch the shared props instead:

```ts
import { usePanelShell } from '@/panel/composables/usePanelShell';

const { reloadNavigation, reloadTopbar, reloadShell } = usePanelShell();

reloadNavigation();   // router.reload({ only: ['navigation'] })
reloadTopbar();       // only: ['panel', 'notifications', 'panels']
reloadShell();        // both of the above
```

Reach for it after something that changes what the navigation says: a badge that counts pending records, a resource that has become visible, a tenant that was switched.

## Notes

- **`defaultOpen` is not what opens the rail.** The shipped `AppShell.vue` passes the host application's `sidebarOpen` prop — read from the `sidebar_state` cookie in `HandleInertiaRequests` — to shadcn's `SidebarProvider`. `panel.sidebar.defaultOpen` is serialized for your own components to read; it does not currently override the cookie.
- `collapsible: false` does two things: a fixed rail, and non-collapsible navigation groups. That second effect comes from `NavigationRegistry`, which is built with the panel's collapsible flag.
- Layout is panel-level. A page that needs a different shell is a different panel, or a page that renders its own component inside the shell it was given.
- Every panel page declares `defineOptions({ layout: PanelLayout })` for itself. An unconditional `page.default.layout = AppLayout` in `resources/js/app.ts` overwrites that and puts every panel screen inside the application's shell at HTTP 200 with nothing logged. Use `??=`; `panel:install` refuses to finish quietly when it finds the unconditional form.
- The layouts use `overflow-x-clip` rather than `overflow-x-hidden` on the content column. `hidden` on one axis computes the other to `auto`, which makes the element a scroll container and silently breaks every sticky bar inside it.

## See also

- [Branding, Logo, Icon, Favicon](branding.md)
- [Navigation Groups](navigation-groups.md)
- [Render Hooks](render-hooks.md)
- [Panel Assets](assets.md)
- [Dashboards](dashboards.md)
- [Custom Shell Components](../frontend/custom-shell.md)
- [Component Tree](../frontend/component-tree.md)
- [Component Registries](../concepts/component-registries.md)
- [Breadcrumbs](../pages-navigation/breadcrumbs.md)
