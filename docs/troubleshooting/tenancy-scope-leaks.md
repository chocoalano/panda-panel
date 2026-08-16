# Tenant scope leaks

A query that escaped the tenant returns rows, renders a table and answers 200. Nothing about the
screen says the `where` was missing, which is why this failure needs a page of its own: every other
kind of bug announces itself. Reach for this when one tenant can see another's records, when a
count looks too high, or when `PanelRegistrationException` says no tenant is bound and you need to
know whether that is the bug or the guard.

## Prove it before changing anything

Two tenants, one resource, one comparison. `PandaPanel\Tenancy\Tenancy::for()` binds a tenant,
runs a callback and restores whatever was bound before, so this works from tinker, a test, or a
console command:

```php
use App\Panels\App\Resources\Documents\DocumentResource;
use PandaPanel\Core\PanelManager;
use PandaPanel\Tenancy\Tenancy;

// A resource reads its scope through the current panel, so bind one first.
app(PanelManager::class)->setCurrentPanel(app(PanelManager::class)->get('app'));

Tenancy::for($acme, fn () => DocumentResource::query()->pluck('title')->all());
// ['Acme plan', 'Acme notes']

Tenancy::for($beta, fn () => DocumentResource::query()->pluck('title')->all());
// ['Beta secrets']
```

Two identical lists is the leak. As an assertion:

```php
expect(Tenancy::for($acme, fn () => DocumentResource::query()->pluck('id')->all()))
    ->not->toEqual(Tenancy::for($beta, fn () => DocumentResource::query()->pluck('id')->all()));
```

## The three conditions, and the order they fail in

`Resource::applyTenantScope()` is the whole mechanism, and it checks three things:

```php
protected static function applyTenantScope(Builder $query): Builder
{
    $panel = panel();

    if ($panel === null || ! $panel->hasTenancy()) {
        return $query;                       // 1. no panel, or the panel is not tenant-scoped
    }

    $relationship = static::tenantRelationship();

    if ($relationship === null) {
        return $query;                       // 2. this resource opted out
    }

    // …validation of the relationship…

    $tenant = Tenancy::require();            // 3. throws rather than running unscoped

    return $query->whereHas(
        $relationship,
        static fn (Builder $related): Builder => $related->whereKey($tenant->getKey()),
    );
}
```

| Condition | Not met | Result |
| --- | --- | --- |
| The panel has tenancy | no current panel, or `hasTenancy()` false | **query returned untouched** |
| The resource names a relationship | `tenantRelationship()` is `null` | **query returned untouched** |
| A tenant is bound | nothing bound | `PanelRegistrationException` |

The first two return the query unchanged, which is where every leak comes from. The third throws,
which is where every "it stopped working" comes from — and the throw is the design, not the bug.

## 1. The resource names no `$tenantRelationship`

The most common leak, and the only opt-in there is. Naming the relationship is the entire mechanism;
there is no convention over a `tenant_id` column and no global scope registered anywhere.

```php
use PandaPanel\Resources\Resource;

final class DocumentResource extends Resource
{
    protected static string $model = Document::class;

    /** The relationship on Document that leads to the tenant. */
    protected static ?string $tenantRelationship = 'workspace';
}
```

```php
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Document extends Model
{
    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
```

| Member | Signature | Default |
| --- | --- | --- |
| `$tenantRelationship` | `protected static ?string $tenantRelationship` | `null` |
| `tenantRelationship` | `public static function tenantRelationship(): ?string` | returns the property |
| `applyTenantScope` | `protected static function applyTenantScope(Builder $query): Builder` | called by `query()` |

Override the method when the answer depends on something a property cannot say:

```php
public static function tenantRelationship(): ?string
{
    return panel()?->getId() === 'app' ? 'workspace' : null;
}
```

**Audit every resource in the panel at once**, which is faster than reading them one at a time:

```php
use PandaPanel\Facades\PandaPanel;

collect(PandaPanel::resources('app')->all())
    ->mapWithKeys(fn (string $resource): array => [$resource => $resource::tenantRelationship()])
    ->filter(fn (?string $relation): bool => $relation === null)
    ->keys();
// every resource in the panel that is not scoped — each one had better be deliberate
```

`null` is a legitimate answer in exactly two cases, and both are worth writing a comment about: a
**database-per-tenant** arrangement where the connection is the boundary and there is no column to
scope by, and a **genuinely global table** — a plan, a country, a feature flag — every tenant reads
the same way.

