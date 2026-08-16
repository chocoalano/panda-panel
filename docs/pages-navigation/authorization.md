# Page Authorization

A standalone page decides for itself who may open it, with one static method. The check runs on the route — inside `Page::render()`, before anything else happens — so a page hidden from the sidebar is still refused by URL. Hiding is never the control.

Three layers apply to any page request, in this order: the panel must let the user in, the page must say yes, and every widget on it answers separately.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Pages;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use PandaPanel\Pages\Page;

final class Settings extends Page
{
    protected static ?string $navigationIcon = 'settings';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->is_admin;
    }
}
```

An ordinary user no longer sees `Settings` in the sidebar, and `GET /admin/settings` answers **403** rather than redirecting.

## `canAccess()`

```php
public static function canAccess(): bool;   // PandaPanel\Pages\Page, default true
```

Static, no arguments, no user parameter. It answers for the currently authenticated user, because that is the only user the page can render as — accepting a user argument would suggest it could render the page as somebody else, which it cannot.

Two callers ask it, and both matter:

| Caller | When | Effect of `false` |
| --- | --- | --- |
| `Page::render()` | every request to the page's route | `abort_unless(static::canAccess(), 403)` |
| `NavigationBuilder` | building the sidebar | the item is dropped before its badge is evaluated |

```php
use PandaPanel\Pages\Page;
use Illuminate\Support\Facades\Gate;

final class AuditLog extends Page
{
    public static function canAccess(): bool
    {
        return Gate::allows('viewAuditLog');
    }
}
```

Route-level enforcement is the point. This test is the one the framework keeps on itself:

```php
it('returns 403 for a direct url to a page the user cannot access', function (): void {
    $page = new ForbiddenPage;

    expect(fn () => $page->render())->toThrow(HttpException::class);
});
```

## Hiding versus refusing

They are separate switches, and only one of them is security.

```php
use PandaPanel\Pages\Page;

final class Webhooks extends Page
{
    // Not in the sidebar. Still routed, still openable.
    protected static bool $shouldRegisterNavigation = false;

    // Not openable. This is the control.
    public static function canAccess(): bool
    {
        return false;
    }
}
```

`$shouldRegisterNavigation = false` makes `navigationItem()` return `null`, which is right for a page reached only from a link elsewhere. It does nothing to the route.

## Panel access comes first

A page inside a panel the user may not enter is never reached, because `PandaPanel\Http\Middleware\ResolvePanel` runs before the controller.

```php
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use PandaPanel\Core\Panel;

$panel->canAccess(static fn (?Authenticatable $user): bool => $user instanceof User && $user->is_admin);
```

```php
public function canAccess(Closure $callback): self;          // fn (?Authenticatable): bool
public function isAccessibleTo(?Authenticatable $user): bool;
```

`isAccessibleTo()` asks two questions and both must agree: the panel's own predicate, and `PanelUser::canAccessPanel()` on the user model when it implements the contract. A panel that says yes cannot overrule a user model that says no.

The middleware runs **after** `auth` and `verified`, so `$request->user()` is populated. A guest is redirected to login earlier; a signed-in user who fails gets 403, never a redirect. The panel's `boot()` runs only after the check passes, so a refused user cannot trigger the panel's boot work.

```php
it('redirects a guest away from a page rather than rendering it', function (): void {
    $this->get('/admin/settings')->assertRedirect('/login');
});

it('refuses a page inside a panel the user cannot access', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/admin/settings')
        ->assertForbidden();
});
```

See [Authorization](../concepts/authorization.md) and [Panel access](../panels/access.md).

## Middleware, for what a boolean cannot say

`canAccess()` answers yes or no. Some concerns need a redirect instead — password confirmation, a signed URL, a subscription wall. Those belong on the route:

```php
use Illuminate\Auth\Middleware\RequirePassword;
use PandaPanel\Pages\Page;

final class SecuritySettings extends Page
{
    /** @var list<string> */
    protected static array $middleware = [RequirePassword::class];
}
```

```php
/** @return list<string> */
public static function middleware(): array;
```

The registrar appends it to this page's route on top of the panel's stack:

```php
$route = $this->router->get($page::routePath(), PanelPageController::class)
    ->defaults('page', $page)
    ->name('pages.'.$page::slug());

