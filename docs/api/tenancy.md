# Tenancy

The reference for every class the framework's tenancy is made of:
`PandaPanel\Tenancy\Tenancy`, the two contracts a project implements, the
`Panel` methods that turn tenancy on, and the `Resource` members that scope a
query to it. Reach for this page when you know what tenancy does and need the
exact signature, return type, or failure mode of one call. For the narrative —
what a tenant is here, why identification is the application's job — start at
[Tenancy Concepts](../tenancy/concepts.md).

This is not a multi-tenancy implementation. Nothing here creates a database,
switches a connection, partitions a cache, or reads a subdomain. It answers one
question, once per request: *which tenant is this request for*, and then keeps
the answer where every reader can get at it.

## A minimal working example

Three pieces, all required: the panel says what a tenant is and how to find
one, the user model says which tenants it may enter, and the resource says how
its records reach a tenant.

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

    /** The relationship on Document that leads to the tenant. */
    protected static ?string $tenantRelationship = 'workspace';
}
```

`GET /app/documents?workspace=1` now resolves workspace 1, checks the user
against it, binds it, and narrows every read of `DocumentResource` to it.

## `PandaPanel\Tenancy\Tenancy`

A `final` class of static methods. The tenant lives in
`PandaPanel\Support\PanelContext` — a `scoped()` container binding — rather
than in a static property, so it lives exactly as long as the request does and
cannot leak between requests, tests, or two requests inside one Octane worker.

| Method | Returns | Purpose |
| --- | --- | --- |
| `bind(Model $tenant)` | `void` | Binds the tenant for this request |
| `current()` | `?Model` | The bound tenant, or null |
| `require()` | `Model` | The bound tenant, or throws |
| `key()` | `int\|string\|null` | The current tenant's identifying value |
| `keyOf(Model $tenant)` | `int\|string` | One tenant's identifying value |
| `nameOf(Model $tenant)` | `string` | What to call a tenant on screen |
| `describe(Model $tenant)` | `array{key, name}` | One tenant as the frontend receives it |
| `availableTo(?Authenticatable $user, Panel $panel)` | `list<Model>` | Every tenant this user may enter |
| `allows(?Authenticatable $user, Model $tenant, Panel $panel)` | `bool` | Whether this user may enter this tenant |
| `for(Model $tenant, callable $callback)` | `mixed` | Runs a callback with a different tenant bound |

### `bind()`

```php
public static function bind(Model $tenant): void
```

Writes the tenant into the request-scoped context under the key
`panel.tenant`.

```php
use PandaPanel\Tenancy\Tenancy;

Tenancy::bind($workspace);
```

Only `PandaPanel\Http\Middleware\ResolveTenant` and tests should call this. A
binding made anywhere else is a scope that took effect halfway through a
request, with everything before it already queried unscoped. Application code
that needs to enter a tenant uses [`for()`](#for) instead, which restores what
was there.

### `current()`

```php
public static function current(): ?Model
```

Null when there is no tenancy, when the request never went through
`ResolveTenant`, or outside a request entirely. Anything in the context that
is not an Eloquent model reads as null.

```php
use PandaPanel\Tenancy\Tenancy;

$workspace = Tenancy::current();

if ($workspace !== null) {
    // ...
}
```

### `require()`

```php
/** @throws \PandaPanel\Exceptions\PanelRegistrationException */
public static function require(): Model
```

`current()`, or a loud failure. This is what
`Resource::applyTenantScope()` calls, and the reason a tenant-scoped resource
with nothing bound raises instead of answering an unscoped query.

```php
use PandaPanel\Tenancy\Tenancy;

$tenant = Tenancy::require();

Invoice::query()->where('workspace_id', $tenant->getKey())->sum('total');
```

### `key()`

```php
public static function key(): int|string|null
```

The current tenant's identifying value, or null when there is no tenancy. What
a `where` clause needs, and the only thing most callers want.

```php
use PandaPanel\Tenancy\Tenancy;

