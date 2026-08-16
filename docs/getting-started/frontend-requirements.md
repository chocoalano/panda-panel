# Frontend requirements

Every panel screen is a Vue component that reaches your application by `vendor:publish` and is
built by your Vite. Three things have to be true for that to work, and none of them is something
the package can do on your behalf: the npm dependencies, the modules under `@/` that belong to
your application, and a Vite config. Getting any of them wrong fails at `npm run build`, in a
message about a module specifier — a true error about the wrong thing.

## Ask the package what is missing

```bash
php artisan panel:install --no-panel --no-user --no-interaction
```

Or ask the individual checks directly:

```bash
php artisan tinker
```

```php
use PandaPanel\Support\Installer\FrontendRequirements;

FrontendRequirements::missingInertia();      // []
FrontendRequirements::hasVite();             // true
FrontendRequirements::missingNpmPackages();  // ['reka-ui@^2.0.0']
FrontendRequirements::missingHostModules();  // ['@/routes/login', …]
FrontendRequirements::layoutOverrides();     // []
```

Everything below is what those five answers mean.

## 1. The npm dependencies

```php
/** @return list<string> `name@range` pairs, ready for npm install */
public static function npmPackages(): array

/** @return list<string> the same pairs, filtered to the ones you do not declare */
public static function missingNpmPackages(): array
```

`npmPackages()` reads the `dependencies` block of *this package's* `package.json` and returns
`name@range` pairs. There is no second copy of the list anywhere — a restated list is a list that
goes stale the first time a component imports something new.

```php
FrontendRequirements::npmPackages();
// [
//   '@inertiajs/vue3@^3.0.0',
//   '@internationalized/date@^3.12.0',
//   '@laravel/echo-vue@^2.4.0',
//   '@laravel/passkeys@^0.4.0',
//   '@lucide/vue@^1.31.0',
//   '@tailwindcss/vite@^4.1.0',
//   '@tanstack/vue-table@^9.0.0',
//   '@vueuse/core@^14.0.0',
//   'class-variance-authority@^0.7.0',
//   'clsx@^2.1.0',
//   'reka-ui@^2.0.0',
//   'tailwind-merge@^3.0.0',
//   'tailwindcss@^4.1.0',
//   'tw-animate-css@^1.2.0',
//   'vue-input-otp@^0.4.0',
//   'vue-sonner@^2.0.0',
//   'vue@^3.5.0',
// ]
```

`missingNpmPackages()` filters that against your `package.json` — both `dependencies` and
`devDependencies` — and returns what is left. It reads the manifest rather than `node_modules`,
because what matters is whether the project has *declared* the dependency: a transitive copy on
disk today is one somebody else's upgrade removes tomorrow.

```bash
npm install \
  @inertiajs/vue3@^3.0.0 \
  reka-ui@^2.0.0
npm run build
```

An application with no `package.json` at all gets the whole list back.

## 2. The host seam

```php
/** @return list<string> the missing modules, as `@/…` specifiers */
public static function missingHostModules(): array
```

The published components import modules the package does not ship. They are the application's,
and there are two kinds — generated route helpers, and the components where a project keeps its
own account UI.

| Specifier | Where it comes from |
| --- | --- |
| `@/routes` | Wayfinder |
| `@/routes/login` | Wayfinder |
| `@/routes/register` | Wayfinder |
| `@/routes/password` | Wayfinder |
| `@/routes/two-factor` | Wayfinder |
| `@/routes/verification` | Wayfinder |
| `@/actions/App/Http/Controllers/Settings/ProfileController` | Wayfinder |
| `@/actions/App/Http/Controllers/Settings/SecurityController` | Wayfinder |
| `@/actions/Laravel/Passkeys/Http/Controllers/PasskeyRegistrationController` | Wayfinder |
| `@/components/Heading` | Starter kit |
| `@/components/UserInfo` | Starter kit |
| `@/components/UserMenuContent` | Starter kit |
| `@/components/PasskeyItem` | Starter kit |
| `@/components/PasskeyRegister` | Starter kit |
| `@/components/TwoFactorRecoveryCodes` | Starter kit |
| `@/components/TwoFactorSetupModal` | Starter kit |
| `@/composables/useTwoFactorAuth` | Starter kit |
| `@/types` | Starter kit |
| `@/types/ui` | Starter kit — imported by the panel's broadcasting and flash bridge |

