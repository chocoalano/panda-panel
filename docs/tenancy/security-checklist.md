# Tenancy Security Checklist

Everything on this list is a failure that looks like a working page. A tenant
scope that silently did not apply returns rows, renders a table and answers
200; nothing about the screen says the `where` was missing. Work through this
before a tenant panel goes live, and again whenever a resource, a route or a
job is added to one.

## The five-minute check

```bash
# 1. The tenant middleware is actually on the routes.
php artisan route:list --path=app

# 2. Nothing in the panel reads records outside Resource::query().
grep -rn "::query()" app/Panels/App --include=*.php

# 3. Every model a tenant owns has an owner column that is not fillable.
grep -rn "fillable" app/Models --include=*.php
```

```php
use PandaPanel\Tenancy\Tenancy;

// 4. Two tenants see two different sets of rows.
expect(Tenancy::for($acme, fn () => DocumentResource::query()->pluck('id')->all()))
    ->not->toEqual(Tenancy::for($beta, fn () => DocumentResource::query()->pluck('id')->all()));
```

## Routing and resolution

- [ ] **The panel declares `tenant()`.** Without it `ResolveTenant` is never
      registered, no tenant is bound, and every resource that names a
      relationship throws instead of running unscoped — which is the safe
      failure, but it means the panel does not work at all.
- [ ] **`route:list` shows `ResolveTenant` on the panel's routes.** The
      registrar appends it last, after `ResolvePanel`, `RequireTwoFactor` and
      `RequireEmailCode`.
- [ ] **Every hand-registered route that reads a resource carries it.** There
      is no middleware alias; name the class and pass the panel id:
      `ResolveTenant::class.':'.$panel->getId()`.
- [ ] **Tenancy identification middleware runs before `ResolvePanel`.** With
      `stancl/tenancy`, put it in `Panel::middleware()` — which replaces the
      base stack, so `web` must be listed too.
- [ ] **A central panel is pinned with `Panel::domain()`.** Without it,
      `admin.example.test` is identified as a tenant called `admin`.
- [ ] **The resolver returns the declared model or null.** Anything else is
      treated as no tenant. A resolver that returned the *user* would scope
      every query by a user id and look, at a glance, like it worked.

## Membership

- [ ] **The user model implements `PandaPanel\Contracts\HasPanelTenants`.** A
      model without it is refused every tenant — correct, and worth confirming
      is not what is happening in production.
- [ ] **`canAccessPanelTenant()` is an independent query, not
      `getPanelTenants()->contains(...)`.** The list is built for a dropdown
      and may be sorted, trimmed or paginated; a security answer must not
      change when a display decision does.
- [ ] **`canAccessPanelTenant()` is cheap.** It runs on every request in the
      panel — one indexed `exists()`.
- [ ] **A user who belongs to no tenant lands somewhere.** Every panel page is
      a 403 for them; decide where they go instead.

## Resources

- [ ] **Every resource whose model a tenant owns names
      `$tenantRelationship`** — in a single-database arrangement. This is the
      opt-in, and a resource that forgets looks exactly like one that has
      nothing to scope.
- [ ] **Every `query()` override calls `parent::query()`.** Skipping it drops
      the tenant scope, the panel's per-panel narrowing and the eager loads at
      once.
- [ ] **The relationship named is a relationship.** A scope or an accessor
      passes `method_exists()` and is caught by the second check, with a
      message naming the resource, the model and the method.
- [ ] **Resources that name nothing are deliberate.** A tenant list, a plan
      table, a country list, or every resource in a database-per-tenant
      arrangement. Write down which and why.
- [ ] **Policies are still in place.** Tenancy is not authorization. A scope
      says which rows exist for this request; a policy says who may do what
      with them.

## Writes

- [ ] **The tenant id is not a form field.** A tenant id that can be submitted
      is a tenant id that can be changed.
- [ ] **The tenant id is not `$fillable`.** Mass assignment from an import, a
      seeder or an action's data array reaches the same column.
- [ ] **Ownership is written server-side** — a `creating` observer, a global
      scope, or `BelongsToTenant` from `stancl/tenancy`.
- [ ] **`Tenancy::require()`, not `Tenancy::current()`, on a write.** A row
      created with a null owner is invisible to every scoped read afterwards —
      a record that vanishes rather than an error.

```php
use PandaPanel\Tenancy\Tenancy;

public function creating(Document $document): void
{
    $document->workspace_id ??= Tenancy::require()->getKey();
}
```

## Queries outside resources

`Resource::query()` covers the list, record lookups, actions, bulk actions,
global search and exports. It covers nothing that does not go through it.

- [ ] **Select and other option lookups.** A field reading options from a model
      reads the model, not the resource.
- [ ] **Widgets.** A stats or chart widget running its own aggregate.
- [ ] **Your own controllers, endpoints and Blade views.**
- [ ] **Relation manager tables that reach past the parent.** The parent was
      found through a scoped query; a query that starts somewhere else was not.

```php
use PandaPanel\Tenancy\Tenancy;

Document::query()->where('workspace_id', Tenancy::require()->getKey());
```

## Console, queues and Octane

- [ ] **Console and queue work binds both the panel and the tenant.**
      `PanelManager::setCurrentPanel()` and `Tenancy::for()`, in that order.
      With no panel set, `applyTenantScope()` returns early and the query runs
      unscoped.
- [ ] **`Tenancy::for()`, never a bare `Tenancy::bind()`, in a worker.** A
      binding with no restore leaks into the next job on that process.