Document::query()->where('workspace_id', Tenancy::key())->count();
```

### `keyOf()`

```php
public static function keyOf(Model $tenant): int|string
```

`PanelTenant::getTenantKey()` when the model implements the contract, and
`getKey()` otherwise — cast to `string` if the primary key is neither `int` nor
`string`.

```php
use PandaPanel\Tenancy\Tenancy;

Tenancy::keyOf($workspace);   // 41, or 'acme' for a tenant that identifies by slug
```

### `nameOf()`

```php
public static function nameOf(Model $tenant): string
```

`PanelTenant::getTenantName()`, then a non-empty `name` attribute, then the
key as a string. It falls through to the key rather than to an empty string on
purpose: a switcher with a blank row is a switcher nobody can use.

```php
use PandaPanel\Tenancy\Tenancy;

Tenancy::nameOf($workspace);   // 'Acme'
```

### `describe()`

```php
/** @return array{key: int|string, name: string} */
public static function describe(Model $tenant): array
```

```php
use PandaPanel\Tenancy\Tenancy;

Tenancy::describe($workspace);   // ['key' => 41, 'name' => 'Acme']
```

`SharePanelData` adds `url` and `current` to this shape before it reaches Vue —
see [What the frontend receives](#what-the-frontend-receives).

### `availableTo()`

```php
/** @return list<Model> */
public static function availableTo(?Authenticatable $user, Panel $panel): array
```

The switcher's list, from `HasPanelTenants::getPanelTenants()`. A user model
that does not implement the contract belongs to nothing as far as the panel is
concerned, and the answer is `[]`.

```php
use PandaPanel\Tenancy\Tenancy;

$tenants = Tenancy::availableTo($request->user(), panel('app'));
```

### `allows()`

```php
public static function allows(?Authenticatable $user, Model $tenant, Panel $panel): bool
```

`HasPanelTenants::canAccessPanelTenant()`, asked directly rather than by
searching `availableTo()` — the list is built for a dropdown and may be
paginated, sorted or trimmed, and a security answer must not change when a
display decision does. False for any user model that does not implement the
contract, and for a null user.

```php
use PandaPanel\Tenancy\Tenancy;

abort_unless(Tenancy::allows($request->user(), $workspace, panel('app')), 403);
```

### `for()`

```php
/**
 * @template TReturn
 *
 * @param  callable(): TReturn  $callback
 * @return TReturn
 */
public static function for(Model $tenant, callable $callback): mixed
```

Binds, runs, and restores the previous binding in a `finally` — including when
the callback throws, which is the whole point. For work that legitimately
crosses the boundary: a console command looping over tenants, a job re-entering
the one it was queued from, a test asserting that two tenants see different
rows.

```php
use PandaPanel\Tenancy\Tenancy;

foreach (Workspace::query()->cursor() as $workspace) {
    Tenancy::for($workspace, function () use ($workspace): void {
        $this->info($workspace->name.': '.DocumentResource::query()->count());
    });
}
```

When nothing was bound to begin with, nothing is bound afterwards.

## `PandaPanel\Contracts\PanelTenant`

Optional, on the tenant model. Two methods and no more: everything a tenant
*is* belongs to the application, and the panel only needs the two things it can
never guess.

```php
interface PanelTenant
{
    public function getTenantKey(): int|string;

    public function getTenantName(): string;
}
```

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Contracts\PanelTenant;

final class Workspace extends Model implements PanelTenant
{
    public function getTenantKey(): int|string
    {
        return (string) $this->slug;   // routed as /app?workspace=acme
    }

    public function getTenantName(): string
    {
        return (string) $this->name;
    }
}
```

Without it, `Tenancy::keyOf()` falls back to the primary key and
`Tenancy::nameOf()` to a `name` attribute. See
[`PanelTenant`](../tenancy/panel-tenant.md).

## `PandaPanel\Contracts\HasPanelTenants`

Required on the user model of any panel that declared `tenant()`. Two
questions, and the difference between them is the difference between what to
*offer* and what to *allow*.

