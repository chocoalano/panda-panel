# Global Search Integration

The command palette above every panel page searches the resources that opted in. Opting in is one property; everything else — the title, the extra lines, the link, the limits — has a default you can override. This page covers all of it.

## Opting in

```php
use PandaPanel\Resources\Resource;

final class UserResource extends Resource
{
    protected static string $model = User::class;

    /** @var list<string> */
    protected static array $globalSearchAttributes = ['name', 'email'];

    // ...
}
```

That is the whole opt-in. Typing two or more characters into the palette now matches users by name or email, groups the hits under **Users**, and links each one at the view page.

A resource with no attributes declared is not searched at all. Adding a resource to a panel must never silently widen what a search can reach, so the default is `[]` and the palette simply does not know about it.

## The declarations

| Property | Type | Default | Effect |
| --- | --- | --- | --- |
| `$globalSearchAttributes` | `list<string>` | `[]` | The columns that may be matched. Empty means not searchable |
| `$globalSearchLimit` | `int` | `5` | How many hits this resource may contribute |
| `$globalSearchSort` | `int` | `0` | Presentation order among resources; ties break on slug |

```php
/** @var list<string> */
protected static array $globalSearchAttributes = ['title', 'author.name'];

protected static int $globalSearchLimit = 3;

protected static int $globalSearchSort = 10;
```

Their accessors are public, because the search service asks:

```php
public static function globalSearchAttributes(): array;    // list<string>
public static function isGloballySearchable(): bool;       // globalSearchAttributes() !== []
public static function globalSearchLimit(): int;
public static function globalSearchSort(): int;
```

## Searching a relation

An attribute containing a dot searches the relation it names:

```php
/** @var list<string> */
protected static array $globalSearchAttributes = ['title', 'author.name', 'author.email'];
```

`title` becomes `where('title', 'like', "%term%")`; `author.name` becomes `whereHas('author', fn ($q) => $q->where('name', 'like', "%term%"))`. Every attribute is OR-ed together inside one grouped `where`, so the resource's own scope is not widened by the search.

Attributes are a whitelist. Nothing from the request ever reaches a column name — the term is only ever a bound value.

## The query

```php
use Illuminate\Database\Eloquent\Builder;

public static function globalSearchQuery(): Builder
{
    return static::query();
}
```

Searching starts from `Resource::query()` like every other lookup, so a tenant, team, or per-panel scope narrows the palette exactly as it narrows a list. Override it to search a narrower set than the pages reach:

```php
use Illuminate\Database\Eloquent\Builder;

public static function globalSearchQuery(): Builder
{
    return static::query()->whereNotNull('published_at');
}
```

## What a hit looks like

```php
use Illuminate\Database\Eloquent\Model;

public static function globalSearchResultTitle(Model $record): string;

/**
 * @return array<string, string>
 */
public static function globalSearchResultDetails(Model $record): array;

public static function globalSearchResultUrl(Model $record): string;
```

**Title** defaults to `recordTitle()`, so a resource that declared `$recordTitleAttribute` already has the right one.

**Details** are the extra lines under the title, and they are empty by default. Scalars only: this is presentation, and it crosses to Vue.

```php
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * @return array<string, string>
 */
public static function globalSearchResultDetails(Model $record): array
{
    return [
        'Email' => (string) $record->getAttribute('email'),
        'Role' => $record instanceof User && $record->is_admin ? 'Administrator' : 'Member',
    ];
}
```

**URL** defaults to the view page when the resource declares one, the edit page otherwise, and the index as a last resort — each of which authorizes independently when it is opened.

```php
use Illuminate\Database\Eloquent\Model;

public static function globalSearchResultUrl(Model $record): string
{
    return static::url('edit', $record);
}
```

## Panel-level settings

```php
$panel->globalSearch(
    enabled: true,
    limit: 50,
    debounce: 300,
    keyBindings: ['mod+k'],
);
```

| Argument | Default | Meaning |
| --- | --- | --- |
| `$enabled` | `true` | Whether the palette exists in this panel |
| `$limit` | `50` | Hits across the whole search, not per resource |
| `$debounce` | `300` | Milliseconds before typing becomes a request |
| `$keyBindings` | `['mod+k']` | `mod` is the platform's command key |

The two limits compose: each resource is asked for at most `min($resource::globalSearchLimit(), $remaining)`, and the loop stops once the panel's budget is spent. So a panel limit of 2 caps a resource that allows 5.

The palette is on by default but searches nothing until some resource declares attributes, so a panel with nothing searchable shows no palette at all — the frontend is told `search.enabled: false` and given no URL.

```php
$panel->globalSearch(false);   // off entirely
```

## The endpoint

One route per panel: `GET {panel path}/search`, named `panel.{panelId}.search`, behind the panel's own middleware.

```bash
curl '/admin/search?q=Lovelace'
```

```json
{
  "groups": [
    {
      "resource": "users",
      "label": "Users",
      "icon": "users",
      "results": [
        {
          "title": "Ada Lovelace",
          "url": "/admin/users/1",
          "details": { "Email": "ada@example.com", "Role": "Administrator" }
        }
      ]
    }
  ]
}
```

It answers JSON rather than an Inertia page: the palette asks while the user is typing, and re-rendering the page they are on to answer would be absurd. The group carries the resource's slug, its plural label, and its navigation icon — resolved on the server, so the frontend has nothing left to decide.

## Rules that always hold

- **A term shorter than two characters returns `[]`.** So does a blank one. `q` is validated as a nullable string of at most 255 characters.
- **`canViewAny()` is checked before a resource is queried,** so a refused resource costs nothing and reveals nothing.
- **Every result URL is a page the same policy already allows,** and that page authorizes again when it is opened.
- **Order is deterministic:** by `globalSearchSort()`, then by slug. Two requests produce the same grouping.
- **A resource contributing no hits is absent** rather than present and empty.
- **No model, closure, or query ever reaches the payload.** By the time a `GlobalSearchResult` exists, the record has been authorized and reduced to a title, a URL, and a map of strings.

## Notes

- **Matching is `LIKE %term%`.** There is no full-text index, no ranking, and no fuzzy matching — a large table wants a real search engine, and `globalSearchQuery()` is where you would point one.
- **The palette is not a way around the resource scope.** It reads through `query()`, so a record the panel cannot open is a record the palette cannot find.
- **Details are strings.** A `Carbon` or a model in that array is a serialization problem, not a formatting one; format on the server.
- **`$globalSearchLimit` is per resource, `Panel::globalSearch(limit:)` is per search.** A resource that seems capped below its own limit is being cut off by the panel budget, most likely because an earlier resource used it up.
- **Sorting ties break on slug, not on registration order,** so adding a resource never reshuffles the groups above it.

## See also

- [Creating resources](creating-resources.md)
- [Resource queries](queries.md)
- [Labels and navigation](labels-navigation.md)
- [Resource authorization](authorization.md)
- [URLs and route names](urls-routes.md)
- [Search overview](../search/overview.md)
- [Searchable resources](../search/searchable-resources.md)
- [Search security](../search/security.md)
- [Table search](../tables/search.md)
