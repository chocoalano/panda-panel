# Compatibility matrix

What this package is tested against, what it merely tolerates, and what will
not work. Every version in the **supported** column is one CI runs on every
push — see [`.github/workflows/tests.yml`](../.github/workflows/tests.yml) —
so this table is a description of the build rather than an intention.

## Backend

| | Supported | Notes |
| --- | --- | --- |
| PHP | 8.2, 8.3, 8.4 | 8.2 is supported *through* Laravel 12, which is the newest Laravel that runs on it. |
| Laravel | 12.x, 13.x | See below for why 11.x is not, and cannot be. |
| Testbench | 10.x (L12), 11.x (L13) | Only relevant to this repository's own suite. |
| Inertia (server) | `inertiajs/inertia-laravel` 3.x | 2.x is **not** supported — see below. |
| Fortify | 1.37+ | The security settings page and the emailed-code second factor both build on it. |
| Database | MySQL 8+, PostgreSQL 13+, SQLite 3.35+, MariaDB 10.6+ | Nothing here is engine-specific. The suite runs on SQLite; the queries are ordinary Eloquent. |

CI runs the cross-product of PHP × Laravel × `prefer-lowest`/`prefer-stable`
— ten jobs — so the *bottom* of every declared range is tested too. A
dependency that only works at its newest version is a dependency whose
constraint is wrong, and that is the failure `prefer-lowest` exists to catch.

PHP 8.2 × Laravel 13 is excluded because it does not exist: **Laravel 13
requires PHP 8.3.** PHP 8.2 users get Laravel 12, and that combination is a
real job in the matrix rather than an inference.

Static analysis also runs twice, at PHP 8.2 / Laravel 12 and PHP 8.4 /
Laravel 13. The two ends of the range genuinely disagree about what exists —
`Password::toPasswordRulesString()` is Laravel 13 only — so analysing one end
misses the call that breaks the other.

### Why not Laravel 11

Two reasons, and the second is decisive.

**Its security window has closed.** Laravel 11 was released in March 2024;
under Laravel's support policy its security fixes ended in March 2026.

**Composer will not install it.** Every 11.x release, from v11.0.0 to
v11.55.1, is flagged by unpatched security advisories. `composer update`
against a `^11.35` constraint does not resolve — it reports the advisory IDs
and stops. A package cannot claim to support a version whose own CI could not
install it, and recommending one would mean recommending a framework with
known unpatched vulnerabilities.

This is the one place this package is deliberately narrower than some
alternatives. The gap it costs is smaller than it looks: an application on
PHP 8.2 is fully supported, and it is Laravel 11 specifically — not old PHP —
that is out of reach.

## Frontend

The published components are Vue 3 SFCs with Tailwind 4, built by the
application's own Vite. Ranges come from
[`package.json`](../package.json), which is the single source of truth —
`php artisan panel:install` reads that same file to tell an application what
to install, so the two cannot disagree.

| | Supported | Notes |
| --- | --- | --- |
| Node | 20.19+, 22, 24 | All three in CI. 20.19 is Vite 7's floor. |
| Vue | 3.5+ | `defineModel`, `useTemplateRef`. |
| Inertia (client) | `@inertiajs/vue3` 3.x | Pairs with `inertia-laravel` 3.x. |
| Vite | 7.x | Version of the *application's* build. |
| Tailwind | 4.1+ | CSS-first: the theme, the variants and `@source` all live in `resources/css/panda-panel.css`. Tailwind 3 has no `@theme` and will not compile it. |
| TypeScript | 5.7+ | Only for an application that type-checks. The components are `.vue` with `lang="ts"`. |
| reka-ui | 2.x | Behind every `components/ui/*` primitive. |

### The starter kit assumption

The published components import **eighteen modules they do not ship**. They are
the application's, and there are two kinds:

- **Generated** — `@/routes/*` and `@/actions/*` come from
  [Wayfinder](https://github.com/laravel/wayfinder), written from the
  application's own route table. Vendoring a copy would be shipping a snapshot
  of somebody else's routes.
- **Starter-kit components** — `@/components/UserMenuContent.vue`,
  `@/composables/useTwoFactorAuth`, and six more. These are where a project
  puts its own account links and its own two-factor flow; shipping ours would
  mean overwriting a file every starter kit already has and every project has
  already edited.

`panel:install` checks for all eighteen and names the ones that are missing.
The full list, and a working stand-in for each, is in
[`frontend/host/`](../frontend/host/README.md).

In practice this means: **a Laravel Vue starter kit application works out of
the box; anything else needs those eighteen files written first.**

## What is deliberately not supported

| | Why |
| --- | --- |
| Inertia (server) 2.x | The panel's forms use Inertia 3's `<Form>` component and the `flash` router event. Neither exists in 2.x, and there is no shim that would make them. |
| Tailwind 3 | `resources/css/panda-panel.css` is a Tailwind 4 stylesheet — `@theme`, `@custom-variant`, `@source`. Tailwind 3 reads none of those directives. |
| React, Svelte | The components are Vue SFCs. The *server* half — resources, tables, forms, actions, policies — is framework-agnostic and serializes to plain arrays, so a React frontend is possible; none is written, and none is planned. |
| Blade-only applications | Every panel screen is an Inertia response. Without Inertia's middleware and a root view, the first panel URL is a 500. |
| Laravel 11 and below | Out of security support, and unresolvable by composer — see [Why not Laravel 11](#why-not-laravel-11). |

## Octane, queues, and long-lived processes

Nothing in this package holds request state in a static. The current panel, the
current parent record, and the current tenant all live in `PanelContext`, which
is a **scoped** container binding — reset at the start of every request by
`ResetPanelContext`, which is registered on the whole `web` group precisely so
it runs for requests that never reach a panel.

Queued work — exports, imports, an action with `->databaseTransaction()` — is
outside a request and therefore outside all of that. The panel's own jobs carry
a panel id and re-resolve it in `handle()`. Tenant-scoped work has to enter a
tenant explicitly:

```php
Tenancy::for($tenant, fn () => InvoiceResource::query()->count());
```

A scoped resource asked outside a tenant raises rather than running unscoped.
That is the whole point: an unscoped query would return every tenant's records
and look like a working page.

## Keeping a published frontend current

The panel's Vue components are published into the application, which means a
package update does not update them. That is a deliberate trade — the
component registries are build-time `import.meta.glob` allowlists over the
application's own tree, and a component you cannot read the source of is one
you cannot debug — but left alone it would mean a frontend that drifts further
behind with every release.

`panel:assets` is the answer, and it is safe to run on an application that has
edited its copies:

```bash
php artisan panel:assets            # what is behind, what you changed, what conflicts
php artisan panel:assets --update   # write only the files you have never touched
```

It works from `.panel-assets.json`, written at install time, which records the
hash of every file *as it was published*. That third value is what separates a
stale file from an edited one — a distinction `vendor:publish` cannot make,
because "differs from the package's copy" is equally true of both. See
`AssetManifest` for the full table.

Files changed on both sides are reported and never written. That is the one
case a tool should not resolve on its own.

## Upgrading

See [upgrade.md](upgrade.md).