```php
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Core\Panel;

interface HasPanelTenants
{
    /** @return Collection<int, Model> */
    public function getPanelTenants(Panel $panel): Collection;

    public function canAccessPanelTenant(Model $tenant, Panel $panel): bool;
}
```

| Method | Called by | When |
| --- | --- | --- |
| `getPanelTenants()` | `Tenancy::availableTo()` | Building the switcher's list |
| `canAccessPanelTenant()` | `Tenancy::allows()`, from `ResolveTenant` | Every request, before anything is queried |

The `Panel` argument is there so one user model can answer differently per
panel. A user model without this interface makes every request to a
tenant-scoped panel a 403 — the correct failure, and a loud one. See
[`HasPanelTenants`](../tenancy/has-panel-tenants.md).

## `PandaPanel\Core\Panel`

| Method | Signature |
| --- | --- |
| `tenant()` | `tenant(string $model, Closure $resolver): self` |
| `tenantUrlUsing()` | `tenantUrlUsing(Closure $url): self` |
| `getTenantUrl()` | `getTenantUrl(Model $tenant): ?string` |
| `hasTenancy()` | `hasTenancy(): bool` |
| `getTenantModel()` | `getTenantModel(): ?string` |
| `resolveTenant()` | `resolveTenant(Request $request, ?Authenticatable $user): ?Model` |

### `tenant()`

```php
/**
 * @param  class-string<Model>  $model
 * @param  Closure(Request, ?Authenticatable): ?Model  $resolver
 */
public function tenant(string $model, Closure $resolver): self
```

Declares the panel tenant-scoped. Four things follow, and nothing else:
`ResolveTenant` is appended to every route group the panel registers,
`Tenancy::current()` answers for the rest of the request, a resource that names
a `tenantRelationship()` is scoped automatically, and the switcher's list is
shared with the frontend.

The resolver receives the request and the authenticated user and returns a
model or null. It is required rather than defaulted because every plausible
default is right for one arrangement and a silent data leak in another:

```php
use App\Models\Team;
use App\Models\Tenant;
use App\Models\Workspace;
use Illuminate\Http\Request;

// Database per tenant: stancl/tenancy has already switched the connection.
->tenant(Tenant::class, static fn () => tenant())

// One database, tenants on a path segment.
->tenant(Team::class, static fn (Request $request) => Team::query()
    ->where('slug', $request->route('team'))
    ->first())

// One tenant per user, nothing in the URL at all.
->tenant(Workspace::class, static fn ($request, $user) => $user?->workspace)
```

### `tenantUrlUsing()`

```php
/** @param  Closure(Model, self): string  $url */
public function tenantUrlUsing(Closure $url): self
```

Where a given tenant lives. The other half of the resolver, and the
application's for the same reason: only the resolver's author can reverse it.

```php
use PandaPanel\Core\Panel;

->tenantUrlUsing(static fn (Team $team): string => "https://{$team->slug}.example.com/app")

->tenantUrlUsing(static fn (Team $team, Panel $panel): string => "/{$panel->getPath()}/{$team->slug}")
```

Without one the switcher does not render, because a switcher whose entries went
nowhere is worse than no switcher. See [Tenant URLs](../tenancy/urls.md).

### `getTenantUrl()`

```php
public function getTenantUrl(Model $tenant): ?string
```

Null when the panel never called `tenantUrlUsing()`.

```php
panel('app')->getTenantUrl($workspace);   // '/app/documents?workspace=41'
```

### `hasTenancy()`, `getTenantModel()`

```php
public function hasTenancy(): bool

/** @return class-string<Model>|null */
public function getTenantModel(): ?string
```

```php
$panel = panel('app');

$panel->hasTenancy();      // true
$panel->getTenantModel();  // 'App\Models\Workspace'
```

`hasTenancy()` is what the route registrar checks before adding
`ResolveTenant`, and what `Resource::applyTenantScope()` checks first.

### `resolveTenant()`

