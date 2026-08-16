# `HasPanelTenants`

`PandaPanel\Contracts\HasPanelTenants` is the user-model side of tenancy: which
tenants this user may enter, and whether it may enter this particular one. It
is required for any panel that declared `tenant()` — without it a user belongs
to nothing as far as the panel is concerned, and every request is refused. You
implement it on whichever model your panel authenticates.

## Implementing it

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

That is the whole contract. Two methods, both taking the panel, so one model
can answer differently for the admin panel and the customer panel.

## The interface

```php
namespace PandaPanel\Contracts;

interface HasPanelTenants
{
    /** @return Collection<int, Model> */
    public function getPanelTenants(Panel $panel): Collection;

    public function canAccessPanelTenant(Model $tenant, Panel $panel): bool;
}
```

| Method | Signature | Asked by | Asked when |
| --- | --- | --- | --- |
| `getPanelTenants` | `getPanelTenants(Panel $panel): Illuminate\Database\Eloquent\Collection` | `Tenancy::availableTo()` | Building the switcher list, once per panel render |
| `canAccessPanelTenant` | `canAccessPanelTenant(Model $tenant, Panel $panel): bool` | `Tenancy::allows()` | Every request, in `ResolveTenant`, before anything is queried |

### `getPanelTenants()`

Every tenant this user may enter through this panel. Used to build the switcher
and to pick a default when a request names none.

```php
public function getPanelTenants(Panel $panel): Collection
{
    // A panel may deserve a different list.
    if ($panel->getId() === 'support') {
        return Workspace::query()->where('supported', true)->get();
    }

    return $this->workspaces()->orderBy('name')->get();
}
```

An empty collection is a legitimate answer. A user who belongs to nothing gets
a panel that refuses, rather than one that shows everything.

Read back through `Tenancy`:

```php
use PandaPanel\Tenancy\Tenancy;

/** @var list<Illuminate\Database\Eloquent\Model> $tenants */
$tenants = Tenancy::availableTo($user, panel('app'));
```

```php
public static function availableTo(?Authenticatable $user, Panel $panel): array
{
    if (! $user instanceof HasPanelTenants) {
        return [];
    }

    return array_values($user->getPanelTenants($panel)->all());
}
```

Note the shape change: the contract returns an Eloquent `Collection`,
`availableTo()` returns a plain `list<Model>`. A user model that does not
implement the contract — a guest, or an application that forgot — gets an empty
list rather than an error, because a switcher is a display concern.

### `canAccessPanelTenant()`

Whether this user may enter this particular tenant through this panel. Asked on
every request, directly, and **never derived from `getPanelTenants()`**.

```php
use PandaPanel\Tenancy\Tenancy;

Tenancy::allows($user, $workspace, panel('app'));   // bool
```

```php
public static function allows(?Authenticatable $user, Model $tenant, Panel $panel): bool
{
    return $user instanceof HasPanelTenants
        && $user->canAccessPanelTenant($tenant, $panel);
}
```

The separation is the point. `getPanelTenants()` is built for a dropdown and
may be sorted, trimmed or paginated as the UI needs; a security answer must not
change when a display decision does. A user who is *offered* three tenants and
*permitted* two has a bug, and the bug is in the model — which is exactly where
a framework cannot fix it, and why both are asked rather than one derived from
the other.

Keep the check a single indexed query. It runs on every request in the panel:

```php
public function canAccessPanelTenant(Model $tenant, Panel $panel): bool
{
    return $this->workspaces()->whereKey($tenant->getKey())->exists();
}
```

## What refusal looks like

```php
abort_unless(Tenancy::allows($user, $tenant, $panel), 403);
```

| Situation | Result |
| --- | --- |
| User model implements the contract and says yes | request proceeds |
| User model implements the contract and says no | `403` |
| User model does not implement the contract | `403` on every tenant |
| No authenticated user (`$user === null`) | `403` |

A 403 rather than a 404, because the request already named a tenant that
exists. Hiding which tenants exist from somebody who could name one is security
theatre that costs a comprehensible error message.

The last row is worth restating: a tenant-scoped panel whose user model does
not implement `HasPanelTenants` refuses **every** request. That is the correct
failure and a loud one — the alternative, falling open, is the exact leak the
mechanism exists to prevent.

## Beyond membership

The contract is a `bool`, so anything expressible as one belongs here — a
suspended membership, an expired invitation, a role that only exists in some
tenants:

```php
public function canAccessPanelTenant(Model $tenant, Panel $panel): bool
{
    $membership = $this->workspaces()
        ->whereKey($tenant->getKey())
        ->first()
        ?->pivot;

    return $membership !== null
        && $membership->suspended_at === null;
}
```

What does *not* belong here is anything that should redirect rather than
refuse. `canAccessPanelTenant()` answers yes or no and produces a 403; an
expired trial that should send the user to billing is middleware in the panel's
stack, modelled on `PandaPanel\Http\Middleware\RequireTwoFactor`.

## Testing it

```php
use PandaPanel\Tenancy\Tenancy;

it('refuses a tenant this user does not belong to', function (): void {
    $this->actingAs($user)
        ->get('/app/documents?workspace='.$otherWorkspace->getKey())
        ->assertForbidden();
});

it('refuses a user model that does not know about tenants at all', function (): void {
    $this->actingAs(User::factory()->create());   // no HasPanelTenants

    $this->get('/app/documents?workspace='.$workspace->getKey())
        ->assertForbidden();
});

it('offers only the tenants the user belongs to', function (): void {
    expect(Tenancy::availableTo($user, panel('app')))->toHaveCount(1);
});
```

## Notes

- **The return type is Eloquent's `Collection`**, not `Support\Collection`.
  `Illuminate\Database\Eloquent\Collection<int, Model>` is what the interface
  declares.
- **Both methods take the `Panel`.** A model can answer differently per panel
  without any registry of its own — useful when a support panel sees every
  tenant and the customer panel sees only the user's.
- **`getPanelTenants()` is called inside a shared prop closure**, so a panel
  screen that never draws a switcher never runs the query behind it. It is
  still worth an `orderBy`: the list is rendered in whatever order it arrives.
- **Do not implement `canAccessPanelTenant()` as
  `$this->getPanelTenants($panel)->contains($tenant)`.** It works until the
  list is trimmed for the dropdown, and then it silently becomes a smaller
  security boundary.
- **This is separate from `PanelUser::canAccessPanel()`.** That decides whether
  the user may open the panel at all; this decides which tenants inside it. Both
  are checked, in that order — see [Authorization](../concepts/authorization.md).

## See also

- [Tenancy Concepts](concepts.md)
- [`PanelTenant`](panel-tenant.md)
- [Tenant Resolver](resolver.md)
- [Tenant Switcher](switcher.md)
- [Tenancy Security Checklist](security-checklist.md)
- [Authorization](../concepts/authorization.md)
- [`PanelUser` Contract](../authentication/panel-user-contract.md)
- [User Model](../authentication/user-model.md)
