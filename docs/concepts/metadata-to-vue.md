# Server Metadata to Vue

Laravel owns registration, routing, authorization, queries, validation, and
metadata. Vue owns rendering. Inertia is the only bridge, and everything that
crosses it is scalars and arrays — a closure is evaluated on the server and
only its result is sent. This page is the map of what crosses, in what shape,
and what deliberately does not.

## Seeing the payload

Every panel page ships the shared props plus its own. The fastest way to read
them is a test:

```php
use Inertia\Testing\AssertableInertia;

$this->actingAs($admin)
    ->get('/admin/users')
    ->assertInertia(fn (AssertableInertia $page) => $page
        ->component('panel/resources/Index')
        ->where('panel.id', 'admin')
        ->where('resource.slug', 'users')
        ->where('page.heading', 'Users')
        ->has('table.columns')
    );
```

In the browser, `usePage().props` holds the same object.

## The shared props

`PandaPanel\Http\Middleware\SharePanelData` shares seven keys through
`Inertia::share()`, which merges — the application's own
`HandleInertiaRequests` is untouched. Every value is a closure, so a request
that never reaches a panel pays for none of them.

| Prop | Source | Value outside a panel |
| --- | --- | --- |
| `panel` | `Panel::toSharedArray()` | `null` |
| `navigation` | `NavigationBuilder::for()` | `[]` |
| `panels` | accessible panels, for the switcher | `[]` |
| `broadcasting` | `{enabled, channel}` | `{false, null}` |
| `search` | `{enabled, url, debounce, keyBindings}` | disabled |
| `notifications` | `{enabled, indexUrl, readUrl, clearUrl, unread}` | disabled |
| `tenancy` | `{current, available}` | `null` |

Read them through one accessor rather than `usePage()` directly:

```ts
import { usePanel } from '@/panel/composables/usePanel';

const { panel, navigation, panels, search, notifications, tenancy } = usePanel();
```

`resources/js/panel/types/shared.ts` performs the single cast in the whole
panel frontend, and `PanelSharedProps` mirrors that middleware exactly. A
contract test asserts that no other file under `resources/js/panel` reads one
of those seven keys off `usePage()`.

### `panel`

`Panel::toSharedArray()` returns exactly:

| Key | Type |
| --- | --- |
| `id`, `name`, `path` | `string` |
| `brandName` | `string` |
| `brandLogo`, `icon`, `favicon` | `string\|null` |
| `darkMode` | `bool` |
| `maxContentWidth` | `string\|null` |
| `unsavedChangesAlerts` | `bool` |
| `prefetch` | `'hover'\|'mount'\|'click'\|null` |
| `errorNotifications` | `array<int, {title, body}\|null>` |
| `renderHooks` | `array<string, list<{component, data, scopes}>>` |
| `sidebar` | `{collapsible, defaultOpen, variant, appearance, width, collapsedWidth, component}` |
| `shell` | `{navigation, topbar, breadcrumbs, topbarComponent, userMenuItems}` |
| `theme` | `{light: Record<string,string>, dark: Record<string,string>}` |
| `cssHooks` | `Record<string, string>` |

What is absent is as deliberate as what is present: middleware, discovery
paths, the asset list, `databaseTransactions`, `strictAuthorization`, and boot
callbacks are server concerns and never cross. The frontend receives the
settings it acts on and nothing else.

The TypeScript mirror is `PanelDefinition` in
`resources/js/panel/types/panel.ts`.

### `navigation`

`NavigationBuilder::for(Panel $panel, string $currentPath): list<array>`
returns groups, each holding items:

```php
[
    'label' => $this->label,
    'href' => $this->href,
    'icon' => $this->icon,
    'activeIcon' => $this->activeIcon ?? $this->icon,
    'badge' => $this->resolveBadge(),
    'active' => $this->active,
    'sort' => $this->sort,
    'fullPage' => $this->fullPage,
    'children' => [/* recursively */],
]
```

Everything here is per request: authorization results, badge values, and
active state all depend on the current user and URL, so none of it may be
cached beside the panel manifest. `activeIcon` is sent whether or not the item
is active, so the sidebar can swap icons on a client-side navigation without
waiting for the server to say which item won.

