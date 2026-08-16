# Inertia root view and middleware

The first panel URL answers 500, usually `View [app] not found` or an Inertia error about the root
template. Every panel screen is an Inertia response, and two files the application owns have to
exist for one to render. Reach for this page on a fresh install into an application that is not a
Laravel Vue starter kit, or after a root view has been renamed or rewritten.

## Start here

```bash
php artisan panel:install --no-panel --no-user --no-interaction
```

```text
  WARN  2 thing(s) this package cannot do for your application:

  1. This application is missing an Inertia root view at resources/views/app.blade.php.
     Every panel screen is an Inertia response and will 500 without it — a Laravel Vue
     starter kit has both already.

  2. This application is missing Inertia's middleware (php artisan inertia:middleware).
     Every panel screen is an Inertia response and will 500 without it — a Laravel Vue
     starter kit has both already.
```

```bash
php artisan inertia:middleware
```

and a root view at `resources/views/app.blade.php`.

## The check, in PHP

```php
use PandaPanel\Support\Installer\FrontendRequirements;

FrontendRequirements::missingInertia();
// ['an Inertia root view at resources/views/app.blade.php',
//  "Inertia's middleware (php artisan inertia:middleware)"]
```

| Method | Signature | Returns |
| --- | --- | --- |
| `missingInertia` | `static missingInertia(): array` | `list<string>` — what is missing, in words; empty when nothing is |

Two paths, both checked for existence only:

| Path | What it is |
| --- | --- |
| `resources/views/app.blade.php` | the root view Inertia renders the page into |
| `app/Http/Middleware/HandleInertiaRequests.php` | the middleware Inertia's own generator writes |

The check is a file test, not a registration test. A `HandleInertiaRequests.php` that exists and was
never added to the `web` group passes this check and still breaks the panel — see
[the middleware](#the-middleware) below.

## The root view

The minimum Inertia needs is a document with `@inertia` in it and a built bundle:

```blade
{{-- resources/views/app.blade.php --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') === 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name') }}</title>

        @routes
        @vite([
            'resources/css/app.css',
            'resources/js/app.ts',
            "resources/js/pages/{$page['component']}.vue",
        ])
        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>
```

That is the shape a Laravel Vue starter kit ships. What the panel needs from it is narrow:

| Directive | Needed because |
| --- | --- |
| `@inertia` | the page div Inertia mounts into; without it the response renders an empty document |
| `@vite([...])` | the panel's components are Vue SFCs built by the application's Vite |
| `"resources/js/pages/{$page['component']}.vue"` | per-page entry, if your starter kit splits pages that way; panel page components live under `resources/js/pages/panel/**` and `resources/js/pages/Panels/**` |
| `@inertiaHead` | only if the application uses SSR or the `<Head>` component |

**A different file name is fine.** Inertia's root view is configurable (`Inertia::setRootView()` or
the `inertia.root_view` config), and the panel never names it — it calls `Inertia::render()` like any
other Inertia code. What the installer checks for is the conventional path, so a renamed root view
will be reported as missing and is not actually a problem.

### Panel assets in the root view

`Panel::assets()` entrypoints are emitted here, and nowhere else:

```blade
@vite([
    'resources/css/app.css',
    'resources/js/app.ts',
    "resources/js/pages/{$page['component']}.vue",
    ...(panel()?->getAssets() ?? []),
])
```

`panel()` is null outside a panel, so the spread contributes nothing there. This line is part of the
application's root view rather than something the package injects, because the root view is the
application's file.

A panel that declares an entrypoint Vite was never told to build fails with a **manifest error**
rather than a missing style — the path must also appear in `vite.config.ts`'s `input`.

## The middleware

```bash
php artisan inertia:middleware
```

```php
// bootstrap/app.php
use App\Http\Middleware\HandleInertiaRequests;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->web(append: [
        HandleInertiaRequests::class,
    ]);
})
```

Generating the class is half of it; registering it is the other half, and the installer's check
cannot see the second. Two symptoms of a class that exists and is not registered:

- **Shared props are missing.** `auth`, `errors`, and anything else the application shares never
  arrive.
- **A form submit re-issues the write.** Inertia needs a `303` after a redirect from `PUT`, `PATCH`
  or `DELETE`; without the middleware the response is a plain `302` and the browser repeats the
  original method against the redirect target. The panel's edit route is a `PUT`, so this shows up
  as an update that appears to loop.

### The panel's own props are not in your middleware

`PandaPanel\Http\Middleware\SharePanelData` shares everything the panel's components read:

| Prop | Read by |
| --- | --- |
| `panel` | the shell |
| `navigation` | the sidebar |
| `panels` | the header's panel switcher |
| `broadcasting` | the toast listener |
| `search` | the command palette |
| `notifications` | the bell |
| `tenancy` | the tenant switcher |

It shares through `Inertia::share()`, which **merges**, so your `HandleInertiaRequests` is untouched
and `auth`, `errors` and the rest still arrive. It belongs to the package rather than to the
application's middleware for the same reason the components do: a prop added in a new version would
otherwise break every application that forgot to copy it across.

