# Testing Tenancy

A tenant-scoped resource asked outside a tenant **raises** — that is the design, not an inconvenience — so a tenancy test always begins by entering one explicitly. The assertion worth writing is the negative one: prove tenant A cannot see tenant B's rows. A test that only checks A sees A's rows passes against a completely unscoped query.

## A minimal working example

```php
<?php

declare(strict_types=1);

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
```

`DocumentResource::query()` is the resource's own query, which every read goes through — the list, the record lookup, the actions, and global search. Proving it there proves all of them at once.

## What is being tested

The framework's part of multi-tenancy, and only that part: which tenant this request is for, whether this user may be in it, and what a scoped resource may see. It does not create databases, switch connections or read subdomains. Three things have to line up:

| Piece | Declared by | Failure it causes when missing |
| --- | --- | --- |
| The tenant model and resolver | `Panel::tenant(Workspace::class, fn (Request $r) => …)` | the panel is not tenant-scoped and `ResolveTenant` never runs |
| The relationship to the tenant | `protected static ?string $tenantRelationship = 'workspace';` on the resource | the resource is left unscoped |
| The user's memberships | `HasPanelTenants` on the user model | the user belongs to nothing and the panel refuses them |

A single-database arrangement is the one worth testing. With a connection per tenant the boundary is the connection and there is nothing here to get wrong; with one database a missing `where` is a data leak.

## Entering a tenant

`PandaPanel\Tenancy\Tenancy` is the whole public surface. Everything is static, and the tenant is held in `PanelContext` rather than in a static property, so it lives exactly as long as the request does and cannot leak between tests.

| Method | Signature | Returns |
| --- | --- | --- |
| `bind` | `static bind(Model $tenant): void` | — binds for this request |
| `current` | `static current(): ?Model` | the bound tenant, or null |
| `require` | `static require(): Model` | the tenant, or throws `PanelRegistrationException` |
| `key` | `static key(): int\|string\|null` | the current tenant's key |
| `keyOf` | `static keyOf(Model $tenant): int\|string` | `getTenantKey()`, else the primary key |
| `nameOf` | `static nameOf(Model $tenant): string` | `getTenantName()`, else a `name` attribute, else the key |
| `describe` | `static describe(Model $tenant): array` | `['key' => …, 'name' => …]` |
| `availableTo` | `static availableTo(?Authenticatable $user, Panel $panel): array` | `list<Model>` — what the switcher offers |
| `allows` | `static allows(?Authenticatable $user, Model $tenant, Panel $panel): bool` | whether this user may enter |
| `for` | `static for(Model $tenant, callable $callback): mixed` | the callback's return, with the previous tenant restored |

### `Tenancy::for()`

The one a test almost always wants. It binds, runs, and restores in a `finally`:

```php
use PandaPanel\Tenancy\Tenancy;

$titles = Tenancy::for($acme, fn (): array => DocumentResource::query()->pluck('title')->all());
```

The `finally` is the point, and is worth asserting once in a suite that leans on it:

```php
it('restores the previous tenant even when the callback throws', function (): void {
    Tenancy::bind($this->acme);

    try {
        Tenancy::for($this->beta, function (): void {
            throw new RuntimeException('nope');
        });
    } catch (RuntimeException) {
        // Expected. What matters is what is bound afterwards.
    }

    expect(Tenancy::current()?->getKey())->toBe($this->acme->getKey());
});

it('leaves nothing bound when it started with nothing', function (): void {
    Tenancy::for($this->acme, fn () => null);

    expect(Tenancy::current())->toBeNull();
});
```

### `Tenancy::bind()` and `current()`

For a test that needs a tenant bound across several statements rather than inside one callback:

```php
Tenancy::bind($this->acme);

expect(Tenancy::current()?->getKey())->toBe($this->acme->getKey())
    ->and(Tenancy::key())->toBe($this->acme->getKey());
```

Outside a test, only `ResolveTenant` should call `bind()`: a binding made halfway through a request is a scope that took effect after everything before it had already queried unscoped.

