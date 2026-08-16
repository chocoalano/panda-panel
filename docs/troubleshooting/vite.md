# Vite Build Errors

The panel's frontend is published into your application and built by your Vite, alongside your own entrypoints. Nothing in `vendor/chocoalano/panel` ships a compiled asset, so every panel screen depends on a build that resolved a few hundred Vue and TypeScript files against modules the package deliberately does not ship. When that goes wrong it goes wrong at `npm run build`, in a message about a module specifier — a true error about the wrong thing. Reach for this page when the build fails, when it succeeds and the panel is still wrong, or when a page dies with a manifest error.

## Ask the package what is missing first

Before reading a Rollup stack trace, run the check that names the cause in a sentence:

```bash
php artisan panel:install --no-panel --no-user --no-interaction
```

It publishes nothing new that is already there, and finishes with a numbered list of everything this package cannot do for your application: the missing npm dependencies with an `npm install` line to paste, the missing host modules, an absent `vite.config`, an absent Inertia root view or middleware, and a `resources/js/app.ts` that overwrites the layout every panel page declares. Then:

```bash
npm ci
php artisan wayfinder:generate
npm run build
```

That is the whole build for an application whose panel has not changed.

## The checks, one at a time

`PandaPanel\Support\Installer\FrontendRequirements` is what the installer calls, and every method is public and callable on its own — from `tinker`, from a test, or from a deploy script.

| Method | Signature | Answers |
| --- | --- | --- |
| `npmPackages` | `static npmPackages(): list<string>` | every dependency the components import, as `name@range` |
| `missingNpmPackages` | `static missingNpmPackages(): list<string>` | the subset this application's `package.json` does not declare |
| `missingHostModules` | `static missingHostModules(): list<string>` | host-seam modules not on disk, as `@/…` specifiers |
| `hasVite` | `static hasVite(): bool` | whether `vite.config.ts` or `vite.config.js` exists |
| `missingInertia` | `static missingInertia(): list<string>` | the root view and Inertia middleware, in words |
| `layoutOverrides` | `static layoutOverrides(): list<array{file: string, line: int, code: string}>` | entry files that overwrite a page's declared layout |

```php
use PandaPanel\Support\Installer\FrontendRequirements;

FrontendRequirements::missingNpmPackages();
// ['@lucide/vue@^1.31.0', 'reka-ui@^2.0.0', …]

FrontendRequirements::missingHostModules();
// ['@/routes/two-factor', '@/components/UserMenuContent', …]

FrontendRequirements::hasVite();          // false — nothing will build
FrontendRequirements::missingInertia();   // ['Inertia\'s middleware (php artisan inertia:middleware)']
FrontendRequirements::layoutOverrides();  // [['file' => 'resources/js/app.ts', 'line' => 4, 'code' => '…']]
```

`npmPackages()` is read from this package's own `package.json` rather than restated in PHP, so it cannot go stale the first time a component imports something new. `missingNpmPackages()` reads your `package.json`, not `node_modules`: what matters is whether the project *declared* the dependency, because a transitive copy on disk today is one somebody else's upgrade removes tomorrow.

## Failure 1: the build cannot resolve `@/…`

The published components import modules the package does not ship, and there are two kinds. `@/routes/*` and `@/actions/*` are **generated** by Wayfinder from your own route table — vendoring a copy would ship a snapshot of somebody else's routes. The rest are components a Laravel Vue starter kit already has, and are where a project puts its own account links and its own two-factor flow. The full list is `FrontendRequirements::HOST_MODULES`:

```text
@/routes                 @/components/Heading
@/routes/login           @/components/UserInfo
@/routes/register        @/components/UserMenuContent
@/routes/password        @/components/PasskeyItem
@/routes/two-factor      @/components/PasskeyRegister
@/routes/verification    @/components/TwoFactorRecoveryCodes
@/types                  @/components/TwoFactorSetupModal
@/types/ui               @/composables/useTwoFactorAuth
@/actions/App/Http/Controllers/Settings/ProfileController
@/actions/App/Http/Controllers/Settings/SecurityController
@/actions/Laravel/Passkeys/Http/Controllers/PasskeyRegistrationController
```

Read the shape of what is missing rather than the count. **All of `@/routes/*` and `@/actions/*`** means Wayfinder has not run:

```bash
php artisan wayfinder:generate
```

