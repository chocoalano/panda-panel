# Frontend Build

The panel's Vue components are published into the application's `resources/js`
and built by the application's own Vite, into the application's own bundle.
Nothing in `vendor/chocoalano/panel` ships a compiled asset, so a deploy that
does not build is a deploy that serves the previous frontend. Reach for this
page when writing the build step, when a component renders a fallback in
production, or when the bundle disagrees with what the server is sending.

## A minimal working example

```bash
npm ci
php artisan wayfinder:generate
npm run build
```

That is the whole of it for an application whose panel has not changed. What
follows is what each step depends on, and the four ways a build can succeed and
still be wrong.

## Why there is a build step at all

The frontend is published rather than imported:

```bash
php artisan vendor:publish --tag=panda-panel-assets
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

Those files are the application's from then on: in its repository, in its build,
and editable. That is what makes the component registries possible — each one is
an `import.meta.glob` over the application's own tree, which is a build-time
allowlist by design:

```ts
const modules = import.meta.glob<{ default: Component }>(
    '../../pages/Panels/**/Widgets/*.vue',
);
```

A component name arrives from the server as a string and resolves through that
map and nothing else. A component that was not compiled in cannot be reached,
however the name arrives. Which is the security property, and also the reason a
build is not optional: **adding a custom widget, field, column, or shell
component to the PHP does nothing until the bundle is rebuilt.**

## Requirements

The package's own `package.json` is the single source of truth for these ranges
— `panel:install` reads that same file to tell an application what to install,
so the two cannot disagree. The full table is in the
[compatibility matrix](../getting-started/compatibility.md).

| | Supported |
| --- | --- |
| Node | 20.19+, 22, 24 |
| Vite | 7.x |
| Vue | 3.5+ |
| Tailwind | 4.1+ |
| TypeScript | 5.7+ |
| `@inertiajs/vue3` | 3.x |
| `reka-ui` | 2.x |

Tailwind 3 will not compile `resources/css/panda-panel.css`: it is a Tailwind 4
stylesheet using `@theme`, `@custom-variant` and `@source`, none of which
Tailwind 3 reads.

## What the components import

```php
use PandaPanel\Support\Installer\FrontendRequirements;