Every value is a closure, so a request that never reaches a panel pays for none of them.

It is registered on the `web` group by the service provider:

```php
// config/panda-panel.php
'register_web_middleware' => true,
```

Turn that off only to register them yourself, in `bootstrap/app.php`, in this order:

```php
use PandaPanel\Http\Middleware\RedirectPanelHome;
use PandaPanel\Http\Middleware\ResetPanelContext;
use PandaPanel\Http\Middleware\ShareFlashToast;
use PandaPanel\Http\Middleware\SharePanelData;

$middleware->web(append: [
    ResetPanelContext::class,
    RedirectPanelHome::class,
    ShareFlashToast::class,
    SharePanelData::class,
]);
```

They are pushed through the **kernel**, after it resolves, rather than onto the router.
`bootstrap/app.php` configures the `web` group in an `afterResolving` hook on the kernel, which then
overwrites whatever the router was holding — a package that pushed straight onto the router would
have its middleware silently dropped on the way in.

## HTTP 200 and the wrong shell

A different failure with the same root cause — the seam between the panel and the application's
Inertia entry.

Every panel page declares its own layout:

```ts
defineOptions({ layout: PanelLayout });   // and PanelBlankLayout for the auth pages
```

An unconditional assignment in `resources/js/app.ts` replaces it *after* the page has asked:

```ts
page.default.layout = AppLayout;      // wrong — panel renders inside your shell
page.default.layout ??= AppLayout;    // right
page.default.layout ||= AppLayout;    // also right
```

```php
use PandaPanel\Support\Installer\FrontendRequirements;

FrontendRequirements::layoutOverrides();
// [['file' => 'resources/js/app.ts', 'line' => 12, 'code' => 'page.default.layout = AppLayout;']]
```

| Method | Signature | Returns |
| --- | --- | --- |
| `layoutOverrides` | `static layoutOverrides(): array` | `list<array{file: string, line: int, code: string}>` |

It reads `resources/js/app.ts`, `app.js`, `ssr.ts` and `ssr.js`, and reports every line that assigns
`.layout` without falling back. `panel:install` prints the file, the line and the replacement,
because this is the one thing about the seam the package cannot fix from the inside: the result is
HTTP 200, your sidebar instead of the panel navigation, and nothing logged.

## Resolving panel page components

Panel pages are Inertia component names, resolved by the application's own resolver. Three
locations, and the split is not optional because `@inertiajs/vite` only globs
`resources/js/pages/**`:

| Location | Role | Inertia-resolvable |
| --- | --- | --- |
| `resources/js/panel/**` | layouts, components, renderers, composables, registries, types | no |
| `resources/js/pages/panel/**` | the framework's own pages | yes |
| `resources/js/pages/Panels/{Panel}/**` | your application's panel pages and custom widgets | yes |

A `Page not found: panel/resources/List` error in the browser means the published pages are not
under `resources/js/pages`, or the build has not been re-run since they were published:

```bash
php artisan vendor:publish --tag=panda-panel-assets
npm run build
```

## Notes

- **Inertia 3 only.** `inertiajs/inertia-laravel` 2.x is not supported: the panel's forms use
  Inertia 3's `<Form>` component and the `flash` router event, and there is no shim for either.
- **A Blade-only application cannot host a panel.** That is not a configuration you can turn on —
  every screen is an Inertia response.
- **`missingInertia()` tests for files, not behaviour.** A renamed root view reports as missing and
  works; a registered-nowhere middleware reports as present and does not.
- **`SharePanelData` runs on the whole `web` group, not on the panel route group.** A redirect *out*
  of a panel still needs `ShareFlashToast`, and `ResetPanelContext` has to run for requests that
  never reach a panel at all — which is why they are group middleware.
- **`ResetPanelContext` is what keeps Octane honest.** The current panel, parent record and tenant
  live in a scoped container binding that it clears at the start of every request.
- **SSR is not something the package configures.** `layoutOverrides()` reads `ssr.ts` because the
  same mistake can be made there, but nothing in the panel requires or forbids SSR.
- **`@routes` is Ziggy's directive, not Wayfinder's.** The panel uses neither for its own URLs —
  every panel href comes from the server — but a starter kit's own pages may need it.

## See also

- [Inertia and Vue approach](../introduction/inertia-vue.md), [Inertia pages](../frontend/inertia-pages.md)
- [Frontend requirements](../getting-started/frontend-requirements.md), [Vue starter kit setup](../getting-started/vue-starter-kit.md)
- [Server metadata to Vue](../concepts/metadata-to-vue.md), [request lifecycle](../concepts/request-lifecycle.md)
- [Middleware configuration](../configuration/middleware.md), [service provider](../configuration/service-provider.md)
- [Panel assets](../panels/assets.md), [component registries](../concepts/component-registries.md)
- [Host modules](host-modules.md), [Vite build errors](vite.md), [Tailwind](tailwind.md)
- [Common install problems](../getting-started/common-install-problems.md)
