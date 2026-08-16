# Custom Page Components

Every panel screen is an Inertia response naming a Vue component. This page is about replacing that component with one of your own — for a standalone page that draws something the generic renderer cannot, or for a resource page that needs a layout the shipped one does not have.

A page does not need a Vue file. `Page::$component` defaults to `panel/Page`, the generic renderer, so the cheapest useful page is a PHP class with a title. Reach for a component when the page has content the framework has no shape for.

## A minimal working example

```bash
php artisan make:panel-page Reports --panel=Admin --component
```

Two files. The class:

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Pages;

use PandaPanel\Pages\Page;

final class Reports extends Page
{
    protected static ?string $navigationIcon = 'file-text';

    protected static int $navigationSort = 0;

    protected static string $component = 'Panels/Admin/Pages/Reports';

    /**
     * @return array<string, mixed>
     */
    public function props(): array
    {
        return [
            'totals' => ['orders' => 128, 'revenue' => '4,201.00'],
        ];
    }
}
```

And the component, at `resources/js/pages/Panels/Admin/Pages/Reports.vue`:

```vue
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PageHeader from '@/panel/components/PageHeader.vue';
import PanelLayout from '@/panel/layouts/PanelLayout.vue';
import type { PageMetadata } from '@/panel/types/page';

defineOptions({ layout: PanelLayout });

defineProps<{
    page: PageMetadata;
    totals: { orders: number; revenue: string };
}>();
</script>

<template>
    <Head :title="page.title" />

    <div class="flex flex-col gap-6">
        <PageHeader :heading="page.heading" :subheading="page.subheading" />

        <dl class="grid gap-4 sm:grid-cols-2">
            <div class="rounded-lg border p-4">
                <dt class="text-sm text-muted-foreground">Orders</dt>
                <dd class="text-2xl tabular-nums">{{ totals.orders }}</dd>
            </div>
            <div class="rounded-lg border p-4">
                <dt class="text-sm text-muted-foreground">Revenue</dt>
                <dd class="text-2xl tabular-nums">{{ totals.revenue }}</dd>
            </div>
        </dl>
    </div>
