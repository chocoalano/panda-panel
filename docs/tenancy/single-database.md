# Single Database Tenancy

One database, every tenant's rows in the same tables, a foreign key saying
which tenant each row belongs to. It is the cheaper arrangement to run and the
one where a missing `where` is a data leak — which is exactly the failure
`Resource::$tenantRelationship` exists to prevent. Reach for it when tenants
are small and numerous, when you need to query across tenants, or when
provisioning a database per signup is not something you want to operate.

## A complete example

Three tables: the tenant, the records, and the membership that says who may
enter which tenant.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->timestamps();

            // The scope is a whereHas on this column. Index it.
            $table->index('workspace_id');
        });

        Schema::create('workspace_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id']);
        });
    }
};
```

### The tenant model

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Contracts\PanelTenant;

final class Workspace extends Model implements PanelTenant
{
    protected $fillable = ['name', 'slug'];

    public function getTenantKey(): int|string
    {
        return (int) $this->getKey();
    }

    public function getTenantName(): string
    {
        return (string) $this->getAttribute('name');
    }
}
```

### The user model

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
        return $this->workspaces()->orderBy('name')->get();
    }

    public function canAccessPanelTenant(Model $tenant, Panel $panel): bool
    {
        return $this->workspaces()->whereKey($tenant->getKey())->exists();
    }
}
```

### The record model

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Document extends Model
{
    protected $fillable = ['title'];

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
```

`workspace_id` is deliberately **not** fillable. A tenant id that can be
submitted is a tenant id that can be changed.

### The panel

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
            ->path('app/{workspace}')
            ->auth()
            ->tenant(
                Workspace::class,
                static fn (Request $request): ?Workspace => Workspace::query()
                    ->where('slug', $request->route('workspace'))
                    ->first(),
            )
            ->tenantUrlUsing(
                static fn (Workspace $workspace): string => "/app/{$workspace->slug}",
            );
    }
}
```

### The resource

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

    // table(), form(), pages() as usual
}
```

That is the whole arrangement. `GET /app/acme/documents` lists Acme's
documents, `GET /app/beta/documents` lists Beta's, and a user who belongs to
neither gets a 403.

## Ownership on create

The scope narrows reads. Nothing writes `workspace_id`, and it must not come
from the form. An observer is the right place, because it runs for every write
path — the create page, a seeder, an import, a console command:

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

```php
use App\Models\Document;
use App\Observers\DocumentObserver;

Document::observe(DocumentObserver::class);
```

`Tenancy::require()` rather than `Tenancy::current()`: a write with no tenant
bound is a bug, and a row with a null owner is invisible to every scoped read
afterwards — a record that vanishes rather than an error.

Seeding or back-filling outside a request goes through `Tenancy::for()`:

```php
Tenancy::for($workspace, static function () use ($rows): void {
    foreach ($rows as $row) {
        Document::query()->create($row);
    }
});
```

## Making the tenant part of every URL

With the tenant on a path segment, `Resource::url()` needs the route parameter
filled. Laravel's `URL::defaults()` does it, set from a middleware in the
panel's own stack:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

final class DefaultWorkspaceUrlParameter
{
    public function handle(Request $request, Closure $next): Response
    {
        $workspace = $request->route('workspace');

        if (is_string($workspace)) {
            URL::defaults(['workspace' => $workspace]);
        }

        return $next($request);
    }
}
```

```php
$panel->middleware(['web', DefaultWorkspaceUrlParameter::class]);
```

`middleware()` replaces the base stack, so `web` has to be listed. See
[Tenant URLs](urls.md).

## Tables that belong to nobody

A plan, a country, a feature flag: every tenant reads the same rows. Name no
relationship and the resource is not scoped.

```php
final class PlanResource extends Resource
{
    protected static string $model = Plan::class;

    // No $tenantRelationship.
}
```

## Deeper relations

A record two hops from the tenant scopes through a relationship that spans both
hops, because the scope is `whereHas` on whatever you name:

```php
/** @return HasOneThrough<Workspace, Document, $this> */
public function workspace(): HasOneThrough
{
    return $this->hasOneThrough(
        Workspace::class,
        Document::class,
        'id',            // documents.id
        'id',            // workspaces.id
        'document_id',   // comments.document_id
        'workspace_id',  // documents.workspace_id
    );
}
```

A denormalised `workspace_id` on the child plus a plain `belongsTo` is usually
faster and always simpler to index. Both are correct; pick per table.

## Everything else that reads records

None of these need any tenancy code of their own, because all of them start
from `Resource::query()`:

| Feature | Scoped because |
| --- | --- |
| The list, filters, search, sorting | `TableQuery` constrains `query()` |
| Record pages, edit, delete | `findRecord()` narrows `query()` |
| Row, bulk and table actions | the action endpoint resolves records through `findRecord()`/`findRecords()` |
| Global search | `GlobalSearch` starts from `query()` |
| Exports | `RunPanelExport` rebuilds through `query()` — but see [Queues](queues.md) |
| Relation managers, nested resources | the parent was itself found through a scoped query |

What is *not* covered automatically: a `Select` field pulling options straight
from a model, a widget running its own query, a controller of your own. Those
read models, not resources. Scope them with `Tenancy::key()` or
`Tenancy::require()`.

```php
use PandaPanel\Forms\Components\Select;
use PandaPanel\Tenancy\Tenancy;

Select::make('document_id')
    ->options(fn (): array => Document::query()
        ->where('workspace_id', Tenancy::require()->getKey())
        ->pluck('title', 'id')
        ->all());
```

## Notes

- **Every unscoped query is a leak waiting to be found.** That is the cost of
  this arrangement, and the reason a scoped resource with no tenant bound
  throws rather than running.
- **`Tenancy::key()` returns `getTenantKey()`.** With the model above that is
  the primary key, so it matches `workspace_id`. If `getTenantKey()` returns a
  slug, compare against `Tenancy::require()->getKey()` instead.
- **Unique constraints are per database, not per tenant.** An email unique
  across all tenants is usually wrong here; scope the index
  (`unique(['workspace_id', 'email'])`).
- **Deleting a tenant deletes rows in shared tables.** Cascades are your
  design, and a `cascadeOnDelete()` on a large table is a long transaction.
- **The framework's own tests use this arrangement**
  (`tests/Feature/Panel/TenancyTest.php`), because with a connection per tenant
  there is nothing in the panel left to get wrong.
- **A user in no workspace gets a 403 on every panel page.** Decide where they
  land — an onboarding page on a central panel is the usual answer.

## See also

- [Tenancy Concepts](concepts.md)
- [Resource Tenant Scoping](resource-scoping.md)
- [Database Per Tenant](database-per-tenant.md)
- [Tenant URLs](urls.md)
- [Queues and Tenant Context](queues.md)
- [Tenancy Security Checklist](security-checklist.md)
- [Resource Queries](../resources/queries.md)
- [Tenant Panel Recipe](../recipes/tenant-panel.md)
