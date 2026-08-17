# Architecture Decisions

This package has one architecture decision record — [`adr/001-panel-framework.md`](adr/001-panel-framework.md) — and it is the reason most of the framework looks the way it does. Read it before proposing a change to the boundary between PHP and Vue, to discovery, to caching, or to how panels are isolated from each other; those are settled questions with written reasons, and a pull request that reopens one without addressing the reason is a pull request that will be sent back.

## A minimal working example

```bash
cat docs/contributing/adr/001-panel-framework.md
```

The header is the whole format:

```markdown
# ADR 001 — A panel framework on Laravel, Inertia, and Vue

- **Status:** accepted
- **Date:** 2026-08-14
- **Supersedes:** nothing
```

Three fields, then Context, Decision, the alternatives that were rejected and why, the trade-offs accepted, and the decisions recorded while implementing it.

## What ADR 001 decides

Nine things, each of which shows up somewhere you would otherwise find surprising.

**Two namespaces.** `PandaPanel\*` is the framework; `App\Panels\*` is an application's panels. PHP owns registration, routing, authorization, query composition, validation, actions and serialization. Vue owns rendering and interaction. Inertia is the only bridge, and there is no separate SPA API.

**No Filament, and no copy of Filament.** Filament renders through Livewire, and this frontend is Vue with shadcn-vue and strict TypeScript; running both would mean two component models, two state models and two build stories in one application. Copying Filament's source was rejected separately: the value being borrowed is the *shape* of the API — Panel, Resource, Table, Form, Action — not an implementation built around Livewire's lifecycle. What was borrowed is the vocabulary, the fluent schema builders, the resource page split and the discovery model. What was not: any code.

**A serialization contract, not an object graph.** PHP classes describe columns, filters, fields, layouts, actions, widgets and navigation as scalars and arrays. Vue renders those descriptions through discriminated unions with exhaustive checks, so a PHP type without a renderer is a compile error rather than an empty cell. Closures live only on the server; a badge closure or a `visible()` predicate is evaluated during serialization and only its result crosses. Values arriving in Vue are **validated, not asserted** — guard functions narrow them, so a shape mismatch degrades to an empty cell rather than throwing inside a table.

**`Resource::query()` is the single entry point.** Every record a resource can reach — list, view, edit, update, delete, bulk, action lookups — comes through it, so overriding it once applies a tenant, module or permission scope everywhere. A page that queries the model directly is a bug, and a test asserts against it.

**Pages are real controllers.** Resource pages route as `[Page::class, 'render']` for the GET and `[Page::class, 'handle']` for the write verb, which is what keeps panel routes `route:cache`-able.

**Panel isolation is structural.** Each panel has its own resource, page, widget and navigation registries. Routes are registered per panel, `Resource::url()` throws when asked for a URL in a panel that does not register it, and the action endpoint resolves the named resource against **that panel's** registry — so a valid session on one panel cannot address another panel's resource through it. That last point is the only place a cross-panel request is plausible, and it is covered by a test.

**Panels are explicit, their contents are discovered.** Registration order and panel count are things a reader should see in one place. File paths become class names through Composer's registered PSR-4 prefixes; discovery parses and evaluates nothing, because the autoloader already knows what a file declares. Only concrete classes implementing the expected contract are included, and results are sorted so two machines produce the same manifest.

**The cache holds class names only.** `php artisan panel:cache` writes `bootstrap/cache/panels.php` atomically. Never cached: authorization results, navigation active state, badge values, record data, widget data — all of them depend on the current user or URL, so caching them would serve one person's answers to another. A test asserts the manifest contains no closure. With a manifest present, discovery does not run at all; the test proves it by pointing a panel at a directory that does not exist and asserting the classes still resolve.

**Security follows from the boundary.** The ADR states the implications as a list, and the negative suite states each one as a test. See [Security](security.md).

## The trade-offs, which are decisions too

Written down so they are not re-argued as bugs:

| Accepted | Cost |
| --- | --- |
| Server round trip per table interaction | Slower than a client-side table; in exchange the URL is the state and there is no duplicated client store |
| PHP metadata plus a Vue renderer | Two places to touch for a new column type; in exchange the boundary is explicit and type-checked on both sides |
| Explicit over magic | More verbose than Filament's conventions in places, for example `getId()` versus a combined accessor |
| Dependency-free SVG chart | No tooltips, zoom or animation; in exchange no charting library and the widget union stays complete |
| Panels listed by hand | One edit per new panel; in exchange registration order is visible |
| No browser test runner | Client-side interaction is covered by types, the build and server-side request tests only |

"No browser test runner" is a decision rather than a gap. A proposal to add one is a proposal to reverse a recorded trade-off, and it needs an ADR.

## The decisions table

The ADR ends with eighteen smaller decisions recorded during implementation, `D1` to `D18`. They are the ones that changed an API without changing the architecture:

| # | Decision |
| --- | --- |
| D1 | Install `@tanstack/vue-table` and six shadcn components |
| D2 | Shared props are `panel` and `navigation` |
| D3 | `panel/` for generic pages, `Panels/{Panel}/` for application pages |
| D4 | Add `is_admin` to `users` |
| D5 | Dependency-free SVG chart instead of a charting library |
| D6 | User status derives from `email_verified_at`; no `status` column |
| D7 | Laravel Precognition evaluated and rejected |
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