Run it before every build and always after a route change — a new resource changes the route table, and a stale generation is a build-time TypeScript error rather than a broken link at runtime, which is the better failure. **A handful of components** means this is not a starter-kit application, and those files are yours to write; a working stand-in for each is in this repository's `frontend/host/`.

A module is looked for as `.ts`, `.vue`, `.d.ts`, `/index.ts`, `/index.vue` or `/index.d.ts`. A bare directory does not count — that was a real bug, and it made `@/types` satisfied by the folder this package publishes into however empty it was of the module actually being imported.

## Failure 2: a missing npm dependency

The components import every package in this repository's `dependencies`. Ranges as shipped:

| Package | Range | Package | Range |
| --- | --- | --- | --- |
| `@inertiajs/vue3` | `^3.0.0` | `reka-ui` | `^2.0.0` |
| `@internationalized/date` | `^3.12.0` | `tailwind-merge` | `^3.0.0` |
| `@laravel/echo-vue` | `^2.4.0` | `tailwindcss` | `^4.1.0` |
| `@laravel/passkeys` | `^0.4.0` | `tw-animate-css` | `^1.2.0` |
| `@lucide/vue` | `^1.31.0` | `vue` | `^3.5.0` |
| `@tailwindcss/vite` | `^4.1.0` | `vue-input-otp` | `^0.4.0` |
| `@tanstack/vue-table` | `^9.0.0` | `vue-sonner` | `^2.0.0` |
| `@vueuse/core` | `^14.0.0` | `class-variance-authority` | `^0.7.0` |
| `clsx` | `^2.1.0` | | |

Node must be `>=20.19`. Tailwind 3 will not compile `resources/css/panda-panel.css` at all: it is a Tailwind 4 stylesheet built on `@import 'tailwindcss'`, `@theme inline`, `@custom-variant` and `@source`, none of which Tailwind 3 reads — see [Tailwind 4 issues](tailwind.md).

## Failure 3: a Vite manifest error at request time

Two different exceptions, two different causes.

| Exception | Message | Cause |
| --- | --- | --- |
| `Illuminate\Foundation\ViteManifestNotFoundException` | `Vite manifest not found at: …/public/build/manifest.json` | no build has run in this release, or the dev server is not running |
| `Illuminate\Foundation\ViteException` | `Unable to locate file in Vite manifest: {file}.` | a path was handed to `@vite` that the build never produced |

The second is the one this package can cause, and it has two shapes.

**A panel entrypoint that is not in `vite.config.ts`.** `Panel::assets()` appends Vite entrypoints loaded on that panel's pages and nowhere else:

```php
use PandaPanel\Core\Panel;

Panel::make('admin')->assets('resources/css/panels/admin.css');
```

| Method | Signature |
| --- | --- |
| `assets` | `assets(string ...$entrypoints): self` |
| `getAssets` | `getAssets(): list<string>` |

They are **paths, not built files**, so the same path must also appear in the application's `vite.config.ts` `input` array. Two edits, deliberately: a declared asset that was never built is a mistake, and the manifest error is the right failure rather than something to paper over. The list accumulates, so a second `assets()` call — or a plugin's — adds to it rather than replacing it, and it never crosses to the frontend: the browser gets the tags, not the list that produced them.

**A page component the build never saw.** A Laravel Vue starter kit's root view passes the current page component to `@vite`:

```blade
@vite([
    'resources/css/app.css',
    'resources/js/app.ts',
    "resources/js/pages/{$page['component']}.vue",
    ...(panel()?->getAssets() ?? []),
])
```

So a panel page whose `$component` names a file that does not exist is a manifest error rather than a blank screen. The usual causes are a `make:panel-page --component` scaffold whose Vue file was renamed, and a custom page written in PHP with `protected static string $component = 'Panels/Admin/Pages/Settings';` and no matching `resources/js/pages/Panels/Admin/Pages/Settings.vue`. A page that declares no component at all uses the generic renderer (`panel/Page`) and needs no Vue file.

## Failure 4: the build succeeds and the panel is wrong

Four things compile cleanly and are still not what you asked for.

**Every panel screen renders inside your application's shell.** Panel pages declare their own layout — `defineOptions({ layout: PanelLayout })` — so nothing needs wiring in `app.ts`. The one thing an application can still get wrong is overwriting that choice:

```ts
page.default.layout = AppLayout;    // replaces the panel shell
page.default.layout ??= AppLayout;  // correct
```