```php
public function resolveTenant(Request $request, ?Authenticatable $user): ?Model
```

Runs the panel's resolver. Called by `ResolveTenant` and by nothing else. A
resolver that returns something other than the declared model is treated as no
tenant — a mistyped resolver returning the *user* would otherwise scope every
query by a user id and look, at a glance, like it worked.

```php
$tenant = panel('app')->resolveTenant(request(), request()->user());
```

## `PandaPanel\Resources\Resource`

```php
/** The relationship leading to the tenant, or null for a resource tenancy does not apply to. */
protected static ?string $tenantRelationship = null;

public static function tenantRelationship(): ?string

/**
 * @param  Builder<covariant Model>  $query
 * @return Builder<covariant Model>
 */
protected static function applyTenantScope(Builder $query): Builder
```

`Resource::query()` calls `applyTenantScope()` on every read — list, view,
edit, delete, bulk, action lookup and global search alike — so naming the
relationship is the whole of the opt-in.

```php
<?php

declare(strict_types=1);

namespace App\Panels\App\Resources\Documents;

use App\Models\Document;
use PandaPanel\Resources\Resource;

final class DocumentResource extends Resource
{
    protected static string $model = Document::class;

    protected static ?string $tenantRelationship = 'workspace';
}
```

Override `tenantRelationship()` instead of the property when the answer is
computed:

```php
public static function tenantRelationship(): ?string
{
    return config('app.single_database') ? 'workspace' : null;
}
```

Three conditions are checked, in the order they fail in practice:

| Condition | When it is false |
| --- | --- |
| `panel()?->hasTenancy()` | Query returned untouched |
| `static::tenantRelationship() !== null` | Query returned untouched |
| A tenant is bound | `PanelRegistrationException` from `Tenancy::require()` |

The third is a throw rather than a skip because a resource that declared itself
tenant-scoped and then ran unscoped would return every tenant's records and
look like a working page.

The scope is built with `whereHas`, so `belongsTo`, `belongsToMany` and
`hasOneThrough` all work and the relationship's own definition decides what
"belongs to this tenant" means:

```php
$query->whereHas('workspace', fn (Builder $related) => $related->whereKey($tenant->getKey()));
```

A resource that names no relationship is not scoped. That is correct for the
two cases that actually occur: a database-per-tenant arrangement where the
connection is already the boundary, and a genuinely global table — a plan, a
country, a feature flag — that every tenant reads. See
[Resource Tenant Scoping](../tenancy/resource-scoping.md).

## `PandaPanel\Http\Middleware\ResolveTenant`

```php
/** @param  Closure(Request): Response  $next */
public function handle(Request $request, Closure $next, string $panelId): Response
```

Registered by `PandaPanel\Routing\PanelRouteRegistrar` for any panel that
declared `tenant()`, last in the panel's middleware list — after the user is
known, before any controller can query. A panel without tenancy never gets it.

Three things happen, and all three must succeed:

1. `Panel::resolveTenant()` identifies the tenant. Null is `abort(404, 'No such tenant.')`.
2. `Tenancy::allows()` checks the user. False is `abort(403)` — deliberately not a 404.
3. `Tenancy::bind()` binds it, once.

There is **no middleware alias** for it. The four registered aliases are
`panel`, `panel.two-factor`, `panel.email-code` and `panel.parent`. A route
registered by hand outside the panel's own group must name the class:

```php
use Illuminate\Support\Facades\Route;
use PandaPanel\Http\Middleware\ResolveTenant;
use PandaPanel\Http\Middleware\ResolvePanel;

Route::middleware([
    'web',
    'auth',
    ResolvePanel::class.':app',
    ResolveTenant::class.':app',
])->get('/app/report', ReportController::class);
```

## What the frontend receives

`PandaPanel\Http\Middleware\SharePanelData` shares a `tenancy` prop on every
panel response. It is a closure, so a screen that never draws a switcher never
runs the query behind it.

