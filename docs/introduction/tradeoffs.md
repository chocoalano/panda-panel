# Package Limits And Tradeoffs

Every decision in this package cost something. This page states the costs, names the API each one
lands on, and says plainly what is not supported. Read it before adopting the package, and again
when something behaves in a way that feels like a bug — several of the entries below are working as
designed.

## The clearest example of the trade

A new column type is two edits, not one. That is the price of an explicit, type-checked boundary
between PHP and Vue:

```php
use PandaPanel\Tables\Columns\CustomColumn;

CustomColumn::make('health')
    ->component('Panels/Admin/Columns/HealthBar')
    ->state(static fn (Model $record): array => [
        'percent' => $record->getAttribute('health'),
    ]);
```

```vue
<!-- resources/js/pages/Panels/Admin/Columns/HealthBar.vue -->
<script setup lang="ts">
defineProps<{ state: { percent: number } | null }>();
</script>

<template>
    <span>{{ state?.percent ?? '—' }}%</span>
</template>
```

```bash
npm run build
```

The build step is not optional: the registry is an `import.meta.glob` evaluated at build time, so a
component the build never saw renders a neutral fallback. In exchange, a name that was not compiled
in cannot be reached however a request spells it, and a PHP type with no Vue renderer is a compile
error rather than an empty cell.

## Accepted trades

| Accepted | Cost |
| --- | --- |
| Server round trip per table interaction | Slower than a client-side table; in exchange the URL is the state and there is no duplicated client store |
| PHP metadata plus a Vue renderer | Two places to touch for a new column type; in exchange the boundary is explicit and type-checked on both sides |
| Explicit over magic | More verbose than Filament's conventions in places, for example `getId()` versus a combined accessor |
| Dependency-free SVG chart | No tooltips, zoom or animation; in exchange no charting library and the widget union stays complete |
| Panels listed by hand | One edit per new panel; in exchange the panel set is visible |
| No browser test runner | Client-side interaction is covered by types, the build and server-side request tests only |

### Server round trips

Search, sort, filter, paginate, group and tab all navigate:

```text
/admin/users?search=ada&sort=name&direction=asc&perPage=25&page=2&filters[verified]=true
```

`useResource()` writes to the query string and lets the server answer, with `preserveState` and
`preserveScroll` so typing does not lose focus. There is no client-side table mode and no option to
turn one on. If a table must be interactive without a request, it is not a resource index —
`PandaPanel\Tables\ArrayTableData` covers tables over data that is not in the database, but its
state still lives in the URL.

### Explicit over magic

```php
$panel->path('admin');     // setter, bare name
$panel->getPath();         // reader, get-prefixed
```

Every fluent setter keeps its bare name and every reader is `get`-prefixed. PHP cannot overload, and
a combined accessor returning `string|static` is exactly the magic this framework avoids. Similarly,
panels are listed in `config/panda-panel.php` rather than discovered, and `make:panel` prints the
line to add rather than editing config silently — `panel:install` writes it, because an install that
finishes with an unreachable panel is a worse outcome.

### Charts

`ChartVariant` is `Bar`, `Line`, `Area`, `Doughnut`. `ChartOptions` covers legend, grid, stacked,
filled, curved, point labels, a pinned `range()` and a value `format()`. Anything beyond that is a
`CustomWidget`:

```php
use PandaPanel\Widgets\CustomWidget;

final class Heatmap extends CustomWidget
{
    protected static string $component = 'Panels/Admin/Widgets/Heatmap';

    /** @return array<string, mixed> */
    public function data(): array
    {
        return ['cells' => /* ... */];
    }
}
```

## Known gaps, stated rather than implied

- **`Select::relationship()` is implemented but has no feature test.** No model in `examples/` has a
  relation worth selecting, so it is covered by types and guard clauses rather than by a request
  test.
- **The `verified` middleware is inert unless your `User` implements `MustVerifyEmail`.** Panels
  declare it correctly through `->auth()` and will enforce it the moment the model implements the
  contract. Whether to require verification is a product decision.
- **`$panel->assets()` is two edits.** The path must also appear in `vite.config.ts`'s `input`, or
  Vite has nothing to serve and the page fails with a manifest error. That failure is the right one
  — a declared asset that was never built is a mistake — but it is why this is not a one-line change.