### `describe()`, `keyOf()`, `nameOf()`

What the frontend receives, and the two fallbacks behind it:

```php
// A tenant implementing PanelTenant answers for itself.
expect(Tenancy::describe($acme))->toBe(['key' => 1, 'name' => 'Acme']);

// One that does not falls back to the primary key and a `name` attribute,
// and to the key as a string when there is no name.
expect(Tenancy::nameOf($unnamed))->toBe('Acme');
```

### `availableTo()` and `allows()`

The switcher's list and the per-request check. They are separate methods on purpose — the list is built for a dropdown and may be sorted or trimmed, and a security answer must not change when a display decision does. Test both:

```php
expect(Tenancy::availableTo($user, $panel))->toHaveCount(1)
    ->and(Tenancy::allows($user, $acme, $panel))->toBeTrue()
    ->and(Tenancy::allows($user, $beta, $panel))->toBeFalse();
```

A user model that does not implement `HasPanelTenants` gets an empty list and `false`, which is what makes a tenant-scoped panel refuse rather than fall open.

## The refusals

Three different answers, and they mean three different things. All three are worth their own test.

```php
// Bound to a tenant this user does not belong to: 403. Hiding which tenants
// exist from somebody who already named one buys nothing.
$this->get('/tenancy-host/documents?workspace='.$beta->getKey())->assertForbidden();

// A tenant that is not there: 404.
$this->get('/tenancy-host/documents?workspace=999999')->assertNotFound();

// A user model that does not know about tenants at all: 403.
$this->actingAs(User::factory()->create())
    ->get('/tenancy-host/documents?workspace='.$acme->getKey())
    ->assertForbidden();
```

And the loud one — a scoped resource with nothing bound:

```php
use PandaPanel\Exceptions\PanelRegistrationException;

it('raises rather than running unscoped when no tenant is bound', function (): void {
    expect(fn () => DocumentResource::query()->get())
        ->toThrow(PanelRegistrationException::class);
});
```

A resource that ran unscoped here would return every tenant's records and look like a working page. The exception is the feature.

A relationship that is named but is not a relationship raises too, naming the resource, the model and the method:

```php
expect(fn () => Tenancy::for($acme, fn () => BrokenTenantResource::query()->get()))
    ->toThrow(PanelRegistrationException::class);
```

## What is deliberately not scoped

Two absences that are correct, and worth pinning down so a later change does not "fix" them:

```php
it('leaves a resource that names no tenant relationship unscoped', function (): void {
    // A table every tenant reads the same way has nothing to scope by.
    $names = Tenancy::for($acme, fn (): array => WorkspaceResource::query()->pluck('name')->all());

    expect($names)->toBe(['Acme', 'Beta']);
});

it('scopes nothing in a panel that declared no tenancy', function (): void {
    // Tenancy is a property of the panel, so a resource shared between a
    // tenant panel and an admin one is scoped in the first and whole in the
    // second.
    app(PanelManager::class)->setCurrentPanel(null);

    expect(DocumentResource::query()->count())->toBe(3);
});
```

## The shared props and the switcher

What the frontend receives is part of the contract. `tenancy` is `null` for a panel with no tenancy — null rather than an empty shape, so the frontend's check is `tenancy === null` and a switcher never renders where there is nothing to switch between.

```php
use Inertia\Testing\AssertableInertia;

$this->get('/tenancy-host/documents?workspace='.$acme->getKey())
    ->assertInertia(fn (AssertableInertia $page) => $page
        ->where('tenancy.current.name', 'Acme')
        ->where('tenancy.current.key', (int) $acme->getKey())
        ->where('tenancy.available.0.name', 'Acme')
        ->where('tenancy.available.0.current', true)
        ->where('tenancy.available.1.current', false));

// A panel with no tenancy at all.
expect($this->get('/admin')->viewData('page')['props']['tenancy'])->toBeNull();
```

The switcher offers only tenants the user belongs to, because it is built from the same list the per-request check reads — so it never offers a 403:

