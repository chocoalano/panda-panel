# Vue Component Tree

Everything under `resources/js/panel` after `vendor:publish`: which component draws what, which composable reads which prop, and which module you import when you write a component of your own. Reach for this page when you need to reuse a piece of the panel inside your own screen, or when you are reading a stack trace and want to know what a file is for.

## A minimal working example

Every published file is importable through the `@` alias, so a page of your own can use the panel's own pieces:

```vue
<!-- resources/js/pages/Panels/Admin/Pages/Reports.vue -->
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PageHeader from '@/panel/components/PageHeader.vue';
import { usePanel } from '@/panel/composables/usePanel';
import PanelLayout from '@/panel/layouts/PanelLayout.vue';
import type { PageMetadata } from '@/panel/types/page';

defineOptions({ layout: PanelLayout });

defineProps<{ page: PageMetadata }>();

const { panel } = usePanel();
</script>

<template>
    <Head :title="page.title" />

    <PageHeader :heading="page.heading" :subheading="page.subheading" />

    <p class="text-sm text-muted-foreground">{{ panel?.brandName }}</p>
</template>
```

Three imports, three layers: a layout, a component, a composable. The rest of this page is what else is in each.

## The tree

```text
resources/js/panel/
  layouts/       PanelLayout, SidebarPanelLayout, HeaderPanelLayout,
                 PanelBlankLayout, PanelAuthLayout
  components/    PanelSidebar, PanelNavigation, PanelNavigationItem,
                 PanelHeader, PanelBreadcrumb, PanelSubNavigation,
                 PanelClusterBar, PanelSearch, PanelNotifications,
                 PanelSwitcher, PanelTenantSwitcher, PanelRenderHook,
                 PanelRecordLayout, PageHeader, EmptyState, LoadingState,
                 DashboardGuide
  tables/        DataTable, DataTableCell, DataTableToolbar, DataTableFilters,
                 DataTableQueryBuilder, DataTablePagination,
                 DataTableBulkActions, DataTableColumnManager, DataTableTabs,
                 filterParams, useFrozenColumns, registry, registryEmptyStates
  forms/         FormRenderer, FormComponentRenderer, FormSection, FormGrid,
                 FormTabs, FormWizard, FormRelationship, FormCustomComponent,
                 FormCallout, FormPrime, FormField, FormEmptyState,
                 fields/*, conditions, validation, http, markdown,
                 optionsEndpoint, uploadEndpoint, formStateEndpoint, registry
  infolists/     InfolistRenderer, InfolistNode, InfolistEntry, InfolistTabs
  widgets/       WidgetGrid, WidgetRenderer, WidgetShell, StatsWidget,
                 TableWidget, ChartWidget, CustomWidget, WidgetFilters,
                 PageWidgets, WidgetFallback, registry
  actions/       ActionButton, ActionGroup, ActionDialog, ActionModal
  relations/     RelationManagerList, RelationManagerPanel, RelationFormDialog
  composables/   usePanel, usePanelPage, usePanelShell, usePanelStyling,
                 usePanelBroadcasting, useNavigation, useResource, useActions,
                 useInfolistActions, useRelationActions, useRelationTable,
                 useErrorNotifications, useUnsavedChangesAlert
  icons/         registry
  hooks/         registry
  shell/         registry
  lib/           grid
  palette.ts
  types/         panel, shared, navigation, breadcrumb, page, table, form,
                 infolist, relation, action, widget, cellGuards, widgetGuards
```

## Layouts

Every panel page declares its own layout, so nothing is wired in `resources/js/app.ts`.

| Layout | Props | Role |
| --- | --- | --- |
| `PanelLayout.vue` | `breadcrumbs?: PanelBreadcrumbItem[]` | The one a page declares. Picks the shell from `panel.sidebar.variant`, registers the error and broadcast listeners, and falls back to the page's own breadcrumbs |
| `SidebarPanelLayout.vue` | `breadcrumbs?` | The side-rail shell |
| `HeaderPanelLayout.vue` | `breadcrumbs?` | The top-navigation shell, used when the panel sets `sidebar(variant: 'header')` |
| `PanelBlankLayout.vue` | none | No chrome at all — what the panel's auth pages declare |
| `PanelAuthLayout.vue` | `panel: PanelDefinition`, `title: string`, `description?: string` | The frame the auth pages draw for themselves: the panel's brand, a heading, and nothing else |

