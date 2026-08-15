# Compatibility matrix

What this package is tested against, what it merely tolerates, and what will not work. Every
version in the **supported** column is one CI runs on every push — see
[`.github/workflows/tests.yml`](../../.github/workflows/tests.yml) — so this table describes the
build rather than an intention.

## Ask your own application

```bash
composer why chocoalano/panel
composer show laravel/framework inertiajs/inertia-laravel laravel/fortify | grep -E 'name|versions'
node -v
npm ls vue tailwindcss vite @inertiajs/vue3 --depth=0
```

If any of those comes back below the floor in the tables here, the failure you will otherwise get
is a build error about a module specifier or a 500 about a missing method — a true error about
the wrong thing.

## Backend

| | Supported | Notes |
| --- | --- | --- |
| PHP | 8.2, 8.3, 8.4 | 8.2 is supported *through* Laravel 12, which is the newest Laravel that runs on it. |
| Laravel | 12.x, 13.x | See [Why not Laravel 11](#why-not-laravel-11). |
| Testbench | 10.x (L12), 11.x (L13) | Only relevant to this repository's own suite. |
| Inertia (server) | `inertiajs/inertia-laravel` 3.x | 2.x is **not** supported. |
| Fortify | 1.37.2+ | The security settings page and the emailed-code second factor both build on it. |
| Database | MySQL, PostgreSQL, SQLite, MariaDB | Nothing here is engine-specific. The suite runs on SQLite; the queries are ordinary Eloquent. |

### What CI actually runs

```yaml
matrix:
  php: ['8.2', '8.3', '8.4']
  laravel: ['12.*', '13.*']
  stability: [prefer-lowest, prefer-stable]
  exclude:
    - php: '8.2'
      laravel: '13.*'
```

Ten jobs: the cross-product of PHP × Laravel × `prefer-lowest`/`prefer-stable`, minus the
combination that does not exist. Testing `prefer-lowest` is what proves the *bottom* of every
declared range — a dependency that only works at its newest version is a dependency whose
constraint is wrong, and that is the failure `prefer-lowest` catches.

PHP 8.2 × Laravel 13 is excluded because **Laravel 13 requires PHP 8.3**. PHP 8.2 users get
Laravel 12, and that combination is a real job in the matrix rather than an inference.

Static analysis runs twice, at PHP 8.2 / Laravel 12 and PHP 8.4 / Laravel 13. The two ends of the
range genuinely disagree about what exists — `Password::toPasswordRulesString()` is Laravel 13
only — so analysing one end misses the call that breaks the other.

### Why not Laravel 11

Two reasons, and the second is decisive.

**Its security window has closed.** Laravel 11 was released in March 2024; under Laravel's support
policy its security fixes ended in March 2026.

**Composer will not install it.** Every 11.x release is flagged by unpatched security advisories.
`composer update` against a `^11.x` constraint does not resolve — it reports the advisory IDs and
stops. A package cannot claim to support a version whose own CI could not install it.

The gap this costs is smaller than it looks: an application on PHP 8.2 is fully supported, and it
is Laravel 11 specifically — not old PHP — that is out of reach.

## Frontend

The published components are Vue 3 SFCs with Tailwind 4, built by the application's own Vite.
Ranges come from [`package.json`](../../package.json), which is the single source of truth:
`php artisan panel:install` reads that same file to tell an application what to install, so the
two cannot disagree.

| | Supported | Notes |
| --- | --- | --- |
| Node | 20.19+, 22, 24 | All three in CI. 20.19 is Vite 7's floor and what `engines.node` declares. |
| Vue | 3.5+ | `defineModel`, `useTemplateRef`. |
| Inertia (client) | `@inertiajs/vue3` 3.x | Pairs with `inertia-laravel` 3.x. |
| Vite | 7.x | The version of the *application's* build. |
| Tailwind | 4.1+ | CSS-first: the theme, the variants and `@source` all live in `resources/css/panda-panel.css`. Tailwind 3 has no `@theme` and will not compile it. |
| TypeScript | 5.7+ | Only for an application that type-checks. The components are `.vue` with `lang="ts"`. |
| reka-ui | 2.x | Behind every `components/ui/*` primitive. |
| TanStack Table | `@tanstack/vue-table` 9.x | Column model, visibility and row selection only. Sorting, filtering and pagination are server-side. |

A second CI job installs the *top* of every range with `npm install --no-package-lock` and builds
again. It is allowed to fail: an upstream minor breaking the build is news, not a reason to block
an unrelated pull request. `npm ci` against the committed lockfile can never catch that, because
the lockfile pins what a range resolved to months ago.

### The starter kit assumption

The published components import modules they do not ship. They are the application's, and there
are two kinds:

- **Generated** — `@/routes/*` and `@/actions/*` come from
  [Wayfinder](https://github.com/laravel/wayfinder), written from the application's own route
  table. Vendoring a copy would be shipping a snapshot of somebody else's routes.
- **Starter-kit components** — `@/components/UserMenuContent`, `@/composables/useTwoFactorAuth`
  and the rest. These are where a project keeps its own account links and its own two-factor flow;
  shipping ours would mean overwriting a file every starter kit already has and every project has
  already edited.

`panel:install` checks for all of them and names the ones that are missing. The full list is in
[Frontend requirements](frontend-requirements.md).

In practice: **a Laravel Vue starter kit application works out of the box; anything else needs
those modules written first.**

### What the panel takes over

Two starter kit addresses stop being screens of their own, and both stay addresses:

| | What happens | How to keep yours |
| --- | --- | --- |
| `/dashboard` | A signed-in user is redirected to the first panel they can enter. The route, its name and `pages/Dashboard.vue` are untouched. | `home_redirect.enabled => false` |
| `/settings/*` | Nothing in the package. The example application redirects them into the panel's own settings pages — see `SettingsRedirectController` in [`examples/`](../../examples). | Do not copy that controller |

Everything the package publishes lands under the paths in
`PandaPanel\Support\Installer\PublishedAssets::map()`. Nothing else the application owns is read,
edited or overwritten.

## What is deliberately not supported

| | Why |
| --- | --- |
| Inertia (server) 2.x | The panel's forms use Inertia 3's `<Form>` component and the `flash` router event. Neither exists in 2.x, and there is no shim that would make them. |
| Tailwind 3 | `resources/css/panda-panel.css` is a Tailwind 4 stylesheet — `@theme`, `@custom-variant`, `@source`. Tailwind 3 reads none of those directives. |
| React, Svelte | The components are Vue SFCs. The *server* half — resources, tables, forms, actions, policies — is framework-agnostic and serializes to plain arrays, so another frontend is possible; none is written, and none is planned. |
| Blade-only applications | Every panel screen is an Inertia response. Without Inertia's middleware and a root view, the first panel URL is a 500. |
| Laravel 11 and below | Out of security support, and unresolvable by composer — see [Why not Laravel 11](#why-not-laravel-11). |

## Octane, queues, and long-lived processes

Nothing in this package holds request state in a static. The current panel, the current parent
record and the current tenant all live in `PandaPanel\Support\PanelContext`, which is a **scoped**
container binding, reset at the start of every request by
`PandaPanel\Http\Middleware\ResetPanelContext` — registered on the whole `web` group precisely so
it also runs for requests that never reach a panel.

Queued work is outside a request and therefore outside all of that. Tenant-scoped work has to
enter a tenant explicitly:

```php
use PandaPanel\Tenancy\Tenancy;

Tenancy::for($tenant, fn () => InvoiceResource::query()->count());
```

A scoped resource asked outside a tenant raises rather than running unscoped. That is the point:
an unscoped query would return every tenant's records and look like a working page.

## Keeping a published frontend current

The panel's Vue components are published into the application, which means a package update does
not update them. That is a deliberate trade — the component registries are build-time
`import.meta.glob` allowlists over the application's own tree — but left alone it would mean a
frontend that drifts further behind with every release.

```bash
composer update chocoalano/panel
php artisan panel:assets            # what is behind, what you changed, what conflicts
php artisan panel:assets --update   # write only the files you have never touched
npm run build
```

| On disk | In package | Reported as | `--update` writes it |
| --- | --- | --- | --- |
| not published | present | `new` | yes |
| = manifest | ≠ manifest | `out of date` | yes |
| ≠ manifest | = manifest | `yours` | no |
| ≠ manifest | ≠ manifest | `CONFLICT` | never |
| absent | present | `deleted by you` | no |
| in manifest | no longer shipped | `no longer shipped` | no |

It works from `.panel-assets.json`, written at install time, which records the hash of every file
*as it was published*. That third value is what separates a stale file from an edited one — a
distinction `vendor:publish` cannot make, because "differs from the package's copy" is equally
true of both. Commit the manifest: it is the record of what your application published, in the
same way `composer.lock` records what it installed.

Files changed on both sides are reported by path and never written. That is the one case a tool
should not resolve on its own.

## Notes

- **`prefer-lowest` is part of the contract.** If your application pins a dependency below the
  bottom of a range here, nothing tests that combination — including this package's own CI.
- **The frontend ranges are not shipped as a lockfile.** `package.json`, `package-lock.json`, the
  Vite config, the tsconfig and the lint configs are all `export-ignore`d in `.gitattributes`, so
  `composer require` pulls none of them. Your application installs from the *ranges*.
- **`panel:assets` returning findings is not a failure.** It exits `0` even with conflicts:
  breaking a deploy over a file somebody edited on purpose would be wrong.

## See also

- [Requirements](requirements.md) — the constraints, and what needs each one
- [Installation](installation.md) — installing into an application that satisfies them
- [Frontend requirements](frontend-requirements.md) — npm packages, host modules, the build
- [Upgrading](../upgrading/upgrade-guide.md) — what breaks between versions and the smallest fix
- [Asset manifest](../upgrading/asset-manifest.md) — the three-way comparison in detail
- [Deployment: production checklist](../deployment/production-checklist.md)
