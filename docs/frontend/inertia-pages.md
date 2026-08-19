# Inertia Pages

Every panel screen is an ordinary Inertia response: a component name, some props, and the shared props the panel middleware adds. There is no separate SPA API. Reach for this page when you need to know which component answers a URL, what props it receives, or why a panel screen is rendering inside your application's shell.

## A minimal working example

A page class names a component; Inertia renders it; the component declares its own layout.

```php
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

Nothing was registered in `resources/js/app.ts`, and nothing needs to be.

## The shipped page components

`vendor:publish --tag=panda-panel-assets` writes these under `resources/js/pages/panel/`. Each is the default for one kind of screen.

| Component | Rendered by | Default for |
| --- | --- | --- |
| `panel/Dashboard` | `PandaPanel\Pages\Dashboard` | the panel's root screen |
| `panel/Page` | `PandaPanel\Pages\Page` | every standalone page |
| `panel/resources/Index` | `Resources\Pages\ListRecords` | a resource's list screen |
| `panel/resources/Create` | `Resources\Pages\CreateRecord` | the create form |
| `panel/resources/Edit` | `Resources\Pages\EditRecord` | the edit form |
| `panel/resources/View` | `Resources\Pages\ViewRecord` | the record view |
| `panel/resources/ManageRelated` | `Resources\Pages\ManageRelatedRecords` | a relation page |
| `panel/resources/Integrations` | `PanelIntegrationController` | the integrations screen |
| `panel/settings/Profile` | `Pages\Settings\ProfileSettings` | the profile settings page |
| `panel/settings/Security` | `Pages\Settings\SecuritySettings` | the security settings page |
| `panel/settings/Appearance` | `Pages\Settings\AppearanceSettings` | the appearance settings page |
| `panel/auth/Login` | `PanelAuthController::login` | a panel with its own front door |
| `panel/auth/Register` | `PanelAuthController::register` | |
| `panel/auth/ForgotPassword` | `PanelAuthController::requestPasswordReset` | |
| `panel/auth/ResetPassword` | `PanelAuthController::resetPassword` | |
| `panel/auth/VerifyEmail` | `PanelAuthController::verifyEmail` | |
| `panel/auth/EmailCode` | `PanelTwoFactorController` | the email code challenge |

The name is the path below `resources/js/pages/`, which is what Inertia's resolver expects. Lowercase `panel/` is the framework's; capitalised `Panels/` is yours.

## Shared props

`PandaPanel\Http\Middleware\SharePanelData` puts ten props on every `web` response through `Inertia::share()`, which merges — your own `HandleInertiaRequests` is untouched.

```ts
export interface PanelSharedProps {
    panel: PanelDefinition | null;      // null outside a panel, never absent
    navigation: NavigationGroup[];
    panels: PanelSummary[];
    broadcasting: PanelBroadcasting;
    search: PanelSearchSettings;
    notifications: PanelNotificationSettings;
    tenancy: PanelTenancy | null;       // null for a panel with no tenancy
    locale: string;                     // what the server resolved
    translations: Record<string, unknown>; // lang/{locale}/frontend.php
    locales: PanelLocales | null;       // null unless the panel offers two
}
```

Read them through the composables rather than `usePage()`:

```ts
import { usePanel } from '@/panel/composables/usePanel';
import { useNavigation } from '@/panel/composables/useNavigation';

