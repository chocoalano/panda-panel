# Tenant Panel

A workspace panel at `/app/{workspace}`, where every record a user sees belongs to the workspace named in the URL and nothing else does. This page builds it from nothing: the tables, the tenant model, the two contracts, the panel provider, a scoped resource, the switcher, the middleware that keeps the tenant in every link, and the tests that prove two tenants see two different sets of rows.

What the framework supplies is one thing: a stable, tested answer to "which tenant is this request for", so a resource can scope to it without every project re-deriving the answer inside an overridden `query()`. It does **not** create databases, switch connections, partition a cache, or decide what a subdomain means — `stancl/tenancy` does all four and does them well. The two fit together; see [Using with stancl/tenancy](../tenancy/stancl-tenancy.md).

This recipe is single-database, because that is the arrangement with something to get wrong. With a connection per tenant the boundary is the connection and there is nothing here to scope.

## A minimal working example

```php
use App\Models\Workspace;
use Illuminate\Http\Request;

return $panel
    ->path('app/{workspace}')
    ->auth()
    ->tenant(
        Workspace::class,
        static fn (Request $request): ?Workspace => Workspace::query()
            ->where('slug', $request->route('workspace'))
            ->first(),
    );
```

```php
final class DocumentResource extends Resource
{
    protected static string $model = Document::class;

    /** The relationship on Document that leads to the tenant. */
    protected static ?string $tenantRelationship = 'workspace';
}
```

Plus `HasPanelTenants` on the user model. That is tenancy on: the middleware resolves and authorizes a workspace on every request into the panel, and every read of that resource is narrowed to it.

## The tables

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
            $table->text('body')->nullable();
            $table->timestamps();

            // The scope is a whereHas on this column. Index it.
            $table->index('workspace_id');
        });

        // Who may enter which workspace.
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

## The tenant model

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Contracts\PanelTenant;

final class Workspace extends Model implements PanelTenant
{
    protected $fillable = ['name', 'slug'];

    /**
     * The value this tenant is identified by. The slug, because that is what
     * the URL carries — `Tenancy` looks tenants up by whatever this returns.
     */
    public function getTenantKey(): int|string
    {
        return (string) $this->getAttribute('slug');
    }

    public function getTenantName(): string
    {
        return (string) $this->getAttribute('name');
    }
}
```

`PandaPanel\Contracts\PanelTenant` has exactly two methods and will not grow. Everything a tenant *is* — a team, an organisation, a customer account, a database — is the application's; a framework that asked for a `plan` or a `logo` would be describing one project's tenant rather than the idea of one.

It is optional. Without it, `Tenancy` falls back to the primary key and a `name` attribute:

```php
Tenancy::keyOf(Model $tenant): int|string     // the contract, then getKey()
Tenancy::nameOf(Model $tenant): string        // the contract, then `name`, then the key
Tenancy::describe(Model $tenant): array       // ['key' => …, 'name' => …]
```

Falling through to the key rather than to an empty string is deliberate: a switcher with a blank row is a switcher nobody can use, and `41` at least identifies which one it is.

## The user model

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
    /**
     * @return BelongsToMany<Workspace, $this>
     */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class);
    }

    /**
     * The switcher's list, and the pool a default is chosen from.
     *
     * @return Collection<int, Model>
     */
    public function getPanelTenants(Panel $panel): Collection
    {
        /** @var Collection<int, Model> $workspaces */
        $workspaces = $this->workspaces()->orderBy('name')->get();

        return $workspaces;
    }

    /**
     * Asked on every request, before anything is queried.
     *
     * An independent query, never `getPanelTenants()->contains(...)`: that
     * list is built for a dropdown and may be sorted, trimmed, or paginated,
     * and a security answer must not change when a display decision does.
     */
    public function canAccessPanelTenant(Model $tenant, Panel $panel): bool
    {
        return $this->workspaces()->whereKey($tenant->getKey())->exists();
    }
}
```

A user model **without** this contract belongs to nothing as far as a tenant-scoped panel is concerned, and every request into the panel is refused. That is the correct failure and a loud one: a tenant panel that fell open for a user model that had not been updated would be the worst possible default.

`canAccessPanelTenant()` runs on every request in the panel. Keep it to one indexed `exists()`.

## The panel provider