FrontendRequirements::npmPackages();          // list<string> 'name@range'
FrontendRequirements::missingNpmPackages();   // the ones this application has not declared
```

| Method | Signature | Answers |
| --- | --- | --- |
| `npmPackages` | `static npmPackages(): list<string>` | every dependency the components import, as `name@range` |
| `missingNpmPackages` | `static missingNpmPackages(): list<string>` | the subset the application's `package.json` does not declare |
| `missingHostModules` | `static missingHostModules(): list<string>` | host-seam modules that are not on disk, as `@/…` specifiers |
| `hasVite` | `static hasVite(): bool` | whether `vite.config.ts` or `vite.config.js` exists |
| `missingInertia` | `static missingInertia(): list<string>` | the root view and Inertia middleware, in words |
| `layoutOverrides` | `static layoutOverrides(): list<array{file: string, line: int, code: string}>` | entry files that overwrite a page's declared layout |

`npmPackages()` is read from the package's own `package.json` rather than
restated in PHP, so the list cannot go stale the first time a component imports
something new. `missingNpmPackages()` reads the application's `package.json`
rather than `node_modules`, because what matters is whether the project
*declared* the dependency — a transitive copy on disk today is one somebody
else's upgrade removes tomorrow.

Run the check before a first build:

```bash
php artisan panel:install     # publishes, scaffolds, and reports all of the above
```

## The host modules the package does not ship

The published components import `@/routes/*`, `@/actions/*` and a handful of
starter-kit components. Two kinds:

- **Generated** — `@/routes/*` and `@/actions/*` come from Wayfinder, written
  from the application's own route table. Vendoring a copy would be shipping a
  snapshot of somebody else's routes.
- **Starter-kit components** — `@/components/UserMenuContent.vue`,
  `@/composables/useTwoFactorAuth`, `@/types/ui` and the rest. These are where a
  project puts its own account links and its own two-factor flow.

```bash
php artisan wayfinder:generate
```

Generate before every build, and always after a route change. A new resource
changes the route table, and a stale generation is a TypeScript error at build
time rather than a broken link at runtime — which is the better failure.

`FrontendRequirements::missingHostModules()` is the authoritative list — it is
what `panel:install` reports — and a working stand-in for each is in
[`frontend/host/`](../frontend/host-modules.md).

## The layout override trap

Every panel page declares its own layout:

```ts
defineOptions({ layout: PanelLayout });
```

So nothing has to be wired in `resources/js/app.ts`. The one thing an
application can still get wrong is overwriting that choice:

```ts
page.default.layout = AppLayout;    // replaces the panel shell — wrong
page.default.layout ??= AppLayout;  // correct
```

An unconditional assignment puts every panel screen inside the application's own
shell, with the host sidebar and the panel navigation nowhere, at HTTP 200 and
with nothing logged. `panel:install` reads `resources/js/app.ts`, `app.js`,
`ssr.ts` and `ssr.js` and names the file and the line when it finds one.
`||=`, `??=`, and any assignment whose right-hand side already falls back all
pass.

## The four things to rebuild after

A build that succeeds is not the same as a build that is current. Four changes
on the PHP side require one:

| Change | Because |
| --- | --- |
| `php artisan panel:icons` | the registry is TypeScript compiled into the bundle |
| a new custom widget / field / column / page component | the registries are build-time globs |
| a route change | Wayfinder modules are compiled in |
| `php artisan panel:assets --update` | it writes component source and says so: `Wrote 3 file(s). Run \`npm run build\`.` |

`VITE_*` environment variables are a fifth: they are inlined at build time, so
changing `VITE_REVERB_HOST` means rebuilding, not restarting.

## Keeping published components current

```bash
php artisan panel:assets            # what is behind, what you changed, what conflicts
php artisan panel:assets --update   # write only the files you have never touched
php artisan panel:assets --force    # also overwrite files this application edited
npm run build
```

| Reported as | On disk | In package | `--update` |
| --- | --- | --- | --- |
| `new` | absent, or present and never recorded | present | **written** |
| `out of date` | unchanged | changed | **written** |
| `yours` | changed | unchanged | left alone |
| `CONFLICT` | changed | changed | never written |
| `deleted by you` | absent | present, previously published | left alone |
| `no longer shipped` | either, but recorded | absent | left alone |
| `current` | unchanged | unchanged | — |

It always exits `0`. A conflict is not a failure of the command — it ran
correctly and found something a person has to look at, and failing a deploy over
a file somebody edited on purpose would be the wrong call.

Commit `.panel-assets.json`. It is the record of what the application published,
the same way `composer.lock` records what it installed.

## The package's own Vite config is not yours

`vite.config.ts` in this repository builds `frontend/entry.ts` into
`build/frontend`, unminified, and nothing consumes the output. It is a compile
check — *does every one of these files actually resolve and compile together?* —
not a deliverable. An application's build is its own.

## Panel-declared entrypoints

A panel can load extra Vite entrypoints on its own pages and nowhere else:

```php
use PandaPanel\Core\Panel;

Panel::make('admin')
    ->assets('resources/css/admin.css', 'resources/js/admin.ts');
```

| Method | Signature |
| --- | --- |
| `assets` | `assets(string ...$entrypoints): self` |
| `getAssets` | `getAssets(): list<string>` |

Paths, not built files — they must **also** appear in the application's
`vite.config.ts` `input` array, or Vite has nothing to serve and the page fails
with a manifest error. That failure is the right one: a declared asset that was
never built is a mistake, not something to paper over. The list accumulates, so a
plugin can add a stylesheet without displacing the panel's own.

## SSR

The panel ships no server-side rendering entry and none of its components are
written against one. `resources/js/ssr.ts` is checked only for the layout
override above. An application that runs `inertia:start-ssr` is on its own for
the panel's screens.

## Gotchas

- **A deploy without `npm run build` serves the previous bundle** against new
  server metadata. The failure is a renderer that has never heard of the shape
  it was handed.
- **`npm ci`, not `npm install`, in a deploy.** `ci` installs exactly what
  `package-lock.json` says.
- **An aliased glob resolves to nothing in the dev server.** The registries use
  relative patterns for that reason; a custom registry that copies one and
  switches to `@/` works in production and renders the fallback in development.
- **`vendor:publish` without `--force` skips existing files**, which on a
  starter kit application means the application's own components win. That is
  usually what you want, and it is why `panel:assets` exists for the cases where
  it is not.
- **A component under `pages/Panels/` that was never committed is not in the
  build.** The glob reads the working tree at build time.
- **Tailwind scans source; it cannot see an interpolated class.** Column spans,
  badge colours and grid columns all map through literal records in the panel's
  own components for this reason. Keep that shape in your own.

## See also

- [Production checklist](production-checklist.md), [Icon registry](icon-registry.md)
- [Published asset structure](../frontend/assets.md), [Updating assets](../frontend/updating-assets.md)
- [Host modules](../frontend/host-modules.md), [Wayfinder](../frontend/wayfinder.md)
- [Component registries](../concepts/component-registries.md), [Frontend assets](../concepts/frontend-assets.md)
- [Tailwind theme](../frontend/tailwind-theme.md), [CSS hooks](../frontend/css-hooks.md)
- [Frontend requirements](../getting-started/frontend-requirements.md), [Compatibility matrix](../getting-started/compatibility.md)
- [`panel:assets`](../cli/panel-assets.md), [`panel:install`](../cli/panel-install.md)
- [Vite troubleshooting](../troubleshooting/vite.md), [Tailwind troubleshooting](../troubleshooting/tailwind.md)