## Page metadata

Every panel page ships a `page` prop with the same shape, so the layout can
render the header, breadcrumbs, and sub-navigation without each page wiring
them up.

```php
protected function metadata(): array
{
    return [
        'title' => static::title(),
        'heading' => static::heading(),
        'subheading' => static::$subheading,
        'breadcrumbs' => array_map(fn (Breadcrumb $c): array => $c->toArray(), $this->breadcrumbs()),
        'headerActions' => $this->headerActions(),
        'scope' => static::renderHookScope(),
        'cluster' => /* ClusterNavigation, or null */,
    ];
}
```

| Key | Type | Notes |
| --- | --- | --- |
| `title` | `string` | The browser tab |
| `heading` | `string` | Follows `title` unless the page separates them |
| `subheading` | `string\|null` | |
| `breadcrumbs` | `list<{label, href, current}>` | |
| `headerActions` | `list<array>` | Serialized actions |
| `scope` | `string\|null` | `resource:{slug}` or `page:{slug}` |
| `cluster` | `{label, icon, position, items}\|null` | Null when the cluster has nothing visible |
| `subNavigation` | `{items, position}` | Record pages only |

A record page — view, edit, a custom page that takes `{record}` — adds
`subNavigation`. A standalone page and a resource index omit the key entirely,
and the frontend's narrower turns that into `{items: [], position: 'top'}`
rather than treating it as a shape error: a page with no record has nowhere
else to be.

`scope` is a slug, never a class name. Nothing in page metadata may name a PHP
class — which is also why `Panel::renderHook()` reduces
`UserResource::class` to `resource:users` at registration time.

Read it on the Vue side through the narrower, not the raw prop:

```ts
import { usePanelPage } from '@/panel/composables/usePanelPage';

const page = usePanelPage();   // ComputedRef<PageMetadata | null>
```

## Resource page props

`ListRecords::render()` is the widest payload in the framework:

| Prop | Shape |
| --- | --- |
| `page` | page metadata, as above |
| `resource` | `{slug, label, pluralLabel, indexUrl, parentKey}` |
| `table` | `TableSchema::toArray()` — columns, filters, groups, toolbar behaviour |
| `state` | `{search, sort, direction, perPage, filters, filterIndicators, columnSearches, columns, group}` |
| `tabs` | `list<array>` |
| `headerWidgets`, `footerWidgets` | widget definitions |
| `widgetData` | deferred data for lazy widgets, or `null` |
| `rows` | serialized cells, one entry per record |
| `summaries`, `groupSummaries` | aggregate values |
| `pagination` | counts and links |
| `actionEndpoints` | `{record, bulk, reorder, cell, table, form, infolist}` |

`actionEndpoints` is sent as data rather than assembled in Vue, so no panel
URL is hardcoded in the frontend. It sits on every resource page, not only the
list, because a view page's infolist can carry actions too.

`resource.parentKey` exists because the action endpoints are one set per panel
and carry no parent segment — a nested resource's table has to send its parent
with every action it posts.

A create or edit page ships `form` instead of `table`, built by
`FormSchema::toArray($record)` and passed through the page's fill hooks.

## Widgets

A widget serializes in two parts.

```php
public function toDefinition(): array   // id, type, sort, columnSpan, lazy, heading, description, polling, filters
```

```php
$widgets->definitions();   // list<array>, one per visible widget
$widgets->deferred();      // Inertia::defer(fn () => ['widget-id' => $data]) — or null
```

A widget that is not lazy carries its data in the definition. A lazy one
carries only the definition, and its data arrives as an Inertia deferred prop.
`deferred()` returns `null` when no widget on the page is lazy, so the page
does not advertise a second request it will never make.

`canView()` is checked before `toArray()` runs, so an unauthorized widget
never executes its queries — and never appears in the definitions either.

## The rules that hold everywhere

- **Metadata only.** Schemas serialize to scalars and arrays. A closure is
  evaluated on the server; only the result is sent.
