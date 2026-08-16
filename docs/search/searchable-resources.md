# Searchable Resources

A resource joins the panel's command palette by declaring which of its attributes may be matched. Everything else — the group's label and icon, the query, the per-resource limit, the position among the other groups — has a default, and you override only what differs. This page covers how a resource opts in and every knob it owns.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Users;

use App\Models\User;
use App\Panels\Admin\Resources\Users\Pages\EditUser;
use App\Panels\Admin\Resources\Users\Pages\ListUsers;
use App\Panels\Admin\Resources\Users\Pages\ViewUser;
use PandaPanel\Resources\Resource;

final class UserResource extends Resource
{
    protected static string $model = User::class;

    protected static ?string $navigationIcon = 'users';

    /** @var list<string> */
    protected static array $globalSearchAttributes = ['name', 'email'];

    /**
     * @return array<string, class-string>
     */
    public static function pages(): array
    {
        return [
            'index' => ListUsers::class,
            'view' => ViewUser::class,
            'edit' => EditUser::class,
        ];
    }

    // ... table(), form()
}
```

That resource is now searchable. `UserResource::isGloballySearchable()` returns true because the attribute list is not empty, and the palette groups its hits under **Users** with the `users` icon.

## Opting in

```php
/**
 * @var list<string>
 */
protected static array $globalSearchAttributes = [];
```

The default is an empty array, and an empty array means "not searchable". `PandaPanel\Search\GlobalSearch` reads this through two public accessors:

```php
public static function globalSearchAttributes(): array;   // list<string>
public static function isGloballySearchable(): bool;      // globalSearchAttributes() !== []
```

`isGloballySearchable()` is derived, not stored: there is no separate on/off switch that could disagree with the attribute list. Overriding `globalSearchAttributes()` instead of setting the property works and is sometimes what you want:

```php
/**
 * @return list<string>
 */
public static function globalSearchAttributes(): array
{
    return auth()->user()?->is_admin === true
        ? ['name', 'email', 'internal_reference']
        : ['name'];
}
```

Two things read `isGloballySearchable()`: the search itself, and `SharePanelData`, which turns the palette off entirely for a panel where nothing opted in.

## How many hits this resource may contribute

```php
protected static int $globalSearchLimit = 5;

public static function globalSearchLimit(): int;
```

Five by default. This is a per-resource cap, evaluated against the panel's remaining budget:

```php
// Asked for at most this many rows:
min($resource::globalSearchLimit(), $remaining)
```

so a resource that allows 10 still returns 2 when the panel's `limit` has only 2 left. See [Panel search configuration](panel-configuration.md) for how the budget is spent.

```php
// A resource with very distinguishable records needs fewer lines.
protected static int $globalSearchLimit = 3;

// An orders resource where the term is usually a reference number.
protected static int $globalSearchLimit = 10;
```

## Where this resource's group appears

```php
protected static int $globalSearchSort = 0;

public static function globalSearchSort(): int;
```

Resources are sorted by `[globalSearchSort(), slug()]`, ascending. Lower sorts first; equal sorts break on the slug, so the order is identical on every request and independent of discovery order.

```php
final class UserResource extends Resource
{
    protected static int $globalSearchSort = 0;    // first
}

final class OrderResource extends Resource
{
    protected static int $globalSearchSort = 10;   // after users
}
```

Sort order also decides who spends the panel's budget first: an early resource with a large limit can leave nothing for a later one.

## The query the search runs

```php
use Illuminate\Database\Eloquent\Builder;

public static function globalSearchQuery(): Builder
{
    return static::query();
}
```

Searching starts from `Resource::query()` like every other lookup in the framework, which means:

- the resource's `$with` eager loads apply, so details that read a relation do not fire a query per row;
- a tenant scope applies, because `query()` runs `applyTenantScope()`;
- a per-panel `modifyQueryUsing()` applies, because `query()` runs the panel's `ResourceConfiguration`;
- an override of `query()` on the resource applies, so a record the list cannot show is a record the palette cannot find.

Override it to search a narrower set than the pages reach:

```php
use Illuminate\Database\Eloquent\Builder;

