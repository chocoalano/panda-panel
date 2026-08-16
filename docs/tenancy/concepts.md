# Tenancy Concepts

Panda Panel's tenancy is one narrow thing: a stable, tested answer to *which
tenant is this request for*, bound before any controller runs, plus the access
check and the query scope that follow from it. It is not a multi-tenancy
implementation — it does not create databases, switch connections, partition a
cache or read a subdomain. You reach for it when a panel's records belong to a
team, workspace, organisation or customer account and you want the scope
enforced in one place instead of re-derived inside every resource.

## A working tenant panel

Three pieces, and all three are required. The panel says what a tenant is and
how to find one; the user model says which tenants it may enter; the resource
says how its records reach a tenant.

```php
<?php

declare(strict_types=1);

namespace App\Panels\App;

use App\Models\Workspace;
use Illuminate\Http\Request;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelProvider;

final class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->path('app')
            ->auth()
            ->tenant(
                Workspace::class,
                static fn (Request $request): ?Workspace => Workspace::query()
                    ->find($request->query('workspace')),
            )
            ->tenantUrlUsing(
                static fn (Workspace $workspace, Panel $panel): string => '/'
                    .$panel->getPath().'/documents?workspace='.$workspace->getKey(),
            );
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use PandaPanel\Contracts\HasPanelTenants;
use PandaPanel\Core\Panel;

final class User extends Authenticatable implements HasPanelTenants
{
    /** @return BelongsToMany<Workspace, $this> */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class);
    }

    /** @return Collection<int, Model> */
    public function getPanelTenants(Panel $panel): Collection
    {
        return $this->workspaces()->orderBy('id')->get();
    }

    public function canAccessPanelTenant(Model $tenant, Panel $panel): bool
    {
        return $this->workspaces()->whereKey($tenant->getKey())->exists();
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Panels\App\Resources\Documents;

use App\Models\Document;
use PandaPanel\Resources\Resource;

final class DocumentResource extends Resource
{
    protected static string $model = Document::class;

    /** The relationship on Document that leads to a Workspace. */
    protected static ?string $tenantRelationship = 'workspace';

    // table(), form(), pages() as usual
}
```

`GET /app/documents?workspace=7` now lists only the documents belonging to
workspace 7, and only for a user who belongs to it. A query parameter is the
smallest identification scheme that works and is what the framework's own test
suite uses; a real application usually identifies by subdomain or path segment
— see [Tenant URLs](urls.md).

## What happens on a request

`PandaPanel\Routing\PanelRouteRegistrar` appends
`PandaPanel\Http\Middleware\ResolveTenant` to a panel's route group, and only
for a panel that called `tenant()`. It runs last in the framework's own
middleware — after `ResolvePanel`, `RequireTwoFactor` and `RequireEmailCode` —
because it asks the panel for its resolver and hands the resolver the
authenticated user.

```php
$panel = app(PanelManager::class)->get($panelId);

if (! $panel->hasTenancy()) {
    return $next($request);
}

$user = $request->user();

$tenant = $panel->resolveTenant($request, $user);

abort_if($tenant === null, 404, 'No such tenant.');
abort_unless(Tenancy::allows($user, $tenant, $panel), 403);

Tenancy::bind($tenant);
```

Three things, and all three must succeed before anything is queried:

| Step | Who answers | Failure |
| --- | --- | --- |
| Identification | the panel's `tenant()` resolver | `404 No such tenant.` |
| Authorization | `HasPanelTenants::canAccessPanelTenant()` | `403` |
| Binding | `Tenancy::bind()` | — |

A tenant that cannot be identified is a **404** rather than a redirect: the
request named something that does not exist. A tenant the user may not enter
is a **403**, deliberately not a 404 — hiding *which* tenants exist from
somebody who already had to name one costs a comprehensible error message and
buys nothing.

## Where the tenant lives

`PandaPanel\Tenancy\Tenancy` stores it in `PandaPanel\Support\PanelContext`
under the key `panel.tenant`, not in a static. The context is bound with
`scoped()`, so the tenant lives exactly as long as the request does and cannot
leak between requests, between tests, or between two requests inside one Octane
worker — the same reason the current panel and the current parent record live
there. See [Panel Context](../concepts/panel-context.md).

## The `Tenancy` API

`PandaPanel\Tenancy\Tenancy` is `final` and every method is static. There is
nothing to instantiate and nothing to inject.

| Method | Signature | Returns |
| --- | --- | --- |
| `bind` | `bind(Model $tenant): void` | — |
| `current` | `current(): ?Model` | the bound tenant, or `null` |
| `require` | `require(): Model` | the tenant, or throws |
| `key` | `key(): int\|string\|null` | the current tenant's key |
| `keyOf` | `keyOf(Model $tenant): int\|string` | one tenant's key |
| `nameOf` | `nameOf(Model $tenant): string` | one tenant's screen name |
| `describe` | `describe(Model $tenant): array{key: int\|string, name: string}` | one tenant, as the frontend receives it |
| `availableTo` | `availableTo(?Authenticatable $user, Panel $panel): list<Model>` | the switcher's list |
| `allows` | `allows(?Authenticatable $user, Model $tenant, Panel $panel): bool` | the per-request check |
| `for` | `for(Model $tenant, callable $callback): mixed` | the callback's return value |

```php
use App\Models\Workspace;
use PandaPanel\Tenancy\Tenancy;

Tenancy::current();                  // ?Model — null outside a tenant panel
Tenancy::current()?->getKey();       // 7

Tenancy::key();                      // 7, or null when there is no tenancy
Tenancy::require();                  // Model, or PanelRegistrationException

$workspace = Workspace::query()->find(7);

Tenancy::keyOf($workspace);          // 7
Tenancy::nameOf($workspace);         // 'Acme'
Tenancy::describe($workspace);       // ['key' => 7, 'name' => 'Acme']
```

