# Contracts Reference

Every interface in `PandaPanel\Contracts`, what implements it, and whether you have to. Reach for this page when you are supplying your own implementation of something the framework normally provides, or when you are teaching static analysis what your user model is.

Nine interfaces, and they split cleanly. Four describe things the framework already implements — a panel, a resource, a page, a widget — and exist so registries and the navigation builder type-hint a contract rather than a base class. Four describe things the *application* implements: a user, a tenant, a user that has tenants, a notifiable. The ninth, `PanelPlugin`, is what a package implements to configure a panel from outside it.

## The nine

| Interface | Implemented by | Do you implement it? |
| --- | --- | --- |
| `PanelContract` | `PandaPanel\Core\Panel` | No |
| `ResourceContract` | `PandaPanel\Resources\Resource` | No |
| `PageContract` | `PandaPanel\Pages\Page` | No |
| `WidgetContract` | `PandaPanel\Widgets\Widget` | No |
| `PanelPlugin` | `PandaPanel\Plugins\Plugin` | Yes, for a plugin shipped as its own package |
| `PanelUser` | your user model | Optional |
| `PanelTenant` | your tenant model | Optional |
| `HasPanelTenants` | your user model | Required for a tenant-scoped panel |
| `PanelNotifiable` | your user model, via Laravel's `Notifiable` | Optional; documentation only |

## A user model that answers all three

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PandaPanel\Contracts\HasPanelTenants;
use PandaPanel\Contracts\PanelNotifiable;
use PandaPanel\Contracts\PanelUser;
use PandaPanel\Core\Panel;

final class User extends Authenticatable implements HasPanelTenants, PanelNotifiable, PanelUser
{
    use Notifiable;

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->suspended_at === null;
    }

    public function getPanelTenants(Panel $panel): Collection
    {
        return $this->teams;
    }

    public function canAccessPanelTenant(Model $tenant, Panel $panel): bool
    {
        return $this->teams()->whereKey($tenant->getKey())->exists();
    }
}
```

Nothing else changes. `Notifiable` already supplies the three methods `PanelNotifiable` names.

## `PanelUser`

```php
namespace PandaPanel\Contracts;

interface PanelUser
{
    public function canAccessPanel(Panel $panel): bool;
}
```

The other half of `Panel::canAccess()`. A closure on the panel is the right place for a rule about *that panel* — "this one is for administrators". This is the right place for a rule about *the user*: a suspended account, one with no team, one whose contract lapsed. Written on the model it applies to every panel at once and cannot be forgotten when a new one is added.

Both are asked, and both must agree:

```php
// PandaPanel\Core\Panel
public function isAccessibleTo(?Authenticatable $user): bool
{
    if ($user instanceof PanelUser && ! $user->canAccessPanel($this)) {
        return false;
    }

    return $this->canAccess === null || ($this->canAccess)($user);
}
```

A panel that says yes cannot overrule a user model that says no. A user model that implements neither is refused nothing, which is what every panel written before the contract existed already assumed.

Asked on every request through the panel's middleware, before any page is built, so a refused user never triggers the panel's boot work — and gets a 403 rather than a redirect.

## `HasPanelTenants`

```php
interface HasPanelTenants
{
    /** @return Collection<int, Model> */
    public function getPanelTenants(Panel $panel): Collection;

    public function canAccessPanelTenant(Model $tenant, Panel $panel): bool;
}
```

Two questions, deliberately not one derived from the other. `getPanelTenants()` is what to *offer* — the switcher is built from it, and it picks a default when the request names no tenant. `canAccessPanelTenant()` is what to *allow*, and it is asked on every request before anything is queried.

Deriving the second from the first would make the security boundary a function of a list built for a dropdown, and a dropdown that grew for performance reasons would quietly grow the boundary too.

An empty collection is a legitimate answer: a user who belongs to nothing gets a panel that refuses, not one that shows everything.

Required only for a panel that declared a tenant model. Without it, `ResolveTenant` has no way to know whether the tenant in this request is one this user may enter, and refuses every request rather than guessing.

## `PanelTenant`

```php
interface PanelTenant
{
    public function getTenantKey(): int|string;

    public function getTenantName(): string;
}
```

Two methods and no more. Everything a tenant *is* — a team, an organisation, a customer account, a database — is the application's. What the panel cannot guess is which value identifies this tenant and what to call it on screen.

```php
final class Team extends Model implements PanelTenant
{
    public function getTenantKey(): int|string
    {
        return $this->slug;   // routed on 'acme', not on 41
    }

    public function getTenantName(): string
    {
        return $this->display_name;
    }
}
```

Optional. A tenant model that does not implement it can still be used as long as it has a key and a `name` — `Tenancy::describe()` falls back to those.

## `PanelNotifiable`

```php
interface PanelNotifiable
{
    public function notifications();
    public function unreadNotifications();
    public function notify($instance);
}
```

Exactly Laravel's own `Notifiable`, named. Nothing has to implement it: the trait already provides every method, and `PanelNotificationController` accepts a model that merely uses the trait.

It exists to be written down. "The user model must be `Notifiable`" is a requirement the panel has either way; an interface makes it one static analysis can see rather than one discovered when the bell throws.

No native return types, deliberately — the trait declares none, and a model using it would be an incompatible declaration against an interface that did.

## `PanelContract`

```php
interface PanelContract
{
    public function getId(): string;
    public function getPath(): string;
    public function getDomain(): ?string;

    /** @return list<string> */
    public function getMiddleware(): array;

