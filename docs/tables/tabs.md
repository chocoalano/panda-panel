# Filter Tabs

Tabs sit above a resource list and split it into named views: all, drafts, archived. Reach for them when a handful of well-known scopes are how people actually use the list — a tab is one click, where the equivalent filter is two and a dropdown.

A tab is a named scope on the resource's own query, never a query of its own, so a tenant or permission scope still applies to whatever it shows.

## A minimal working example

Tabs are declared on the **list page**, not on the table schema:

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Posts\Pages;

use App\Models\Post;
use App\Panels\Admin\Resources\Posts\PostResource;
use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Resources\Pages\ListRecords;
use PandaPanel\Tables\Tab;

final class ListPosts extends ListRecords
{
    protected static string $resource = PostResource::class;

    /**
     * @return array<string, Tab>
     */
    public function tabs(): array
    {
        return [
            'all' => Tab::make('all')->badge(static fn (): int => Post::query()->count()),

            'published' => Tab::make('published')
                ->icon('check')
                ->query(static fn (Builder $query): Builder => $query->whereNotNull('published_at')),

            'drafts' => Tab::make('drafts')
                ->query(static fn (Builder $query): Builder => $query->whereNull('published_at')),
        ];
    }
}
```

The page now renders three tabs. Selecting one writes `?tab=drafts` and resets the page number.

## `ListRecords::tabs()`

```php
use PandaPanel\Resources\Pages\ListRecords;
use PandaPanel\Tables\Tab;

/**
 * @return array<string, Tab>
 */
public function tabs(): array
```

Returning `[]` — the default — means no tabs, and the page ships `tabs: []`.

The **array key** is the value the tab takes in the URL. The **first entry** is the fallback: when the request names no tab, or names one the page does not declare, `reset($tabs)` is the active one. A query string is user input, so an unknown key falls back rather than erroring, exactly as an unknown sort column does.

Make the array key and `Tab::make()`'s first argument the same string. The active tab is matched by array key on the way in, and the frontend writes `?tab=` from the serialized `key`, which is the `Tab`'s own — so if the two disagree, clicking the tab selects nothing and the page falls back to the first.

## `Tab`

```php
use Closure;
use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Tables\Tab;

Tab::make(string $key, ?string $label = null): self
Tab::query(Closure(Builder): Builder $callback): self
Tab::badge(string|int|Closure|null $badge): self
Tab::icon(?string $icon): self
Tab::getLabel(): string
Tab::apply(Builder $query): Builder
Tab::resolveBadge(): string|int|null
Tab::toArray(bool $active): array
```

`$key` is also a public readonly property: `$tab->key`.

| Method | Default | Notes |
| --- | --- | --- |
| `make($key, $label)` | label `null` | an omitted label becomes `Str::headline($key)` — `all` reads as "All" |
| `query($callback)` | none | the tab narrows nothing without it, which is what makes an "all" tab an "all" tab |
| `badge($badge)` | `null` | a string, an int, a closure returning either, or `null` for no badge |
| `icon($icon)` | `null` | an icon **registry key**, never a component path |

```php
use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Tables\Tab;

// A label that is not the key.
Tab::make('needs_review', 'Needs review')
    ->icon('alert-triangle')
    ->badge(static fn (): int => Post::query()->where('flagged', true)->count())
    ->query(static fn (Builder $query): Builder => $query->where('flagged', true)),

// A static badge, for a number that is not worth a query.
Tab::make('archived')->badge('90d'),
```

### The query closure

`query()` receives `Resource::query()` — the resource's own builder, with its scope, its eager loads, and its tenant narrowing already applied — and must return a builder:

```php
use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Tables\Tab;

Tab::make('mine')->query(
    static fn (Builder $query): Builder => $query->where('author_id', auth()->id()),
);
```

`Tab::apply()` returns the query unchanged when no closure was given, so a bare `Tab::make('all')` shows everything the resource shows.

### Badges

`resolveBadge()` calls the closure on the server and only the scalar crosses to Vue, like a navigation badge — nothing executable is ever serialized. The frontend hides the badge when it is `null` or an empty string.

A badge closure runs on every render of the page, once per tab, whether or not that tab is active. Three tabs with three `count()` closures are three queries per page load; cache them if that matters.

## What the page sends

`tabs` is a list, in declaration order, each entry the result of `Tab::toArray($active)`:

```php
[
    ['key' => 'all', 'label' => 'All', 'icon' => null, 'badge' => 128, 'active' => true],
    ['key' => 'published', 'label' => 'Published', 'icon' => 'check', 'badge' => null, 'active' => false],
    ['key' => 'drafts', 'label' => 'Drafts', 'icon' => null, 'badge' => null, 'active' => false],
]
```

`active` is the server's decision, like every other piece of table state. A tab is a URL, not local state: pressing one navigates, which is what keeps back, forward, refresh, and bookmark behaving.

## What the tab scopes

The list page builds the scoped query once and uses it for three things:

```php
$tab = $this->activeTab($request);
$scoped = fn (): Builder => $tab === null
    ? static::$resource::query()
    : $tab->apply(static::$resource::query());

$query = $scoped();
$records = $tableQuery->paginate($query);
```

- **The rows**, through `TableQuery::paginate()`, so search, filters, sort, and pagination all apply on top of the tab.
- **The summaries**, because `summaries()` is given the same builder the paginator was.
- **The page's widgets**, through `PageContext::forQuery($scoped)` — a widget counts what the user is looking at rather than the whole table. See [Widget filters](../widgets/filters.md).

## Tabs, filters, and the URL

`?tab=` is a top-level query parameter, alongside `search`, `sort`, `page`, and `filters`. Selecting a tab resets `page` to 1 — a tab narrows the result set, so page 7 of the previous one is rarely where the user wants to land — and leaves everything else where it was. A filter set under one tab is still set under the next.

Tabs are not remembered in the session. `persistFiltersInSession()`, `persistSearchInSession()`, and `persistSortInSession()` cover the table's own state; the tab is read straight from the request each time and falls back to the first.

## Notes

- **A tab narrows the resource query; it never replaces it.** That is the whole design: authorization, tenancy, and `Resource::$with` are applied before the closure sees the builder, so a tab cannot widen what the user may see.
- **The first declared tab is the default.** Put the broadest one first, or declare an explicit "all".
- **Tabs are a list-page feature.** `TableSchema` has no `tabs()`; relation manager tables and table widgets do not have them. Narrow those with a [filter](filters.md) instead.
- **A tab is not a filter indicator.** It does not appear among `filterIndicators`, and clearing the filters does not change the tab.
- **Badges are resolved even for inactive tabs**, which is what makes them useful and what makes them cost queries.
- **An icon is a registry key.** `panel:icons` finds the names by reading the source, so a key that was not compiled in renders as no icon rather than as a broken import. See [Component registries](../concepts/component-registries.md).

## See also

- [Filters](filters.md)
- [Search](search.md)
- [Grouping](grouping.md)
- [Persisted state](persisted-state.md)
- [List, create, view and edit pages](../resources/crud-pages.md)
- [Resource queries](../resources/queries.md)
- [Widget filters](../widgets/filters.md)
- [Tables overview](overview.md)
