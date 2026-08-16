# `PanelTenant`

`PandaPanel\Contracts\PanelTenant` is the tenant-model side of tenancy: which
value identifies this tenant, and what to call it on screen. It is optional —
`PandaPanel\Tenancy\Tenancy` falls back to the primary key and a `name`
attribute — and you implement it when either of those guesses is wrong, or when
you would rather the answer be stated than inferred.

## Implementing it

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
        return (int) $this->getKey();
    }

    public function getTenantName(): string
    {
        return (string) $this->getAttribute('name');
    }
}
```

```php
use PandaPanel\Tenancy\Tenancy;

Tenancy::keyOf($workspace);      // 7
Tenancy::nameOf($workspace);     // 'Acme'
Tenancy::describe($workspace);   // ['key' => 7, 'name' => 'Acme']
```

## The interface

```php
namespace PandaPanel\Contracts;

interface PanelTenant
{
    public function getTenantKey(): int|string;

    public function getTenantName(): string;
}
```

| Method | Signature | Used for |
| --- | --- | --- |
| `getTenantKey` | `getTenantKey(): int\|string` | `Tenancy::keyOf()`, `Tenancy::key()`, the switcher entry's `key`, marking the current tenant |
| `getTenantName` | `getTenantName(): string` | `Tenancy::nameOf()`, the switcher entry's `name` |

Deliberately two methods and no more. Everything a tenant *is* — a team, an
organisation, a customer account, a database — is the application's, and a
framework that asked for a `plan` or a `logo` would be describing one project's
tenant rather than the idea of one.

### `getTenantKey()`

The value this tenant is identified by. Usually the primary key. A project that
routes on `acme` rather than on `41` returns the slug:

```php
public function getTenantKey(): int|string
{
    return $this->slug;
}
```

### `getTenantName()`

What to call this tenant on screen — in the switcher, in the shell, in the
sentence that says which tenant a record belongs to.

```php
public function getTenantName(): string
{
    return $this->trading_name ?: $this->legal_name;
}
```

## The fallbacks

A tenant model that does not implement the contract still works. `Tenancy`
resolves both values itself:

```php
public static function keyOf(Model $tenant): int|string
{
    if ($tenant instanceof PanelTenant) {
        return $tenant->getTenantKey();
    }

    $key = $tenant->getKey();

    return is_int($key) || is_string($key) ? $key : (string) $key;
}
```

```php
public static function nameOf(Model $tenant): string
{
    if ($tenant instanceof PanelTenant) {
        return $tenant->getTenantName();
    }

    $name = $tenant->getAttribute('name');

    return is_string($name) && $name !== '' ? $name : (string) self::keyOf($tenant);
}
```

| Value | Contract implemented | Not implemented |
| --- | --- | --- |
| key | `getTenantKey()` | `getKey()`, cast to `string` if it is neither `int` nor `string` |
| name | `getTenantName()` | the `name` attribute, if it is a non-empty string |
| name, no `name` attribute | — | the key, as a string |

Falling through to the key rather than to an empty string is on purpose: a
switcher with a blank row is a switcher nobody can use, and `41` at least
identifies which one it is.

```php
use PandaPanel\Tenancy\Tenancy;

// A model with no contract and a `name` column.
Tenancy::describe($workspace);      // ['key' => 7, 'name' => 'Acme']

// A model with no contract and no name.
Tenancy::describe($blank);          // ['key' => 12, 'name' => '12']
```

## `Tenancy::describe()`

One tenant, in exactly the shape the frontend receives:

```php
/** @return array{key: int|string, name: string} */
public static function describe(Model $tenant): array
```

```php
Tenancy::describe($workspace);   // ['key' => 7, 'name' => 'Acme']
```

`PandaPanel\Http\Middleware\SharePanelData` spreads this and adds two fields of
its own before sharing it:

```php
$describe = static fn (Model $tenant): array => [
    ...Tenancy::describe($tenant),
    'url' => $panel->getTenantUrl($tenant),
    'current' => $currentKey !== null && Tenancy::keyOf($tenant) === $currentKey,
];
```

So `key` is what marks the current entry in the switcher, and it is compared
with `===`. A `getTenantKey()` that returns `'7'` for one tenant and `7` for
another will never match. Cast it, as the example at the top of this page does.

## `Tenancy::key()`

The current tenant's key, or null when there is no tenancy — what a `where`
clause needs, and the only thing most callers want:

```php
public static function key(): int|string|null
```

```php
use PandaPanel\Tenancy\Tenancy;

Post::query()->where('workspace_id', Tenancy::key())->get();
```

It goes through `keyOf()`, so it returns whatever `getTenantKey()` returns. A
tenant identified by slug returns the slug here, which is usually *not* the
foreign key on your other tables. When you want the primary key, ask for it:

```php
Tenancy::current()?->getKey();
```

## Where the contract is not consulted

`Resource::applyTenantScope()` builds its `whereHas` from the model's primary
key, not from `getTenantKey()`:

```php
return $query->whereHas(
    $relationship,
    static fn (Builder $related): Builder => $related->whereKey($tenant->getKey()),
);
```

That is correct — the relationship leads to the tenant *row*, and rows are
joined on primary keys — but it means a `getTenantKey()` returning a slug
changes URLs, switcher entries and `Tenancy::key()`, and changes nothing about
how records are scoped.

## Notes

- **The contract is optional in the same way `PanelUser` is.** A panel with no
  tenancy never asks, and a tenant model with a key and a `name` column works
  without it.
- **`getTenantKey()` must return `int` or `string`.** A UUID cast object, a
  value object or an enum has to be converted; the interface's return type
  enforces it.
- **Implementing it does not opt a model into being a tenant.** `Panel::tenant()`
  is what declares the tenant model; this contract only describes one.
- **`Tenancy::nameOf()` is the only place a name is read.** There is no
  `getTenantAvatar()`, `getTenantLogo()` or `getTenantPlan()` hook. A switcher
  that needs more than a name is a switcher you render yourself from the shared
  prop plus your own — see [Tenant Switcher](switcher.md).
- **A tenant model on another connection needs `$connection` set.** In a
  database-per-tenant arrangement the tenant list lives centrally, and reading
  it from inside a tenant context otherwise queries the tenant's own database.
  See [Database Per Tenant](database-per-tenant.md).

## See also

- [Tenancy Concepts](concepts.md)
- [`HasPanelTenants`](has-panel-tenants.md)
- [Tenant Switcher](switcher.md)
- [Tenant URLs](urls.md)
- [Resource Tenant Scoping](resource-scoping.md)
- [Database Per Tenant](database-per-tenant.md)
- [Server Metadata to Vue](../concepts/metadata-to-vue.md)