- **No PHP class names.** Render-hook scopes, page scopes, and navigation
  entries all carry slugs. `ResourceRoutingTest` asserts that a serialized
  table definition contains no `App\` and no SQL.
- **No dynamic component resolution.** Icons and custom components are names
  resolved through build-time registries. A name that was not compiled in
  renders nothing rather than being fetched. See
  [Component Registries](component-registries.md).
- **No server state in local state.** The only local state in the frontend is
  a debounced search input, form working values, row selection, and collapsed
  navigation groups.
- **The URL is the table state.** Page, per-page, search, sort, direction, and
  filters live in the query string, so back, forward, refresh, and bookmark
  behave.

## Validate, do not assert

Values crossing from PHP are narrowed by guards rather than cast, so a shape
mismatch degrades to an empty cell instead of throwing inside a layout.

```ts
export function normalizePageMetadata(value: unknown): PageMetadata | null {
    if (!isRecord(value) || typeof value.heading !== 'string') {
        return null;
    }

    return {
        title: typeof value.title === 'string' ? value.title : value.heading,
        heading: value.heading,
        subheading: typeof value.subheading === 'string' ? value.subheading : null,
        breadcrumbs,
        headerActions: Array.isArray(value.headerActions) ? value.headerActions : [],
        scope: typeof value.scope === 'string' ? value.scope : null,
        subNavigation: toSubNavigation(value.subNavigation),
        cluster: toCluster(value.cluster),
    };
}
```

The same pattern lives in `cellGuards.ts` for table cells and `widgetGuards.ts`
for widget payloads. A sub-navigation position the shell has no branch for
falls back to `'top'`; a cluster with no visible items becomes `null`.

Where a payload is genuinely not yet typed it is `unknown[]`, not `any` —
`headerActions` is the example. Typing it as `unknown` keeps the compiler
honest at the point of use.

## The type mirrors

Each file under `resources/js/panel/types/` mirrors one server shape:

| File | Mirrors |
| --- | --- |
| `panel.ts` | `Panel::toSharedArray()`, plus the switcher, search, notification, and tenancy props |
| `navigation.ts` | `NavigationGroup::toArray()` and `NavigationItem::toArray()` |
| `page.ts` | `Page::metadata()` |
| `breadcrumb.ts` | `Breadcrumb::toArray()` |
| `table.ts` | `TableSchema::toArray()` |
| `form.ts` | `FormSchema::toArray()` |
| `infolist.ts` | `InfolistSchema::toArray()` |
| `action.ts` | `Action::toArray()` |
| `widget.ts` | `Widget::toDefinition()` |
| `relation.ts` | relation manager payloads |
| `shared.ts` | `SharePanelData` |

Metadata unions are discriminated on `type`, and every switch over one ends in
an exhaustive `never` check — so a new PHP type without a Vue renderer is a
compile error rather than a blank cell.

## Notes

- Props are shared for every `web` request, not only panel ones. On a
  starter-kit page `panel` is `null` and `navigation` is `[]`; both are
  present rather than absent, so the frontend never has to distinguish
  "missing" from "outside a panel".
- `usePage()` is reactive and the props change under a client-side navigation.
  `panelSharedProps()` returns a `ComputedRef` for that reason — snapshotting
  once would type-check perfectly and leave the sidebar showing the panel the
  user left.
- Render-hook scope filtering happens in Vue, not on the server. Shared props
  are built in middleware, before the request reaches a page, so the shell
  knows which page it is rendering and the middleware does not.
- The notification unread count is read on every panel request rather than
  polled, so the bell is right after any navigation without a second round
  trip. It is one indexed count on a table scoped to one user.
- Nothing that crosses is cached. `bootstrap/cache/panels.php` holds class
  names only — see [Caching](caching.md).

## See also

- [Request Lifecycle](request-lifecycle.md)
- [Panels](panels.md)
- [Component Registries](component-registries.md)
- [Frontend Assets](frontend-assets.md)
- [Caching](caching.md)
- [Component Tree](../frontend/component-tree.md)
- [Inertia Pages](../frontend/inertia-pages.md)
- [Frontend Contract Tests](../testing/frontend-contract-tests.md)