The scope is built with `whereHas`, so the relationship's own definition decides what "belongs to
this tenant" means. `belongsTo`, `belongsToMany` and `hasOneThrough` all work, and the related query
is narrowed with `whereKey($tenant->getKey())` — the tenant model's **primary key**, not
`PanelTenant::getTenantKey()`. A tenant identified by slug in its URLs is still joined on its id.

## 2. `query()` was overridden without `parent::query()`

**Symptom.** One resource leaks and the rest do not.

**Cause.** `Resource::query()` is the single funnel. An override that builds its own builder drops
the tenant scope, the panel's per-panel narrowing and the eager loads at the same time, and nothing
says so:

```php
// Wrong: three things silently gone.
public static function query(): Builder
{
    return Document::query()->where('is_archived', false);
}
```

```php
// Right.
use Illuminate\Database\Eloquent\Builder;

public static function query(): Builder
{
    return parent::query()->where('is_archived', false);
}
```

When the relationship is not the whole story — a "shared with everyone" flag, a column instead of a
relation — build on top rather than replacing:

```php
use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Tenancy\Tenancy;

public static function query(): Builder
{
    return parent::query()
        ->where(static fn (Builder $q) => $q
            ->where('workspace_id', Tenancy::key())
            ->orWhere('is_public', true));
}
```

`Tenancy::key()` returns `getTenantKey()` when the tenant model implements `PanelTenant`. If that is
a slug and your foreign key is an id, use `Tenancy::require()->getKey()` instead.

**Find the overrides:**

```bash
grep -rn "function query" app/Panels --include=*.php
```

## 3. There is no current panel, so nothing scoped at all

This is the quietest failure of the three, because it produces a working query with no exception.
`applyTenantScope()` reads `panel()`, and outside a request there is no panel unless something set
one.

```php
panel();                    // null in a console command, a job, or a test that never bound one
DocumentResource::query();  // unscoped: no panel, so the scope returned early
```

**Fix.** Set the panel *and* the tenant, always, in that order:

```php
use PandaPanel\Core\PanelManager;
use PandaPanel\Tenancy\Tenancy;

public function handle(PanelManager $manager): void
{
    $manager->setCurrentPanel($manager->get('app'));

    Tenancy::for($workspace, static fn () => DocumentResource::query()->count());
}
```

With the panel set and no tenant bound, the query throws. With neither, it runs and returns every
tenant's rows. The loud failure is the one you want, and setting the panel is what turns the silent
failure into it.

| Member | Signature |
| --- | --- |
| `PanelManager::setCurrentPanel` | `setCurrentPanel(?Panel $panel): void` |
| `PanelManager::currentPanel` | `currentPanel(): ?Panel` |
| `Panel::hasTenancy` | `hasTenancy(): bool` |
| `panel()` | `panel(?string $id = null): ?Panel` — the helper `applyTenantScope()` reads |

## 4. Console commands, queued jobs, and schedulers

A queued job runs outside the request that dispatched it and the binding does not travel with it.
`Tenancy` keeps the tenant in `PandaPanel\Support\PanelContext`, which is a `scoped()` container
binding, and Laravel's worker calls `forgetScopedInstances()` between jobs — so every job starts
with nothing bound. That is correct behaviour, and it is why entering a tenant is explicit.

```php
use PandaPanel\Tenancy\Tenancy;

final class RebuildWorkspaceIndex implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $workspaceKey) {}

    public function handle(PanelManager $manager): void
    {
        $manager->setCurrentPanel($manager->get('app'));

        $workspace = Workspace::query()->findOrFail($this->workspaceKey);

        Tenancy::for($workspace, function (): void {
            // Everything in here reads through the bound tenant.
        });
    }
}
```

Carry the **key**, not the model. A serialized model reloads on the far side through whatever
connection is current, which in a database-per-tenant arrangement is not the one it was serialized
from.

Looping every tenant, from a command:

```php
Workspace::query()->each(function (Workspace $workspace): void {
    Tenancy::for($workspace, function () use ($workspace): void {
        $this->line($workspace->name.': '.DocumentResource::query()->count());
    });
});
```

`each()` sits **outside** the loop's `Tenancy::for()` deliberately: the list of tenants is a central
question, and asking it from inside a tenant would cross the boundary the design exists to keep.

### The framework's own jobs bind a panel and not a tenant

| Job | Sets the panel | Binds a tenant |
| --- | --- | --- |
| `PandaPanel\Jobs\RunPanelExport` | yes | **no** |
| `PandaPanel\Jobs\RunPanelImport` | yes | **no** |
| `PandaPanel\Jobs\SendPanelIntegration` | no | no |

On a single-database tenant panel with a scoped resource, a queued export therefore reaches
`Tenancy::require()` with nothing bound and **fails loudly** rather than writing a file containing
every tenant's rows. Three ways out, in order of preference.

