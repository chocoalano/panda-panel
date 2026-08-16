# Using with `stancl/tenancy`

[`stancl/tenancy`](https://tenancyforlaravel.com) does the parts Panda Panel
deliberately does not: creating tenant databases, switching the connection,
partitioning the cache and filesystem, and deciding what a subdomain means.
Panda Panel does the part it cannot: which tenant a panel request is for,
whether this user may enter it, and how a scoped resource is narrowed. This
page is how to put the two together.

**`stancl/tenancy` is not a dependency of this package.** Nothing here is
installed for you — `composer.json` requires PHP `^8.2`, `laravel/framework`
`^12.0|^13.0`, `inertiajs/inertia-laravel` `^3.0`, `laravel/fortify` and
`symfony/finder`, and nothing tenancy-related. The extension points named below
are real; the steps are yours to run, because they add a dependency and change
your database schema.

## Install

```bash
composer require stancl/tenancy
php artisan tenancy:install
php artisan migrate
```

`tenancy:install` publishes `config/tenancy.php`, a `TenancyServiceProvider`,
the `tenants`/`domains` migrations, and a `database/migrations/tenant`
directory. Register the provider in `bootstrap/providers.php`.

```php
// config/tenancy.php

'tenant_model' => App\Models\Tenant::class,

'central_domains' => [
    'example.test',        // where tenants are created and billed
    'admin.example.test',  // and where your admin panel lives
],

'bootstrappers' => [
    Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper::class,
    Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper::class,
    Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper::class,
    Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper::class,
],
```

The **cache bootstrapper is not optional for this framework.** The panel stores
per-user state in the cache, and user 1 exists in every tenant database —
`PandaPanel\Auth\EmailCodeChallenge` keys the emailed second-factor code on the
user id, so without cache separation one tenant's code would satisfy another
tenant's challenge. The same reasoning covers table state, widget filters, and
anything else keyed by an id that is only unique within a tenant.

## The tenant model

```php
<?php

declare(strict_types=1);

namespace App\Models;

use PandaPanel\Contracts\PanelTenant;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

final class Tenant extends BaseTenant implements TenantWithDatabase, PanelTenant
{
    use HasDatabase;
    use HasDomains;

    /**
     * Columns that are real columns rather than JSON in `data`.
     *
     * The panel reads a name for the switcher, so it is worth promoting out
     * of the JSON blob — a switcher that has to decode JSON to draw a label
     * is a switcher that cannot be sorted by it.
     *
     * @return array<int, string>
     */
    public static function getCustomColumns(): array
    {
        return ['id', 'name', 'plan', 'trial_ends_at'];
    }

    public function getTenantKey(): int|string
    {
        return (string) $this->getKey();
    }

    public function getTenantName(): string
    {
        return (string) $this->getAttribute('name');
    }
}
```

Add those columns in a migration on the **central** connection.

`PandaPanel\Contracts\PanelTenant` is optional — without it, `Tenancy` falls
back to the primary key and a `name` attribute, which a stock `stancl` tenant
with a promoted `name` column already satisfies. Implementing it makes the
answer explicit and lets `getTenantKey()` return a string id without a cast at
every comparison. See [`PanelTenant`](panel-tenant.md).

## Routing: which panels are central, which are tenant

This is the part that matters most, and the part a naive install gets wrong.

`PandaPanel\Routing\PanelRouteRegistrar` registers every panel's routes at boot
with the panel's own middleware stack, then appends `ResolvePanel`,
`RequireTwoFactor`, `RequireEmailCode` and — for a panel with tenancy —
`ResolveTenant`. `stancl/tenancy` needs its identification middleware to run
**before** all of those, including `ResolvePanel`: a panel's `canAccess`
predicate reads the user, and which user depends on which database.

```php
use PandaPanel\Core\Panel;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

// A tenant panel: identified by subdomain, on the tenant's database.
Panel::make('app')
    ->path('app')
    ->middleware([
        'web',
        InitializeTenancyByDomain::class,
        PreventAccessFromCentralDomains::class,
    ])
    ->auth()
    ->tenant(Tenant::class, static fn (): ?Tenant => tenant());

// A central panel: no tenancy middleware, and pinned to a central host.
Panel::make('admin')
    ->path('admin')
    ->domain('admin.example.test')
    ->auth();
```

`Panel::middleware()` **replaces** the base stack, which is what puts the
tenancy middleware ahead of `ResolvePanel` — the registrar appends its own
middleware last, always. `Panel::domain()` is what keeps the central panel off
tenant subdomains; without it, `admin.` would be identified as a tenant called
`admin`.

Check the order once, and then again after any change to the panel's stack:

```bash
php artisan route:list --path=app
```

The tenancy middleware must appear before
`PandaPanel\Http\Middleware\ResolvePanel`.

### Routes registered at boot, tenants resolved per request

`PanelRouteRegistrar::registerAll()` runs in the package's service provider
boot. That is fine — routes are static and identification happens per request —
but it means **a panel cannot be registered per tenant.** Every tenant gets the
same panels. If tenants need different resources, express it with
`Resource::canViewAny()` and `Panel::canAccess()`, not by registering different
panels.

## The resolver

By the time `ResolveTenant` runs, `stancl/tenancy` has already identified the
tenant and switched the connection, so the resolver reads it back:

```php
$panel->tenant(Tenant::class, static fn (): ?Tenant => tenant());
```

`tenant()` here is `stancl/tenancy`'s helper, not this package's. Panda Panel's
own global helper is `panel()`; there is no `tenant()` helper in
`src/Support/helpers.php`, so the two never collide.

The return value is type-guarded against the declared model, so a helper that
returns `null` on a central domain produces a 404 rather than a half-scoped
request.

## Membership and the switcher

`ResolveTenant` still asks `HasPanelTenants::canAccessPanelTenant()` on every
request. Connection switching proves which database; it does not prove this
user belongs to it.

```php
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Contracts\HasPanelTenants;
use PandaPanel\Core\Panel;

final class User extends Authenticatable implements HasPanelTenants
{
    /** @return Collection<int, Model> */
    public function getPanelTenants(Panel $panel): Collection
    {
        // Central connection: a user inside a tenant database cannot see the
        // tenant list without one.
        return Tenant::query()
            ->whereIn('id', Membership::query()->where('email', $this->email)->pluck('tenant_id'))
            ->get();
    }

    public function canAccessPanelTenant(Model $tenant, Panel $panel): bool
    {
        return Membership::query()
            ->where('email', $this->email)
            ->where('tenant_id', $tenant->getKey())
            ->exists();
    }
}
```

With users living inside tenant databases, a user reaching the panel at all
already proves they exist in that tenant. The check above is what makes the
*switcher* honest, and what stops a central-connection user model from entering
a tenant it was never invited to.

```php
$panel->tenantUrlUsing(
    static fn (Tenant $tenant, Panel $panel): string
        => "https://{$tenant->domains->first()?->domain}/{$panel->getPath()}",
);
```

## Scoping resources

With a database per tenant, **there is nothing to do**. Leave
`$tenantRelationship` null; `Resource::query()` runs on the current connection
and the current connection is the tenant's. See
[Database Per Tenant](database-per-tenant.md).

If you chose one database instead, scoping *is* your job and
`$tenantRelationship` is where it goes. See
[Single Database Tenancy](single-database.md).

## Sessions

Subdomain identification means cookies and sessions are per-host by default. A
user signed in on `acme.example.test` is not signed in on `beta.example.test`
unless you set:

```bash
SESSION_DOMAIN=.example.test
```

That is either what you want — a hard boundary — or a support ticket every day.
Decide before you build the switcher, not after.

## Migrations

```bash
php artisan tenants:migrate
php artisan tenants:seed
```

Existing migrations need splitting. Anything a tenant owns — `users`,
`notifications`, your domain tables — moves to `database/migrations/tenant`.
Anything central — `tenants`, `domains`, plans — stays in
`database/migrations`.

Two of this package's own migrations are tenant migrations:

- `create_notifications_table` — a notification belongs to a user, and users
  are per tenant.
- `add_email_two_factor_to_users_table` — a column on `users`.

## Queued work

`QueueTenancyBootstrapper` re-enters the tenant when a job runs, for jobs
dispatched inside a tenant context — so the *connection* is right. It does not
restore Panda Panel's own tenant binding: `Tenancy::current()` is null inside
the job, because `PandaPanel\Support\PanelContext` is a scoped binding and the
queue worker forgets scoped instances between jobs. That matters only for code
that reads the binding. See [Queues and Tenant Context](queues.md).

## Billing and trials

`Panel::canAccess()` answers yes or no, which gives a 403 — the wrong answer
for an expired trial, where the user should be sent to billing rather than told
no:

```php
// A rule about the panel — a 403 when it fails.
$panel->canAccess(static fn (?Authenticatable $user): bool => tenant()?->subscribed() === true);
```

For a redirect, use middleware in the panel's stack, modelled on
`PandaPanel\Http\Middleware\RequireTwoFactor` — it holds every page until a
condition is met and sends the user to the one page where it can be resolved:

```php
$panel->middleware([
    'web',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
    EnsureTenantIsSubscribed::class,   // redirects to billing, not 403
]);
```

Exempt the page you redirect to, or you build a loop.

## Panel-side pieces

| Need | Where it goes |
| --- | --- |
| Tenant profile, billing links | `Panel::userMenuItems()` |
| Tenant profile page | a standalone page: `php artisan make:panel-page TenantProfile --panel=App` |
| Tenant registration | a page on the **central** panel — a tenant that does not exist yet has no subdomain to be created from |
| Tenant search | `Panel::globalSearch()` over a central `Tenant` resource, on the central panel |
| Default tenant on login | your own routing; there is no framework hook, because it decides where a bare login lands |

```php
$panel->userMenuItems([
    ['label' => 'Team settings', 'url' => '/app/tenant', 'icon' => 'settings'],
    ['label' => 'Billing', 'url' => '/app/billing', 'icon' => 'receipt'],
]);
```

## The order to do this in

1. Decide the three questions first — one database or many, how a tenant is
   identified, whether a user belongs to one tenant or many — and write the
   answers down.
2. Install and configure. Get `tenants:migrate` working before touching any
   panel.
3. Set `SESSION_DOMAIN` and confirm signing in on a subdomain works, with no
   panels involved.
4. Add the tenancy middleware to one panel and check `route:list`.
5. Move the tenant migrations.
6. Then the panel-side work: `tenant()`, `tenantUrlUsing()`,
   `HasPanelTenants`, the menu and any billing gate.

Steps 3 and 4 are where a mistake is cheap now and invisible later. A panel that
resolves before the tenant does will read the central database and look like it
works, right up until two tenants have a row with the same id.

## Notes

- **Test the two halves separately.** The panel test suite builds panels inline
  and registers routes at runtime; that pattern works unchanged for tenant
  panels, but a test that enters a real tenant needs that tenant's database to
  exist, which is slow. Test *panel* behaviour against a fixture panel with no
  tenancy middleware, and *tenancy* behaviour in its own file. Very few tests
  need both.
- **`stancl/tenancy`'s `tenant()` helper and this package's `panel()` helper
  are unrelated.** Both are global functions; neither shadows the other.
- **`PreventAccessFromCentralDomains` and `Panel::domain()` solve different
  halves of the same problem.** The first keeps the tenant panel off central
  hosts; the second keeps the central panel off tenant hosts.
- **Nothing in this package reads `config/tenancy.php`.** The only bridge is
  your resolver.

## See also

- [Tenancy Concepts](concepts.md)
- [Database Per Tenant](database-per-tenant.md)
- [Single Database Tenancy](single-database.md)
- [Tenant Resolver](resolver.md)
- [Queues and Tenant Context](queues.md)
- [Tenancy Security Checklist](security-checklist.md)
- [Middleware and Guards](../panels/middleware.md)
- [Panel IDs, Paths, and Domains](../panels/ids-paths-domains.md)
- [Compatibility Matrix](../getting-started/compatibility.md)