`@/x` means `resources/js/x`. A specifier is satisfied by any of these spellings on disk, tried in
order:

```text
.ts   .vue   .d.ts   /index.ts   /index.vue   /index.d.ts
```

A bare match is deliberately *not* in that list. `File::exists()` answers true for a directory, so
a bare match made every directory-shaped entry vacuous: `@/types` was satisfied by the folder this
package publishes into, however empty it was of the module actually being imported. `.d.ts` is
there because a starter kit writes its shared types as `resources/js/types/index.d.ts`, which is a
real answer to `@/types`.

For the generated half:

```bash
php artisan wayfinder:generate
```

For the rest: a Laravel Vue starter kit has them. If yours is not one, minimal stand-ins for every
module live in [`frontend/host/`](../../frontend/host/README.md) in this repository — they are
`export-ignore`d, so `composer require` does not bring them, but they are readable as a reference
for what each one has to export. Each declares the exact props, emits and exports the panel's own
components use.

A test derives the list from the imports in the published tree and compares it against
`FrontendRequirements`, so a module that reached one and not the other fails here rather than in
somebody's build.

## 3. Vite, Inertia, and the root view

```php
public static function hasVite(): bool          // vite.config.ts or vite.config.js at the app root

/** @return list<string> what is missing, in words */
public static function missingInertia(): array
```

`missingInertia()` looks for exactly two files and describes what is absent:

| Missing file | Reported as |
| --- | --- |
| `resources/views/app.blade.php` | `an Inertia root view at resources/views/app.blade.php` |
| `app/Http/Middleware/HandleInertiaRequests.php` | `Inertia's middleware (php artisan inertia:middleware)` |

Without either, the first panel URL is a 500: every panel screen is an Inertia response.

The root view is also where a panel's own Vite entrypoints are emitted. This is the shape from the
example application:

```blade
@vite([
    'resources/css/app.css',
    'resources/js/app.ts',
    "resources/js/pages/{$page['component']}.vue",
    ...(panel()?->getAssets() ?? []),
])
```

`panel()` is the panel for the current request, or `null` outside one, so the spread is empty on
every non-panel page and on a panel that declared no assets of its own.

Your `HandleInertiaRequests` needs nothing panel-specific. `panel`, `navigation`, `panels`,
`search`, `notifications`, `broadcasting` and `tenancy` are all shared by the package's own
`PandaPanel\Http\Middleware\SharePanelData`, so a prop added in a new version never means
hand-merging a line into a file you own.

## 4. The layout rule

```php
/** @return list<array{file: string, line: int, code: string}> */
public static function layoutOverrides(): array
```

Every published panel page names its own layout:

```ts
defineOptions({ layout: PanelLayout });        // PanelBlankLayout on the auth pages
```

So a host entry that does not mention `panel/` at all is correct and is not reported. What is not
correct is an unconditional assignment in the Inertia resolver, which replaces the panel shell
*after* the page has already asked for it:

```ts
page.default.layout = AppLayout;                        // reported
page.default.layout ??= AppLayout;                      // fine
page.default.layout ||= AppLayout;                      // fine
page.default.layout = page.default.layout || AppLayout; // fine
```

Left as it is, every panel screen renders inside the application's own shell — host sidebar, panel
navigation nowhere — at HTTP 200, with nothing logged. It is the one thing about this seam that
cannot be fixed from inside the package, which is why it is checked rather than documented.

`resources/js/app.ts`, `app.js`, `ssr.ts` and `ssr.js` are read, in that order, and each offending
line is returned with its one-indexed line number and the trimmed source.

## What gets published, and where

```php
use PandaPanel\Support\Installer\PublishedAssets;

PublishedAssets::map();                 // absolute source => absolute destination
PublishedAssets::files();               // absolute destination => absolute source, per file
PublishedAssets::relative($absolute);   // the destination as it reads in a report
```

| Package source | Application destination |
| --- | --- |
| `resources/js/panel` | `FrontendPaths::panel()` — `resources/js/panel` by default |
| `resources/js/components` | `resources/js/components` |
| `resources/js/composables` | `resources/js/composables` |
| `resources/js/lib` | `resources/js/lib` |
| `resources/js/pages` | `resources/js/pages` |
| `resources/js/types` | `resources/js/types` |
| `resources/css/panda-panel.css` | `resources/css/panda-panel.css` |