**Do not queue it.** A negative `queueAfter()` runs in the request whatever the row count:

```php
final class DocumentExporter extends Exporter
{
    public static function queueAfter(): int
    {
        return -1;
    }
}
```

`Exporter::queueAfter()` defaults to `2000` and `Importer::queueAfter()` to `500`; both queue when
the count exceeds the number, and `0` always queues.

**Dispatch your own job** carrying the tenant key and wrapping the work in `Tenancy::for()`, using
`ExportRun` / `ImportRun` directly.

**Restore the binding from a queue hook you own**, if your infrastructure already carries a tenant
id on every job.

In a **database-per-tenant** arrangement none of this applies the same way: `stancl/tenancy`'s
`QueueTenancyBootstrapper` restores the connection around a job dispatched inside a tenant context,
and the resource names no relationship because the connection is the boundary.

## 5. `Tenancy::require()` threw — is that the bug?

No. It is the guard working.

```text
PandaPanel\Exceptions\PanelRegistrationException

This panel is tenant-scoped, but no tenant is bound to this request. Routes registered by the
panel resolve one through ResolveTenant; a route registered by hand has to include that
middleware, and console or queue work has to enter a tenant with Tenancy::for().
```

A resource that declared itself tenant-scoped and then ran unscoped because nothing was bound would
return every tenant's records — the exact failure this whole mechanism exists to prevent, and one
that looks like a working page. Three causes, in order:

| Cause | Fix |
| --- | --- |
| A route registered by hand, outside the panel's group | add `ResolveTenant::class.':'.$panel->getId()` to it |
| Console or queue work | `Tenancy::for($tenant, …)` |
| A test that never bound one | `Tenancy::for()`, or drive the request through the panel's routes |

There is **no middleware alias** for `ResolveTenant`; name the class and pass the panel id, exactly
as the registrar does.

```bash
php artisan route:list --path=app   # confirm ResolveTenant is on the routes
```

The registrar appends it last, after `ResolvePanel`, `RequireTwoFactor` and `RequireEmailCode`, and
**only for a panel that declared `tenant()`** — so a panel without tenancy pays nothing and a panel
with it cannot forget.

## 6. Two relationship mistakes that are caught by name

```php
protected static ?string $tenantRelationship = 'nothing_like_this';
```

> `[DocumentResource]` scopes to the tenant through `[nothing_like_this]`, which
> `[App\Models\Document]` does not have. Name a relationship that exists, or override `query()` and
> scope it yourself.

```php
protected static ?string $tenantRelationship = 'getTable';
```

> `[DocumentResource]` scopes by the tenant relationship `[getTable]`, and
> `[App\Models\Document::getTable()]` exists but does not return an Eloquent relationship. A scope
> or an accessor cannot be traversed to a tenant — name a `belongsTo`, `belongsToMany` or
> `hasOneThrough`, or override `query()` and scope it yourself.

Both are `PandaPanel\Exceptions\PanelRegistrationException`. The second check exists because
`method_exists()` passing is not the same as a relationship: a scope or an accessor gets past it and
then fails inside `whereHas` as `Call to a member function getRelated() on null`, which names
neither the resource nor the property that pointed at it.

## 7. Reads that never go through `Resource::query()`

The funnel is the guarantee, so anything outside it is unscoped by construction. These are the
places that matter, and each has its own answer:

| Read | Scoped? | What to do |
| --- | --- | --- |
| `Select::options()` | **no** | it takes an array; build that array scoped |
| `Select::relationship()` | **no** | it queries the related model directly — use `options()` when the list must be scoped |
| A widget querying a model directly | **no** | scope it, or read through the resource |
| A custom page's own query | **no** | `parent::query()` on the resource, or `Tenancy::key()` |
| A relation manager | by the owner | the owner was resolved through a scoped `query()`; the child's own relationship still applies on top when it names one |
| A nested resource | by the parent | same reasoning — the parent came through a scoped query |
| Global search | yes | starts from `Resource::query()` |
| Exports and imports in the request | yes | same query; queued runs are section 4 |
| Actions, bulk actions, record lookups | yes | all resolve through `query()` |

`Select::options()` takes an `array<array-key, string>`, and `form()` is called while building the
schema for a request that already has a tenant bound — so the scoping happens where the array is
built:

```php
use PandaPanel\Forms\Components\Select;
use PandaPanel\Tenancy\Tenancy;

Select::make('project_id')->options(
    Project::query()
        ->where('workspace_id', Tenancy::require()->getKey())
        ->pluck('name', 'id')
        ->all(),
);
```