```php
<?php

declare(strict_types=1);

namespace App\Panels\App;

use App\Http\Middleware\DefaultWorkspaceUrlParameter;
use App\Models\Workspace;
use Illuminate\Http\Request;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelProvider;

final class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            // The path is the route group's prefix verbatim, so `{workspace}`
            // is an ordinary route parameter on every route the panel
            // registers.
            ->path('app/{workspace}')
            ->name('Workspace')
            ->auth()
            // `middleware()` REPLACES the base stack, so `web` is listed.
            // This runs before ResolvePanel and ResolveTenant, which is why it
            // reads the route parameter rather than Tenancy::current().
            ->middleware(['web', DefaultWorkspaceUrlParameter::class])
            ->tenant(
                Workspace::class,
                static fn (Request $request): ?Workspace => Workspace::query()
                    ->where('slug', $request->route('workspace'))
                    ->first(),
            )
            ->tenantUrlUsing(
                static fn (Workspace $workspace, Panel $panel): string
                    => '/app/'.$workspace->slug,
            )
            ->discoverResources(app_path('Panels/App/Resources'))
            ->discoverPages(app_path('Panels/App/Pages'))
            ->discoverWidgets(app_path('Panels/App/Widgets'));
    }
}
```

### `tenant()`

```php
/**
 * @param  class-string<Model>  $model
 * @param  Closure(Request, ?Authenticatable): ?Model  $resolver
 */
public function tenant(string $model, Closure $resolver): self

public function hasTenancy(): bool
public function getTenantModel(): ?string
public function resolveTenant(Request $request, ?Authenticatable $user): ?Model
```

Two arguments, and declaring them is what turns tenancy on — nothing else does. Calling it has four effects:

- Every route group the panel registers gets `PandaPanel\Http\Middleware\ResolveTenant`.
- `PandaPanel\Tenancy\Tenancy::current()` answers for the rest of the request.
- A resource naming a `tenantRelationship()` is scoped automatically; one that does not is left exactly as it was.
- The switcher's list is shared with the frontend.

The resolver returns a model or `null`. Anything that is not an instance of the declared model is treated as no tenant — which is the guard that stops a resolver returning the *user* from scoping every query by a user id and looking, at a glance, like it worked.

Three shapes, all valid, depending on how tenants are addressed:

```php
// Path segment — this recipe.
->tenant(Workspace::class, fn (Request $request) => Workspace::query()
    ->where('slug', $request->route('workspace'))->first())

// Database per tenant, identified by subdomain: stancl has already switched
// the connection by the time this runs, so the resolver reads it back.
->tenant(Tenant::class, fn () => tenant())

// One tenant per user, nothing in the URL at all.
->tenant(Workspace::class, fn ($request, $user) => $user?->workspace)
```

### `tenantUrlUsing()`

```php
/** @param  Closure(Model, self): string  $url */
public function tenantUrlUsing(Closure $url): self

public function getTenantUrl(Model $tenant): ?string
```

The other half of the resolver: it turns a tenant back into a request, so the switcher has somewhere to send people. Only the resolver's author knows how a tenant is addressed, so only they can reverse it.

Without a builder, `getTenantUrl()` answers `null` for every tenant and the switcher does not render. The panel still resolves, authorizes, and scopes perfectly; it offers no way to move between tenants.

For a subdomain scheme the URL must be **absolute**, because the switcher is navigating to another origin and renders a plain `<a>` for exactly that reason.

## Keeping the tenant in every link

`Resource::url()` and `Page::url()` build every link the panel renders, and both go through Laravel's route names. The parameters they pass hold the record and, for a nested resource, the parent — never a tenant. The framework does not know the name of your route parameter, and adding one would be guessing.

Laravel's own `URL::defaults()` closes that:

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

With that in the panel's stack:

```php
DocumentResource::url();                     // '/app/acme/documents'
DocumentResource::url('edit', $document);    // '/app/acme/documents/12/edit'
```

A **query-parameter** scheme has no equivalent — `URL::defaults()` only fills route parameters — so links inside the panel lose the tenant. It resolves and it switches, and it is the right choice only for a test harness.

## The scoped resource