const { panel, shell, notifications } = usePanel();
const { groups, activeItem } = useNavigation();
```

`panelSharedProps()` in `@/panel/types/shared` is the single cast in the whole frontend, and it lives in one file on purpose: a module augmentation of `@inertiajs/core` has to reach the host's tsconfig and merge with whatever its starter kit already declares, and when any of that fails the type falls back to `{}` and every read becomes a compile error in *the application's* build. A test asserts that no other file in `resources/js/panel` reads a shared prop from `usePage()` directly.

## Page props, per screen

Each page type sends its own props beside the shared ones.

### `panel/Page`

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

`Page::render()` also sends `filters`, which the generic renderer does not declare — only `panel/Dashboard` draws the filter bar.

### `panel/Dashboard`

```ts
withDefaults(
    defineProps<{
        page: PageMetadata;
        widgets: WidgetDefinition[];
        widgetData?: WidgetData | null;
        filters?: { form: FormDefinition } | null;
    }>(),
    { widgetData: null, filters: null },
);
```

### `panel/resources/Index`

Thirteen props, the most of any screen:

| Prop | Type | Notes |
| --- | --- | --- |
| `page` | `PageMetadata` | |
| `resource` | `ResourceMeta` | slug, labels, index URL |
| `table` | `TableDefinition` | the serialized schema |
| `state` | `TableState` | search, sort, direction, per page, filters, columns, group |
| `rows` | `TableRow[]` | cells, already formatted |
| `pagination` | `PaginationMeta` | |
| `summaries`, `groupSummaries` | `TableSummaries` | empty for a table declaring none |
| `actionEndpoints` | `ActionEndpoints` | the URLs `useActions` posts to |
| `tabs` | `TableTab[]` | empty for a table declaring none |
| `headerWidgets`, `footerWidgets` | `WidgetDefinition[]` | placed by the page around its content |
| `widgetData` | `WidgetData \| null` | **deferred** |

### `panel/resources/Create` and `panel/resources/Edit`

| Prop | Sent by |
| --- | --- |
| `page`, `resource` | both |
| `form` | the serialized `FormSchema`, with state on edit |
| `submitUrl` | `store` on create, `update` on edit |
| `optionsUrl` | the options endpoint for live selects |
| `uploadUrl` | the file upload endpoint |
| `formStateUrl` | the endpoint that rebuilds the schema for a live field |
| `validateStepUrl` | present only for a wizard; `null` otherwise |
| `recordKey` | edit only |
| `relations` | edit only |
| `canCreateAnother` | create only |
| widget props | both |

### `panel/resources/View`

`page`, `resource`, `infolist` (null when the resource declares none), `entries` (the form-derived fallback used when it does), `recordKey`, `actionEndpoints`, `relations`, and the widget props.

### The auth screens

Each is given the panel itself, because a guest has no shared panel prop to read:

| Component | Props |
| --- | --- |
| `panel/auth/Login` | `panel`, `canResetPassword`, `canRegister`, `status` |
| `panel/auth/Register` | `panel`, `passwordRules` |
| `panel/auth/ForgotPassword` | `panel`, `status` |
| `panel/auth/ResetPassword` | `panel`, `email`, `token`, `passwordRules` |
| `panel/auth/VerifyEmail` | `panel`, `status` |

## Layouts

Every panel page declares its own layout. Nothing has to be registered in `resources/js/app.ts`.

```ts
defineOptions({ layout: PanelLayout });        // panel screens
defineOptions({ layout: PanelBlankLayout });   // the panel's own auth screens
```

| Layout | Role |
| --- | --- |
| `PanelLayout` | picks the shell from `panel.sidebar.variant`, registers the error and broadcast listeners, resolves breadcrumbs |
| `SidebarPanelLayout` | the side-rail shell |
| `HeaderPanelLayout` | the top-navigation shell |
| `PanelBlankLayout` | no chrome at all |
| `PanelAuthLayout` | the frame the auth pages draw for themselves |

The auth pages declare `PanelBlankLayout` and then draw `PanelAuthLayout` inside their own template. They are separate because none of the shell applies to a guest — no navigation, no notifications, no user menu — and because `layout: null` does not work: the common host resolver is `page.default.layout = page.default.layout || AppLayout`, and `null || AppLayout` is `AppLayout`.

### The one thing an application can get wrong

```ts
// resources/js/app.ts
createInertiaApp({
    resolve: (name) => {
        const page = resolvePageComponent(name, import.meta.glob('./pages/**/*.vue'));

        page.default.layout = AppLayout;    // wrong
        page.default.layout ??= AppLayout;  // correct

        return page;
    },
});
```

An unconditional assignment replaces the panel shell with your application's after the page has already asked for its own. Every panel screen then renders inside the host's sidebar, with the panel navigation nowhere, at HTTP 200 and with nothing logged.

`panel:install` reads `app.ts`, `app.js`, `ssr.ts` and `ssr.js` and refuses to finish quietly when it finds one, naming the file and the line:

```text
resources/js/app.ts line 4 overwrites the layout every panel page declares:

  page.default.layout = AppLayout;

Make it fall back instead, so a page that names its own layout keeps it:

  page.default.layout ??= AppLayout