- **`cssHooks()` classes must survive the Tailwind build.** Arbitrary strings from a panel provider
  are not in any file Tailwind scans, so either use classes that appear elsewhere in the application
  or add the provider to the content globs.
- **Colours set through `colors()` are dropped silently when invalid.** The property must be one the
  stylesheet reads and the value must parse as a colour. A panel with one bad colour still renders
  with the rest of its theme, which is the right failure, but nothing tells you the value was
  ignored.
- **An unregistered icon name renders nothing, with no error.** Run `php artisan panel:icons` after
  declaring a new one; `--check` fails instead of writing, for CI.

## Deliberately not supported

| | Why |
| --- | --- |
| Inertia (server) 2.x | The panel's forms use Inertia 3's `<Form>` component and the `flash` router event. Neither exists in 2.x, and no shim would make them. |
| Tailwind 3 | `resources/css/panda-panel.css` is a Tailwind 4 stylesheet — `@theme`, `@custom-variant`, `@source`. Tailwind 3 reads none of those directives. |
| React, Svelte | The components are Vue SFCs. The server half serializes to plain arrays and is framework-agnostic, so another renderer is possible; none is written, and none is planned. |
| Blade-only applications | Every panel screen is an Inertia response. Without Inertia's middleware and a root view, the first panel URL is a 500. |
| Laravel 11 and below | Out of security support, and unresolvable by composer — see below. |
| Livewire | Not used anywhere. No Filament plugin, theme or custom field works here. |

### Why not Laravel 11

Two reasons, and the second is decisive. Laravel 11's security window closed in March 2026. And
every 11.x release, from v11.0.0 to v11.55.1, is flagged by unpatched security advisories, so
`composer update` against a `^11.x` constraint does not resolve — it reports the advisory IDs and
stops. A package cannot claim to support a version whose own CI could not install it.

The gap is smaller than it looks: an application on **PHP 8.2 is fully supported**, through Laravel
12, which is the newest Laravel that runs on it. It is Laravel 11 specifically, not old PHP, that is
out of reach.

## Version support

| | Supported |
| --- | --- |
| PHP | 8.2, 8.3, 8.4 |
| Laravel | 12.x, 13.x |
| Inertia (server) | `inertiajs/inertia-laravel` 3.x |
| Fortify | 1.37.2+ |
| Node | 20.19+, 22, 24 |
| Vue | 3.5+ |
| Vite | 7.x |
| Tailwind | 4.1+ |
| TypeScript | 5.7+ |
| Database | MySQL 8+, PostgreSQL 13+, SQLite 3.35+, MariaDB 10.6+ |

CI runs the cross-product of PHP × Laravel × `prefer-lowest`/`prefer-stable`, so the bottom of every
declared range is tested too. PHP 8.2 × Laravel 13 is excluded because it does not exist: Laravel 13
requires PHP 8.3.

## The starter kit assumption

The published components import **[nineteen modules they do not ship](../frontend/host-modules.md)**,
and both kinds are the application's on purpose: `@/routes/*` and `@/actions/*` are generated by
Wayfinder from your own route table, and the rest — `@/components/UserMenuContent.vue`,
`@/composables/useTwoFactorAuth`, eight more — are where a project keeps its own account UI.

In practice: **a Laravel Vue starter kit application works out of the box; anything else needs those
nineteen files written first.** `panel:install` checks for all of them and names the ones missing.

Two starter kit addresses change behaviour, and both stay addresses:

| | What happens | How to keep yours |
| --- | --- | --- |
| `/dashboard` | A signed-in user is redirected to the first panel they can enter. The route, its name and its page component are untouched. | `home_redirect.enabled => false` |
| `/settings/*` | Nothing in the package. The example application redirects them into the panel's own settings pages. | do not copy `SettingsRedirectController` |

## Owning the frontend

The panel's Vue components are published into `resources/js`, which is what makes them debuggable
and what the build-time registries require. The cost: `composer update` cannot improve a file you
now own, and `vendor:publish` cannot help — without `--force` it updates nothing, with `--force` it
overwrites your edits, and it cannot tell the two apart.

```bash
php artisan panel:assets            # what is behind, what you changed, what conflicts
php artisan panel:assets --update   # write only the files you have never touched
php artisan panel:assets --force    # also overwrite files this application has edited
npm run build
```

