# Inertia And Vue Approach

The frontend half of the boundary: what PHP puts on the wire, which Vue components read it, and the
rules that keep a shape mismatch from taking a page down. Read this before writing a custom column,
field, widget, page or shell component — all five resolve through the same build-time registries.

## A page, end to end

The server declares a page. Nothing wires a layout in `app.ts`:

```php
namespace App\Panels\Admin\Pages;

use PandaPanel\Pages\Page;

final class Reports extends Page
{
    protected static ?string $title = 'Reports';

    protected static string $component = 'Panels/Admin/Pages/Reports';
}
```

```vue
<!-- resources/js/pages/Panels/Admin/Pages/Reports.vue -->
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PageHeader from '@/panel/components/PageHeader.vue';
import PanelLayout from '@/panel/layouts/PanelLayout.vue';
import type { PageMetadata } from '@/panel/types/page';

defineOptions({ layout: PanelLayout });

defineProps<{ page: PageMetadata }>();
</script>

<template>
    <Head :title="page.title" />

    <PageHeader :heading="page.heading" :subheading="page.subheading" />
</template>
```

```bash
npm run build
```

A page with nothing bespoke to draw needs no Vue file at all: `Page::$component` defaults to
`panel/Page`, the generic renderer.

## Inertia is the only bridge

There is no separate SPA API. The same auth guard, the same middleware, the same routing, the same
session, the same flash toasts, and the same build serve panel screens and application screens
alike. A second API boundary would have meant duplicating authorization at it for no gain.

What crosses is a *description*. `TableSchema`, `FormSchema`, `InfolistSchema`, `Action` and
`Widget` all serialize to scalars and arrays. Closures live only on the server: a badge colour, a
`visible()` predicate, a `tooltip()` are evaluated during serialization and only their result
travels. That is why panel definitions can hold application logic without any of it becoming part of
the client bundle.

## Shared props

`PandaPanel\Http\Middleware\SharePanelData` shares seven props on every `web` request through
`Inertia::share()`, which merges — your own `HandleInertiaRequests` is untouched. The TypeScript
mirror is `PanelSharedProps` in `resources/js/panel/types/shared.ts`:

```ts
export interface PanelSharedProps {
    panel: PanelDefinition | null;      // null outside a panel, never absent
    navigation: NavigationGroup[];
    panels: PanelSummary[];
    broadcasting: PanelBroadcasting;
    search: PanelSearchSettings;
    notifications: PanelNotificationSettings;
    tenancy: PanelTenancy | null;       // null for a panel with no tenancy
}
```

Every value on the PHP side is a closure, so a request that never reaches a panel pays for none of
them. `panelSharedProps(): ComputedRef<PanelSharedProps>` is the one cast in the whole frontend, and
it returns a `ComputedRef` rather than a snapshot because `usePage()` is reactive and the props
change under a client-side navigation.

The host application's own shared props — `name`, `auth`, `sidebarOpen` — are deliberately absent
from that interface. They belong to the application, and a package that named them would be
describing somebody else's contract.

## Page props

Each page type sends its own props beside the shared ones. The resource index sends the most:

| Prop | Type | Notes |
| --- | --- | --- |
| `page` | `PageMetadata` | title, heading, subheading, breadcrumbs, header actions, scope |
| `resource` | `ResourceMeta` | slug, labels, index URL |
| `table` | `TableDefinition` | the serialized schema |
| `state` | `TableState` | search, sort, direction, perPage, filters, columns, group |
| `rows` | `TableRow[]` | cells, already formatted |
| `pagination` | `PaginationMeta` | |
| `summaries`, `groupSummaries` | `TableSummaries` | empty for a table declaring none |
| `actionEndpoints` | `ActionEndpoints` | the URLs the action composable posts to |
| `tabs` | `TableTab[]` | empty for a table declaring none |
| `headerWidgets`, `footerWidgets` | `WidgetDefinition[]` | |
| `widgetData` | `WidgetData \| null` | **deferred** — absent from the first response |