An unconditional assignment puts every panel screen inside the host sidebar with the panel navigation nowhere, at HTTP 200 and with nothing logged. `layoutOverrides()` reads `resources/js/app.ts`, `app.js`, `ssr.ts` and `ssr.js` and reports the file, the line and the code. `||=`, `??=`, and any right-hand side that already falls back (`page.default.layout || AppLayout`) all pass.

**A custom widget, field, column or shell component draws a fallback.** Every registry is an `import.meta.glob` over your own tree — a build-time allowlist, which is the security property and the reason a build is not optional:

```ts
const modules = import.meta.glob<{ default: Component }>(
    '../../pages/Panels/**/Widgets/*.vue',
);
```

| Registry | Glob |
| --- | --- |
| `panel/widgets/registry.ts` | `pages/Panels/**/Widgets/*.vue` |
| `panel/forms/registry.ts` | `pages/Panels/**/{Fields,Schemas,Entries,Modals}/*.vue` |
| `panel/tables/registry.ts` | custom columns |
| `panel/tables/registryEmptyStates.ts` | custom empty states |
| `panel/hooks/registry.ts` | render-hook components |
| `panel/shell/registry.ts` | shell replacements |

A name that resolves to nothing warns once, in development only, naming the directory the component has to live in: `[panel] The widget component [x] is not in the build-time registry, so a fallback is drawn instead.` Three causes are indistinguishable from the screen and all three are covered by that message — a typo, a file outside the globbed directory, and a build that was not re-run.

**An icon is simply absent.** `resources/js/panel/icons/registry.ts` is generated by `php artisan panel:icons` and is a closed map: an unknown name resolves to `null` and nothing is drawn. Lucide ships 1768 icons and only the ones your panels declare belong in the bundle. In development the registry warns once per name and names the command; in production the icon is absent without comment, because this is a build problem rather than a runtime one.

```bash
php artisan panel:icons          # rewrite the registry from the icons the PHP declares
php artisan panel:icons --check  # fail instead of writing, for CI
npm run build
```

The generator scans source for `->icon('…')`, `$navigationIcon = '…'`, `icon: '…'`, `'icon' => '…'`, `Icon::make('…')` and the body of any method literally named `icon()`. A name Lucide does not have is reported as `Not a Lucide icon: …` and the command exits non-zero. Note that the `make:panel-page` stub declares `'file-text'`, which is not in the shipped registry — a scaffolded page has no navigation icon until `panel:icons` and a rebuild have run.

**The bundle is older than the server metadata.** A deploy that skips `npm run build` serves the previous frontend against new props, and the failure is a renderer that has never heard of the shape it was handed. Five changes require a rebuild:

| Change | Because |
| --- | --- |
| `php artisan panel:icons` | the registry is TypeScript compiled into the bundle |
| a new custom widget / field / column / page component | the registries are build-time globs |
| a route change | Wayfinder modules are compiled in |
| `php artisan panel:assets --update` | it writes component source, and says `Wrote N file(s). Run \`npm run build\`.` |
| a `VITE_*` environment variable | they are inlined at build time, so this is a rebuild rather than a restart |

## Failure 5: it works built and not in the dev server

`import.meta.glob` with an aliased pattern resolves to nothing at all under `npm run dev` — `Object.assign({})` — while the production build resolves it normally. Every registry in the package uses a relative pattern for that reason. A custom registry copied from one and switched to `@/` renders the fallback in development and works once built, which is a confusing way round.

The same asymmetry applies to keys: Vite's glob key format follows the pattern as written and differs between the dev server and the build, so the registries derive their names from the real paths (`path.indexOf('/pages/')`) rather than reconstructing them. A registry that rebuilds keys by hand fails silently, as a component that renders nothing forever.

Development-only behaviour to expect, all of it guarded by `import.meta.env.DEV`: the icon warning, the six registry warnings, and the Echo warning when a panel broadcasts without a configured broadcaster.

## Failure 6: `vue-tsc` fails inside files nobody wrote

The panel reads its shared Inertia props through `panel/types/shared.ts`, which needs no module augmentation. It deliberately does not ship a `declare module '@inertiajs/core'` for `name`, `auth` or `sidebarOpen` — those are the application's to declare, and a package augmentation that failed to take effect left `page.props` as `{}` and produced errors inside published files. `FrontendContractTest` asserts that no file under `resources/js/panel` reads `usePage().props.<shared key>` directly, so the rule holds for the code the package ships. Keep to it in your own components under `pages/Panels/`.

## Failure 7: moving the frontend paths

Two destinations are configurable:

```php
// config/panda-panel.php
'frontend' => [
    'panel_path' => 'js/panel',
    'pages_path' => 'js/pages/Panels',
],
```

Both are relative to `resources/`. `PandaPanel\Support\FrontendPaths::panel()` and `::pages()` read them, and `PublishedAssets::map()` publishes to them. What config cannot move is the globs: they are literal strings inside the published TypeScript, written relative to `resources/js/panel/{registry}` and pointing at `../../pages/Panels/**`. Changing `panel_path` changes the depth, and changing `pages_path` changes the target — either way the glob has to be edited in the published files to match, or every custom component resolves to nothing.

`pages_path` has a second constraint that is not this package's: `@inertiajs/vite` only globs `resources/js/pages/**`, so a page component outside that tree is not Inertia-resolvable however the build is configured.

## Keeping published components current

A build compiles what is on disk, and what is on disk is a copy taken when you published:

```bash
php artisan panel:assets            # report only
php artisan panel:assets --update   # write the files that are safe to write
php artisan panel:assets --force    # also overwrite files this application edited
npm run build
```

| Reported as | On disk | In package | `--update` |
| --- | --- | --- | --- |
| `new` | absent | present | **written** |
| `out of date` | unchanged | changed | **written** |
| `yours` | changed | unchanged | left alone |
| `CONFLICT` | changed | changed | never written |
| `deleted by you` | absent | present, previously published | left alone |
| `no longer shipped` | present | absent | left alone |
| `current` | unchanged | unchanged | — |

The three-way comparison comes from `.panel-assets.json`, written at `AssetManifest::path()` — `base_path('.panel-assets.json')`. Commit it: it is the record of which version of the frontend this application published, the same way `composer.lock` records what it installed, and without it an upgrade cannot tell your edits from a stale copy. Hashes are taken with line endings normalised, so a CRLF checkout does not report every file as a conflict. The command always exits `0`; a conflict is something a person has to look at, not a reason to fail a deploy.

## The package's own `vite.config.ts` is not yours

This repository builds `frontend/entry.ts` — a generated glob over the whole tree — into `build/frontend`, unminified, and nothing consumes the output. It is a compile check answering the one question type-checking does not: does every one of these files actually resolve and compile together? Its `hostSeam()` plugin implements the same two-step `@/x` resolution that `tsconfig.json`'s `paths` gives TypeScript, so the bundler and the type-checker never disagree. None of it applies to an application, whose build is its own.

## Gotchas

- **`npm ci`, not `npm install`, in a deploy.** `ci` installs exactly what `package-lock.json` says.
- **`vendor:publish` without `--force` skips files that already exist**, so on a starter-kit application the application's own components win. That is usually right, and it is why `panel:assets` exists for when it is not.
- **A component under `pages/Panels/` that was never committed is not in the build.** The glob reads the working tree at build time, which is why it works locally and not on CI.
- **Tailwind scans source and cannot see an interpolated class.** Column spans, badge colours and grid columns all map through literal records in the panel's components. A class set from `cssHooks()` is a string in a PHP provider, which is in no file Tailwind scans by default — add the provider to the content globs or use classes that appear elsewhere.
- **There is no SSR entry.** The panel ships none and none of its components are written against one; `ssr.ts` is read only for the layout check.
- **`php artisan panel:cache` is not a frontend cache.** It caches class names, not components. A resource that is missing after a build is more likely a stale panel manifest — the boot check logs `[panel] The cached panel manifest is out of date` in development, and `php artisan panel:clear` is the answer.

## See also

- [Frontend build](../deployment/frontend-build.md), [Production checklist](../deployment/production-checklist.md)
- [Frontend requirements](../getting-started/frontend-requirements.md), [Compatibility matrix](../getting-started/compatibility.md)
- [Published asset structure](../frontend/assets.md), [Updating assets](../frontend/updating-assets.md)
- [Host modules](../frontend/host-modules.md), [Wayfinder](../frontend/wayfinder.md)
- [Component registries](../concepts/component-registries.md), [Frontend assets](../concepts/frontend-assets.md)
- [`panel:install`](../cli/panel-install.md), [`panel:assets`](../cli/panel-assets.md), [`panel:icons`](../cli/panel-icons.md)
- [Tailwind 4 issues](tailwind.md), [Asset conflicts](asset-conflicts.md), [Missing host modules](host-modules.md), [Missing icons](icons.md)
- [Inertia root view](inertia-root-view.md)
