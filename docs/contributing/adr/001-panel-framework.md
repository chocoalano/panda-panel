# ADR 001 — A panel framework on Laravel, Inertia, and Vue

- **Status:** accepted
- **Date:** 2026-08-14
- **Supersedes:** nothing

---

## Context

The application needs admin-style panels: resource CRUD, tables with
server-side search, sorting, filtering and pagination, forms with real
validation, dashboards, standalone pages, and per-panel authorization. It
needs several panels that do not leak into each other.

The starter kit is the official Laravel 13 Vue kit: Inertia v3, Vue 3 with
`<script setup lang="ts">` and strict TypeScript, Tailwind CSS v4,
shadcn-vue, Wayfinder, Fortify, Pest, Pint, Larastan.

The requirement was explicit about the target: Filament's developer
experience for Panel, Resource, Page, Widget, Navigation, Form, Table, and
Action — with Vue and shadcn-vue as the rendering layer, and without
Filament or Livewire.

## Decision

Build an internal panel framework in two namespaces:

- `PandaPanel\*` — the framework.
- `App\Panels\*` — this application's panels.

PHP owns registration, routing, authorization, query composition,
validation, actions, and serialization. Vue owns rendering and interaction.
Inertia is the only bridge; there is no separate SPA API.

Backend schema classes serialize to plain data. Vue receives definitions,
never implementations.

## Why not Filament

Filament is excellent and would have been faster to adopt. It was excluded
because it renders through Livewire, and this application's frontend is Vue
with shadcn-vue and strict TypeScript. Running Livewire for the panel and Vue
for everything else would mean two component models, two state models, and
two build stories in one application.

The alternative of copying Filament's source was also rejected: the value
being borrowed is the *shape* of the API — Panel, Resource, Table, Form,
Action — not its implementation, which is built around Livewire's lifecycle
and would not fit here.

What was borrowed: the vocabulary, the fluent schema builders, the resource
page split, and the discovery model. What was not: any code.

## Why Laravel + Inertia + Vue

Inertia already connects Laravel controllers to Vue pages in this codebase.
Using it for panels means the panel is not a special zone: the same auth
guard, the same middleware, the same routing, the same session, the same
flash toasts, and the same build. A separate SPA API would have duplicated
authorization at a second boundary for no gain.

Trade-off accepted: no client-side routing inside a panel, and each table
interaction is a server round trip. That is what makes the URL the state and
keeps back, forward, refresh, and bookmark working without a client store.

## PHP metadata versus Vue renderer

The boundary is a serialization contract:

- PHP classes describe columns, filters, fields, layouts, actions, widgets,
  and navigation as scalars and arrays.
- Vue renders those descriptions through discriminated unions with exhaustive
  checks, so a PHP type without a renderer is a compile error rather than an
  empty cell.
- Closures live only on the server. A badge closure or a `visible()` predicate
  is evaluated during serialization and only its result crosses.

Values arriving in Vue are **validated, not asserted**. Guard functions narrow
them, so a shape mismatch degrades to an empty cell rather than throwing
inside a table.

## Resource architecture

`Resource::query()` is the single entry point for every record a resource can
reach — list, view, edit, update, delete, bulk, and action lookups. Overriding
it once applies a tenant, module, or permission scope everywhere. A page that
queries the model directly is a bug, and a test asserts against it.

Resource pages are routed as `[Page::class, 'render']` for the GET and
`[Page::class, 'handle']` for the write verb, so pages are real controllers
and panel routes remain `route:cache`-able.

Form fields separate three concerns — rendering, validation, persistence —
which is what allows a password field to be required on create, optional on
edit, still validated when filled, and dropped entirely when blank.

## Panel isolation

Isolation is structural rather than conventional:

- Each panel has its own resource, page, widget, and navigation registries.
- Routes are registered per panel; a resource not registered in a panel has
  no route there.
- `Resource::url()` throws when asked for a URL in a panel that does not
  register it.
