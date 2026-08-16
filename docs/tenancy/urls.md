# Tenant URLs

A tenant's URL is the other half of the resolver. The resolver turns a request
into a tenant; `Panel::tenantUrlUsing()` turns a tenant back into a request, so
the switcher has somewhere to send people. Only the resolver's author knows how
a tenant is addressed — a subdomain, a path segment, one tenant per user — so
only they can reverse it, and the framework never guesses.

## Declaring one

```php
use App\Models\Team;
use PandaPanel\Core\Panel;

$panel->tenantUrlUsing(
    static fn (Team $team, Panel $panel): string => "https://{$team->slug}.example.com/{$panel->getPath()}",
);
```

```php
panel('app')->getTenantUrl($team);   // 'https://acme.example.com/app'
```

That URL is what every switcher entry links to. Without a builder,
`getTenantUrl()` returns `null` for every tenant and the switcher does not
render.

## The API

```php
/** @param  Closure(Model, self): string  $url */
public function tenantUrlUsing(Closure $url): self

public function getTenantUrl(Model $tenant): ?string
```

| Method | Signature | Notes |
| --- | --- | --- |
| `tenantUrlUsing` | `tenantUrlUsing(Closure $url): self` | Closure receives the tenant model and the panel |
| `getTenantUrl` | `getTenantUrl(Model $tenant): ?string` | `null` when no builder was declared |

```php
public function getTenantUrl(Model $tenant): ?string
{
    return $this->tenantUrl === null ? null : ($this->tenantUrl)($tenant, $this);
}
```

The closure's second argument is the panel, so a builder can be written once
and reused across panels:

```php
$urlForTenant = static fn (Team $team, Panel $panel): string
    => "https://{$team->slug}.example.com/{$panel->getPath()}";

$appPanel->tenantUrlUsing($urlForTenant);      // .../app
$reportsPanel->tenantUrlUsing($urlForTenant);  // .../reports
```

## Three addressing schemes

### Subdomain

The tenant is the host. The URL must be absolute, because the switcher is
navigating to another origin.

```php
$panel->tenantUrlUsing(
    static fn (Team $team, Panel $panel): string
        => "https://{$team->slug}.example.com/{$panel->getPath()}",
);
```

Pin the panel to a host pattern so the router only matches tenant subdomains,
and identify from the route's domain parameter:

```php
use Illuminate\Http\Request;

$panel
    ->domain('{team}.example.com')
    ->tenant(
        Team::class,
        static fn (Request $request): ?Team => Team::query()
            ->where('slug', $request->route('team'))
            ->first(),
    );
```

A domain string is passed to the router unchanged, so route parameters in it
work exactly as they do in `Route::domain()`.

### Path segment

The tenant is part of the panel's own prefix.

```php
$panel
    ->path('app/{team}')
    ->tenant(
        Team::class,
        static fn (Request $request): ?Team => Team::query()
            ->where('slug', $request->route('team'))
            ->first(),
    )
    ->tenantUrlUsing(
        static fn (Team $team): string => "/app/{$team->slug}",
    );
```

The path becomes the route group prefix verbatim, so `{team}` is an ordinary
route parameter on every route the panel registers.

### Query parameter

The smallest scheme that works, and what the framework's own test suite uses
because it needs no host or route setup:

```php
$panel
    ->tenant(
        Workspace::class,
        static fn (Request $request): ?Workspace => Workspace::query()
            ->find($request->query('workspace')),
    )
    ->tenantUrlUsing(
        static fn (Workspace $workspace, Panel $panel): string => '/'
            .$panel->getPath().'/documents?workspace='.$workspace->getKey(),
    );
```

It resolves and it switches, but see the warning below: framework-generated
URLs do not carry a query string, so links *inside* the panel lose the tenant.

## Making the rest of the panel's URLs carry the tenant

`Resource::url()` and `Page::url()` build every link the panel renders, and both
go through Laravel's route names:

```php
public static function url(
    string $page = 'index',
    Model|int|string|null $record = null,
    Panel|string|null $panel = null,
    Model|int|string|null $parent = null,
): string
```

```php
return route(static::routeName($page, $resolved), $parameters, absolute: false);
```

The `$parameters` array holds the record and, for a nested resource, the
parent. It never holds a tenant — the framework does not know the name of your
route parameter, and adding one would be guessing.

For a **path segment** or a **subdomain**, the answer is Laravel's own
`URL::defaults()`. Set it from the route parameter in a middleware in the
panel's stack, and every subsequent `route()` call fills the parameter without
being told:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

final class DefaultTenantUrlParameter
{
    public function handle(Request $request, Closure $next): Response
    {
        $team = $request->route('team');

        if (is_string($team)) {
            URL::defaults(['team' => $team]);
        }

        return $next($request);
    }
}
```

```php
$panel->middleware(['web', DefaultTenantUrlParameter::class]);
```

`Panel::middleware()` **replaces** the base stack, so include `web` yourself.
The panel's stack runs before `ResolvePanel` and `ResolveTenant`, which is why
this middleware reads the route parameter rather than `Tenancy::current()` —
nothing is bound yet.

With that in place:

```php
DocumentResource::url('edit', $document);   // '/app/acme/documents/12/edit'
```

For a **query parameter**, there is no equivalent. `URL::defaults()` only fills
route parameters, and `route()` appends a query string only for arguments you
pass it explicitly. Either append the tenant yourself at every call site, or —
much better — move to a path segment or a subdomain.

## Sessions across subdomains

Subdomain tenancy has a consequence worth deciding before you commit: cookies
and sessions are per-host by default. A user signed in on `acme.example.test`
is not signed in on `beta.example.test` unless you set:

```bash
SESSION_DOMAIN=.example.test
```

That is either what you want — a hard boundary between tenants — or a support
ticket every day. It is much easier to decide now than to change later.

## Notes

- **No builder, no switcher.** `canSwitchTenants` requires at least one entry
  with a non-null `url`. A panel with tenancy and no `tenantUrlUsing()` still
  resolves, authorizes and scopes perfectly; it offers no way to move.
- **Return an absolute URL for cross-host schemes.** The switcher renders a
  plain `<a>` precisely so a cross-origin move works; a relative path would
  stay on the current host and land back in the tenant the user was already in.
- **The builder is not called for tenants the user cannot enter.** It runs over
  `Tenancy::availableTo()`, which is already filtered by the user model.
- **`getTenantUrl()` is called once per tenant per panel render**, inside a
  shared-prop closure. Keep it string building; it is not a place for a query.
- **A cluster changes paths, not route names.** URLs built from route names
  keep working when a resource adopts a cluster, which is another reason to
  build tenant URLs from `$panel->getPath()` rather than hard-coding `/app`.
- **`Panel::path()` trims slashes only.** `->path('app/{team}')` is passed to
  the route group as a prefix unchanged, so the parameter has to be one your
  routes and your `URL::defaults()` agree on.

## See also

- [Tenant Switcher](switcher.md)
- [Tenant Resolver](resolver.md)
- [Tenancy Concepts](concepts.md)
- [Using with stancl/tenancy](stancl-tenancy.md)
- [Panel IDs, Paths, and Domains](../panels/ids-paths-domains.md)
- [Middleware and Guards](../panels/middleware.md)
- [URLs and Route Names](../resources/urls-routes.md)
- [Routing](../concepts/routing.md)