</template>
```

```bash
npm run build     # or: npm run dev
```

## `$component` is an Inertia component name

```php
protected static string $component = 'panel/Page';
```

The value is what `Inertia::render()` is given, so it is resolved by the **application's** Inertia resolver — normally a glob over `resources/js/pages/**`. It is not a registry key, and it is not resolved through `import.meta.glob` the way a custom column or widget is:

| Value | File |
| --- | --- |
| `panel/Page` | `resources/js/pages/panel/Page.vue` (shipped) |
| `panel/Dashboard` | `resources/js/pages/panel/Dashboard.vue` (shipped) |
| `Panels/Admin/Pages/Reports` | `resources/js/pages/Panels/Admin/Pages/Reports.vue` (yours) |

Both directories are under `resources/js/pages` because that is where the resolver looks. `Panels/` is capitalised and `panel/` is not, which is what keeps the framework's own screens and the application's apart at a glance.

A component name that resolves to nothing fails in Inertia, in the browser, with the name in the message. This is unlike a registry miss, which degrades to a fallback — a page has nothing to fall back to.

## The generic renderer

Leaving `$component` alone gives you `resources/js/pages/panel/Page.vue`, which draws:

- `<Head :title="page.title" />`;
- a `PageHeader` with the heading, subheading and the page's header actions;
- a `WidgetGrid`, when the page declares widgets;
- a slot, which nothing fills — so a page with no widgets is a header and nothing else.

Its props:

```ts
defineProps<{
    page: PageMetadata;
    widgets: WidgetDefinition[];
    widgetData?: WidgetData | null;
}>();
```

That is the whole reason a page needs no Vue file: a settings screen made of widgets is already drawable.

## What every page component receives

`Page::render()` sends four framework props, then spreads `props()` over them:

```php
return Inertia::render(static::$component, [
    'page' => $this->metadata(),
    'widgets' => $widgets->definitions(),
    'widgetData' => $widgets->deferred(),
    'filters' => $schema === null ? null : ['form' => $schema->toArrayWithState(null, $filters->dashboard())],
    ...$this->props(),
]);
```

| Prop | Type | Always present |
| --- | --- | --- |
| `page` | `PageMetadata` | yes |
| `widgets` | `WidgetDefinition[]` | yes, empty when the page declares none |
| `widgetData` | `WidgetData \| null` | **deferred** — absent from the first response |
| `filters` | `{ form: FormDefinition } \| null` | yes, null unless `filterSchema()` returns one |
| anything from `props()` | yours | — |

`page` is the one to declare in every page component, because the layout reads it too:

```ts
export interface PageMetadata {
    title: string;
    heading: string;
    subheading: string | null;
    breadcrumbs: PanelBreadcrumbItem[];
    headerActions: unknown[];
    scope: string | null;
    subNavigation: PageSubNavigation;
    cluster: ClusterNavigation | null;
}
```

`headerActions` is `unknown[]` rather than a typed union. Cast it at the point of use, the way the shipped renderer does:

```ts
import type { ActionDefinition } from '@/panel/types/action';

const headerActions = props.page.headerActions as ActionDefinition[];
```

`widgetData` is deferred, so declare it optional:

```ts
withDefaults(
    defineProps<{
        page: PageMetadata;
        widgets: WidgetDefinition[];
        widgetData?: WidgetData | null;
    }>(),
    { widgetData: null },
);
```

## Declaring the layout

**Every page component must declare its own layout.** This is the single most consequential line in the file:

```ts
import PanelLayout from '@/panel/layouts/PanelLayout.vue';

defineOptions({ layout: PanelLayout });
```

Without it, the page takes whatever the application's resolver gives a page that names none — which on a Laravel Vue starter kit is the signed-in application shell. The panel then renders with the host's sidebar and its own navigation nowhere, at HTTP 200, with nothing logged.

The generator's `--component` stub does **not** include this line. Add it to anything the generator writes.

`PanelLayout` picks the sidebar or header shell from the panel's configuration and resolves breadcrumbs from `page.breadcrumbs`, so a page never has to wire either up. It takes one optional prop for the rare page that builds its trail client-side:

```ts
defineProps<{ breadcrumbs?: PanelBreadcrumbItem[] }>();
```

## The generator

```bash
php artisan make:panel-page Reports --panel=Admin
php artisan make:panel-page Reports --panel=Admin --component
php artisan make:panel-page Reports --panel=Admin --component --force
```

| Option | Effect |
| --- | --- |
| `--panel=` | required; the panel the page belongs to. `Admin` and `admin` mean the same panel |
| `--component` | also write the Vue file, and set `$component` to `Panels/{Panel}/Pages/{Class}` |
| `--force` | overwrite files that already exist |

Without `--component`, `$component` is written as `panel/Page` and no Vue file is created.

The Vue file is written to `FrontendPaths::pages("{$panel}/Pages/{$class}.vue")` — `resources/js/pages/Panels/Admin/Pages/Reports.vue` by default, and wherever `panda-panel.frontend.pages_path` points otherwise.

The generator refuses to overwrite an existing file and reports it as skipped. When every file it would have written already exists, the command exits non-zero — which is what makes a missing `--force` visible in CI rather than a silent no-op.

## Resource pages

Resource pages have the same `$component` mechanism, with a shipped default each:

| Page class | Default `$component` | Shipped file |
| --- | --- | --- |
| `PandaPanel\Resources\Pages\ListRecords` | `panel/resources/Index` | `pages/panel/resources/Index.vue` |
| `PandaPanel\Resources\Pages\CreateRecord` | `panel/resources/Create` | `pages/panel/resources/Create.vue` |
| `PandaPanel\Resources\Pages\ViewRecord` | `panel/resources/View` | `pages/panel/resources/View.vue` |
| `PandaPanel\Resources\Pages\EditRecord` | `panel/resources/Edit` | `pages/panel/resources/Edit.vue` |
| `PandaPanel\Resources\Pages\ManageRelatedRecords` | `panel/resources/ManageRelated` | `pages/panel/resources/ManageRelated.vue` |

```php
namespace App\Panels\Admin\Resources\Users\Pages;

use PandaPanel\Resources\Pages\ListRecords;

final class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected static string $component = 'Panels/Admin/Pages/UsersBoard';
}
```

Replacing one of these means taking on everything that page sends. `panel/resources/Index` alone declares thirteen props — `page`, `resource`, `table`, `state`, `rows`, `pagination`, `summaries`, `groupSummaries`, `actionEndpoints`, `tabs`, `headerWidgets`, `footerWidgets`, `widgetData` — and wires them into the table components and `useResource`. Read the shipped file before replacing it; most of the time a [custom column](custom-columns.md) or a [render hook](../panels/render-hooks.md) is the smaller change that does the job.

## Composing with the panel's own pieces

Everything under `resources/js/panel` is importable, which is what makes a custom page cheap:

```vue
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import EmptyState from '@/panel/components/EmptyState.vue';
import PageHeader from '@/panel/components/PageHeader.vue';
import PanelRenderHook from '@/panel/components/PanelRenderHook.vue';
import { usePanelStyling } from '@/panel/composables/usePanelStyling';
import PanelLayout from '@/panel/layouts/PanelLayout.vue';
import type { PageMetadata } from '@/panel/types/page';
import type { WidgetData, WidgetDefinition } from '@/panel/types/widget';
import WidgetGrid from '@/panel/widgets/WidgetGrid.vue';

defineOptions({ layout: PanelLayout });

withDefaults(
    defineProps<{
        page: PageMetadata;
        widgets: WidgetDefinition[];
        widgetData?: WidgetData | null;
        rows: Array<{ id: number; label: string }>;
    }>(),
    { widgetData: null },
);

const { hook } = usePanelStyling();
</script>

<template>
    <Head :title="page.title" />

    <div class="flex flex-col gap-6">
        <PageHeader :heading="page.heading" :subheading="page.subheading" />

        <PanelRenderHook name="page.start" />

        <WidgetGrid
            v-if="widgets.length > 0"
            :widgets="widgets"
            :widget-data="widgetData"
        />

        <EmptyState v-if="rows.length === 0" heading="Nothing yet" />

        <!-- A block of your own that a panel's cssHooks can still reach. -->
        <section v-else class="rounded-lg border p-4" :class="hook('widget')">
            <p v-for="row in rows" :key="row.id" class="text-sm">
                {{ row.label }}
            </p>
        </section>
    </div>
</template>
```

`PageHeader` already applies `hook('page-header')` itself, and the layout applies `hook('page')` around your content. Calling `hook()` on a block of your own is what lets a panel's `cssHooks()` reach what you drew — see [CSS Hooks](css-hooks.md).

## Gotchas

- **A generated page component declares no layout.** The published pages under `resources/js/pages/panel` all do, and a test asserts it, but the `--component` stub does not. Add `defineOptions({ layout: PanelLayout })`.
- **`props()` is spread last.** Returning a key named `page`, `widgets`, `widgetData` or `filters` replaces the framework's. Name page props for what they hold.
- **`filters` is sent even when the component ignores it.** Only `panel/Dashboard` renders the filter bar; `panel/Page` declares no such prop.
- **`$component` is not a registry key.** `import.meta.glob` does not resolve it. It goes through the application's own Inertia page resolver, so a bad name is a runtime error rather than a fallback.
- **Header actions on the generic renderer must be links.** Nothing on `panel/Page` handles a callback action's `run` event.
- **A page that draws its own component still gets `canAccess()`.** `render()` starts with `abort_unless(static::canAccess(), 403)` — the component has nothing to do with authorization.
- **`panel()` throws outside a panel request.** A page instantiated in a unit test needs a current panel set first.
- **`pages_path` moves where the generator writes, not where Inertia looks.** Inertia resolves against `resources/js/pages`; the config only changes the subdirectory the generators target.

## See also

- [Custom Pages (pages guide)](../pages-navigation/custom-pages.md)
- [Inertia Pages](inertia-pages.md)
- [Vue Component Tree](component-tree.md)
- [Custom Widgets](custom-widgets.md), [Custom Shell Components](custom-shell.md)
- [CSS Hooks](css-hooks.md)
- [Resource Pages](../resources/resource-pages.md), [CRUD Pages](../resources/crud-pages.md)
- [Render Hooks](../panels/render-hooks.md)
- [make:panel-page](../cli/make-panel-page.md)
