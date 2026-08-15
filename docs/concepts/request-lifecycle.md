# Request Lifecycle

Every panel screen is an ordinary Laravel request that passes through a fixed
sequence of middleware before a page class builds metadata and Inertia
serializes it. This page traces that sequence in order, names every class in
it, and says what each one may and may not assume. Reach for it when a request
is 403 or 404 and you need to know which layer answered.

## The shape of a request

```text
request
  ↓  web middleware        ResetPanelContext clears any previous panel
  ↓  panel route group     panel middleware, then ResolvePanel:{id}
  ↓  PanelContext          the current panel, request-scoped
  ↓  page or resource page authorize → build metadata → serialize
  ↓  Inertia               shared props (panel, navigation) + page props
  ↓  Vue                   PanelLayout → page component → renderers
```

You can see the real stack for any panel route:

```bash
php artisan route:list --path=admin
```

```php
use Illuminate\Support\Facades\Route;

Route::getRoutes()->getByName('panel.admin.dashboard')?->gatherMiddleware();
// ['web', 'auth', 'verified', 'PandaPanel\Http\Middleware\ResolvePanel:admin', ...]
```

## Stage 1 — the web group

`PandaPanelServiceProvider` appends four middleware to the `web` group, in this
order, unless `panda-panel.register_web_middleware` is `false`:

| Class | Runs for | Purpose |
| --- | --- | --- |
| `PandaPanel\Http\Middleware\ResetPanelContext` | every web request | Clears the request-scoped panel. |
| `PandaPanel\Http\Middleware\RedirectPanelHome` | every web request | Hands `/dashboard` over to the first accessible panel. |
| `PandaPanel\Http\Middleware\ShareFlashToast` | every web request | Maps Laravel flash keys onto the toast channel. |
| `PandaPanel\Http\Middleware\SharePanelData` | every web request | Shares the seven panel props with Inertia. |

They are on the `web` group rather than the panel group deliberately.
`ResetPanelContext` has to run for requests that never reach a panel;
`ShareFlashToast` has to run for redirects back out of one.

### ResetPanelContext

```php
public function handle(Request $request, Closure $next): Response
{
    $this->context->forget();

    return $next($request);
}
```

In a classic PHP request the container is rebuilt each time and the leak this
prevents is invisible. Under Octane, or inside a test that issues several
requests, it is not. Enforcing the invariant here makes "no current panel
outside a panel" true in every environment rather than true by accident.

### RedirectPanelHome

GET requests only, and never one that `expectsJson()`. It asks
`PandaPanel\Support\PanelHomeRedirect::for($request)`, which returns `null` —
changing nothing — for a request that is not one of the configured paths, for
a guest, for a user no panel will admit, and for a request already inside a
panel. Otherwise it issues a 302 to that panel's dashboard route.

```php
// config/panda-panel.php
'home_redirect' => [
    'enabled' => true,
    'paths' => ['dashboard'],
],
```

`paths` are `Request::is()` patterns, so `'reports/*'` hands over a whole
section.

### ShareFlashToast

Reads `error`, `warning`, `success`, `info` from the session in that order and
flashes the first non-empty one as `flash.toast`. An explicit
`Inertia::flash('toast', …)` is never overwritten. A request with no session
is skipped.

### SharePanelData

Shares seven props through `Inertia::share()`, which merges — the
application's own `HandleInertiaRequests` is untouched.

| Prop | Value outside a panel |
| --- | --- |
| `panel` | `null` |
| `navigation` | `[]` |
| `panels` | `[]` |
| `broadcasting` | `['enabled' => false, 'channel' => null]` |
| `search` | `['enabled' => false, 'url' => null, 'debounce' => 300, 'keyBindings' => []]` |
| `notifications` | `['enabled' => false, …, 'unread' => 0]` |
| `tenancy` | `null` |