```php
<?php

declare(strict_types=1);

namespace App\Panels\App\Resources\Documents;

use App\Models\Document;
use App\Panels\App\Resources\Documents\Pages\CreateDocument;
use App\Panels\App\Resources\Documents\Pages\EditDocument;
use App\Panels\App\Resources\Documents\Pages\ListDocuments;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Components\Textarea;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\Resource;
use PandaPanel\Tables\Columns\DateTimeColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

final class DocumentResource extends Resource
{
    protected static string $model = Document::class;

    protected static ?string $slug = 'documents';

    protected static ?string $navigationIcon = 'file-text';

    /**
     * The relationship on Document that leads to the tenant. Naming it is
     * the whole opt-in; a resource that names nothing is left unscoped.
     */
    protected static ?string $tenantRelationship = 'workspace';

    public static function table(TableSchema $table): TableSchema
    {
        return $table->columns([
            TextColumn::make('title')->searchable()->sortable(),
            DateTimeColumn::make('updated_at')->label('Changed')->relative()->sortable(),
        ]);
    }

    public static function form(FormSchema $schema): FormSchema
    {
        // No workspace_id field. The record is written through the panel's
        // own create page, and the owning workspace is not the user's to
        // choose.
        return $schema->schema([
            TextInput::make('title')->required()->maxLength(255),
            Textarea::make('body')->rows(10),
        ]);
    }

    /**
     * @return array<string, class-string>
     */
    public static function pages(): array
    {
        return [
            'index' => ListDocuments::class,
            'create' => CreateDocument::class,
            'edit' => EditDocument::class,
        ];
    }
}
```

```php
// app/Models/Document.php

/**
 * @return BelongsTo<Workspace, $this>
 */
public function workspace(): BelongsTo
{
    return $this->belongsTo(Workspace::class);
}
```

### Setting the tenant on create

Scoping narrows reads. A new record still needs its foreign key, and the form must not be where it comes from:

```php
use PandaPanel\Resources\Pages\CreateRecord;
use PandaPanel\Tenancy\Tenancy;

final class CreateDocument extends CreateRecord
{
    protected static string $resource = DocumentResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // require(), not current(): a create that ran with no tenant bound
        // would write a row belonging to nobody.
        $data['workspace_id'] = Tenancy::require()->getKey();

        return $data;
    }
}
```

Keep `workspace_id` out of the model's `$fillable` as well. Two locks: the schema discards a key it never declared, and mass assignment refuses it even if something else passes it through.

## How the scope is applied

`Resource::query()` is the single funnel — list, record lookup, actions, bulk actions, global search, exports all go through it.

```php
protected static function applyTenantScope(Builder $query): Builder
{
    // 1. the panel has tenancy, 2. the resource names a relationship,
    // 3. a tenant is bound.
    $tenant = Tenancy::require();

    return $query->whereHas(
        $relationship,
        static fn (Builder $related): Builder => $related->whereKey($tenant->getKey()),
    );
}
```

The third condition is a **throw**, not a skip, and that is the important decision. A resource that declared itself tenant-scoped and then ran unscoped because nothing was bound would return every tenant's records and look like a working page. `Tenancy::require()` makes it a `PandaPanel\Exceptions\PanelRegistrationException` instead.

Two more failures are caught by name rather than by Eloquent's internals:

- A relationship the model does not have → `PanelRegistrationException::unknownTenantRelationship()`, naming the resource, the model, and the method.
- A method that exists but is not a relation — a scope, an accessor, a helper → `PanelRegistrationException::tenantRelationshipIsNotARelation()`. Without that check it fails inside `whereHas` with "Call to a member function getRelated() on null", which names neither.

A resource that names **nothing** is deliberate rather than forgotten: a plan table every tenant reads the same way, a country list, or every resource in a database-per-tenant arrangement where the connection is already the boundary. Write down which and why.

Override the method when the answer depends on something a property cannot say:

```php
public static function tenantRelationship(): ?string
{
    return panel()?->getId() === 'app' ? 'workspace' : null;
}
```

Tenancy is a property of the **panel**, so the same resource class is scoped in a tenant panel and whole in an admin one.

## Entering a tenant outside a request

```php
/**
 * @template TReturn
 *
 * @param  callable(): TReturn  $callback
 * @return TReturn
 */
public static function for(Model $tenant, callable $callback): mixed
```

For work that legitimately crosses the boundary: a console command looping over tenants, a job re-entering the one it was queued from, a test asserting that two tenants see different rows.

```php
use PandaPanel\Tenancy\Tenancy;

foreach (Workspace::query()->cursor() as $workspace) {
    Tenancy::for($workspace, static function () use ($workspace): void {
        $this->info($workspace->name.': '.DocumentResource::query()->count());
    });
}
```

The previous binding is restored in a `finally`, so a callback that throws does not leave the rest of the process scoped to somebody else's tenant.

The rest of the API:

```php
Tenancy::bind(Model $tenant): void          // only ResolveTenant and tests should call this
Tenancy::current(): ?Model
Tenancy::require(): Model                   // throws rather than running unscoped
Tenancy::key(): int|string|null
Tenancy::keyOf(Model $tenant): int|string
Tenancy::nameOf(Model $tenant): string
Tenancy::describe(Model $tenant): array
Tenancy::availableTo(?Authenticatable $user, Panel $panel): array
Tenancy::allows(?Authenticatable $user, Model $tenant, Panel $panel): bool
```