```php
$this->get('/tenancy-host/documents?workspace='.$acme->getKey())
    ->assertInertia(fn (AssertableInertia $page) => $page
        ->has('tenancy.available', 1)
        ->where('tenancy.available.0.name', 'Acme'));
```

A URL is only built when the panel said how. Without `tenantUrlUsing()`, `getTenantUrl()` is null and the frontend hides the switcher rather than rendering entries that go nowhere:

```php
expect(Panel::make('urlless')->tenant(Workspace::class, fn () => null)->getTenantUrl($acme))
    ->toBeNull();
```

## Setting up a tenant-scoped panel in a test

Identification is the application's, so a fixture picks whichever mechanism is cheapest to drive — a query parameter is fine, because what is being tested is what happens *after* identification:

```php
use Illuminate\Http\Request;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Routing\PanelRouteRegistrar;

$panel = app(PanelManager::class)->register(
    Panel::make('tenancy-host')
        ->path('tenancy-host')
        ->settings(false)
        ->tenant(
            Workspace::class,
            static fn (Request $request): ?Workspace => Workspace::query()->find($request->query('workspace')),
        )
        ->tenantUrlUsing(static fn (Workspace $w, Panel $p): string => '/'.$p->getPath().'/documents?workspace='.$w->getKey())
        ->resources([DocumentResource::class]),
);

app(PanelRouteRegistrar::class)->register($panel);

Route::getRoutes()->refreshNameLookups();

app(PanelManager::class)->setCurrentPanel($panel);
```

The user model needs `HasPanelTenants`:

```php
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Contracts\HasPanelTenants;
use PandaPanel\Core\Panel;

final class TenantUser extends User implements HasPanelTenants
{
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

Watch the panel's own door while you are there: the example user model's `canAccessPanel()` reads `email_verified_at`, and a 403 from the panel door looks exactly like a 403 from tenancy. Verify the fixture user, or the test is asserting the wrong refusal.

## Console and queue work

Anything that legitimately crosses the boundary — a command looping over tenants, a job re-entering the one it was queued from — enters with `Tenancy::for()`, and that is the shape to test:

```php
it('re-enters the tenant the job was queued from', function (): void {
    $counts = [];

    foreach (Workspace::all() as $workspace) {
        $counts[$workspace->name] = Tenancy::for(
            $workspace,
            fn (): int => DocumentResource::query()->count(),
        );
    }

    expect($counts)->toBe(['Acme' => 2, 'Beta' => 1]);
});
```

## Gotchas

- **`Tenancy::for()` binds; it does not authorize.** It is the mechanism `ResolveTenant` uses after the check, so a test using it directly is skipping the membership check on purpose. Assert `Tenancy::allows()` or make a request when authorization is the subject.
- **Panel context first.** Scoping asks `panel()` before anything else. With no panel bound the scope is skipped, which is a correct answer to a different question.
- **403 and 404 are not interchangeable.** A tenant that exists but is not yours is 403; one that does not exist is 404. Assert the one you mean.
- **The positive test is nearly worthless alone.** `expect($acme)->toBe(['Acme plan', 'Acme notes'])` passes against an unscoped query on a fixture where Acme happens to own everything. Always assert the other tenant's rows are absent.
- **A resource with no `$tenantRelationship` is unscoped, silently.** That is correct behaviour and a very easy mistake. Assert the scope on every resource that should have one rather than assuming the panel provides it.

## See also

- [Testing helpers](helpers.md) and [test setup](setup.md)
- [Tenancy concepts](../tenancy/concepts.md), [resource scoping](../tenancy/resource-scoping.md)
- [`HasPanelTenants`](../tenancy/has-panel-tenants.md), [`PanelTenant`](../tenancy/panel-tenant.md), [the resolver](../tenancy/resolver.md)
- [Single database](../tenancy/single-database.md), [database per tenant](../tenancy/database-per-tenant.md)
- [Tenancy security checklist](../tenancy/security-checklist.md)
- [Negative security tests](negative-security-tests.md)
