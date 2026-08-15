# Changelog

All notable changes to `pandapanel/panda-panel` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Security

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
