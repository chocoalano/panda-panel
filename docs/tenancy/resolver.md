# Tenant Resolver

The resolver is the closure that answers "which tenant is this request for".
It is the one piece of tenancy the framework refuses to guess at, because every
plausible default — a subdomain, a route parameter, the user's only team — is
right for one arrangement and a silent data leak in another. You write it once,
on the panel, and `PandaPanel\Http\Middleware\ResolveTenant` calls it once per
request before any controller runs.

## Declaring one

```php
<?php

declare(strict_types=1);

namespace App\Panels\App;

use App\Models\Team;
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
                Team::class,
                static fn (Request $request): ?Team => Team::query()
                    ->where('slug', $request->route('team'))
                    ->first(),
            );
    }
}
```

Two arguments. The first is the tenant model class; the second is the closure.
Declaring them is what turns tenancy on for the panel — nothing else does.

## `Panel::tenant()`

```php
/**
 * @param  class-string<Model>  $model
 * @param  Closure(Request, ?Authenticatable): ?Model  $resolver
 */
public function tenant(string $model, Closure $resolver): self
```

| Argument | Type | Meaning |
| --- | --- | --- |
| `$model` | `class-string<Illuminate\Database\Eloquent\Model>` | What a tenant is. Also the type guard on the resolver's return value. |
| `$resolver` | `Closure(Request, ?Authenticatable): ?Model` | How to find one. Receives the request and the authenticated user. |

Calling `tenant()` has four effects, and they are worth being precise about:

- Every route group the panel registers gets `ResolveTenant`, which
  identifies the tenant, checks the user against it and binds it before any
  controller runs.
- `PandaPanel\Tenancy\Tenancy::current()` answers for the rest of the request.
- A resource that names a `tenantRelationship()` is scoped automatically, and
  one that does not is left exactly as it was.
- The switcher's list is shared with the frontend.

It does **not** switch a connection, partition a cache, or read a subdomain.

## Three resolver shapes

The resolver's job is to return a model or `null`. Where it gets the model from
is entirely yours.

**Database per tenant, identified by subdomain.** `stancl/tenancy` has already
identified the tenant and switched the connection by the time this runs, so the
resolver reads it back:

```php
use App\Models\Tenant;

$panel->tenant(Tenant::class, static fn (): ?Tenant => tenant());
```

**One database, tenants on a path segment.** The route parameter is the
identifier and the resolver does the lookup:

```php
use App\Models\Team;
use Illuminate\Http\Request;

$panel->tenant(
    Team::class,
    static fn (Request $request): ?Team => Team::query()
        ->where('slug', $request->route('team'))
        ->first(),
);
```

**One tenant per user, nothing in the URL at all.** The second argument is the
authenticated user:

```php
use App\Models\Workspace;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

$panel->tenant(
    Workspace::class,
    static fn (Request $request, ?Authenticatable $user): ?Workspace => $user?->workspace,
);
```

A query parameter works too, and is what the framework's own test suite uses
because it exercises the same code path with no host or route setup:

```php
$panel->tenant(
    Workspace::class,
    static fn (Request $request): ?Workspace => Workspace::query()
        ->find($request->query('workspace')),
);
```

## Reading the declaration back

```php
public function hasTenancy(): bool
public function getTenantModel(): ?string          // class-string<Model>|null
public function resolveTenant(Request $request, ?Authenticatable $user): ?Model
```

```php
use PandaPanel\Core\Panel;

panel('app')->hasTenancy();        // true
panel('app')->getTenantModel();    // 'App\Models\Team'
```

`resolveTenant()` is called by `ResolveTenant` and by nothing else. It is where
the type guard lives:

```php
public function resolveTenant(Request $request, ?Authenticatable $user): ?Model
{
    $model = $this->tenantModel;
    $resolver = $this->tenantResolver;

    if ($resolver === null || $model === null) {
        return null;
    }

    $tenant = $resolver($request, $user);

    return $tenant instanceof $model ? $tenant : null;
}
```

A resolver returning something other than the declared model is treated as **no
tenant**, which is a 404. A mistyped resolver that returned the *user* would
otherwise scope every query by a user id and look, at a glance, like it worked.

## What the middleware does with the answer

`PandaPanel\Http\Middleware\ResolveTenant`:

```php
public function handle(Request $request, Closure $next, string $panelId): Response
```

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

| Resolver returned | Response |
| --- | --- |
| A model of the declared class, the user may enter it | the request proceeds, tenant bound |
| A model of the declared class, the user may not enter it | `403` |
| `null` | `404 No such tenant.` |
| Anything else (wrong class, an array, a string) | `404 No such tenant.` |

The panel id is a middleware *parameter*, not something matched from the URL,
so two panels sharing a prefix are never ambiguous and the route stays
cacheable.

## Where it sits in the stack

The registrar appends the framework's middleware after the panel's own stack,
in this order:

| Position | Middleware | Why there |
| --- | --- | --- |
| 1 | `ResolvePanel:{id}` | Binds the panel; the resolver is read off it. |
| 2 | `RequireTwoFactor:{id}` | |
| 3 | `RequireEmailCode:{id}` | |
| 4 | `ResolveTenant:{id}` | After the user is known, before any controller can query. |

Only a panel that called `tenant()` gets the fourth entry, so a panel without
tenancy pays nothing and a panel with it cannot forget.

```bash
php artisan route:list --path=app
```

Any middleware that must run *before* the panel is resolved — `stancl/tenancy`'s
identification, for instance — goes in the panel's own stack, which replaces the
base stack:

```php
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

$panel->middleware([
    'web',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
]);
```

## Registering a route by hand

There is no middleware alias for `ResolveTenant`. A route outside the panel's
own group that needs a bound tenant names the class and passes the panel id:

```php
use Illuminate\Support\Facades\Route;
use PandaPanel\Http\Middleware\ResolvePanel;
use PandaPanel\Http\Middleware\ResolveTenant;

Route::get('/app/report', ReportController::class)
    ->middleware([
        'web',
        'auth',
        ResolvePanel::class.':app',
        ResolveTenant::class.':app',
    ]);
```

Without it, any tenant-scoped resource read from that controller throws
`PanelRegistrationException::noCurrentTenant()`.

## Notes

- **The resolver runs on every panel request**, including the dashboard, the
  search endpoint, the action endpoints and the upload endpoint. Keep it to one
  indexed lookup; it is not a place for eager loading a tenant's whole graph.
- **The panel's guest pages never call it.** Login, registration, password
  reset and email verification are registered with the base middleware and
  `ResolvePanel` only, so a tenant is not resolved before somebody has signed
  in.
- **`null` from the resolver is a 404, not a redirect.** If a bare `/app` with
  no tenant named should land somewhere sensible, that is a routing decision:
  redirect from your own middleware placed ahead of `ResolveTenant`, or have
  the resolver fall back to a default tenant for the user.
- **The resolver cannot assume a user.** The second argument is `?Authenticatable`
  and is null on any panel route reachable by a guest.
- **`tenant()` called twice replaces both values.** There is one model and one
  resolver per panel.
- **Adding tenancy after boot has no effect on already-registered routes.** The
  middleware list is built when the route group is registered.

## See also

- [Tenancy Concepts](concepts.md)
- [`HasPanelTenants`](has-panel-tenants.md)
- [Tenant URLs](urls.md)
- [Resource Tenant Scoping](resource-scoping.md)
- [Using with stancl/tenancy](stancl-tenancy.md)
- [Middleware and Guards](../panels/middleware.md)
- [Panel IDs, Paths, and Domains](../panels/ids-paths-domains.md)
- [Request Lifecycle](../concepts/request-lifecycle.md)