The tenant lives in `PandaPanel\Support\PanelContext` — a `scoped()` container binding — rather than in a static, so it lasts exactly as long as the request does and cannot leak between requests, between tests, or between two requests inside one Octane worker.

### Queued jobs

The binding does not travel with a job: Laravel's queue worker calls `forgetScopedInstances()` between jobs, so every job starts with nothing bound.

```php
final class RebuildWorkspaceIndex implements ShouldQueue
{
    use Queueable;

    // The key, not the model. A serialized model reloads on the far side
    // through whatever connection is current.
    public function __construct(private readonly int $workspaceKey) {}

    public function handle(): void
    {
        $workspace = Workspace::query()->findOrFail($this->workspaceKey);

        Tenancy::for($workspace, static function (): void {
            DocumentResource::query()->each(/* … */);
        });
    }
}
```

## The switcher

There is nothing to register. `PandaPanel\Http\Middleware\SharePanelData` shares the `tenancy` prop and the shell renders it when all three of these are true:

| Condition | Where it is decided |
| --- | --- |
| The panel declared tenancy | `Panel::tenant()` — otherwise `tenancy` is `null` |
| The user may enter more than one tenant | `HasPanelTenants::getPanelTenants()` returns two or more |
| At least one entry has a URL | `Panel::tenantUrlUsing()` was called |

A user who belongs to exactly one tenant sees nothing, because there is nowhere to switch to.

The prop is a closure, so a panel screen that never draws a switcher never runs the query behind it. For a panel with no tenancy it is `null` rather than an empty shape, so the frontend's check is `tenancy === null` and a switcher never renders where there is nothing to switch between.

```php
[
    'current' => ['key' => 'acme', 'name' => 'Acme'],
    'available' => [
        ['key' => 'acme', 'name' => 'Acme', 'url' => '/app/acme', 'current' => true],
        ['key' => 'beta', 'name' => 'Beta', 'url' => '/app/beta', 'current' => false],
    ],
]
```

The list is built from `Tenancy::availableTo()`, which is the same list the per-request check reads — so the switcher never offers a tenant that would answer 403.

## What the middleware does

`ResolveTenant` is appended last in the panel's stack: after the user is known, before any controller can query.

```php
$tenant = $panel->resolveTenant($request, $user);

abort_if($tenant === null, 404, 'No such tenant.');
abort_unless(Tenancy::allows($user, $tenant, $panel), 403);

Tenancy::bind($tenant);
```

| Case | Answer | Why |
| --- | --- | --- |
| The resolver found nothing | **404** | the request named something that does not exist |
| The user does not belong to it | **403** | hiding which tenants exist from somebody who already named one is theatre that costs a comprehensible error |
| The user model does not implement `HasPanelTenants` | **403** | it belongs to nothing |
| The panel declared no tenancy | pass through | the middleware is not even registered |

## The test