```

`||=`, `??=`, and any assignment whose right-hand side already falls back all pass.

## Page metadata

Every panel screen carries a `page` prop with the same shape, so the layout can render the header and breadcrumbs without each page wiring them up.

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

It is **validated rather than cast**, because it crosses the PHP/TypeScript boundary and a shape mismatch should degrade to a bare page instead of throwing inside the layout:

```ts
import {
    usePanelPage,
    normalizePageMetadata,
} from '@/panel/composables/usePanelPage';

const page = usePanelPage();                 // ComputedRef<PageMetadata | null>
normalizePageMetadata(someUnknownValue);     // PageMetadata | null
```

`scope` is what a render hook's scoping matches against — `resource:{slug}` or `page:{slug}`, a slug and never a class name. Nothing in page metadata names a PHP class.

## Deferred props

`widgetData` is Inertia-deferred: it is absent from the first response and arrives in a follow-up request. Declare it optional everywhere it appears:

```ts
withDefaults(
    defineProps<{ widgetData?: WidgetData | null }>(),
    { widgetData: null },
);
```

`WidgetRenderer` shows a `LoadingState` skeleton while a lazy widget's payload is missing, and mounts the real renderer once it lands — so a widget component never sees undefined props.

## Navigating

Every href comes from the server or from Wayfinder. Nothing in the panel builds a panel URL by hand.

```vue
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { useNavigation } from '@/panel/composables/useNavigation';

const { items } = useNavigation();

function refresh(): void {
    router.reload({ only: ['rows', 'pagination'] });
}
</script>

<template>
    <Link v-for="item in items" :key="item.href" :href="item.href">
        {{ item.label }}
    </Link>
</template>
```

Table state is written to the query string by `useResource`, with `preserveState` and `preserveScroll`, so typing in the search box loses neither focus nor scroll position — and back, forward, refresh and bookmark all mean what they say.

A navigation item may declare `fullPage: true`, which means the destination needs a real browser navigation rather than an Inertia visit. `PanelNavigationItem` renders a plain anchor for those; prefetching one would fetch a document the client cannot use.

## Adding a page of your own

1. Write the component under `resources/js/pages/Panels/{Panel}/Pages/{Name}.vue`.
2. Declare `defineOptions({ layout: PanelLayout })`.
3. Declare `page: PageMetadata` in `defineProps`, plus whatever `props()` returns.
4. Point the PHP class at it: `protected static string $component = 'Panels/{Panel}/Pages/{Name}';`
5. Rebuild.

`php artisan make:panel-page Reports --panel=Admin --component` does steps 1 and 4 for you. It does **not** write the layout line — add it.

See [Custom Page Components](custom-pages.md).

## Gotchas

- **`$component` is not a registry key.** It goes through the application's own Inertia resolver, so a bad name is a runtime error rather than a fallback. Custom columns, fields, widgets, hooks and shell replacements are the ones that resolve through `import.meta.glob`.
- **A page component with no layout is the failure that answers 200.** Nothing logs it. If a panel screen has your application's sidebar, this is why.
- **`page` props override framework props.** `Page::props()` is spread last, so a key named `page`, `widgets`, `widgetData` or `filters` replaces the framework's.
- **`headerActions` is `unknown[]`.** Cast it at the point of use; typing it as `unknown` rather than `any` keeps the compiler honest.
- **The panel prop is null outside a panel.** It is shared on every `web` request, so it is never absent — but `/`, `/login` and any non-panel page get `null`. Every consumer must tolerate that.
- **There is no second API.** The same guard, middleware, routing, session, flash toasts and build serve panel screens and application screens alike. A second API boundary would have meant duplicating authorization at it for no gain.

## See also

- [Custom Page Components](custom-pages.md)
- [Vue Component Tree](component-tree.md)
- [Published Asset Structure](assets.md)
- [Host Modules](host-modules.md), [Wayfinder Routes](wayfinder.md)
- [Server Metadata to Vue](../concepts/metadata-to-vue.md)
- [Request Lifecycle](../concepts/request-lifecycle.md)
- [Inertia and Vue Approach](../introduction/inertia-vue.md)
- [Sidebar and Header Layouts](../panels/layouts.md)
- [Panel Authentication](../authentication/login.md)
- [Inertia root view troubleshooting](../troubleshooting/inertia-root-view.md)