`Dashboard.vue` and `Page.vue` send `page`, `widgets`, `widgetData` and (for a dashboard) `filters`.
Lazy widget payloads arrive as a deferred prop keyed by widget id, which is why `widgetData` is
optional everywhere it appears.

## Layouts

Every panel page declares its own layout, so nothing has to be registered in `resources/js/app.ts`:

```ts
defineOptions({ layout: PanelLayout });        // panel screens
defineOptions({ layout: PanelBlankLayout });   // the panel's own auth screens
```

| Layout | Role |
| --- | --- |
| `PanelLayout` | picks the shell from `panel.sidebar.variant`, registers the error and broadcast listeners, resolves breadcrumbs |
| `SidebarPanelLayout` | the side rail shell |
| `HeaderPanelLayout` | the top navigation shell, used when `->sidebar(variant: 'header')` |
| `PanelBlankLayout` | no shell |
| `PanelAuthLayout` | the panel's login, register, reset and verify screens |

`PanelLayout` takes one optional prop, `breadcrumbs?: PanelBreadcrumbItem[]`. It defaults to the
page's own metadata, so a page never has to wire its trail up; passing the prop wins for the rare
page that builds one client-side.

The one thing an application can still get wrong is overwriting that choice in `app.ts`:

```ts
page.default.layout = AppLayout;               // replaces the panel shell
page.default.layout ??= AppLayout;             // correct
```

An unconditional assignment puts every panel screen inside the application's own shell, with the
host sidebar and the panel navigation nowhere, at HTTP 200 and with nothing logged. `panel:install`
reads `app.ts` and refuses to finish quietly when it finds one, naming the file and the line.

## Composables

All under `resources/js/panel/composables/`.

| Composable | Signature | What it does |
| --- | --- | --- |
| `usePanel` | `(): UsePanelReturn` | the shared panel props: `panel`, `hasPanel`, `maxContentWidthClass`, `panels`, `canSwitchPanels`, `broadcasting`, `search`, `notifications`, `shell`, `tenancy`, `canSwitchTenants` |
| `usePanelPage` | `(): ComputedRef<PageMetadata \| null>` | validates the `page` prop rather than casting it |
| `useNavigation` | `(): UseNavigationReturn` | `groups`, `items`, `activeItem`, `isCollapsed(group)`, `toggle(group)` |
| `useResource` | `(resource: () => ResourceMeta, state: () => TableState): UseResourceReturn` | every table control: `setSearch`, `setSort`, `setPage`, `setPerPage`, `setFilter`, `setFilters`, `setColumns`, `resetColumns`, `setColumnSearch`, `setTab`, `clearFilters`, `nextDirectionFor` |
| `useActions` | `(resourceSlug: () => string, endpoints: () => ActionEndpoints, parentKey?: () => string \| number \| null): UseActionsReturn` | runs record, table, bulk and cell actions |
| `useInfolistActions` | `(...): UseInfolistActionsReturn` | the view page's own action whitelist |
| `useRelationActions` | `(...): UseRelationActionsReturn` | relation manager actions |
| `useRelationTable` | `(...): UseRelationTableReturn` | a relation's own table state |
| `usePanelShell` | `(): UsePanelShellReturn` | `reloadNavigation()`, `reloadTopbar()`, `reloadShell()` |
| `usePanelStyling` | `(): UsePanelStylingReturn` | `themeStyle`, `hook(name)` |
| `usePanelBroadcasting` | `(): void` | subscribes to the panel's channel; registered by `PanelLayout` |
| `useErrorNotifications` | `(): void` | maps failed responses onto toasts; registered by `PanelLayout` |
| `useUnsavedChangesAlert` | `(isDirty: Ref<boolean>): void` | the leave-confirmation for a dirty form |

`usePanelShell()` refetches part of the shell as a partial reload of the shared props:

```ts
import { usePanelShell } from '@/panel/composables/usePanelShell';

const { reloadNavigation, reloadTopbar, reloadShell } = usePanelShell();

reloadNavigation();   // router.reload({ only: ['navigation'] })
reloadTopbar();       // router.reload({ only: ['panel', 'notifications', 'panels'] })
```