```php
<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use App\Panels\App\Resources\Documents\DocumentResource;
use Inertia\Testing\AssertableInertia;
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Tenancy\Tenancy;

beforeEach(function (): void {
    $this->acme = Workspace::query()->create(['name' => 'Acme', 'slug' => 'acme']);
    $this->beta = Workspace::query()->create(['name' => 'Beta', 'slug' => 'beta']);

    Document::query()->create(['workspace_id' => $this->acme->id, 'title' => 'Acme plan']);
    Document::query()->create(['workspace_id' => $this->acme->id, 'title' => 'Acme notes']);
    Document::query()->create(['workspace_id' => $this->beta->id, 'title' => 'Beta secrets']);

    $this->user = User::factory()->create();
    $this->user->workspaces()->attach($this->acme);

    $this->actingAs($this->user);
});

it('shows one tenant\'s records and not the other\'s', function (): void {
    $this->user->workspaces()->attach($this->beta);

    // Every read goes through query(), so proving it there proves the list,
    // the record lookup, the actions, and global search at once.
    $acme = Tenancy::for($this->acme, fn (): array => DocumentResource::query()->pluck('title')->all());
    $beta = Tenancy::for($this->beta, fn (): array => DocumentResource::query()->pluck('title')->all());

    expect($acme)->toBe(['Acme plan', 'Acme notes'])
        ->and($beta)->toBe(['Beta secrets']);
});

it('refuses a tenant this user does not belong to', function (): void {
    $this->get('/app/beta/documents')->assertForbidden();
    $this->get('/app/acme/documents')->assertOk();
});

it('answers 404 for a tenant that is not there', function (): void {
    $this->get('/app/nothing-like-this/documents')->assertNotFound();
});

it('refuses a user model that does not know about tenants at all', function (): void {
    // HasPanelTenants is what makes a tenant-scoped panel answerable. Without
    // it a user belongs to nothing, which is a refusal rather than a panel
    // that falls open.
    $this->actingAs(new class extends User {});

    $this->get('/app/acme/documents')->assertForbidden();
});

it('raises rather than running unscoped when no tenant is bound', function (): void {
    // The failure the whole mechanism exists to prevent. A scoped resource
    // that ran without a tenant would return every tenant's records and look
    // like a working page.
    expect(fn () => DocumentResource::query()->get())
        ->toThrow(PanelRegistrationException::class);
});

it('restores the previous tenant even when the callback throws', function (): void {
    Tenancy::bind($this->acme);

    try {
        Tenancy::for($this->beta, static fn () => throw new RuntimeException('nope'));
    } catch (RuntimeException) {
        // Expected. What matters is what is bound afterwards.
    }

    expect(Tenancy::current()?->getKey())->toBe($this->acme->getKey());
});

it('shares the current tenant and the ones this user could switch to', function (): void {
    $this->user->workspaces()->attach($this->beta);

    $this->get('/app/acme/documents')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tenancy.current.name', 'Acme')
            ->where('tenancy.available.0.name', 'Acme')
            ->where('tenancy.available.0.current', true)
            ->where('tenancy.available.1.url', '/app/beta'));
});

it('keeps the tenant in the URLs the panel builds', function (): void {
    $this->get('/app/acme/documents')->assertOk();

    // URL::defaults(), set by the panel's own middleware from the route
    // parameter. Without it every link inside the panel loses the tenant.
    expect(DocumentResource::url())->toBe('/app/acme/documents');
});
```

```bash
php artisan test --compact --filter=Tenan
php artisan route:list --path=app      # ResolveTenant must be on every row
```

`tests/Feature/Panel/TenancyTest.php` is the framework's own version of these, against `Workspace` / `Document` / `TenantUser` fixtures using a query-parameter resolver — because what is being tested is what happens *after* identification, and identification itself is the application's.

## Gotchas

- **A resource that names no relationship is silently unscoped.** This is the opt-in, and a resource that forgets looks exactly like one that has nothing to scope. Keep the list of exemptions written down.
- **A `query()` override that skips `parent::query()`** drops the tenant scope along with the eager loads and the per-panel narrowing, and the page still renders.
- **`middleware()` replaces the base stack.** Adding `DefaultWorkspaceUrlParameter` without listing `web` drops sessions and CSRF.
- **A central panel needs `domain()` under a subdomain scheme.** Without it, `admin.example.test` is identified as a tenant called `admin`.
- **The tenant does not survive a queued job.** Carry the key and re-enter with `Tenancy::for()`.
- **`Tenancy::bind()` is not for application code.** A binding made outside `ResolveTenant` is a scope that took effect halfway through a request, with everything before it already queried unscoped.
- **`canAccessPanelTenant()` runs on every request.** One indexed `exists()`; anything heavier is a query on every page load.
- **A user who belongs to no tenant gets 403 on every panel page.** Decide where they go instead — an invitation screen, a create-workspace flow — and put it outside the panel.
- **Under a subdomain scheme, sessions are per host by default.** `SESSION_DOMAIN=.example.test` shares them; leaving it is a hard boundary between tenants. Decide now rather than later.
- **Tenancy is not authorization.** A scope decides which rows exist; a policy decides what may be done to them. Keep both.

## See also

- [Tenancy Concepts](../tenancy/concepts.md)
- [Tenant Resolver](../tenancy/resolver.md), [Tenant URLs](../tenancy/urls.md)
- [The PanelTenant Contract](../tenancy/panel-tenant.md), [HasPanelTenants](../tenancy/has-panel-tenants.md)
- [Resource Tenant Scoping](../tenancy/resource-scoping.md)
- [Single Database Tenancy](../tenancy/single-database.md), [Database per Tenant](../tenancy/database-per-tenant.md)
- [Using with stancl/tenancy](../tenancy/stancl-tenancy.md)
- [Tenant Switcher](../tenancy/switcher.md)
- [Queues and Tenant Context](../tenancy/queues.md)
- [Tenancy Security Checklist](../tenancy/security-checklist.md)
- [Testing Tenancy](../testing/tenancy.md)
- [Tenancy Scope Leaks](../troubleshooting/tenancy-scope-leaks.md)
- [Locking a Panel Down](security.md), [App Panel Example](app-panel.md)