- The action endpoint exists on every panel and resolves the named resource
  against **that panel's** registry, so a valid session on one panel cannot
  address another panel's resource through it.

That last point is the one worth keeping in mind: it is the only place where
a cross-panel request is plausible, and it is covered by a test.

## Discovery strategy

Panels are listed explicitly in `PanelServiceProvider`. The classes inside a
panel are discovered from declared paths.

Panels are explicit because registration order and panel count are things a
reader should see in one place. Their contents are discovered because listing
every resource by hand is the boilerplate the requirement asked to remove.

File paths become class names through Composer's registered PSR-4 prefixes.
Discovery does not parse or evaluate source: the autoloader already knows what
a file declares. Only concrete classes implementing the expected contract are
included, and results are sorted so two machines produce the same manifest.

## Caching strategy

`php artisan panel:cache` writes `bootstrap/cache/panels.php` atomically. It
holds **class names only**.

Never cached: authorization results, navigation active state, badge values,
record data, widget data. All of those depend on the current user or URL, so
caching them would serve one person's answers to another. A test asserts the
manifest contains no closure.

With a manifest present, discovery does not run at all. The test proves it by
pointing a panel at a directory that does not exist and asserting the classes
still resolve.

The commands are registered as `optimize` hooks, so a deploy that already runs
`php artisan optimize` gets the panel cache for free.

## Security implications

- **No closure, SQL, policy internals, or configuration in props.** Schemas
  serialize scalars and arrays.
- **The frontend sends identifiers only.** An action request carries an action
  name, a resource slug, and record keys. The backend resolves what to run.
- **Component and icon names resolve through build-time registries.** An
  `import.meta.glob` allowlist means a name that was not compiled in cannot be
  reached, whatever the request says.
- **Navigation visibility is not access control.** Routes, actions, pages, and
  widgets each authorize independently, and hidden items are covered by tests
  that request the URL directly.
- **Query parameters are whitelisted by schema.** An unknown sort column, an
  out-of-range `perPage`, or an unrecognised filter is ignored rather than
  reaching the builder. LIKE wildcards in a search term are escaped.
- **Widget authorization precedes data resolution**, so an unauthorized widget
  never runs a query.
- **Bulk actions are all-or-nothing.** Every record is authorized before any is
  touched, inside one transaction.
- **`is_admin` is not mass-assignable.** Tests assert registration and profile
  update cannot set it.
- **Passwords never round-trip.** A password field always serializes as null,
  and the view page skips password fields rather than displaying a hash.

## Trade-offs

| Accepted | Cost |
| --- | --- |
| Server round trip per table interaction | Slower than a client-side table; in exchange the URL is the state and there is no duplicated client store |
| PHP metadata plus a Vue renderer | Two places to touch for a new column type; in exchange the boundary is explicit and type-checked on both sides |
| Explicit over magic | More verbose than Filament's conventions in places, for example `getId()` versus a combined accessor |
| Dependency-free SVG chart | No tooltips, zoom, or animation; in exchange no charting library and the widget union stays complete |
| Panels listed by hand | One edit per new panel; in exchange registration order is visible |
| No browser test runner | Client-side *interaction* is covered by types, the build, and server-side request tests only. Pure frontend logic is unit-tested — see `D20`. |

Known gaps, stated rather than implied:

- `Select::relationship()` is implemented but unused: no model here has a
  relation worth selecting, so it is covered by types and guard clauses rather
  than by a feature test.
- The `verified` middleware is inert application-wide because `User` does not
  implement `MustVerifyEmail` while Fortify enables email verification. Panels
  declare it correctly and will enforce it the moment the model implements the
  contract. Whether to require verification is a product decision.

## Future extensions

The seams already exist; none of these are implemented.