Three of them corrected an earlier mistake rather than choosing between options: D17 replaced two documented hooks that could never have been called, D14 replaced a shell that could never have been reached, and the phase 8 work replaced a `PanelContext` that leaked between requests. A row that records a correction is more useful than a quiet fix, because the next person to have the same idea reads why it did not work.

A change of this size — an API decision with a reason worth keeping, that does not alter the architecture — adds a row here rather than a new ADR.

## When a change needs a new ADR

Write one when the change would make a sentence in ADR 001 false, or would reverse a row in its trade-off table. Concretely:

| Change | Needs an ADR |
| --- | --- |
| Anything other than scalars and arrays crossing to Vue | Yes — it is the central decision |
| A second bridge beside Inertia, such as a JSON API for panels | Yes |
| Adding a runtime dependency to `composer.json` or `dependencies` in `package.json` | Yes — D1 is the precedent |
| Caching anything user- or URL-dependent in the panel manifest | Yes |
| Changing how panels are isolated, or how the action endpoint resolves a resource | Yes |
| Discovery reading or evaluating source instead of using PSR-4 | Yes |
| Client-side routing inside a panel | Yes — it reverses a stated trade-off |
| A browser test runner | Yes — same |
| Implementing one of the listed future extensions | No — the seam was already decided |
| A new column, field or widget type | No |
| A new fluent setter following D9 | No |
| A bug fix, however large | No |

The listed extensions are the ones ADR 001 already accounted for, each with the seam it would use:

| Extension | Seam |
| --- | --- |
| Relation managers | `Resource::pages()` plus a `relations()` array |
| Clusters | `NavigationRegistry` group ordering plus a panel-level prefix |
| Global search | `GlobalSearchProvider` and `Resource::globalSearchable()` |
| Notifications | The flash toast bridge plus a shared prop slot |
| Import and export | Header actions plus the existing action endpoint |
| Wizard forms, tabs, fieldsets | `FormSchema` layout components |
| Infolists | `ViewRecord` display serializer |
| Kanban, calendar | Alternative page classes registered through `pages()` |
| Tenant panels | `PanelContext` extra context and the single `Resource::query()` scope point |
| Modules | `discover*()` and `navigationGroups()` accumulating rather than overwriting |

Several of these have since been built — relation managers, clusters, global search, notifications, import and export, wizards, infolists, tenancy — and none of them needed a new ADR, because each used the seam the record named. The module seam is the one deliberately verified: a test registers two discovery paths on one panel and asserts both survive, because a single-path implementation would have been a dead end.

## Writing one

Add a file beside the first:

```bash
$EDITOR docs/contributing/adr/002-your-decision.md
```

Number it sequentially, name the file after the decision, and open with the same three fields:

```markdown
# ADR 002 — A title that states the decision, not the topic

- **Status:** proposed
- **Date:** 2026-08-16
- **Supersedes:** nothing
```

`Status` is `proposed` until it is merged, then `accepted`. A record that replaces an earlier one sets `Supersedes` to that number, and the earlier record's status becomes `superseded by 002` — an ADR is never deleted or edited into a different decision, because the point of the file is that somebody can find out what was believed at the time.

Then the sections ADR 001 uses, in order:

- **Context** — what was true when the question came up. Constraints, not conclusions.
- **Decision** — one or two paragraphs, in the present tense.
- **Why not X** — the alternatives, named, with the reason each was rejected. This is the section people actually read; "Filament was excluded because it renders through Livewire" is worth more than the decision itself.
- **Implications** — what follows mechanically, including for security.
- **Trade-offs** — a table of what was accepted and what it costs. Anything with no cost has not been thought about yet.
- **Known gaps** — stated rather than implied. ADR 001 names `Select::relationship()` as implemented but untested by a feature test, and the `verified` middleware as inert because the example `User` does not implement `MustVerifyEmail`.

Link the new record from the pull request that implements it, and add a `Changed` entry to `CHANGELOG.md` if the decision changes anything an application can see.

## Notes

- **The ADR lives under `docs/contributing/adr/`, which is `export-ignore`d.** So does the rest of `docs/`. It is a repository document, not something an installed package carries.
- **The reference documentation is the master, not the ADR.** [`docs/index.md`](../index.md) indexes it, and it describes what the framework *does*; the ADR describes *why it is that shape*. When the two disagree about a command name or a level number, the source files win — the configuration in the repository root is the authority. (The old single-file master, `docs/target_framework/panel-framework.md`, was removed once it had drifted past the sectioned documentation that replaced it; `git log` still has it.)
- **A known gap is not a bug report.** The two ADR 001 names are deliberate and documented; closing one is a normal change, and neither needs an ADR to close.
- **`Supersedes: nothing` is a real value.** Write it rather than omitting the field, so a reader can tell the field was considered.
- **Nothing enforces this mechanically.** There is no test that fails when an ADR is missing. It is a review question, which is why the table above is written as a table.

## See also

- [Coding standards](coding-standards.md) — the conventions D9 and the serialization boundary produce
- [Pull requests](pull-requests.md) — where the ADR question is asked
- [Security](security.md) — the ADR's security implications, as tests
- [Releases](releases.md) — recording a decision that changes what an application sees
- [Architecture at a glance](../introduction/architecture.md)
- [Package limits and trade-offs](../introduction/tradeoffs.md)
- [Comparison with Filament concepts](../introduction/filament-comparison.md)
- [Server metadata to Vue](../concepts/metadata-to-vue.md)
- [Discovery](../concepts/discovery.md) and [Caching](../concepts/caching.md)
- [Multi-panel applications](../panels/multi-panel.md)