Every value is a closure, so a request that never reaches a panel pays for
none of them. Nothing here is cached: visibility, badge counts, active state,
and the unread count are all per-user and per-URL. See
[Server Metadata to Vue](metadata-to-vue.md).

Note the ordering constraint this creates: shared props are built in
middleware, before the request reaches a page, so the shell knows which page
it is rendering and this middleware does not. That is why render-hook scope
filtering happens in Vue.

## Stage 2 — the panel route group

`PandaPanel\Routing\PanelRouteRegistrar::register()` builds one group per
panel:

```php
$attributes = [
    'prefix' => $panel->getPath(),
    'as' => $panel->getRouteNamePrefix(),
    'middleware' => [
        ...$panel->getMiddleware(),                        // base + auth stacks
        ResolvePanel::class.':'.$panel->getId(),
        RequireTwoFactor::class.':'.$panel->getId(),
        RequireEmailCode::class.':'.$panel->getId(),
        ...($panel->hasTenancy() ? [ResolveTenant::class.':'.$panel->getId()] : []),
    ],
];
```

The panel id travels as a middleware parameter rather than being matched from
the path. Which panel a route belongs to is a registration fact, and reading
it from the URL would make it something a request could choose.

### ResolvePanel

Runs last in the authentication part of the stack, after `auth` and
`verified`, so `$request->user()` is populated.

```php
$this->manager->setCurrentPanel($panel);

abort_unless($panel->isAccessibleTo($request->user()), 403);

$panel->boot();
```

Three things in that order. A user who is refused the panel gets **403**, not
a redirect — hiding navigation is not an access control. Boot callbacks run
after the check, never before, so a refused user cannot trigger the panel's
boot work.

Called without a parameter, it falls back to
`PanelManager::resolveFromRequest()`. The registrar always passes the id.

### RequireTwoFactor

A no-op unless the panel called `requireTwoFactor()`. It holds a user at the
security settings page until they have a second factor — an authenticator app
(`hasEnabledTwoFactorAuthentication()`), the panel's emailed-code factor, or a
passkey. Exempt: the security page itself, and every other route named
`panel.{id}.pages.*`, because signing out is a legitimate answer to being
asked for a second factor. A panel with no security page registered lets the
request through rather than becoming a panel nobody can enter.

### RequireEmailCode

A no-op unless the signed-in account turned the emailed-code factor on. It
works the way password confirmation does: the session carries a mark, and this
refuses everything until it is there. Exempt: every route named
`panel.{id}.auth.two-factor.*`, or answering the challenge would be refused by
the thing being answered. The intended URL is stashed in `url.intended` first.

### ResolveTenant

Registered only for a panel that declared `tenant()`. Three steps, all before
anything is queried:

1. **Identification** through the panel's own resolver. Null is a **404**.
2. **Authorization** through `HasPanelTenants::canAccessPanelTenant()`, asked
   directly on every request. Refused is a **403**, deliberately not a 404.
3. **Binding**, only then, and once.

### ResolveParentRecord

Attached to the route group of a nested resource, not to the panel:

```php
$attributes['middleware'] = [ResolveParentRecord::class.':'.$resource];
```

It resolves the parent through the *parent* resource's `query()` and
authorizes it with the parent's `canView()`, so a parent the user could not
have opened is a 404 here too. Without that, `/users/9/posts` would be a way
to read user 9's children while `/users/9` itself was refused.

### Page middleware

A standalone `Page` may add its own:

```php
use Illuminate\Auth\Middleware\RequirePassword;

final class BillingSettings extends Page
{
    protected static array $middleware = [RequirePassword::class];
}
```

The registrar appends them to that page's route only. This is for concerns the
route must enforce before the page is constructed — password confirmation, a
signed URL — which `canAccess()` cannot express because it answers yes or no
rather than redirecting.

## Stage 3 — the page

Every panel route points at a controller method, never a closure, so
`php artisan route:cache` keeps working.