public static function globalSearchQuery(): Builder
{
    return static::query()
        ->whereNotNull('published_at')
        ->latest('published_at');
}
```

The term is applied *after* your builder is returned, as one grouped `where` containing the OR-ed attribute conditions. Your own constraints are therefore never widened by the search: the grouping keeps `A AND (B OR C)` from collapsing into `A OR B OR C`.

Two things you cannot do here: you cannot see the term (it is not a parameter, and the caller escapes it for `LIKE` before applying it), and you cannot change the limit (the caller applies it).

## What a group looks like

`GlobalSearch` builds one array per resource that produced hits:

```php
[
    'resource' => $resource::slug(),          // 'users'
    'label' => $resource::pluralLabel(),      // 'Users'
    'icon' => $resource::navigationIcon(),    // 'users' or null
    'results' => [ /* GlobalSearchResult::toArray() */ ],
]
```

| Key | Source | Notes |
| --- | --- | --- |
| `resource` | `Resource::slug()` | the slug **in this panel**, so a resource registered under a different slug groups under it |
| `label` | `Resource::pluralLabel()` | falls back to `defaultPluralLabel()`; follows a per-panel `pluralLabel()` configuration |
| `icon` | `Resource::navigationIcon()` | the class's own `$navigationIcon`; resolved to a component by the icon registry |
| `results` | one entry per record | `title`, `url`, `details` |

A resource that returns no hits is skipped entirely rather than sent as an empty group.

## Being skipped

A resource is not searched when any of these is true:

| Condition | Consequence |
| --- | --- |
| It does not extend `PandaPanel\Resources\Resource` | skipped — the search calls static methods that only that base class guarantees |
| `isGloballySearchable()` is false | skipped, and it costs no query |
| `canViewAny()` is false | skipped before any query runs |
| The panel's budget is already spent | the loop has stopped; later resources are not reached |

`canViewAny()` routes through `Resource::authorize()` and `PandaPanel\Support\PolicyGate`, so a panel with `strictAuthorization()` on will throw `PanelAuthorizationException` here for a model with no policy — the same as anywhere else in the panel.

## Testing that a resource is searchable

```php
use PandaPanel\Core\PanelManager;
use PandaPanel\Search\GlobalSearch;

it('searches only resources that declared attributes', function (): void {
    expect(UserResource::isGloballySearchable())->toBeTrue()
        ->and(UserResource::globalSearchAttributes())->toBe(['name', 'email']);
});

it('finds a record by any declared attribute', function (): void {
    app(PanelManager::class)->setCurrentPanel(panel('admin'));

    $groups = app(GlobalSearch::class)->for(panel('admin'), 'Lovelace');

    expect($groups[0]['resource'])->toBe('users')
        ->and($groups[0]['results'][0]['title'])->toBe('Ada Lovelace');
});
```

Through HTTP it is one request:

```php
$groups = $this->actingAs($admin)
    ->getJson('/admin/search?q=Lovelace')
    ->json('groups');
```

## Gotchas

- **A nested resource cannot use the default query.** `Resource::query()` on a resource with `$parentResource` starts from the parent's relation and calls `ParentRecord::require()`, which throws `PanelRegistrationException` when no parent is bound — and a search request binds none. Either leave nested resources out of the palette, or override both `globalSearchQuery()` (to start from the model directly) and `globalSearchResultUrl()` (to supply a parent). See [Search result URLs](result-urls.md).
- **The group icon ignores a per-panel icon.** `ResourceConfiguration::navigationIcon()` changes the sidebar entry, not the search group: `GlobalSearch` calls `Resource::navigationIcon()`, which returns the class's own property. The label and slug do follow the per-panel configuration.
- **`$globalSearchLimit` is per resource; the panel's `limit` is per search.** A resource that seems capped below its own limit is usually being cut off by the budget an earlier resource spent.
- **A large limit early in the sort order starves later groups.** Sorting is not just presentation.
- **Opting in does not add a table search box.** The two are unrelated: table search is declared per column with `->searchable()`. See [Table search](../tables/search.md).
- **`globalSearchQuery()` runs on every keystroke that survives the debounce.** Keep it to something the database can answer with an index; the search adds `LIKE %escaped-term%` conditions on top of whatever you wrote.

## See also

- [Global search overview](overview.md)
- [Search attributes](attributes.md)
- [Relationship search](relationships.md)
- [Search result details](result-details.md)
- [Search result URLs](result-urls.md)
- [Search security](security.md)
- [Resource queries](../resources/queries.md)
- [Resource authorization](../resources/authorization.md)
- [Per-panel resource configuration](../resources/per-panel-configuration.md)
- [Labels and navigation](../resources/labels-navigation.md)