`require()` throws `PandaPanel\Exceptions\PanelRegistrationException` rather
than degrading to "no scope". A tenant-scoped resource with no tenant bound is
a route registered without `ResolveTenant`, and answering the query unscoped
would show every tenant's records to whoever asked.

### Entering a tenant outside a request

```php
use PandaPanel\Tenancy\Tenancy;

$titles = Tenancy::for($workspace, static fn (): array => DocumentResource::query()
    ->pluck('title')
    ->all());
```

`for()` binds, runs, and restores the previous binding in a `finally` — so a
callback that throws does not leave the rest of the process scoped to somebody
else's tenant. It is the supported entry point for console commands looping
over tenants, jobs re-entering the one they were queued from, and tests
asserting that two tenants see different rows.

```php
Tenancy::bind($acme);

try {
    Tenancy::for($beta, fn () => throw new RuntimeException('nope'));
} catch (RuntimeException) {
    // ...
}

Tenancy::current()?->getKey();   // still Acme
```

Starting from nothing bound leaves nothing bound:

```php
Tenancy::for($acme, fn () => null);

Tenancy::current();   // null
```

`bind()` is public because `ResolveTenant` and tests need it. Nothing else
should call it: a binding made halfway through a request is a scope that took
effect after everything before it had already queried unscoped.

## The two contracts

| Contract | Implemented by | Required? |
| --- | --- | --- |
| `PandaPanel\Contracts\HasPanelTenants` | the user model | Yes, for any tenant-scoped panel |
| `PandaPanel\Contracts\PanelTenant` | the tenant model | Optional |

`HasPanelTenants` is what makes a tenant panel answerable at all. A user model
that does not implement it belongs to nothing as far as the panel is
concerned, so `Tenancy::allows()` is false and every request is a 403 — a loud
refusal rather than a panel that falls open. See
[`HasPanelTenants`](has-panel-tenants.md).

`PanelTenant` states which value identifies a tenant and what to call it on
screen. Without it, `Tenancy` falls back to the primary key and a `name`
attribute. See [`PanelTenant`](panel-tenant.md).

## What the frontend receives

`PandaPanel\Http\Middleware\SharePanelData` shares a `tenancy` prop — `null`
for a panel with no tenancy, so the frontend's check is `tenancy === null` and
nothing tenant-shaped renders in an application that has no tenants.

```ts
import { usePanel } from '@/panel/composables/usePanel';

const { tenancy, canSwitchTenants } = usePanel();

tenancy.value?.current?.name;      // 'Acme'
tenancy.value?.available;          // PanelTenantSummary[]
```

See [Tenant Switcher](switcher.md) and
[Server Metadata to Vue](../concepts/metadata-to-vue.md).

## What this is not

| Concern | Owner |
| --- | --- |
| Creating a tenant's database | `stancl/tenancy`, or your own code |
| Switching the database connection | `stancl/tenancy` |
| Partitioning the cache, filesystem, queue | `stancl/tenancy` bootstrappers |
| Deciding what a subdomain means | your resolver |
| Building a tenant's URL | your `tenantUrlUsing()` closure |
| Writing `tenant_id` on create | a model observer or a global scope |

The resolver is required rather than defaulted because every plausible default
is right for one arrangement and a silent data leak in another. See
[Using with stancl/tenancy](stancl-tenancy.md).

## Notes

- **The panel's guest pages have no tenant.** `login`, `register`,
  `forgot-password`, `reset-password/{token}` and `verify-email` are
  registered with the panel's *base* middleware and `ResolvePanel` only, so
  `ResolveTenant` never runs there and `Tenancy::current()` is null.
- **Tenancy is a property of the panel, not of the resource.** The same
  resource class registered in a tenant panel and in an admin panel is scoped
  in the first and whole in the second — `applyTenantScope()` returns the
  query untouched when `panel()` is null or `hasTenancy()` is false.
- **A resource that names no relationship is not scoped.** That is the opt-in,
  not an oversight: a global lookup table and a database-per-tenant
  arrangement both have nothing to scope by.
- **`Tenancy::key()` is not always `current()->getKey()`.** It goes through
  `keyOf()`, which asks `PanelTenant::getTenantKey()` first. A tenant that
  identifies by slug returns the slug here.
- **There is no `ResolveTenant` middleware alias.** The four registered
  aliases are `panel`, `panel.two-factor`, `panel.email-code` and
  `panel.parent`. A route registered by hand must name the class and pass the
  panel id: `ResolveTenant::class.':'.$panel->getId()`.
- **Panels are registered at boot; tenants are resolved per request.** A panel
  cannot be registered per tenant. Express per-tenant differences with
  `Resource::canViewAny()` and `Panel::canAccess()`.

## See also

- [Tenant Resolver](resolver.md)
- [`HasPanelTenants`](has-panel-tenants.md)
- [`PanelTenant`](panel-tenant.md)
- [Tenant Switcher](switcher.md)
- [Tenant URLs](urls.md)
- [Resource Tenant Scoping](resource-scoping.md)
- [Single Database Tenancy](single-database.md)
- [Database Per Tenant](database-per-tenant.md)
- [Using with stancl/tenancy](stancl-tenancy.md)
- [Queues and Tenant Context](queues.md)
- [Tenancy Security Checklist](security-checklist.md)
- [Panel Context](../concepts/panel-context.md)
- [Authorization](../concepts/authorization.md)
- [Middleware and Guards](../panels/middleware.md)
