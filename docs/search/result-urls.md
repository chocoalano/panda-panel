# Search Result URLs

Every hit in the palette carries a URL the server generated. The frontend never builds one, never resolves a route and never decides where a record lives — it renders a link. This page covers the default destination, how to change it, and the cases where the default cannot work.

## A minimal working example

Nothing is required: a resource that opted into search already produces working links.

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
}
```

A hit for user 1 gets `"url": "/admin/users/1"` — the view page, because this resource declares one.

## The default

```php
use Illuminate\Database\Eloquent\Model;

public static function globalSearchResultUrl(Model $record): string
{
    $pages = static::pages();

    if (array_key_exists('view', $pages)) {
        return static::url('view', $record);
    }

    return array_key_exists('edit', $pages)
        ? static::url('edit', $record)
        : static::url();
}
```

Three steps, in order:

| Condition | Destination |
| --- | --- |
| `pages()` has a `view` key | `static::url('view', $record)` |
| otherwise, `pages()` has an `edit` key | `static::url('edit', $record)` |
| otherwise | `static::url()` — the index |

The index fallback is what keeps a resource with neither page from producing a link to a route that was never registered. Each destination authorizes independently when it is opened, so the chain is about reachability, not permission.

## Overriding it

```php
use Illuminate\Database\Eloquent\Model;

public static function globalSearchResultUrl(Model $record): string
{
    return static::url('edit', $record);
}
```

Any key from `pages()` works, including a custom page:

```php
use Illuminate\Database\Eloquent\Model;

/**
 * @return array<string, class-string>
 */
public static function pages(): array
{
    return [
        'index' => ListOrders::class,
        'view' => ViewOrder::class,
        'invoice' => OrderInvoice::class,
    ];
}

public static function globalSearchResultUrl(Model $record): string
{
    return static::url('invoice', $record);
}
```

Decide per record when the right page depends on the record's state:

```php
use Illuminate\Database\Eloquent\Model;

public static function globalSearchResultUrl(Model $record): string
{
    return $record->getAttribute('status') === 'draft'
        ? static::url('edit', $record)
        : static::url('view', $record);
}
```

Point at another resource, or at a page outside the resource, when that is genuinely where the record is worked on:

```php
use App\Panels\Admin\Resources\Invoices\InvoiceResource;
use Illuminate\Database\Eloquent\Model;

public static function globalSearchResultUrl(Model $record): string
{
    return InvoiceResource::url('view', $record->getAttribute('invoice_id'));
}
```

## `Resource::url()`

The one way to build a panel URL. It is route-name based, so a panel that changes its path does not leave a hand-built string behind:

```php
public static function url(
    string $page = 'index',
    Model|int|string|null $record = null,
    Panel|string|null $panel = null,
    Model|int|string|null $parent = null,
): string;
```

| Argument | Type | Default | Meaning |
| --- | --- | --- | --- |
| `$page` | `string` | `'index'` | a key from `pages()` |
| `$record` | `Model\|int\|string\|null` | `null` | the record; a model is reduced to its key |
| `$panel` | `Panel\|string\|null` | `null` | which panel to build for; the current one by default |
| `$parent` | `Model\|int\|string\|null` | `null` | the parent record, for a nested resource |

It returns a **relative** URL (`route(..., absolute: false)`), which is what an Inertia visit wants. The route name it resolves is `panel.{panelId}.resources.{slug}.{page}`, where the slug is the one this resource was registered under *in that panel*.

Two guards worth knowing about:

- `assertRegisteredIn()` throws `PandaPanel\Exceptions\PanelRegistrationException` when the resource is not registered in the target panel. Panel isolation is only real if asking for a URL in the wrong panel fails loudly.
- `resolvePanel()` throws when there is no current panel and none was passed, rather than guessing one.

The related reader:

```php
public static function routeName(string $page = 'index', Panel|string|null $panel = null): string;
// 'panel.admin.resources.users.view'
```

## Singular resources

A singular resource's pages carry no `{record}` — there is nothing to choose between — and `url()` drops the record parameter for them:

```php
if ($record !== null && ! static::isSingular()) {
    $parameters['record'] = $record instanceof Model ? $record->getKey() : $record;
}
```

So the default chain works unchanged: every hit from a singular resource links to the same page, which is correct, because there is only one record. Passing a record is harmless.

## Nested resources

A nested resource — one declaring `$parentResource` — is the case where the defaults cannot work, and it is worth being blunt about it:

- `Resource::query()` starts from the parent's relation and calls `ParentRecord::require()`, which throws when no parent is bound to the request. A search request binds none, so `globalSearchQuery()` fails.
- `Resource::url()` also calls `ParentRecord::require()` for a nested resource, so even a query that succeeded could not produce a link.

Search a nested resource only if you override both, and supply the parent yourself:

```php
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Not `static::query()`: that one demands a parent record, and a search
 * request has none.
 */
public static function globalSearchQuery(): Builder
{
    return Task::query()->with('project');
}

public static function globalSearchResultUrl(Model $record): string
{
    return static::url('view', $record, parent: $record->getAttribute('project_id'));
}
```

Be aware that starting from the model rather than from `query()` skips the tenant scope and any per-panel query modification, so restate them:

```php
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Tenancy\Tenancy;

public static function globalSearchQuery(): Builder
{
    $tenant = Tenancy::require();

    return Task::query()
        ->with('project')
        ->whereHas('project', static fn (Builder $project): Builder => $project->whereBelongsTo($tenant));
}
```

If that feels like too much rope, leaving nested resources out of the palette and searching the parent instead is a reasonable answer.

## How the palette uses the URL

`GlobalSearchResult` keeps only relative URLs and the `http`, `https`, `mailto`, and `tel` schemes; an unsafe custom URL becomes `#`. `resources/js/panel/components/PanelSearch.vue` applies the same guard before rendering `<Link>` or calling `router.visit()`. Consequences:

- **The URL must be one Inertia can visit** — a relative path inside the application. An absolute URL to another host will be fetched as an Inertia request and fail.
- **The panel's `fullPageUrls()` patterns do not apply here.** They are evaluated by the navigation builder for sidebar items only; a search result is always an Inertia visit.
- **Any navigation closes the palette,** including one started elsewhere.

## Gotchas

- **A findable record is not necessarily a viewable one.** The default chain does not call `canView()`; it picks a page. The page authorizes on open, so a user can see a hit and then get a 403. Narrow `globalSearchQuery()` if a record should not appear at all — see [Search security](security.md).
- **A resource with only `index` in `pages()` links every hit to the same list.** That is the intended fallback, not a bug, but it makes the palette useless for that resource; give it a view page or override the URL.
- **`url()` throws when the resource is not registered in the current panel.** This surfaces as a failed search request, which the palette renders as "Nothing found." Check the log.
- **Custom page keys are free-form.** `pages()` maps `'invoice' => …`; nothing validates that `globalSearchResultUrl()` names a key that exists, and an unknown one throws a route-not-defined error at search time.
- **The URL is generated per hit.** It is a route lookup, not a database query, so the cost is small — but a `globalSearchResultUrl()` that queries something is a query per row.

## See also

- [Search result details](result-details.md)
- [Searchable resources](searchable-resources.md)
- [Search security](security.md)
- [Global search overview](overview.md)
- [URLs and route names](../resources/urls-routes.md)
- [Nested resources](../resources/nested-resources.md)
- [Singular resources](../resources/singular-resources.md)
- [Resource pages](../resources/resource-pages.md)