`relationship('project', 'name')` cannot be scoped this way: `Select::resolveOptions()` builds its
query from `$related->newQuery()`, which knows nothing about the tenant. Use `options()` when the
list must be narrowed, or put a global scope on the related model.

## 8. Writes are not scoped either

`applyTenantScope()` narrows **reads**. Nothing writes the owner column on create — the framework
calls `FormSchema::dehydrate()` and then saves, and a tenant id that can be submitted is a tenant id
that can be changed. Set it where the request cannot reach:

```php
use PandaPanel\Tenancy\Tenancy;

final class DocumentObserver
{
    public function creating(Document $document): void
    {
        $document->workspace_id ??= Tenancy::require()->getKey();
    }
}
```

Keep the foreign key out of `$fillable`, for the same reason a privilege flag is kept out of it.
Because every read is scoped, a record created without an owner is invisible immediately — a bug
that shows up in the first test rather than in production.

## 9. The tenant resolved is the wrong one, or none

`ResolveTenant` does three things and all three must succeed before anything is queried:

```php
$tenant = $panel->resolveTenant($request, $user);

abort_if($tenant === null, 404, 'No such tenant.');
abort_unless(Tenancy::allows($user, $tenant, $panel), 403);

Tenancy::bind($tenant);
```

| Status | Meaning |
| --- | --- |
| 404 | The tenant could not be identified — the request named something that does not exist |
| 403 | The tenant exists and this user may not enter it |

A **404 where you expected a tenant** is usually the resolver. `Panel::resolveTenant()` returns
`null` for anything that is not an instance of the declared model, which is deliberate: a mistyped
resolver returning the *user* would otherwise scope every query by a user id and look, at a glance,
like it worked.

```php
$panel->tenant(
    Workspace::class,
    static fn (Request $request, ?Authenticatable $user): ?Workspace => Workspace::query()
        ->find($request->query('workspace')),
);
```

A **403 for a tenant the user is in** is the membership contract. `Tenancy::allows()` asks
`HasPanelTenants::canAccessPanelTenant()` directly on every request — not by searching the
switcher's list, because that list is built for a dropdown and may be sorted, trimmed or paginated,
and a security answer must not change when a display decision does.

A user model that does not implement `HasPanelTenants` belongs to nothing as far as the panel is
concerned, which is what makes a tenant-scoped panel refuse rather than fall open.

## The full `Tenancy` API

```php
use PandaPanel\Tenancy\Tenancy;
```

| Method | Signature | Notes |
| --- | --- | --- |
| `bind` | `static bind(Model $tenant): void` | for `ResolveTenant` and tests only |
| `current` | `static current(): ?Model` | null outside a tenant |
| `require` | `static require(): Model` | throws `PanelRegistrationException` rather than degrading |
| `key` | `static key(): int\|string\|null` | what a `where` clause needs |
| `keyOf` | `static keyOf(Model $tenant): int\|string` | `getTenantKey()`, or the primary key |
| `nameOf` | `static nameOf(Model $tenant): string` | `getTenantName()`, a `name` attribute, then the key |
| `describe` | `static describe(Model $tenant): array{key: int\|string, name: string}` | one tenant, as the frontend receives it |
| `availableTo` | `static availableTo(?Authenticatable $user, Panel $panel): list<Model>` | the switcher's list |
| `allows` | `static allows(?Authenticatable $user, Model $tenant, Panel $panel): bool` | the per-request check |
| `for` | `static for(Model $tenant, callable $callback): mixed` | binds, runs, restores in a `finally` |

`for()` restores the previous binding even when the callback throws — a callback that failed must
not leave the rest of the process scoped to somebody else's tenant:

```php
Tenancy::bind($acme);

try {
    Tenancy::for($beta, fn () => throw new RuntimeException('nope'));
} catch (RuntimeException) {
}

Tenancy::current()?->getKey();   // still Acme
```

Never call `bind()` in a worker without a matching restore: it leaks into the next job on that
process. Use `for()`.

### Panel members

| Member | Signature |
| --- | --- |
| `tenant` | `tenant(string $model, Closure $resolver): self` — `Closure(Request, ?Authenticatable): ?Model` |
| `tenantUrlUsing` | `tenantUrlUsing(Closure $url): self` — `Closure(Model, Panel): string` |
| `getTenantUrl` | `getTenantUrl(Model $tenant): ?string` |
| `hasTenancy` | `hasTenancy(): bool` |
| `getTenantModel` | `getTenantModel(): ?class-string<Model>` |
| `resolveTenant` | `resolveTenant(Request $request, ?Authenticatable $user): ?Model` |