One of those destinations is configurable — `panel_path` — which is why the map is built per call
rather than frozen in a constant:

```php
// config/panda-panel.php
'frontend' => [
    'panel_path' => 'js/panel',          // PandaPanel\Support\FrontendPaths::panel()
    'pages_path' => 'js/pages/Panels',   // PandaPanel\Support\FrontendPaths::pages()
],
```

`pages_path` is not a publish destination: it is where the generators write their components, and
the root of the `import.meta.glob` the frontend resolves component names through, so moving it
means changing that glob too.

## The stylesheet

`resources/css/panda-panel.css` is a complete Tailwind 4 stylesheet, not a fragment: it opens with
`@import 'tailwindcss'` and `@import 'tw-animate-css'`, declares the dark variant, the `@theme
inline` token map, the light and dark custom property sets including every `--sidebar-*` token the
shell reads, and the `.panel-table-frozen-edge` component class that draws the seam beside a
frozen column.

A Laravel Vue starter kit ships an `app.css` that is nearly identical — the panel-specific parts
are the sidebar tokens and the frozen-column seam. So there are two sensible arrangements:

- **Keep your `app.css`** and copy across anything it lacks. Nothing else is needed; the panel's
  components use ordinary theme utilities.
- **Build `panda-panel.css` as an entrypoint of its own**, declared on the panel so it loads on
  that panel's pages and nowhere else:

```php
$panel->assets('resources/css/panda-panel.css');
```

```ts
// vite.config.ts
input: ['resources/css/app.css', 'resources/js/app.ts', 'resources/css/panda-panel.css'],
```

Two edits, deliberately: `Panel::assets()` takes *paths*, not built files, so the entry must also
appear in Vite's `input` or the page fails with a manifest error. That failure is the right one —
a declared asset that was never built is a mistake — but it is why this is not a one-line change.
Entrypoints accumulate across calls, and the list never crosses to the frontend: the browser gets
the tags, not what produced them.

Tailwind 3 will not compile any of it. `@theme`, `@custom-variant` and `@source` are Tailwind 4
directives.

## Building

```bash
npm install
npm run build      # or npm run dev
```

Panel components resolve through `import.meta.glob` over your own tree — a build-time allowlist by
design. A component the build never saw is a name that cannot resolve, which is why custom
columns, widgets and pages live under `resources/js/pages/Panels/**` rather than in a package.

After a package upgrade, the published copies do not update themselves:

```bash
php artisan panel:assets            # what is behind, what you changed, what conflicts
php artisan panel:assets --update   # write only the files you have never touched
npm run build
```

## Notes

- **A missing dependency and a missing host module fail identically at build time.** Both are
  "cannot resolve module". `panel:install` is the only thing that distinguishes them for you.
- **`missingNpmPackages()` compares declared names, not versions.** A package declared at a range
  older than the panel's is not reported here; it fails later, in the build or at runtime.
- **`missingHostModules()` cannot see aliases.** It resolves specifiers under `resources/js` only.
  An application whose `@/` points somewhere else will be told modules are missing that are not.
- **Unregistered component names render nothing rather than throwing.** In development the panel
  warns once per name, naming the directory the component has to live in; in production the
  component is simply absent. The same is true of icons — run
  [`panel:icons`](../cli/panel-icons.md) after declaring a new one.

## See also

- [Laravel Vue starter kit setup](vue-starter-kit.md) — the application that satisfies all of this
- [Running panel:install](installer.md) — where these checks are run for you
- [Compatibility](compatibility.md) — the supported Vue, Vite, Tailwind and Node versions
- [Frontend: host modules](../frontend/host-modules.md), [Wayfinder](../frontend/wayfinder.md)
- [Frontend: Tailwind theme](../frontend/tailwind-theme.md),
  [updating assets](../frontend/updating-assets.md)
- [Concepts: component registries](../concepts/component-registries.md),
  [frontend assets](../concepts/frontend-assets.md)
- [Troubleshooting: Vite](../troubleshooting/vite.md),
  [host modules](../troubleshooting/host-modules.md),
  [Tailwind](../troubleshooting/tailwind.md)