There is no endpoint answering "what does the sidebar look like now", because such an endpoint would
have to re-resolve the panel, the user and the URL to say anything true — which is exactly what a
request already does.

## The URL is the state

`useResource` writes to the query string and lets the server answer. Nothing keeps a local copy of
rows, sort or filters:

```ts
const { setSearch, setSort, setFilter, clearFilters } = useResource(
    () => props.resource,
    () => props.state,
);

setSearch('ada');                    // ?search=ada
setSort('name');                     // ?sort=name&direction=asc
setFilter('verified', 'true');       // ?filters[verified]=true
clearFilters();
```

Visits use `preserveState` and `preserveScroll`, so typing in the search box does not lose focus or
scroll position. TanStack Table is registered for the column model, visibility and row selection
only; its sorting, filtering and pagination features are deliberately not registered, because those
answers come from the server.

## Component registries

Custom components resolve through build-time `import.meta.glob` allowlists over the application's
own tree. A name that was not compiled in cannot be reached, whatever the request says — and because
the name always originates from a registered PHP class rather than from request input, the glob is
the second lock rather than the first.

| Registry | Glob | Put your component in |
| --- | --- | --- |
| columns | `pages/Panels/**/Columns/*.vue` | `resources/js/pages/Panels/{Panel}/Columns/` |
| fields, layouts, entries, modals | `pages/Panels/**/{Fields,Schemas,Entries,Modals}/*.vue` | the matching directory |
| widgets | `pages/Panels/**/Widgets/*.vue` | `resources/js/pages/Panels/{Panel}/Widgets/` |
| render hooks | `pages/Panels/**/Hooks/*.vue` | `resources/js/pages/Panels/{Panel}/Hooks/` |
| shell replacements | `pages/Panels/**/Shell/*.vue` | `resources/js/pages/Panels/{Panel}/Shell/` |
| table empty states | `pages/Panels/**/EmptyStates/*.vue` | `resources/js/pages/Panels/{Panel}/EmptyStates/` |

The key PHP sends is the path below `pages/` without the extension:

```php
use PandaPanel\Tables\Columns\CustomColumn;

CustomColumn::make('health')->component('Panels/Admin/Columns/HealthBar');
```

```vue
<!-- resources/js/pages/Panels/Admin/Columns/HealthBar.vue -->
```

An unknown name renders a neutral fallback rather than throwing, so one mistyped component cannot
take a dashboard down. In development the registry warns once per name in the console, naming the
directory the component has to live in — the three causes (a typo, a file outside the globbed
directory, a build that was not re-run) are indistinguishable from the screen otherwise.

Icons are a different mechanism: `resources/js/panel/icons/registry.ts` is a generated file, not a
glob. Write whatever Lucide name you want and run `php artisan panel:icons` to rewrite it; an
unregistered key renders nothing at all, with no error.

## Rules that keep the frontend honest

- **No `any`.** Metadata unions are discriminated on `type` and every switch ends in an exhaustive
  `never` check, so a new PHP type without a Vue renderer is a compile error rather than an empty
  cell.
- **Validate, do not assert.** Values crossing from PHP are narrowed by guards — `cellGuards.ts`,
  `widgetGuards.ts`, `usePanelPage.ts` — so a shape mismatch degrades to an empty cell instead of
  throwing inside a table. `panelSharedProps()` is the single deliberate cast.
- **No server state in local state.** The only local state is a debounced search input, form working
  values, row selection, and which navigation groups are collapsed.
- **No interpolated Tailwind classes.** Column spans, badge colours, grid columns and content widths
  all map through literal records, because `max-w-${token}` is invisible to the Tailwind compiler and
  would silently not exist in the bundle.
- **No hardcoded panel URLs.** Every href comes from the server or from Wayfinder.

## Theming from PHP

Two separate mechanisms, both read from the shared panel props by one composable:

```php
$panel
    ->colors(
        light: ['primary' => '#4f46e5'],
        dark: ['primary' => '#818cf8'],
    )
    ->cssHooks([
        'topbar' => 'border-b-2 border-amber-500',
        'table-row' => 'hover:bg-amber-50',
    ]);
```

```ts
import { usePanelStyling } from '@/panel/composables/usePanelStyling';

const { themeStyle, hook } = usePanelStyling();
```

Colours are *values*, so the set is open and validated: the property must be one the stylesheet
reads and the value must parse as a colour, or it is dropped silently. Hooks are *meanings*, so the
set of names is closed — `shell`, `sidebar`, `topbar`, `page`, `page-header`, `table`, `table-row`,
`form`, `infolist`, `widget`, `modal`. `hook(name)` always includes the stable `panel-{name}` class,
which is what a stylesheet targets whether or not the panel said anything.

Classes added through `cssHooks()` must survive the Tailwind build. Arbitrary strings from a panel
provider are not in any file Tailwind scans, so either use classes that appear elsewhere in the
application or add the provider to the content globs.

## Per-panel assets

```php
$panel->assets('resources/css/panels/admin.css');
```

Vite entrypoints emitted on that panel's pages and nowhere else. Two edits, deliberately: the path
must also appear in `vite.config.ts`'s `input`, or Vite has nothing to serve and the page fails with
a manifest error. That failure is the right one — a declared asset that was never built is a mistake
— but it is why this is not a one-line change. The list never crosses to the frontend; the browser
gets the tags, not what produced them.

## What the components expect from your application

The published components import [nineteen modules](../frontend/host-modules.md) the package does
not ship, and both kinds are the application's on purpose:

- **Generated** — `@/routes/*` and `@/actions/*` come from Wayfinder, written from your own route
  table. Vendoring a copy would be shipping a snapshot of somebody else's routes.
- **Starter-kit components** — `@/components/UserMenuContent.vue`, `@/composables/useTwoFactorAuth`
  and eight more. These are where a project keeps its own account links and its own two-factor flow.

`panel:install` checks for all nineteen and names the ones that are missing. A Laravel Vue starter
kit application has them all; anything else needs them written first, and
[`frontend/host/`](../frontend/host-modules.md) documents a working stand-in for each.

## Gotchas

- **Glob patterns are relative, never aliased.** Vite's dev server resolves an aliased glob to
  nothing at all while the production build resolves it normally, so an aliased pattern means every
  custom component renders the fallback in development and works once built.
- **A new component is not in the bundle until `npm run build`.** The glob is evaluated at build
  time. This is the most common reason a widget or column that exists renders the fallback.
- **Render hooks are filtered in Vue, not on the server.** Shared props are built in middleware,
  before the request reaches a page, so the shell knows which page it is rendering and the middleware
  does not. Every page reports its own scope in `page.scope`.
- **Published components are yours.** `composer update` cannot improve a file you now own. Run
  `php artisan panel:assets` to see what is behind and `--update` to write only the files you have
  never touched.
- **The panel components are Vue only.** The server half serializes to plain arrays and is
  framework-agnostic, but no React or Svelte renderer is written and none is planned.

## See also

- [Architecture at a Glance](architecture.md) — the server half of this path
- [Server Metadata to Vue](../concepts/metadata-to-vue.md) and [Component Registries](../concepts/component-registries.md)
- [Component Tree](../frontend/component-tree.md), [Inertia Pages](../frontend/inertia-pages.md)
- [Custom Columns](../frontend/custom-columns.md), [Custom Fields](../frontend/custom-fields.md), [Custom Widgets](../frontend/custom-widgets.md)
- [CSS Hooks](../frontend/css-hooks.md), [Tailwind Theme](../frontend/tailwind-theme.md), [Icons](../frontend/icons.md)
- [Wayfinder](../frontend/wayfinder.md), [Host Modules](../frontend/host-modules.md)
- [Updating Assets](../frontend/updating-assets.md)
