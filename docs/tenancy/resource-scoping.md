# Resource Tenant Scoping

A resource in a tenant-scoped panel names the relationship that leads to the
tenant, and every read of that resource is narrowed to the bound tenant — the
list, the record lookup, actions, bulk actions, global search and exports
alike. Naming the relationship is the whole opt-in; a resource that names
nothing is left exactly as it was.

## Scoping a resource

```php
<?php

declare(strict_types=1);

namespace App\Panels\App\Resources\Documents;

use App\Models\Document;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\Resource;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

final class DocumentResource extends Resource
{
    protected static string $model = Document::class;

    /** The relationship on Document that leads to the tenant. */
    protected static ?string $tenantRelationship = 'workspace';

    public static function table(TableSchema $table): TableSchema
    {
        return $table->columns([TextColumn::make('title')]);
    }

    public static function form(FormSchema $schema): FormSchema
    {
        return $schema->schema([TextInput::make('title')->required()]);
    }

    /** @return array<string, class-string> */
    public static function pages(): array
    {
        return ['index' => ListDocuments::class];
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
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

```php
use PandaPanel\Tenancy\Tenancy;

Tenancy::for($acme, fn () => DocumentResource::query()->pluck('title')->all());
// ['Acme plan', 'Acme notes']

Tenancy::for($beta, fn () => DocumentResource::query()->pluck('title')->all());
// ['Beta secrets']
```

## The API

| Member | Signature | Default |
| --- | --- | --- |
| `$tenantRelationship` | `protected static ?string $tenantRelationship` | `null` |
| `tenantRelationship()` | `public static function tenantRelationship(): ?string` | returns `static::$tenantRelationship` |
| `applyTenantScope()` | `protected static function applyTenantScope(Builder $query): Builder` | called by `query()` |

Override the method when the relationship depends on something a property
cannot say:

```php
public static function tenantRelationship(): ?string
{
    return panel()?->getId() === 'app' ? 'workspace' : null;
}
```

## How the scope is applied

`Resource::query()` is the single funnel:

```php
public static function query(): Builder
{
    $query = static::isNested()
        ? static::parentRelation()->getQuery()->with(static::$with)
        : static::getModel()::query()->with(static::$with);

    $query = static::applyTenantScope($query);

    return static::configurationIn(panel())?->applyQuery($query) ?? $query;
}
```

Everything in the framework goes through it — `ListRecords`, `findRecord()`,
`findRecords()`, the action endpoints, `GlobalSearch`, `TableQuery`, exports.
Proving the scope there proves it everywhere, which is why the framework has
one place to scope rather than a hook on each page.

The scope itself:

```php
protected static function applyTenantScope(Builder $query): Builder
{
    $panel = panel();

    if ($panel === null || ! $panel->hasTenancy()) {
        return $query;
    }

    $relationship = static::tenantRelationship();

    if ($relationship === null) {
        return $query;
    }

    // ... validation of the relationship, below

    $tenant = Tenancy::require();

    return $query->whereHas(
        $relationship,
        static fn (Builder $related): Builder => $related->whereKey($tenant->getKey()),
    );
}
```

Three conditions, all required, checked in the order they fail in practice:

| Condition | Not met | Result |
| --- | --- | --- |
| The panel has tenancy | no current panel, or `hasTenancy()` false | query returned untouched |
| The resource names a relationship | `tenantRelationship()` is `null` | query returned untouched |
| A tenant is bound | nothing bound | `PanelRegistrationException` |

The third is a **throw** rather than a skip, and that is the important decision
here. A resource that declared itself tenant-scoped and then ran unscoped
because nothing was bound would return every tenant's records — the exact
failure the mechanism exists to prevent, and one that looks like a working
page.

## Which relationships work

The scope is built with `whereHas`, so it is the relationship's own definition
that decides what "belongs to this tenant" means, rather than a column name the
framework guessed.

| Relation | Works | Typical shape |
| --- | --- | --- |
| `belongsTo` | yes | `Document` → `Workspace` |
| `belongsToMany` | yes | `Document` shared across workspaces through a pivot |
| `hasOneThrough` | yes | `Comment` → `Post` → `Workspace` |
| `hasMany`, `morphTo`, others | anything Eloquent can `whereHas` | |

```php
/** @return HasOneThrough<Workspace, Post, $this> */
public function workspace(): HasOneThrough
{
    return $this->hasOneThrough(Workspace::class, Post::class, 'id', 'id', 'post_id', 'workspace_id');
}
```

The related query is narrowed with `whereKey($tenant->getKey())` — the tenant
model's **primary key**, not `PanelTenant::getTenantKey()`. A tenant that
identifies by slug in its URLs is still joined on its id.

## When not to name one

A `null` relationship is not an oversight. It is right for the two cases that
actually occur:

- **Database per tenant.** The connection is the boundary; there is no column
  to scope by, and a `whereHas` would be looking for a table that is not there.
  See [Database Per Tenant](database-per-tenant.md).
- **A genuinely global table** — a plan, a country, a feature flag — that every
  tenant reads the same way.

```php
final class WorkspaceResource extends Resource
{
    protected static string $model = Workspace::class;

