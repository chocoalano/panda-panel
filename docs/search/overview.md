# Global Search Overview

Global search is the command palette that sits above every page of a panel: one box that reaches across resources instead of one search box per table. Reach for it when a user knows *what* they are looking for but not *where* it lives. A table's own search box answers "which rows of this list"; the palette answers "which record in this panel".

Nothing is searchable until a resource says so. That is the whole opt-in, and it is deliberate: adding a resource to a panel must never silently widen what a search can reach.

## A minimal working example

Declare the attributes that may be matched on a resource:

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Users;

use App\Models\User;
use PandaPanel\Resources\Resource;

final class UserResource extends Resource
{
    protected static string $model = User::class;

    protected static ?string $navigationIcon = 'users';

    /** @var list<string> */
    protected static array $globalSearchAttributes = ['name', 'email'];

    // ... table(), form(), pages()
}
```

Open the panel and press `mod+k` (`⌘K` on macOS, `Ctrl+K` elsewhere), or click the magnifier in the header. Type two characters and hits appear, grouped under **Users**, each linking to that user's view page.

Nothing else is required. The panel's palette is on by default; it simply had nothing to search until this property existed.

## What happens on a keystroke

1. The palette waits `debounce` milliseconds after the last keypress (300 by default), then `GET`s `{panel path}/search?q={term}` with `Accept: application/json`.
2. The request passes through the panel's own middleware — `web`, then whatever `auth()` added, then `ResolvePanel`, which 403s a user the panel refuses.
3. `PandaPanel\Http\Controllers\PanelSearchController` validates `q`, resolves the current panel, and hands both to `PandaPanel\Search\GlobalSearch`.
4. `GlobalSearch` walks the panel's resources: it skips those that did not opt in, skips those whose `canViewAny()` is false, and queries the rest through `globalSearchQuery()`, which starts at `Resource::query()`.
5. Each record becomes a `PandaPanel\Search\GlobalSearchResult` — a title, a URL, and a map of detail strings. No model and no query survives that step.
6. The JSON goes back as groups, one per resource, in a deterministic order.
7. The palette draws them. Arrows walk the flattened list, Enter visits the highlighted result through Inertia.

## The resource side

Everything a resource can declare or override:

| Member | Signature | Default |
| --- | --- | --- |
| `$globalSearchAttributes` | `protected static array` (`list<string>`) | `[]` — not searchable |
| `$globalSearchLimit` | `protected static int` | `5` |
| `$globalSearchSort` | `protected static int` | `0` |
| `globalSearchAttributes()` | `public static function (): array` | returns the property |
| `isGloballySearchable()` | `public static function (): bool` | `globalSearchAttributes() !== []` |
| `globalSearchLimit()` | `public static function (): int` | returns the property |
| `globalSearchSort()` | `public static function (): int` | returns the property |
| `globalSearchQuery()` | `public static function (): Builder` | `static::query()` |
| `globalSearchResultTitle()` | `public static function (Model $record): string` | `static::recordTitle($record)` |
| `globalSearchResultDetails()` | `public static function (Model $record): array` | `[]` |
| `globalSearchResultUrl()` | `public static function (Model $record): string` | view → edit → index |

A full example using every one of them:

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Posts;

use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Resources\Resource;

final class PostResource extends Resource
{
    protected static string $model = Post::class;

    protected static ?string $recordTitleAttribute = 'title';

    /** @var list<string> */
    protected static array $globalSearchAttributes = ['title', 'slug', 'author.name'];

    protected static int $globalSearchLimit = 3;

    protected static int $globalSearchSort = 10;

    /** @var list<string> */
    protected static array $with = ['author'];

    public static function globalSearchQuery(): Builder
    {
        return static::query()->whereNotNull('published_at');
    }

    public static function globalSearchResultTitle(Model $record): string
    {
        return (string) $record->getAttribute('title');
    }

    /**
     * @return array<string, string>
     */
    public static function globalSearchResultDetails(Model $record): array
    {
        $author = $record->getAttribute('author');

        return [
            'Author' => $author instanceof Model ? (string) $author->getAttribute('name') : 'Unknown',
            'Slug' => (string) $record->getAttribute('slug'),
        ];
    }

    public static function globalSearchResultUrl(Model $record): string
    {
        return static::url('edit', $record);
    }

    // ... table(), form(), pages()
}
```

Each of these has its own page: [Searchable resources](searchable-resources.md), [Search attributes](attributes.md), [Relationship search](relationships.md), [Result details](result-details.md), [Result URLs](result-urls.md).

## The panel side

```php
use PandaPanel\Core\Panel;

public function panel(Panel $panel): Panel
{
    return $panel
        ->path('admin')
        ->auth()
        ->globalSearch(
            enabled: true,
            limit: 50,
            debounce: 300,
            keyBindings: ['mod+k'],
        );
}
```

| Argument | Type | Default | Meaning |
| --- | --- | --- | --- |
| `$enabled` | `bool` | `true` | whether this panel has a palette at all |
| `$limit` | `int` | `50` | hits across the whole search, not per resource |
| `$debounce` | `int` | `300` | milliseconds before typing becomes a request |
| `$keyBindings` | `list<string>` | `['mod+k']` | `mod` is the platform's command key |

Readers: `hasGlobalSearch(): bool`, `getGlobalSearchLimit(): int`, `getGlobalSearchDebounce(): int`, `getGlobalSearchKeyBindings(): array`. See [Panel search configuration](panel-configuration.md).

## The endpoint

One route per panel, registered inside the panel's group by `PandaPanel\Routing\PanelRouteRegistrar`:

| | |
| --- | --- |
| Method and path | `GET {panel path}/search` |
| Route name | `panel.{panelId}.search` |
| Controller | `PandaPanel\Http\Controllers\PanelSearchController` |
| Query parameter | `q` — `nullable`, `string`, `max:255` |
| Response | `application/json` |

```bash
curl --cookie jar.txt -H 'Accept: application/json' 'https://example.test/admin/search?q=Lovelace'
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

JSON rather than an Inertia page, because the palette asks while the user is typing and re-rendering the page they are standing on to answer a keystroke would be absurd.

Reach the same answer from PHP — this is what tests do:

```php
use PandaPanel\Search\GlobalSearch;

$groups = app(GlobalSearch::class)->for(panel('admin'), 'Lovelace');
```

`GlobalSearch::for(Panel $panel, string $term): array` is the whole service surface. It returns a `list<array{resource: string, label: string, icon: string|null, results: list<array<string, mixed>>}>`.

## What crosses to Vue

The shell needs to know whether to draw the palette and where to ask. `PandaPanel\Http\Middleware\SharePanelData` puts that in the shared props under `search`:

```ts
export interface PanelSearchSettings {
    enabled: boolean;
    /** Null when searching is off, so there is nothing to ask. */
    url: string | null;
    debounce: number;
    keyBindings: string[];
}

export interface PanelSearchResult {
    title: string;
    url: string;
    details: Record<string, string>;
}

export interface PanelSearchGroup {
    resource: string;
    label: string;
    icon: string | null;
    results: PanelSearchResult[];
}
```

Read them from any panel component:

```ts
import { usePanel } from '@/panel/composables/usePanel';

const { search } = usePanel();

search.value.enabled;      // boolean
search.value.url;          // '/admin/search' or null
search.value.debounce;     // 300
search.value.keyBindings;  // ['mod+k']
```

`enabled` is false when the panel turned searching off **or** when no resource in the panel opted in, and `url` is null whenever `enabled` is false. A palette that could only ever answer nothing is worse than no palette, so `resources/js/panel/components/PanelSearch.vue` renders nothing at all in that case — including the header button.

## The palette

`PanelSearch.vue` is mounted by `PanelHeader.vue`. Its behaviour, in full:

- A key binding from `search.keyBindings` opens it; the header button does the same.
- Typing is debounced by `search.debounce`. Fewer than two non-space characters clears the results without a request.
- One request is in flight at a time. A new keystroke aborts the previous fetch, so a slow early answer cannot overwrite a fast later one.
- `ArrowDown` and `ArrowUp` walk the results flattened in draw order, wrapping at both ends. `Enter` visits the highlighted result with `router.visit()`.
- Every result is an Inertia `<Link>`, so clicking is an ordinary SPA visit.
- Any Inertia navigation closes the palette — the user found what they wanted.
- A non-2xx response, or a malformed body, degrades to no results rather than throwing inside the dialog.

## Limits

Two limits compose. The panel's is a budget for the whole search; a resource's is a cap on its own share:

```php
$panel->globalSearch(limit: 50);          // whole search
protected static int $globalSearchLimit = 5;  // this resource
```

Each resource is asked for `min($resource::globalSearchLimit(), $remaining)` rows, and `$remaining` drops by the number of hits it actually returned. When the budget reaches zero the loop stops, so resources sorted after the ones that consumed it contribute nothing.

## Ordering

Resources are sorted by `globalSearchSort()`, then by `slug()`. Ties break on the slug rather than on registration or filesystem order, so the groups are in the same order on every request and adding a resource never reshuffles the ones above it.

Within a group, rows arrive in whatever order the database returned them; there is no relevance ranking. Add an `orderBy` in `globalSearchQuery()` if a particular order matters.

## What global search is not

- **Not an index.** Matching is `LIKE %escaped-term%` against the columns you named. There is no full-text index, no ranking, no fuzzy matching and no highlighting.
- **Not Scout.** Nothing integrates with Laravel Scout. `globalSearchQuery()` is where you would introduce another engine, but the term is applied by `GlobalSearch` afterwards and is not handed to your query.
- **Not a per-record permission check.** `canViewAny()` gates the resource; individual rows are limited by `query()`, not by `canView()`. See [Search security](security.md).
- **Not paginated.** A limit is a limit; there is no "show more".

## Gotchas

- **Two characters minimum, after trimming.** `mb_strlen(trim($term)) < 2` returns `[]`, and the palette does not even ask. A one-character surname is unreachable.
- **`q` longer than 255 characters is a 422,** not an empty result. The palette never sends one, but a hand-made request can.
- **A resource with no hits is absent from `groups`,** not present with an empty `results` array.
- **The group icon is the resource class's own `$navigationIcon`,** not one a panel set through `ResourceConfiguration::navigationIcon()`. The label and slug *do* follow the per-panel configuration.
- **A failed request looks like "Nothing found."** The palette swallows non-2xx responses, so a 419 after a session expiry reads as an empty result. Check the network tab before assuming the search is wrong.
- **The endpoint is not rate limited by the framework.** It inherits the panel's middleware and nothing more; add `throttle` to the panel's middleware stack if you want one.

## See also

- [Searchable resources](searchable-resources.md)
- [Search attributes](attributes.md)
- [Relationship search](relationships.md)
- [Search result details](result-details.md)
- [Search result URLs](result-urls.md)
- [Panel search configuration](panel-configuration.md)
- [Search security](security.md)
- [Global search integration on a resource](../resources/global-search.md)
- [Table search](../tables/search.md)
- [Server metadata to Vue](../concepts/metadata-to-vue.md)