### Contracts

| Contract | Methods |
| --- | --- |
| `PandaPanel\Contracts\PanelTenant` | `getTenantKey(): int\|string`, `getTenantName(): string` |
| `PandaPanel\Contracts\HasPanelTenants` | `getPanelTenants(Panel $panel): Collection`, `canAccessPanelTenant(Model $tenant, Panel $panel): bool` |

## Testing for the leak

The tests worth having are the ones that fail when a scope is dropped, not the ones that assert a
page renders.

```php
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Tenancy\Tenancy;

it('shows one tenant\'s records and not the other\'s', function (): void {
    expect(Tenancy::for($this->acme, fn (): array => DocumentResource::query()->pluck('title')->all()))
        ->toBe(['Acme plan', 'Acme notes'])
        ->and(Tenancy::for($this->beta, fn (): array => DocumentResource::query()->pluck('title')->all()))
        ->toBe(['Beta secrets']);
});

it('raises rather than running unscoped when no tenant is bound', function (): void {
    expect(fn () => DocumentResource::query()->get())
        ->toThrow(PanelRegistrationException::class);
});

it('refuses a tenant this user does not belong to', function (): void {
    $this->get('/app/documents?workspace='.$this->beta->getKey())->assertForbidden();
});

it('answers 404 for a tenant that is not there', function (): void {
    $this->get('/app/documents?workspace=999999')->assertNotFound();
});

it('scopes nothing in a panel that declared no tenancy', function (): void {
    app(PanelManager::class)->setCurrentPanel(null);

    expect(DocumentResource::query()->count())->toBe(3);
});
```

The last one is not a leak: tenancy is a property of the **panel**, so the same resource class
registered in a tenant panel and in a central admin panel is scoped in the first and whole in the
second, with no change to the class. Write it down as a test so the day it stops being deliberate is
the day the suite says so.

## Notes

- **Nothing scopes by `tenant_id` automatically.** There is no convention over a column name and no
  global scope. The relationship you name is the entire mechanism.
- **`parent::query()` is not optional in an override.** Skipping it drops three things at once and
  reports none of them.
- **A record outside the tenant is a 404, not a filtered row.** The lookup goes through the same
  query, so a hand-typed id from another tenant does not resolve — which is also why a leak is
  invisible from the record page.
- **`findRecord()` lifts `SoftDeletingScope` and nothing else.** The tenant scope still applies to a
  trashed record, so a restore action cannot reach another tenant's row.
- **The scope is a `whereHas` subquery.** Index the foreign key. On a large table with a
  `belongsToMany` tenant relationship, the exists subquery is the first thing to look at when the
  list is slow.
- **A central panel on the same domain as tenants needs `Panel::domain()`.** Without it,
  `admin.example.test` is identified as a tenant called `admin`.
- **The tenant is not held in a static.** `PanelContext` is a `scoped()` binding and
  `ResetPanelContext` runs at the start of every `web` request, so nothing leaks between requests,
  between tests, or between two requests inside one Octane worker.
- **The switcher only offers tenants the user may enter**, because it is built from the same
  membership answer the per-request check reads — so it never offers a 403.
- **A panel with tenancy and no `tenantUrlUsing()` renders no switcher.** Entries that went nowhere
  would be worse than no switcher.

## See also

- [Tenancy concepts](../tenancy/concepts.md), [resource scoping](../tenancy/resource-scoping.md),
  [the tenant resolver](../tenancy/resolver.md)
- [Single-database tenancy](../tenancy/single-database.md),
  [database per tenant](../tenancy/database-per-tenant.md),
  [with stancl/tenancy](../tenancy/stancl-tenancy.md)
- [Queues and tenant context](../tenancy/queues.md),
  [tenancy security checklist](../tenancy/security-checklist.md)
- [The `PanelTenant` contract](../tenancy/panel-tenant.md),
  [`HasPanelTenants`](../tenancy/has-panel-tenants.md),
  [tenant URLs](../tenancy/urls.md), [the switcher](../tenancy/switcher.md)
- [Resource queries](../resources/queries.md), [nested resources](../resources/nested-resources.md),
  [per-panel configuration](../resources/per-panel-configuration.md)
- [Queued exports](../import-export/queued-exports.md),
  [queued imports](../import-export/queued-imports.md)
- [Testing tenancy](../testing/tenancy.md),
  [negative security tests](../testing/negative-security-tests.md)
- [Tenancy API reference](../api/tenancy.md)
- [403 responses](authorization-403.md), [panel routes that 404](panel-routes-404.md)