if ($page::middleware() !== []) {
    $route->middleware($page::middleware());
}
```

This is exactly why the built-in `SecuritySettings` page uses middleware rather than a `canAccess()` check: a stale session must reach the confirmation screen, and a `canAccess()` check would turn re-confirmation into a 403.

Middleware is a `list<string>`, so anything Laravel accepts as a middleware string works, parameters included: `'throttle:6,1'`, `'can:manage-billing'`.

## Widgets on the page

A page's widgets authorize independently:

```php
public static function canView(): bool;   // PandaPanel\Widgets\Widget, default true
```

```php
use PandaPanel\Widgets\StatsWidget;

final class RevenueStats extends StatsWidget
{
    public static function canView(): bool
    {
        return auth()->user()?->can('viewRevenue') === true;
    }
}
```

`PandaPanel\Pages\WidgetCollection::for()` filters on `canView()` **before** constructing the widget, so an unauthorized widget is never instantiated and never runs a query. A page the user may open can therefore show a different set of widgets to different people without any branching in `widgets()`. See [Widget authorization](../widgets/authorization.md).

## Clusters

A cluster has its own gate, and it is independent of its members:

```php
use PandaPanel\Clusters\Cluster;

final class OperationsCluster extends Cluster
{
    public static function canAccess(): bool
    {
        return auth()->user()?->can('viewOperations') === true;
    }
}
```

A cluster the user may not enter hides the whole set from the sidebar and produces no sub-navigation bar. It does **not** authorize its members — every member still answers for itself, and the member's own route is what refuses. See [Clusters](clusters.md).

## What the sidebar does

`PandaPanel\Support\NavigationBuilder` is a convenience layer over the same checks:

- `Page::canAccess()` and `Resource::canViewAny()` run before anything else, so an unauthorized item never reaches badge evaluation.
- A group left empty by authorization disappears rather than rendering as a heading with nothing under it.
- Everything is per-request. Authorization results are never written to the panel manifest, because they depend on the user.

```php
it('omits a page the user cannot access from navigation', function (): void {
    $labels = collect(app(NavigationBuilder::class)->for($panel, '/nav-host'))
        ->flatMap(fn (array $group): array => array_column($group['items'], 'label'))
        ->all();

    expect($labels)->toContain('Settings')
        ->and($labels)->not->toContain('Restricted');
});
```

## Testing a page's access

```php
use App\Models\User;

it('lets an admin open the page', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/settings')
        ->assertOk();
});

it('refuses everybody else', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/admin/settings')
        ->assertForbidden();
});
```

Assert on the route, not on `canAccess()` alone. A test that only calls the static method passes for a page whose route was never registered. See [Testing authorization](../testing/authorization.md).

## Gotchas

- **`canAccess()` runs at least twice per page view.** Once for the sidebar, once in `render()`. Keep it cheap, or cache inside the request — a query per call becomes a query per navigation item.
- **`canAccess()` cannot redirect.** `abort_unless()` produces 403. Anything that needs to send the user somewhere is middleware.
- **A page in a cluster the user cannot access is still routed.** The cluster gate governs navigation and the cluster bar. Put the check on the page too if the page itself must be closed.
- **Strict authorization does not apply here.** `strictAuthorization()` governs Gate-backed resource abilities and throws when a policy is missing. `Page::canAccess()` is plain PHP and returns `true` by default; a page that forgets to override it is open to everybody who may enter the panel.
- **Panel access is checked before the panel boots.** Anything registered in the panel's boot callbacks — plugins included — never runs for a refused user.
- **`Auth::user()` inside `canAccess()` reads whichever guard is current.** There is no guard setting on `Panel`; a panel that authenticates with a non-default guard does it by passing `authMiddleware(['auth:admin'])`, and Laravel's own `Authenticate` middleware is what makes that guard current for the request. The same static call made outside the panel's route group sees the application default instead.

## See also

- [Custom pages](custom-pages.md)
- [Page discovery](discovery.md)
- [Clusters](clusters.md)
- [Authorization](../concepts/authorization.md)
- [Panel access](../panels/access.md), [Panel middleware](../panels/middleware.md)
- [Resource authorization](../resources/authorization.md)
- [Widget authorization](../widgets/authorization.md)
- [Action authorization](../actions/authorization.md)
- [Testing authorization](../testing/authorization.md)