```vue
<script setup lang="ts">
import PanelLayout from '@/panel/layouts/PanelLayout.vue';

defineOptions({ layout: PanelLayout });
</script>
```

`PanelLayout` resolves breadcrumbs from `page.breadcrumbs` when the prop is not passed, so a page never has to wire its trail up:

```vue
<script setup lang="ts">
import PanelLayout from '@/panel/layouts/PanelLayout.vue';
import type { PanelBreadcrumbItem } from '@/panel/types/breadcrumb';

// Only for the rare page that builds its trail client-side.
const breadcrumbs: PanelBreadcrumbItem[] = [
    { label: 'Dashboard', href: '/admin', current: false },
    { label: 'Reports', href: null, current: true },
];

defineOptions({ layout: PanelLayout });
</script>
```

`PanelBlankLayout` exists because `layout: null` does not work: the common host resolver is `page.default.layout = page.default.layout || AppLayout`, and `null || AppLayout` is `AppLayout`. A component that renders nothing but its slot is the only way to say "not the application's".

## Shell components

| Component | Draws |
| --- | --- |
| `PanelSidebar.vue` | the rail: brand, navigation, user footer. Reads `panel.sidebar.appearance` and validates it before handing it to shadcn |
| `PanelNavigation.vue` | the groups, with nested groups indented under their parent |
| `PanelNavigationItem.vue` | one item, including its active icon and badge |
| `PanelHeader.vue` | the top bar: sidebar trigger, breadcrumbs, search, switchers, notifications, theme toggle |
| `PanelBreadcrumb.vue` | the trail |
| `PanelSearch.vue` | the command palette; renders nothing unless search is on *and* a resource opted in |
| `PanelNotifications.vue` | the bell and the notification centre |
| `PanelSwitcher.vue` | moves between panels; renders nothing when the user may enter only one |
| `PanelTenantSwitcher.vue` | moves between tenants; needs tenancy, more than one tenant, and a tenant URL |
| `PanelLocaleSwitcher.vue` | changes the language; renders nothing unless the panel offers more than one |
| `PanelClusterBar.vue` | a cluster's sub-navigation, as a bar or a column |
| `PanelSubNavigation.vue` | the links between one record's pages |
| `PanelRecordLayout.vue` | arranges a record page around that sub-navigation |
| `PanelRenderHook.vue` | renders whatever the panel injected at a named point |
| `PageHeader.vue` | heading, subheading, and an `actions` slot |
| `EmptyState.vue`, `LoadingState.vue` | the neutral states |
| `DashboardGuide.vue` | what a dashboard shows before anything is on it |
| `PanelDatePicker.vue` | one date, picked from a popover calendar. Mounted by `DateField` and by both bounds of a date filter, so a date is chosen the same way everywhere |

`PanelRenderHook` is the only one of these you are likely to place yourself, and it takes one prop:

```vue
<script setup lang="ts">
import PanelRenderHook from '@/panel/components/PanelRenderHook.vue';
</script>

<template>
    <PanelRenderHook name="page.start" />
</template>
```

The name is a `PanelRenderHookName`: `body.start`, `body.end`, `sidebar.start`, `sidebar.end`, `header.start`, `header.end`, `page.start`, `page.end`. Scoping is filtered here rather than on the server, because shared props are built in middleware — before the request reaches a page — so the shell knows which page it is rendering and the middleware does not.

## Renderers

Four trees, one shape: a top-level renderer, a node dispatcher that recurses, and leaves.

| Area | Entry | Dispatcher | Leaves |
| --- | --- | --- | --- |
| Tables | `DataTable.vue` | `DataTableCell.vue` | switch on `column.type` |
| Forms | `FormRenderer.vue` | `FormComponentRenderer.vue` | `forms/fields/*.vue` |
| Infolists | `InfolistRenderer.vue` | `InfolistNode.vue` | `InfolistEntry.vue` |
| Widgets | `WidgetGrid.vue` | `WidgetRenderer.vue` | `StatsWidget`, `TableWidget`, `ChartWidget`, `CustomWidget` |

Every dispatcher switches on a discriminant and ends in an exhaustive `never` check, so adding a PHP type without a Vue renderer is a compile error rather than a blank cell.

### Tables