| Route | Controller |
| --- | --- |
| `panel.{id}.dashboard` | `PanelDashboardController` → `$panel->getDashboard()` |
| `panel.{id}.pages.{slug}` | `PanelPageController` → the class bound in route defaults |
| `panel.{id}.resources.{slug}.*` | `[PageClass::class, 'render'\|'handle'\|'validateStep']` |

`PanelPageController` never resolves a class name from the request; the page
class is bound at registration:

```php
$this->router
    ->get($page::routePath(), PanelPageController::class)
    ->defaults('page', $page)
    ->name('pages.'.$page::slug());
```

### What a page does

`Page::render()` is the whole shape:

```php
public function render(): Response
{
    abort_unless(static::canAccess(), 403);

    $filters = $this->resolveFilters();
    $widgets = $this->resolveWidgets($filters);
    $schema = $this->filterSchema();

    return Inertia::render(static::$component, [
        'page' => $this->metadata(),
        'widgets' => $widgets->definitions(),
        'widgetData' => $widgets->deferred(),
        'filters' => $schema === null ? null : ['form' => $schema->toArrayWithState(null, $filters->dashboard())],
        ...$this->props(),
    ]);
}
```

Authorize, build metadata, serialize. A resource page does the same with more
parts: `ListRecords::render()` authorizes with `canViewAny()`, builds the
table schema, reads state from the query string through `TableQuery`,
paginates from `Resource::query()`, and serializes rows, summaries,
pagination, and action endpoints.

Authorization is asked at the page, not inferred from the button that was
rendered. See [Authorization](authorization.md).

## Stage 4 — Inertia and Vue

The response carries the shared props plus the page's own. `resources/js/app.ts`
resolves the component name to a file under `resources/js/pages/`, and the
component declares its own layout:

```ts
defineOptions({ layout: PanelLayout });
```

`PanelLayout` reads `panel` and `navigation` from the shared props and picks
`SidebarPanelLayout` or `HeaderPanelLayout` from `panel.sidebar.variant`.
Panel auth pages declare `PanelBlankLayout` instead — a guest has no
navigation, no notifications, and no user menu.

## Writes

A write is the same lifecycle with a different verb. `POST create`,
`PUT {record}/edit`, and the action endpoints all pass through the identical
middleware stack, so the panel is resolved and the access check has run before
any handler sees the request.

Transactions resolve most-specific-first: an action's
`databaseTransaction(bool)`, then a page's `$hasDatabaseTransactions`, then the
panel's `databaseTransactions()`, then on. `null` at any level means "did not
decide" rather than "off".

## Notes

- The web middleware are appended through the HTTP kernel in an
  `afterResolving` hook, not pushed onto the router. `bootstrap/app.php`
  configures the `web` group in its own `afterResolving` hook, which
  overwrites whatever the router was holding — a package that pushed straight
  onto the router would have its middleware silently dropped.
- `RedirectPanelHome` issues a 302 rather than an Inertia location visit,
  because the destination is a page in this application and an Inertia request
  follows it without a full reload.
- A guest hitting a panel URL is redirected by Laravel's `auth` middleware,
  before `ResolvePanel` ever runs. Where to is decided by
  `PandaPanel\Support\PanelLoginRedirect`: the panel's own login when it has
  one, `route('login')` otherwise.
- `panel()` returns `null` for the entire request outside a panel route group,
  including inside `SharePanelData` on a starter-kit page. Every consumer must
  tolerate that.
- Middleware aliases exist for applications that want to name these in their
  own route files: `panel`, `panel.two-factor`, `panel.email-code`,
  `panel.parent`. The registrar names the classes directly and never uses the
  aliases.

## See also

- [Panel Context](panel-context.md)
- [Routing](routing.md)
- [Authorization](authorization.md)
- [Server Metadata to Vue](metadata-to-vue.md)
- [Panels](panels.md)
- [Middleware Configuration](../configuration/middleware.md)
- [Home Redirect](../configuration/home-redirect.md)
- [Guest Redirect](../configuration/guest-redirect.md)
