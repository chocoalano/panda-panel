# Database Per Tenant

One database per tenant, with the connection itself as the boundary. There is
no `tenant_id` to forget on a query, because there is no `tenant_id` — which
removes a whole class of bug and moves the work to provisioning, migrating and
operating many databases. Reach for it when tenants are few and large, when
isolation has to be demonstrable, or when a customer's data has to be
exportable or deletable as a unit.

## What the panel does, and does not do

Panda Panel never switches a connection. That is `stancl/tenancy`'s job, and it
runs before the panel's own middleware. What the panel still contributes is the
part the connection cannot answer:

| Concern | Owner |
| --- | --- |
| Creating the database, running its migrations | `stancl/tenancy` |
| Switching the connection for this request | `stancl/tenancy` identification middleware |
| Which tenant this request is for, as a model | `Panel::tenant()` |
| Whether this user may enter it | `HasPanelTenants::canAccessPanelTenant()` |
| The switcher and its URLs | `Panel::tenantUrlUsing()` |
| Scoping resources | **nothing — the connection is the scope** |

## A tenant panel

```php
<?php

declare(strict_types=1);

namespace App\Panels\App;

use App\Models\Tenant;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelProvider;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

final class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->path('app')
            // Replaces the base stack, which is what puts the tenancy
            // middleware ahead of ResolvePanel. Include `web` yourself.
            ->middleware([
                'web',
                InitializeTenancyByDomain::class,
                PreventAccessFromCentralDomains::class,
            ])
            ->auth()
            // By the time this runs the connection is already the tenant's,
            // so the resolver reads the identified tenant back.
            ->tenant(Tenant::class, static fn (): ?Tenant => tenant())
            ->tenantUrlUsing(
                static fn (Tenant $tenant, Panel $panel): string
                    => "https://{$tenant->domains->first()?->domain}/{$panel->getPath()}",
            );
    }
}
```

`middleware()` **replaces** the base stack, which is what gets the
identification middleware in front of `PandaPanel\Http\Middleware\ResolvePanel`
— the panel's `canAccess` predicate reads the user, and which user depends on
which database. Check the order once:

```bash
php artisan route:list --path=app
```

The tenancy middleware must appear before `PandaPanel\Http\Middleware\ResolvePanel`.

## Scoping resources: nothing to do

```php
final class DocumentResource extends Resource
{
    protected static string $model = Document::class;

    // No $tenantRelationship. There is no column to scope by.
}
```

`Resource::query()` runs on whatever connection is current, and the current
connection is the tenant's. Naming a `tenantRelationship()` here would build a
`whereHas` against a `tenants` table that does not exist inside a tenant
database.

`applyTenantScope()` returns the query untouched for any resource that names no
relationship, so declaring `tenant()` on the panel costs these resources
nothing.

Two things still need saying.

**`Resource::query()` remains the single scope.** Everything in this framework
goes through it — the list, the record lookup, actions, global search, exports.
A further narrowing *inside* a tenant belongs there and nowhere else.

**Central resources need a connection.** A panel on the central domain that
manages tenants reads a model that does not live in a tenant database:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

final class Tenant extends BaseTenant
{
    /** Whatever config/tenancy.php names as the central connection. */
    protected $connection = 'central';
}
```

Without it, opening the tenant list from inside a tenant context queries that
tenant's own database — which has no `tenants` table, and the error will not
say why.

## Why declare `tenant()` at all

With no scoping to do, the panel-side declaration still earns its place:

- **The per-request access check.** `ResolveTenant` asks
  `canAccessPanelTenant()` on every request. Connection switching proves *which*
  database; it does not prove this user belongs to it.
- **The switcher.** `Tenancy::availableTo()` and `Panel::getTenantUrl()` are
  what let a user move between tenants at all.
- **`Tenancy::current()`** for your own code — a heading that names the tenant,
  a widget, an audit entry.

If the panel declares no `tenant()`, none of the above exists and
`ResolveTenant` is never registered. That is a legitimate choice for an
arrangement where a user belongs to exactly one tenant and there is nothing to
switch.

## Where users live

With a database per tenant, users usually live **inside** the tenant database,
and "one user, many tenants" means separate rows that happen to share an email
address. If you need one account across tenants, the user table has to be
central and the tenant databases hold everything else — a real design decision,
not a setting.

It also decides what `getPanelTenants()` can answer. Users inside the tenant
database cannot see the tenant list, so the switcher needs a central pivot:

```php
/** @return Collection<int, Model> */
public function getPanelTenants(Panel $panel): Collection
{
    return Tenant::query()          // on the central connection
        ->whereIn('id', Membership::query()
            ->where('email', $this->email)
            ->pluck('tenant_id'))
        ->get();
}
```

## Migrations

Split them by owner:

| Migration | Where |
| --- | --- |
| `users`, your domain tables | `database/migrations/tenant` |
| `tenants`, `domains`, plans, billing | `database/migrations` |

Two of this package's own migrations are tenant migrations:

- `create_notifications_table` — a notification belongs to a user, and users
  are per tenant.
- `add_email_two_factor_to_users_table` — a column on `users`.

```bash
php artisan tenants:migrate
php artisan tenants:seed
```

The `jobs` and `cache` tables are a judgement call. Central is simpler and
means one worker; per-tenant means one tenant cannot fill another's queue.

## Cache separation is not optional

The panel stores per-user state in the cache, keyed by the user id — and user 1
exists in every tenant database. `PandaPanel\Auth\EmailCodeChallenge` keys its
emailed second-factor code on the user id; without cache separation, one
tenant's code would satisfy another tenant's challenge. The same reasoning
covers table state, widget filters and anything else keyed by an id that is
only unique within a tenant.

```php
// config/tenancy.php
'bootstrappers' => [
    Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper::class,
    Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper::class,
    Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper::class,
    Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper::class,
],
```

## Notes

- **The connection is the boundary, and `Tenancy` is the label.** They are
  independent. A resolver that returns the wrong tenant model does not move the
  connection, and switching the connection does not bind a tenant.
- **Panels are registered at boot.** Every tenant gets the same panels; a panel
  cannot be registered per tenant. Express per-tenant differences with
  `Resource::canViewAny()` and `Panel::canAccess()`.
- **A central panel needs `->domain()`.** Without it, `admin.example.test`
  would be identified as a tenant called `admin`.
- **Queued work does not carry the panel's tenant binding.** The connection is
  restored by `QueueTenancyBootstrapper`; `Tenancy::current()` is not. It
  matters only for code that reads it — see [Queues](queues.md).
- **`php artisan panel:cache` caches panel discovery, not tenant data.** It is
  safe here, and it is per deployment rather than per tenant.

## See also

- [Tenancy Concepts](concepts.md)
- [Using with stancl/tenancy](stancl-tenancy.md)
- [Single Database Tenancy](single-database.md)
- [Resource Tenant Scoping](resource-scoping.md)
- [Queues and Tenant Context](queues.md)
- [Tenancy Security Checklist](security-checklist.md)
- [Middleware and Guards](../panels/middleware.md)
- [Panel IDs, Paths, and Domains](../panels/ids-paths-domains.md)
- [Email Code Challenge](../authentication/email-code-challenge.md)