| Extension | Seam |
| --- | --- |
| Relation managers | `Resource::pages()` plus a future `relations()` array |
| Clusters | `NavigationRegistry` group ordering plus a panel-level prefix |
| Global search | `GlobalSearchProvider` and `Resource::globalSearchable()` |
| Notifications | The existing flash toast bridge plus a shared prop slot |
| Import / export | Header actions plus the existing action endpoint |
| Wizard forms, tabs, fieldsets | `FormSchema` layout components |
| Infolists | `ViewRecord` display serializer |
| Kanban, calendar | Alternative page classes registered through `pages()` |
| Tenant panels | `PanelContext` extra context and the single `Resource::query()` scope point |
| Modules | `discover*()` and `navigationGroups()` accumulate rather than overwrite, so a `registerModule()` could contribute to a panel without core changes |

The module seam is the one deliberately verified: a test registers two
discovery paths on one panel and asserts both survive, because a
single-path implementation would have been a dead end.

## Decisions recorded during implementation

| # | Decision |
| --- | --- |
| D1 | Install `@tanstack/vue-table` and six shadcn components — approved |
| D2 | Shared props are `panel` and `navigation` |
| D3 | `panel/` for generic pages, `Panels/{Panel}/` for application pages |
| D4 | Add `is_admin` to `users` — approved |
| D5 | Dependency-free SVG chart instead of a charting library |
| D6 | User status derives from `email_verified_at`; no `status` column |
| D7 | Laravel Precognition evaluated and rejected: Inertia v3 plus server validation already satisfies the requirement, and Precognition would add a dependency and a round trip per keystroke |
| D8 | `->with('success', ...)` maps onto the existing single toast bridge |
| D9 | Fluent setters keep bare names; readers are `get`-prefixed |
| D10 | Panel access is one mechanism: `->canAccess(Closure)` |
| D11 | `NavigationBuilder` takes no user argument, because it could not honour one |
| D12 | `is_admin` shipped in phase 2, defaulted in the model as well as the database |
| D13 | Shared props typed through `InertiaConfig.sharedPageProps` |
| D14 | `Panel::sidebar(variant:)`, without which the header shell would be dead code |
| D15 | Pages do not wire their layout; `usePanelPage()` reads and validates the metadata prop |
| D16 | `render`/`handle` routing, with `store` and `update` route names |
| D17 | Delete hooks live on `Action`, not on the page trait, because the endpoint runs without a page instance |
| D18 | `Field::dehydrateTo()` maps a field onto a different attribute |
| D19 | A card grid is a second renderer over one `TableSchema`, not the alternative page class the Future extensions table reserves for Kanban and calendar |
| D20 | Vitest unit-tests the frontend's pure modules; components and interaction stay uncovered by design |

D20 narrows the "no browser test runner" row rather than reversing it. That row was written about
*interaction* — a click, a popover, a component under a DOM — and that is still covered by the
types, the build, and the request tests that assert the payload a component is handed. What it was
never meant to exclude is a plain function: how a card face resolves against a column arrangement,
how a filter value becomes a query string, where a run of grouped rows breaks. Each of those decides
something the server cannot check and the type system cannot state, and each was previously covered
by reading it. `vitest run` covers those and nothing else; there is no DOM, no component harness,
and adding one would be a different decision.

D19 is the one that argues with a row above it, so it states its case here.
The Future extensions table files "alternative presentations" under page
classes, and that is right for Kanban and for a calendar: each needs its own
query shape, its own interactions, and its own state. A card grid needs none
of them. It shows the same records from the same query, narrowed by the same
filters, ordered by the same whitelist, paged by the same paginator, and
addressed by the same URL — a page class would have duplicated all five in
order to change one template. What it did need was a sixth piece of state,
`?layout=`, validated exactly as `perPage` is. Where the seam genuinely is
elsewhere the table says so: a reorderable table offers no grid, because
dragging into a wrapping grid is the different interaction that *would* need
its own page.

Three of these corrected an earlier mistake rather than choosing between
options: D17 replaced two documented hooks that could never have been called,
D14 replaced a shell that could never have been reached, and the phase 8 work
replaced a `PanelContext` that leaked between requests.