- [ ] **Jobs carry a tenant key, not a serialized tenant model.** A serialized
      model reloads through whatever connection is current on the far side.
- [ ] **Queued exports and imports are accounted for.** `RunPanelExport` and
      `RunPanelImport` carry a panel id and no tenant; in a single-database
      arrangement a scoped resource makes them throw. Return `-1` from
      `queueAfter()` to keep them in the request, or dispatch your own job. See
      [Queues and Tenant Context](queues.md).
- [ ] **Nothing tenant-related is held in a static.** `Tenancy` keeps the
      tenant in `PanelContext`, which is a `scoped()` binding, for exactly this
      reason — a static would survive between requests under Octane and
      between jobs in a worker.

## Shared infrastructure, database per tenant

User ids are only unique *within* a tenant, and several things in the framework
are keyed by one.

- [ ] **`CacheTenancyBootstrapper` is installed.**
      `PandaPanel\Auth\EmailCodeChallenge` stores the emailed second-factor
      code at `panel.mfa.email.code.{user id}`. Without cache separation, one
      tenant's code satisfies another tenant's challenge.
- [ ] **`FilesystemTenancyBootstrapper` is installed.** Exports and import
      failure reports are filed at
      `{exporter::directory()}/{user id}/{file}` and served back from the same
      path. User 1 in two tenants shares a directory unless the disk root is
      partitioned.
- [ ] **The export disk is private.** A public disk puts a copy of records at a
      URL that can be guessed, and an export is exactly the kind of file worth
      guessing at.
- [ ] **Central models declare `$connection`.** A tenant list read from inside
      a tenant context otherwise queries the tenant's own database.

## Sessions and the browser

- [ ] **`SESSION_DOMAIN` is a decision, not a default.** Left unset, a user
      signed in on `acme.example.test` is not signed in on `beta.example.test`
      — a hard boundary. Set to `.example.test`, one session spans every
      tenant.
- [ ] **Session-persisted table state is shared when the session is.** The key
      is `panel.{panel}.table.{resource}` and carries no tenant, so a
      remembered filter follows a user across tenants. Not a leak — every value
      is re-validated against the schema and the query is still scoped — but
      surprising enough to disable `persist*InSession()` on a shared-session
      tenant panel.
- [ ] **The switcher only offers what the user may enter.** It is built from
      `getPanelTenants()`; if that is broader than `canAccessPanelTenant()`,
      the switcher offers destinations that answer 403.

## Error semantics

Confirm these are what you actually see, because a difference means something
in the chain is not running:

| Situation | Expected |
| --- | --- |
| Tenant not found by the resolver | `404 No such tenant.` |
| Tenant found, user not a member | `403` |
| User model without `HasPanelTenants` | `403` |
| Scoped resource, no tenant bound | `PanelRegistrationException` (500) |
| Record key from another tenant | `404` |
| `$tenantRelationship` naming nothing | `PanelRegistrationException` (500) |

A 403 rather than a 404 for a non-member is deliberate: hiding *which* tenants
exist from somebody who already had to name one is security theatre that costs
a comprehensible error message.

## Tests worth having

```php
it('refuses a tenant this user does not belong to', function (): void {
    $this->actingAs($ada)
        ->get('/app/documents?workspace='.$beta->getKey())
        ->assertForbidden();
});

it('answers 404 for a tenant that is not there', function (): void {
    $this->get('/app/documents?workspace=999999')->assertNotFound();
});

it('refuses a user model that does not know about tenants at all', function (): void {
    $this->actingAs(User::factory()->create());   // no HasPanelTenants

    $this->get('/app/documents?workspace='.$acme->getKey())->assertForbidden();
});

it('raises rather than running unscoped when no tenant is bound', function (): void {
    expect(fn () => DocumentResource::query()->get())
        ->toThrow(PanelRegistrationException::class);
});

it('cannot reach another tenant\'s record by key', function (): void {
    $this->get('/app/documents/'.$betaDocument->getKey().'?workspace='.$acme->getKey())
        ->assertNotFound();
});

it('offers no tenant this user does not belong to', function (): void {
    $this->get('/app/documents?workspace='.$acme->getKey())
        ->assertInertia(fn (AssertableInertia $page) => $page->has('tenancy.available', 1));
});
```

The framework's own suite carries the first four in
`tests/Feature/Panel/TenancyTest.php`. Copy the shape, not the fixtures.

## Notes

- **The most common real failure is a resource that names no relationship**,
  in a single-database panel, where the author assumed a global scope existed.
  There is none. The relationship you name is the entire mechanism.
- **The second most common is a query that never touched a resource** — a
  widget, a select, an endpoint of your own.
- **The third is queued work**, because it fails in a worker log rather than on
  a screen.
- **Tenancy is not authorization, and neither replaces the other.** Run the
  panel with policies in place and confirm both refuse independently; a fixture
  policy that also said no would make it impossible to tell a scope that worked
  from a gate that said no.

## See also

- [Tenancy Concepts](concepts.md)
- [Resource Tenant Scoping](resource-scoping.md)
- [`HasPanelTenants`](has-panel-tenants.md)
- [Single Database Tenancy](single-database.md)
- [Database Per Tenant](database-per-tenant.md)
- [Queues and Tenant Context](queues.md)
- [Authorization](../concepts/authorization.md)
- [Negative Security Tests](../testing/negative-security-tests.md)
- [Testing Tenancy](../testing/tenancy.md)
- [Tenancy Scope Leaks](../troubleshooting/tenancy-scope-leaks.md)
- [Production Checklist](../deployment/production-checklist.md)