    // No $tenantRelationship: the tenant list itself is not per tenant.
}
```

```php
Tenancy::for($acme, fn () => WorkspaceResource::query()->pluck('name')->all());
// ['Acme', 'Beta']
```

## Scoping by hand

When the relationship is not the whole story — a soft "shared with everyone"
flag, a column instead of a relation — override `query()` and call
`parent::query()`, or the panel's own narrowing is silently dropped:

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

`Tenancy::key()` returns `getTenantKey()` when the tenant model implements
`PanelTenant`. If that is a slug and your foreign key is an id, use
`Tenancy::require()->getKey()` instead.

## What is not scoped for you

**Writes.** `applyTenantScope()` narrows reads. Nothing writes `workspace_id`
on create — the framework calls `FormSchema::dehydrate()` and then saves, and a
tenant id that can be submitted is a tenant id that can be changed. Set it
where the request cannot reach:

```php
<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Document;
use PandaPanel\Tenancy\Tenancy;

final class DocumentObserver
{
    public function creating(Document $document): void
    {
        $document->workspace_id ??= Tenancy::require()->getKey();
    }
}
```

Because every read is scoped, a record created without an owner is invisible
immediately — which is a bug that shows up in the first test rather than in
production.

**Relation managers and nested resources.** Their queries start from the parent
record's relation, and the parent was itself looked up through a scoped
`query()`. The tenant scope on the child resource still applies on top when the
child names a relationship.

**Select options and other form lookups.** A `Select` reading options from a
model reads that model, not a resource. Scope it in the field's own query.

## Errors

Two mistakes are caught with a message that names the resource, the model and
the method, instead of failing inside Eloquent:

```php
protected static ?string $tenantRelationship = 'nothing_like_this';
```

> `[DocumentResource]` scopes to the tenant through `[nothing_like_this]`,
> which `[App\Models\Document]` does not have. Name a relationship that exists,
> or override `query()` and scope it yourself.

```php
protected static ?string $tenantRelationship = 'getTable';
```

> `[DocumentResource]` scopes by the tenant relationship `[getTable]`, and
> `[App\Models\Document::getTable()]` exists but does not return an Eloquent
> relationship. A scope or an accessor cannot be traversed to a tenant — name a
> `belongsTo`, `belongsToMany` or `hasOneThrough`, or override `query()` and
> scope it yourself.

Both are `PandaPanel\Exceptions\PanelRegistrationException`. The second check
exists because `method_exists()` passing is not the same as a relationship: a
scope or an accessor gets past it and then fails inside `whereHas` as "Call to
a member function getRelated() on null", which names neither the resource nor
the property that pointed at it.

The third failure is the important one:

```php
DocumentResource::query()->get();   // outside a request, nothing bound
```

> This panel is tenant-scoped, but no tenant is bound to this request. Routes
> registered by the panel resolve one through `ResolveTenant`; a route
> registered by hand has to include that middleware, and console or queue work
> has to enter a tenant with `Tenancy::for()`.

## Testing it

```php
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Tenancy\Tenancy;

it('shows one tenant\'s records and not the other\'s', function (): void {
    $acme = Tenancy::for($this->acme, fn (): array => DocumentResource::query()
        ->pluck('title')
        ->all());

    $beta = Tenancy::for($this->beta, fn (): array => DocumentResource::query()
        ->pluck('title')
        ->all());

    expect($acme)->toBe(['Acme plan', 'Acme notes'])
        ->and($beta)->toBe(['Beta secrets']);
});

it('raises rather than running unscoped when no tenant is bound', function (): void {
    expect(fn () => DocumentResource::query()->get())
        ->toThrow(PanelRegistrationException::class);
});

it('scopes nothing in a panel that declared no tenancy', function (): void {
    app(PanelManager::class)->setCurrentPanel(null);

    expect(DocumentResource::query()->count())->toBe(3);
});
```

## Notes

- **Tenancy is a property of the panel.** The same resource class registered in
  a tenant panel and in a central admin panel is scoped in the first and whole
  in the second, with no change to the class.
- **The scope is a `whereHas` subquery.** Index the foreign key. On a large
  table with a `belongsToMany` tenant relationship, the exists subquery is the
  first thing to look at when the list is slow.
- **`findRecord()` lifts `SoftDeletingScope` and nothing else.** The tenant
  scope still applies to a trashed record, which is why a restore action cannot
  reach another tenant's row.
- **A record outside the tenant is a 404, not a filtered row.** The lookup goes
  through the same query, so a hand-typed id from another tenant does
  not resolve.
- **`parent::query()` is not optional in an override.** Skipping it drops the
  tenant scope, the panel's per-panel narrowing and the eager loads at once.
- **Nothing scopes by `tenant_id` automatically.** There is no convention over
  the column name and no global scope registered by the framework; the
  relationship you name is the entire mechanism.

## See also

- [Tenancy Concepts](concepts.md)
- [Single Database Tenancy](single-database.md)
- [Database Per Tenant](database-per-tenant.md)
- [Queues and Tenant Context](queues.md)
- [Tenancy Security Checklist](security-checklist.md)
- [Resource Queries](../resources/queries.md)
- [Nested Resources](../resources/nested-resources.md)
- [Global Search](../resources/global-search.md)
- [Action Scopes](../actions/scopes.md)