| File | Role |
| --- | --- |
| `DataTable.vue` | rows, headers, grouping, frozen columns, reordering, empty state |
| `DataTableCell.vue` | one cell, including editable controls and custom columns |
| `DataTableToolbar.vue` | search, filter trigger, toolbar actions, deferred filter staging |
| `DataTableFilters.vue` | the filter controls; every accessor narrows rather than asserts |
| `DataTableQueryBuilder.vue` | composed conditions, from the server's declaration of what may be constrained |
| `DataTableTabs.vue` | filter tabs — a tab is a URL, not local state |
| `DataTablePagination.vue` | page links and per-page |
| `DataTableBulkActions.vue` | the sticky selection bar |
| `DataTableColumnManager.vue` | which columns are shown and in what order |
| `useFrozenColumns.ts` | offsets for pinned columns |
| `filterParams.ts` | writing and clearing filter query parameters |

TanStack Table v9 owns the row model and row selection, and nothing else — `tableFeatures({ rowSelectionFeature })` is the whole registration. Sorting, filtering, pagination, and column visibility and order are all server-side, so those features are deliberately not registered.

### Forms

`forms/fields/` holds one component per field type, plus two that are not field types:

```text
BuilderField        CheckboxField       CheckboxListField   CodeEditorField
ColorPickerField    DateField           DateTimeField       FileUploadField
KeyValueField       MarkdownEditorField NumberField         PasswordField
RadioField          RepeaterField       RichEditorField     SelectField
SliderField         TagsInputField      TextInputField      TextareaField
TimeField           ToggleButtonsField  ToggleField

FieldWrapper        the label, helper text and error every field wears
CustomFieldRenderer resolves a CustomField's component through the registry
```

The layout components — `FormSection`, `FormGrid`, `FormTabs`, `FormWizard`, `FormRelationship`, `FormCustomComponent` — all recurse back through `FormComponentRenderer`, so nesting depth is a data concern rather than a component one.

The supporting modules:

| Module | Exports |
| --- | --- |
| `conditions.ts` | `matchesConditions()`, `conditionDependencies()`, `isBlankValue()` |
| `validation.ts` | `validateFields()` — the subset of rules a browser can honestly check |
| `http.ts` | `csrfToken()`, `postJson()`, `postForm()` |
| `markdown.ts` | `renderMarkdown()` |
| `optionsEndpoint.ts` | `provideOptionsUrl()`, `useOptionsUrl()`, `fetchOptions()` |
| `uploadEndpoint.ts` | `provideUploadUrl()`, `useUploadUrl()`, `uploadFile()` |
| `formStateEndpoint.ts` | `provideFormStateUrl()`, `useFormStateUrl()`, `fetchFormState()` |

### Widgets

`WidgetShell.vue` wraps every widget with its heading, description, filter form, polling timer and the `panel-widget` hook class, so a custom widget draws only its body. `PageWidgets.vue` renders the widgets a resource page places above and below its own content. `WidgetFallback.vue` is what an unresolvable component name gets.

### Actions

| File | Role |
| --- | --- |
| `ActionButton.vue` | one action, as a button or a link |
| `ActionGroup.vue` | row actions collapsed into a menu |
| `ActionDialog.vue` | the confirmation for a destructive action |
| `ActionModal.vue` | the one dialog every action opens: confirmation, custom content, and the action's form |

## Composables

All under `@/panel/composables/`.

| Composable | Signature |
| --- | --- |
| `usePanel` | `usePanel(): UsePanelReturn` |
| `usePanelPage` | `usePanelPage(): ComputedRef<PageMetadata \| null>` |
| `usePanelShell` | `usePanelShell(): UsePanelShellReturn` |
| `usePanelStyling` | `usePanelStyling(): UsePanelStylingReturn` |
| `usePanelBroadcasting` | `usePanelBroadcasting(): void` |
| `useNavigation` | `useNavigation(): UseNavigationReturn` |
| `useResource` | `useResource(resource: () => ResourceMeta, state: () => TableState): UseResourceReturn` |
| `useActions` | `useActions(resourceSlug: () => string, endpoints: () => ActionEndpoints, parentKey?: () => string \| number \| null): UseActionsReturn` |
| `useInfolistActions` | `useInfolistActions(...): UseInfolistActionsReturn` |
| `useRelationActions` | `useRelationActions(...): UseRelationActionsReturn` |
| `useRelationTable` | `useRelationTable(...): UseRelationTableReturn` |
| `useErrorNotifications` | `useErrorNotifications(): void` |
| `useUnsavedChangesAlert` | `useUnsavedChangesAlert(isDirty: Ref<boolean>): void` |