    public function isAccessibleTo(?Authenticatable $user): bool;
}
```

The minimum a panel must expose to the route registrar, the middleware, the navigation builder, and the Inertia sharing layer. `Panel` implements it and adds around a hundred more methods; the contract is what `Resource::navigationItem()` and `Page::navigationItem()` type-hint.

```php
public static function navigationItem(PanelContract $panel): ?NavigationItem;
```

Inside those methods a `PanelContract` that is not a `Panel` is treated as no panel at all — `$panel instanceof Panel ? $panel : null` — so per-panel configuration is read only when a real panel is in hand.

## `ResourceContract`

```php
interface ResourceContract
{
    public static function slug(): string;

    /** @return Builder<covariant Model> */
    public static function query(): Builder;

    /** @return array<string, class-string> */
    public static function pages(): array;

    public static function canViewAny(): bool;

    public static function navigationItem(PanelContract $panel): ?NavigationItem;

    /** @return class-string<Cluster>|null */
    public static function cluster(): ?string;
}
```

What `ResourceRegistry` and `PanelDiscoverer` type-hint. A class in a discovery directory is registered only if it is concrete and implements this, which is how a base resource or a trait can sit beside real ones without being picked up.

`cluster()` is on the contract because the navigation builder asks every registered class before it can decide what to list: a clustered resource is listed *under* its cluster, not beside it.

## `PageContract`

```php
interface PageContract
{
    public static function slug(): string;
    public static function routePath(): string;
    public static function canAccess(): bool;
    public static function navigationItem(PanelContract $panel): ?NavigationItem;

    /** @return class-string<Cluster>|null */
    public static function cluster(): ?string;
}
```

`routePath()` is relative to the panel prefix and carries no leading slash. `canAccess()` is enforced by the page route itself — `Page::render()` opens with `abort_unless(static::canAccess(), 403)` — never only by navigation visibility.

## `WidgetContract`

```php
interface WidgetContract
{
    public static function id(): string;
    public static function type(): WidgetType;
    public static function canView(): bool;

    /** @return array<string, mixed> */
    public function toArray(): array;
}
```

`canView()` is checked before `toArray()` runs, so an unauthorized widget never executes its queries. `WidgetType` is `PandaPanel\Widgets\Enums\WidgetType`: `Stats`, `Table`, `Chart`, `Custom`.

## `PanelPlugin`

```php
interface PanelPlugin
{
    public function id(): string;
    public function register(Panel $panel): void;
    public function boot(Panel $panel): void;
    public function metadata(): PluginMetadata;

    /** @return array<string, string> source => destination */
    public function publishes(): array;
}
```

Everything a plugin does, it does through the panel's own public API. There is no second configuration surface, which is what stops a plugin doing something a panel cannot.

`PandaPanel\Plugins\Plugin` implements four of the five and leaves `register()` abstract. Implementing the interface directly is what a plugin shipped as its own package should do — a package that extends an application class is a package coupled to it. Nothing in the framework asks for a `Plugin`; every lookup goes through the contract.

See [Plugins reference](plugins.md) for the lifecycle in detail.

## Implementing a contract yourself

The four framework-side contracts are typed rather than sealed, so a module can supply its own implementation:

```php
use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Contracts\PanelContract;
use PandaPanel\Contracts\ResourceContract;
use PandaPanel\Support\NavigationItem;

final class LegacyReportResource implements ResourceContract
{
    public static function slug(): string
    {
        return 'legacy-reports';
    }

    public static function query(): Builder
    {
        return LegacyReport::query();
    }

    public static function pages(): array
    {
        return ['index' => ListLegacyReports::class];
    }

    public static function canViewAny(): bool
    {
        return true;
    }

    public static function navigationItem(PanelContract $panel): ?NavigationItem
    {
        return NavigationItem::make('Legacy reports', '/admin/legacy-reports');
    }

    public static function cluster(): ?string
    {
        return null;
    }
}
```

It will be discovered and registered. What it will *not* get is anything the base class provides: `PanelRouteRegistrar` calls `Resource::isSingular()`, `parentResource()`, and `integrationSettings()` on registered resources, so a bare `ResourceContract` implementation has to be registered somewhere the registrar does not reach, or extend `Resource` after all. Extending the base class is the supported path; the contract exists for type-hinting.

## Notes

- **Nothing in `Contracts` is required to run a panel.** A vanilla Laravel user model, a resource extending `Resource`, and a page extending `Page` satisfy everything the framework asks.
- **`PanelUser` and `HasPanelTenants` are checked with `instanceof`.** A model that has the method but not the interface is not asked. That is deliberate: adding a method named `canAccessPanel` to a model should not silently change who can enter a panel.
- **`PanelNotifiable` is the one contract nothing checks.** `SharePanelData` and `PanelNotificationController` both use `method_exists()`, because the trait is the real requirement and the interface is the documentation of it.
- **`cluster()` returning a class name is not a leak.** Class names never cross to Vue: `Cluster::slug()` is what reaches the frontend, and `Panel::renderHook()` reduces resource and page classes to `resource:{slug}` and `page:{slug}` before serializing.

## See also

- [Core API reference](core.md)
- [Resources reference](resources.md)
- [Pages reference](pages.md)
- [Plugins reference](plugins.md)
- [Authorization](../concepts/authorization.md)
- [Panel access](../panels/access.md)
- [Tenancy concepts](../tenancy/concepts.md)
- [`PanelTenant`](../tenancy/panel-tenant.md)
- [`HasPanelTenants`](../tenancy/has-panel-tenants.md)
- [Notification centre](../notifications/notification-center.md)