```ts
type PanelTenant = {
    key: number | string
    name: string
    url: string | null
    current: boolean
}

type Tenancy = {
    current: PanelTenant | null
    available: PanelTenant[]
} | null
```

Null — rather than an empty shape — for a panel with no tenancy, so the
frontend's check is `tenancy === null`. `available` is filtered by
`Tenancy::availableTo()`, which is what stops the switcher offering a
destination that answers 403.

```php
$this->get('/app/documents?workspace=1')
    ->assertInertia(fn (AssertableInertia $page) => $page
        ->where('tenancy.current.name', 'Acme')
        ->where('tenancy.available.0.current', true));
```

See [Tenant Switcher](../tenancy/switcher.md).

## Exceptions

All three are `PandaPanel\Exceptions\PanelRegistrationException`.

| Factory | Raised by | Meaning |
| --- | --- | --- |
| `noCurrentTenant()` | `Tenancy::require()` | A tenant-scoped read with nothing bound |
| `unknownTenantRelationship(string $resource, string $model, string $relation)` | `Resource::applyTenantScope()` | `$tenantRelationship` names a method the model does not have |
| `tenantRelationshipIsNotARelation(string $resource, string $model, string $relation)` | `Resource::applyTenantScope()` | The method exists but does not return a `Relation` |

```php
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Tenancy\Tenancy;

expect(fn () => DocumentResource::query()->get())
    ->toThrow(PanelRegistrationException::class);

expect(Tenancy::for($workspace, fn () => DocumentResource::query()->count()))
    ->toBe(2);
```

The second and third exist because `whereHas` on a non-relationship fails with
"Call to a member function getRelated() on null" — an error about Eloquent's
internals that names neither the resource nor the property that pointed at it.

## Gotchas

- **The scope matches on the primary key, not `getTenantKey()`.**
  `applyTenantScope()` builds `whereKey($tenant->getKey())`. `keyOf()`,
  `describe()` and the switcher go through `PanelTenant::getTenantKey()`. A
  tenant that identifies by slug therefore appears as `acme` in the URL and the
  switcher while the scope still joins on the numeric key — which is what you
  want, and worth knowing before you debug a query log.
- **`Tenancy::bind()` is not the application's entry point.** Use `for()`, which
  restores the previous binding even when the callback throws. A bare `bind()`
  in a loop leaves the last tenant bound for the rest of the process.
- **Nothing is bound inside a queued job.** `PanelContext` is a `scoped()`
  binding and the queue worker calls `forgetScopedInstances()` between jobs.
  Carry the tenant key in the job payload and re-enter with `for()` — see
  [Queues and Tenant Context](../tenancy/queues.md).
- **A resolver returning the wrong class is a 404, not an error.**
  `resolveTenant()` requires `$tenant instanceof $model`, so a resolver that
  returns a `User` where a `Workspace` was declared resolves to null.
- **404 and 403 mean different things here.** 404 is a tenant that could not be
  identified; 403 is one the user may not enter. Hiding which tenants exist from
  somebody who already had to name one buys nothing and costs a comprehensible
  error.
- **Tenancy is a property of the panel, not of the resource.** The same resource
  class is scoped inside a tenant panel and whole inside an admin panel, because
  `applyTenantScope()` asks the current panel first.
- **Panels are registered at boot, tenants resolved per request.** A panel
  cannot be registered per tenant. Express per-tenant differences with
  `Resource::canViewAny()` and `Panel::canAccess()`.

## See also

- [Tenancy Concepts](../tenancy/concepts.md)
- [Tenant Resolver](../tenancy/resolver.md)
- [Resource Tenant Scoping](../tenancy/resource-scoping.md)
- [Tenant Switcher](../tenancy/switcher.md)
- [Tenancy Security Checklist](../tenancy/security-checklist.md)
- [Using with stancl/tenancy](../tenancy/stancl-tenancy.md)
- [Core Classes](core.md)
- [Contracts](contracts.md)
- [Resources](resources.md)
- [Exceptions](exceptions.md)
- [Panel Context](../concepts/panel-context.md)