### `usePanel()`

```ts
import { usePanel } from '@/panel/composables/usePanel';

const {
    panel,                 // ComputedRef<PanelDefinition | null>
    hasPanel,              // ComputedRef<boolean>
    maxContentWidthClass,  // ComputedRef<string> — a literal Tailwind class
    panels,                // ComputedRef<PanelSummary[]>
    canSwitchPanels,       // ComputedRef<boolean>
    broadcasting,          // ComputedRef<PanelBroadcasting>
    search,                // ComputedRef<PanelSearchSettings>
    notifications,         // ComputedRef<PanelNotificationSettings>
    shell,                 // ComputedRef<PanelShellSettings>
    tenancy,               // ComputedRef<PanelTenancy | null>
    canSwitchTenants,      // ComputedRef<boolean>
} = usePanel();
```

Every consumer must tolerate a null `panel`: the shell can render during a navigation that leaves the panel. `shell` falls back to everything-on, because the shell a panel has said nothing about is the whole shell.

### `usePanelPage()`

```ts
import { usePanelPage } from '@/panel/composables/usePanelPage';

const page = usePanelPage();
// page.value?.heading, page.value?.breadcrumbs, page.value?.scope
```

The `page` prop is validated rather than cast. `normalizePageMetadata(value: unknown): PageMetadata | null` is exported from the same module for anywhere you have the raw value.

### `usePanelShell()`

```ts
import { usePanelShell } from '@/panel/composables/usePanelShell';

const { reloadNavigation, reloadTopbar, reloadShell } = usePanelShell();

reloadNavigation();  // router.reload({ only: ['navigation'] })
reloadTopbar();      // router.reload({ only: ['panel', 'notifications', 'panels'] })
reloadShell();       // both
```

There is no endpoint answering "what does the sidebar look like now" — it would have to re-resolve the panel, the user and the URL to say anything true, which is what a request already does.

### `usePanelStyling()`

```ts
import { usePanelStyling } from '@/panel/composables/usePanelStyling';

const { themeStyle, hook } = usePanelStyling();
// themeStyle.value === { '--primary': '#4f46e5' }
// hook('topbar') === 'panel-topbar border-b-2 border-amber-500'
```

See [CSS Hooks](css-hooks.md).

### `useNavigation()`

```ts
import { useNavigation } from '@/panel/composables/useNavigation';

const { groups, items, activeItem, isCollapsed, toggle } = useNavigation();
```

The groups are read-only server data and are never copied into local state. The only client-owned bit is which collapsible groups the user closed, persisted per panel under `panel:{id}:collapsed-groups`.

### `useResource()`

```ts
import { useResource } from '@/panel/composables/useResource';

const {
    setSearch, setSort, setPage, setPerPage,
    setFilter, setFilters, clearFilters,
    setColumns, resetColumns, setColumnSearch,
    setTab, nextDirectionFor,
} = useResource(() => props.resource, () => props.state);

setSearch('ada');                // ?search=ada
setFilter('verified', 'true');   // ?filters[verified]=true
```

Every control writes to the query string and lets the server answer. Visits use `preserveState` and `preserveScroll`, so typing in the search box loses neither focus nor scroll position.

## Registries

Six build-time `import.meta.glob` allowlists, plus the generated icon map.

| Module | Glob | Resolver |
| --- | --- | --- |
| `@/panel/tables/registry` | `pages/Panels/**/Columns/*.vue` | `resolveColumnComponent(name)` |
| `@/panel/forms/registry` | `pages/Panels/**/{Fields,Schemas,Entries,Modals}/*.vue` | `resolveFormComponent(name)` |
| `@/panel/widgets/registry` | `pages/Panels/**/Widgets/*.vue` | `resolveWidgetComponent(name)` |
| `@/panel/hooks/registry` | `pages/Panels/**/Hooks/*.vue` | `resolveHookComponent(name)` |
| `@/panel/shell/registry` | `pages/Panels/**/Shell/*.vue` | `resolveShellComponent(name)` |
| `@/panel/tables/registryEmptyStates` | `pages/Panels/**/EmptyStates/*.vue` | `resolveEmptyStateComponent(name)` |
| `@/panel/icons/registry` | generated by `panel:icons` | `resolveIcon(name)` |