| On disk | In package | Reported as | `--update` |
| --- | --- | --- | --- |
| unchanged | unchanged | current | — |
| unchanged | changed | out of date | written |
| changed | unchanged | yours | left alone |
| changed | changed | **conflict** | never written |

It works from `.panel-assets.json`, written at install time, which records the hash of every file
*as it was published*. Commit that file: it is the record of what your application published, the
same way `composer.lock` records what it installed. A conflict is named by path and left exactly as
it is — that is the one case a tool should not resolve on its own.

## Outside a request

Nothing in this package holds request state in a static. The current panel, the current parent
record and the current tenant all live in `PanelContext`, a **scoped** container binding reset by
`ResetPanelContext`, which is registered on the whole `web` group precisely so it runs for requests
that never reach a panel. Octane is therefore fine — as long as you do not turn
`register_web_middleware` off without re-registering it.

Queued work is outside a request and therefore outside all of that:

```php
use PandaPanel\Tenancy\Tenancy;

Tenancy::for($tenant, fn () => InvoiceResource::query()->count());
```

A tenant-scoped resource asked outside a tenant **raises** rather than running unscoped. That is the
whole point: an unscoped query would return every tenant's records and look like a working page.
`panel()` likewise returns `null` outside a panel; code that needs one asks by id.

## Security posture, and where it stops

Some defaults are deliberately inconvenient:

- **Integrations are deny-by-default.** `integrations.allowed_hosts` is empty, so nothing is
  reachable until a destination is added to config — a deploy, not a form submission. The screen
  issues server-side HTTP with a destination somebody typed, which is an SSRF surface by
  construction. `integrations.block_private_networks` refuses hosts resolving into private, loopback
  or link-local ranges, checked at save time and again immediately before each request.
- **CSV cells beginning with `=`, `+`, `-`, `@`, a tab or a carriage return carry a leading
  apostrophe.** Spreadsheets evaluate such cells on open. `Exporter::escapesFormulas()` turns it off
  for a feed another machine reads.
- **Export and import files land on a private disk** under a per-user directory, and the download
  endpoint builds that segment from whoever is asking.
- **A missing policy denies.** A freshly generated resource 403s until its model has one. Turn on
  `strictAuthorization()` to make a missing policy — or a policy missing the ability — raise instead.

What this package does *not* do: it does not create databases, switch connections, or decide what a
subdomain means. Tenancy identification is your application's, which is also why reversing a tenant
into a URL is (`tenantUrlUsing()`), and why the switcher does not render without it.

## Gotchas

- **`--update` never resolves a conflict.** A file changed on both sides is reported and left alone.
  Diff it against `vendor/chocoalano/panel`, merge by hand, then `--force`.
- **Turning `register_web_middleware` off removes `ResetPanelContext` too.** Under Octane that is
  the one you cannot skip.
- **`DeleteBulkAction` is transactional whatever the panel says.** All or nothing is the guarantee it
  advertises, so `databaseTransactions(false)` does not reach it.
- **A resource overriding `query()` must call `parent::query()`,** or a per-panel
  `modifyQueryUsing()` narrowing is silently dropped.
- **A panel registered twice in `panels` registers once**, but two panels sharing an id, or a
  path/domain pair, throw `PandaPanel\Exceptions\PanelRegistrationException` at boot.
- **`panel:cache` means discovery does not run at all.** A new resource added after a deploy-time
  cache is invisible until `panel:cache` runs again. In development the manifest warns when it is
  stale; in production it does not, because there is nobody to warn.

## See also

- [Why Panda Panel](why-panda-panel.md) — the reasoning these costs pay for
- [Feature Overview](features.md) — what does exist
- [Comparison With Filament Concepts](filament-comparison.md) — where the two frameworks part
- [Compatibility Matrix](../getting-started/compatibility.md) — the full support table
- [Frontend Requirements](../getting-started/frontend-requirements.md) and [Host Modules](../frontend/host-modules.md)
- [Updating Assets](../frontend/updating-assets.md), [Asset Conflicts](../upgrading/asset-conflicts.md)
- [Octane](../deployment/octane.md), [Queues](../deployment/queues.md)
- [Tenancy Security Checklist](../tenancy/security-checklist.md)
