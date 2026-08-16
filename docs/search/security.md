# Search Security

A search box that reaches across every resource in a panel is the easiest place in an admin panel to leak a record. This page states exactly what global search guarantees, what it does not, and where the remaining decisions are yours. Read it before opting a resource with sensitive rows into the palette.

## A minimal working example

The guarantees are testable, and this is what proving them looks like:

```php
<?php

declare(strict_types=1);

use App\Models\User;

it('refuses a panel the user may not enter', function (): void {
    $this->actingAs(User::factory()->create())   // not an admin
        ->getJson('/admin/search?q=Lovelace')
        ->assertForbidden();
});

it('sends a guest to login rather than searching', function (): void {
    $this->get('/admin/search?q=Lovelace')->assertRedirect(route('login'));
});

it('never leaves a model or a hash in the payload', function (): void {
    $encoded = $this->actingAs(User::factory()->admin()->create())
        ->getJson('/admin/search?q=Lovelace')
        ->content();

    expect($encoded)->not->toContain('App\\\\Models')
        ->and($encoded)->not->toContain('$2y$');
});
```

## Four layers

A search request passes through four independent narrowings, in this order.

| Layer | Enforced by | Effect |
| --- | --- | --- |
| Transport | the panel's middleware stack | a guest is redirected, a refused user gets 403, an unfinished two-factor challenge never reaches the controller |
| Resource | `Resource::canViewAny()` | a resource the user may not view at all is skipped before it is queried |
| Row | `Resource::query()` | tenant scope, per-panel query modification and the resource's own scope all apply |
| Payload | `GlobalSearchResult` | a hit is a title, a URL and a map of strings — no model, no query, no closure |

### Transport

The route is registered inside the panel's group, so it carries the panel's whole stack:

```php
$this->router->get('search', PanelSearchController::class)->name('search');
```

which means `web`, whatever `auth()` added (`auth`, and `verified` unless you passed `false`), then `ResolvePanel`, `RequireTwoFactor` and `RequireEmailCode`, and `ResolveTenant` for a tenant-scoped panel. `ResolvePanel` calls `abort_unless($panel->isAccessibleTo($request->user()), 403)`: a signed-in user who is not allowed into the panel gets a 403, never a redirect, and never a result.

There is nothing special about the search endpoint's protection — that is the point. It is the same door every other panel route uses.

### Resource

```php
if (! $resource::isGloballySearchable() || ! $resource::canViewAny()) {
    continue;
}
```

Both checks happen before any query is built, so a refused resource costs nothing and reveals nothing — not even a timing difference from a query that returned zero rows.

`canViewAny()` routes through `Resource::authorize()` and `PandaPanel\Support\PolicyGate`, the single place panel abilities are asked. With `strictAuthorization()` on, a model with no policy — or a policy with no `viewAny()` and no `before()` — raises `PandaPanel\Exceptions\PanelAuthorizationException` and the request fails loudly, rather than a missing policy reading as a working deny.

### Row

`globalSearchQuery()` starts at `Resource::query()`, and `query()` is where scoping lives:

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

So the palette narrows exactly as a list narrows. A resource that scopes itself cannot be widened through search:

```php
use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Resources\Resource;

final class ScopedUserResource extends Resource
{
    /**
     * The palette reads through `globalSearchQuery()`, which starts at `query()`.
     *
     * @var list<string>
     */
    protected static array $globalSearchAttributes = ['name', 'email'];

    public static function query(): Builder
    {
        return parent::query()->where('is_admin', false);
    }
}
```

Admins are unfindable through that resource, whatever the term.

### Payload

```php
final readonly class GlobalSearchResult
{
    public function __construct(
        public string $title,
        public string $url,
        public array $details = [],
    ) {}
}
```

The model is gone by the time anything is serialized. The frontend receives a title, a server-generated URL, and the details the resource chose — so no attribute the resource did not name can appear, and no client-side decision about what a record is or where it lives is possible.

## The term never becomes SQL

`$globalSearchAttributes` is a whitelist. The term is only ever a bound value:

```php
$like = '%'.$this->escapeLike($term).'%';

$query->orWhere($attribute, 'like', $like);
```

The column name comes from the resource class; the request contributes `q` and nothing else. There is no `searchable` parameter, no column list in the query string, and no way for a request to name a table.

The controller validates what little it does accept:

```php
$validated = $request->validate([
    'q' => ['nullable', 'string', 'max:255'],
]);
```

Over 255 characters is a 422. A term shorter than two characters after trimming returns `[]` without touching the database, which also keeps a one-character probe from being a cheap way to enumerate a table.

The term is escaped for `LIKE`: `%`, `_`, and `\` are treated as literal characters before the framework wraps the pattern in leading and trailing `%`. A term is still matched as a substring, so a searchable column holding a secret is a searchable secret.

## What global search does not check

**Per-record authorization.** `canView($record)` is never called. Membership of a result set is decided by `globalSearchQuery()`, not by a policy. The consequence is concrete: if a policy allows `viewAny` broadly but `view` narrowly, a user can see a record's title and details in the palette, click it, and get a 403 from the page.

That is a deliberate trade — a policy check per row would make the palette a per-record authorization loop on every keystroke — but it means the *scope*, not the policy, is your access control here. Two ways to line them up:

```php
use Illuminate\Database\Eloquent\Builder;

// Express the same rule the policy expresses, in SQL.
public static function globalSearchQuery(): Builder
{
    return static::query()->where('team_id', auth()->user()?->team_id);
}
```

```php
// Or keep the resource out of the palette when the rule cannot be expressed
// as a query.
protected static array $globalSearchAttributes = [];
```

**What is in the details.** `globalSearchResultDetails()` output is shown to every user who can search the resource. A detail is not filtered by any policy. Do not put a token, an internal note, a hash or another user's contact information in there.

**Rate limiting.** The framework adds none. Every keystroke past the debounce is a query; a scripted client can ask far faster than a person types. Add a limiter to the panel's middleware if that matters:

```php
$panel->middleware(['web', 'throttle:120,1']);
```

Note that `middleware()` replaces the base stack rather than appending to it, so restate `web`.

**Escaping the search term for `LIKE`.** The built-in search escapes `%`, `_`, and `\`. If you replace the search logic in your own query, keep that property.

## A review checklist

| Question | Where to look |
| --- | --- |
| Does every searchable resource have a policy with `viewAny()`? | `Resource::canViewAny()`, and turn on `strictAuthorization()` to find gaps |
| Can `viewAny` be true while `view` is false? | If so, narrow `globalSearchQuery()` to match the policy |
| Does the resource's `query()` carry the tenant scope? | `$tenantRelationship`, and [Tenancy resource scoping](../tenancy/resource-scoping.md) |
| Does an overridden `globalSearchQuery()` still start at `query()`? | Anything starting at `Model::query()` drops the tenant and per-panel scopes |
| Is any searched column sensitive? | `$globalSearchAttributes` — substring matching turns a column into an oracle |
| Is anything sensitive in the details? | `globalSearchResultDetails()` |
| Does the panel need a rate limit? | `Panel::middleware()` |

## Gotchas

- **A nested resource in the palette is a bug waiting to happen.** Its `query()` demands a bound parent record, which a search request has none of; overriding `globalSearchQuery()` to start from the model instead silently drops the tenant scope. See [Search result URLs](result-urls.md).
- **`strictAuthorization()` turns a missing policy into a failed search,** not an empty one — the exception is a `RuntimeException`, so the request 500s. That is the intended loud failure, but it means enabling strict mode can break the palette before it breaks a page.
- **A failed request renders as "Nothing found."** The palette swallows non-2xx responses, so a 403 after a permission change and a genuine empty result look identical to the user. Read the log, not the dialog.
- **Turning search off does not remove the route.** It answers `{"groups": []}` for that panel, because `GlobalSearch::for()` checks `hasGlobalSearch()` first.
- **Hiding a resource from navigation does not hide it from search.** `$shouldRegisterNavigation` and `$globalSearchAttributes` are unrelated declarations.
- **Details and titles are escaped by Vue,** so a record whose name contains markup cannot inject anything into the palette. Do not rely on that for anything else: nothing else about a result is sanitized.

## See also

- [Global search overview](overview.md)
- [Searchable resources](searchable-resources.md)
- [Search attributes](attributes.md)
- [Search result details](result-details.md)
- [Search result URLs](result-urls.md)
- [Panel search configuration](panel-configuration.md)
- [Authorization](../concepts/authorization.md)
- [Resource authorization](../resources/authorization.md)
- [Tenancy security checklist](../tenancy/security-checklist.md)
- [Negative security tests](../testing/negative-security-tests.md)