Every resolver returns a loader or `null`; nothing throws. Four also expose a membership test — `hasColumnComponent`, `hasFormComponent`, `hasWidgetComponent`, `hasHookComponent`. See [Component Registries](../concepts/component-registries.md).

## Types and guards

`types/` mirrors the PHP serialization, one module per area: `panel`, `shared`, `navigation`, `breadcrumb`, `page`, `table`, `form`, `infolist`, `relation`, `action`, `widget`.

Two of them are runtime code rather than types:

```ts
import { asBadgeCell, asTextCell } from '@/panel/types/cellGuards';
import { asStats, asChart } from '@/panel/types/widgetGuards';
```

| Module | Exports |
| --- | --- |
| `cellGuards.ts` | `asTextCell`, `asNumberCell`, `asBadgeCell`, `asBooleanCell`, `asDateCell`, `asImageCell`, `asIconCell`, `asColorCell`, `asEditableCell` |
| `widgetGuards.ts` | `asStats`, `asTable`, `asChart`, `asCustomData` |

Each takes `unknown` and returns a narrowed value or a null-ish fallback. Values crossing from PHP are narrowed rather than asserted, so a shape mismatch degrades to an empty cell instead of throwing inside a table.

`types/shared.ts` holds `PanelSharedProps` and `panelSharedProps()`, the one deliberate cast in the whole frontend:

```ts
import { panelSharedProps } from '@/panel/types/shared';

const props = panelSharedProps();  // ComputedRef<PanelSharedProps>
```

A `ComputedRef` rather than a snapshot, because `usePage()` is reactive and the props change under a client-side navigation.

## Layout helpers

```ts
import { MAX_COLUMNS, gridClass, spanClass } from '@/panel/lib/grid';

MAX_COLUMNS;             // 4
gridClass(3);            // 'grid-cols-1 md:grid-cols-2 lg:grid-cols-3'
spanClass('full', 3);    // 'col-span-full'
spanClass(3, 4);         // clamped to two columns at md, three at lg
```

A four-column container is only two columns wide at `md` — 768px, of which a panel spends about 256px on the sidebar — so a span is clamped separately at each breakpoint. Without that, `grid-column: span 3` against two tracks creates a third implicit one and the row overflows sideways.

```ts
import { BADGE_CLASSES, ICON_CLASSES, SELECTED_CLASSES } from '@/panel/palette';
import type { BadgeColorName } from '@/panel/palette';

BADGE_CLASSES.success;   // 'bg-emerald-100 text-emerald-800 dark:...'
```

Both modules exist for the same reason: every class is written out in full. An interpolated `md:grid-cols-${n}` or `bg-${color}-100` is invisible to the Tailwind compiler, so the class would simply not exist in the bundle.

## Notes

- **No `any`.** Metadata unions are discriminated on `type` and every switch ends in an exhaustive `never` check.
- **Validate, do not assert.** `cellGuards`, `widgetGuards` and `usePanelPage` narrow what crosses the wire. `panelSharedProps()` is the single deliberate cast, and it lives in one file so the risk is inside the package rather than in an application's build.
- **No server state in local state.** The only local state is a debounced search input, form working values, row selection, and which navigation groups are collapsed.
- **No hardcoded panel URLs.** Every href comes from the server or from Wayfinder.
- **The content column uses `overflow-x-clip`, not `overflow-x-hidden`.** `hidden` on one axis computes the other to `auto`, which makes the element a scroll container — and a scroll container captures every `position: sticky` inside it. The selection bar and the form's save row both sit in there. A test asserts the spelling.
- **These files are published, so they are yours.** Editing one is supported; `panel:assets` will then report it as `modified` and leave it alone on an upgrade.

## See also

- [Published Asset Structure](assets.md)
- [Inertia Pages](inertia-pages.md)
- [CSS Hooks](css-hooks.md), [Tailwind Theme](tailwind-theme.md)
- [Custom Columns](custom-columns.md), [Custom Fields](custom-fields.md), [Custom Widgets](custom-widgets.md), [Custom Shell Components](custom-shell.md)
- [Component Registries](../concepts/component-registries.md)
- [Server Metadata to Vue](../concepts/metadata-to-vue.md)
- [Inertia and Vue Approach](../introduction/inertia-vue.md)
- [Sidebar and Header Layouts](../panels/layouts.md)
- [Render Hooks](../panels/render-hooks.md)
