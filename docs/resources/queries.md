# Resource Queries

`Resource::query()` is the single entry point for every record a resource can reach. Override it once and the scope applies to the list, the record pages, the actions, the bulk operations, and global search alike. This page covers that method, the declarations that feed it, and the layers that narrow it further.

## The minimal override

```php
use Illuminate\Database\Eloquent\Builder;

public static function query(): Builder
{
    return parent::query()->where('team_id', currentTeamId());
}
```

That is the whole mechanism. Nothing else has to be touched: a record with another team's id is a **404** on the view page, on the edit page, from the action endpoint, and from the command palette — not a row that was filtered out of a list but still reachable by URL.

**Always call `parent::query()`.** The base method applies the resource's eager loads, the nested-resource scope, the tenant scope, and the panel's own narrowing. Starting from `Model::query()` instead silently drops all four.

## What the base method does

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

Three things in order: the starting builder (the model's, or the parent record's relation for a [nested resource](nested-resources.md)), the tenant scope, and the current panel's configured narrowing.

## Everything that reads through it

| Surface | Path |
| --- | --- |
| The index | `ListRecords::render()` starts from `query()` and hands it to the table layer |
| View, edit, and custom record pages | `resolveRecord()` → `recordQuery()` → `query()` |
| Record, cell, and infolist actions | `findRecord()` → `recordQuery()` → `query()` |
| Bulk actions | `findRecords()` → `recordQuery()` → `query()` |
| Global search | `globalSearchQuery()`, which returns `query()` |
| File uploads attached to a record | `query()` directly |
| Exports | the same table query the list built |

`recordQuery()` is `query()` with exactly one difference — `SoftDeletingScope` is lifted for a resource that declares `$softDeletes` — so a trashed record can be opened and restored while tenant, module, and permission scopes still apply. See [Soft deletes](soft-deletes.md).

## Eager loading

```php
/** @var list<string> */
protected static array $with = ['author', 'tags'];
```

Applied on every query the resource builds, so serializing a column can never trigger a lazy load per row. This is not an optimisation to add later: with `Model::shouldBeStrict()` on outside production, a column reading an unloaded relation fails loudly, and without strict mode it quietly costs a query per record.

The list page's serialization is expected to run a fixed number of queries whatever the page size — the package's own test asserts that five rows and thirty-five rows produce the same query count.

For a relation needed by one column only on one panel, prefer `$with`; for something conditional, add it in the override:

```php
use Illuminate\Database\Eloquent\Builder;

public static function query(): Builder
{
    return parent::query()->withCount('comments');
}
```

## Narrowing per panel

A resource registered in two panels can mean something narrower in one of them, without a subclass:

```php
use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Resources\ResourceConfiguration;

$panel->resources([
    ResourceConfiguration::for(UserResource::class)
        ->slug('people')
        ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('is_admin', false)),
]);
```

`modifyQueryUsing()` is applied by `query()` itself, last, so it composes with whatever the resource already did. The same 404 guarantee holds: from that panel, an administrator record cannot be opened, edited, deleted, bulk-selected, or found by search. See [Per-panel configuration](per-panel-configuration.md).

## Narrowing per tab

```php
use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Tables\Tab;

/**
 * @return array<string, Tab>
 */
public function tabs(): array
{
    return [
        'all' => Tab::make('all'),
        'drafts' => Tab::make('drafts')
            ->query(static fn (Builder $query): Builder => $query->whereNull('published_at')),
    ];
}
```

A tab receives `Resource::query()` and returns it narrowed. It is a presentation filter, not a security boundary: the record pages know nothing about tabs, so a record hidden by a tab is still reachable by URL. Use `query()` or `modifyQueryUsing()` for anything that must not be reachable.

## Tenant scoping

A resource in a tenant-scoped panel opts in by naming the relationship that leads to the tenant:

```php
final class DocumentResource extends Resource
{
    protected static string $model = Document::class;

    protected static ?string $tenantRelationship = 'workspace';
}
```

```php
public static function tenantRelationship(): ?string;   // reads $tenantRelationship
```

Naming one is the whole opt-in. A resource that names nothing is not scoped, which is right for the two cases that actually occur: a database-per-tenant arrangement where the connection is already the boundary, and a genuinely global table — a plan, a country, a feature flag — that every tenant reads.

The scope is built with `whereHas`, so `belongsTo`, `belongsToMany`, and `hasOneThrough` all work and it is the relationship's own definition that decides what "belongs to this tenant" means. Three conditions must hold, and they fail in this order:

1. The panel has tenancy. If not, the query is returned untouched.
2. The resource names a relationship. If not, untouched.
3. A tenant is bound. If not, `PandaPanel\Tenancy\Tenancy::require()` **throws**.

The third is a throw rather than a skip on purpose. A resource that declared itself tenant-scoped and then ran unscoped would return every tenant's records — the exact failure the mechanism exists to prevent, and one that looks like a working page.

Console commands and queued jobs that legitimately run outside a request enter a tenant explicitly:

```php
use PandaPanel\Tenancy\Tenancy;

Tenancy::for($workspace, static function (): void {
    DocumentResource::query()->each(/* ... */);
});
```

Two registration mistakes fail loudly rather than silently: a `$tenantRelationship` naming a method that does not exist on the model, and one naming a method that exists but does not return a `Relation` — a scope or an accessor. Both throw `PanelRegistrationException` naming the resource, the model, and the property.

## The table layer on top

`ListRecords` does not apply search, sorting, filters, or pagination itself. It hands the resource query to `PandaPanel\Tables\TableQuery`, which reads the URL and applies only what the schema declared: a column that is not `searchable()` is not searched, a column that is not `sortable()` is not sorted, an unknown filter name is ignored, and `perPage` is clamped to the declared options. Nothing from the request ever reaches a column name.

Because the same builder is used for the page and for its summaries, an aggregate reflects exactly what the page is a page of. See [Tables](../tables/overview.md) and [Filters](../tables/filters.md).

## Global search

```php
use Illuminate\Database\Eloquent\Builder;

public static function globalSearchQuery(): Builder
{
    return static::query();
}
```

Searching starts from the same query as everything else, so a resource scope narrows the palette exactly as it narrows a list. Override it to search a different starting point — a published-only scope, say — while keeping the resource's own reach for its pages. See [Global search](global-search.md).

## Notes

- **A resource that overrides `query()` and forgets `parent::query()` loses the panel's narrowing, the tenant scope, the nested parent scope, and `$with`,** all silently. The failure looks like a working page showing too much.
- **The scope is a 404, not a filtered row.** That is what makes it provable: a guessed id is refused by the same rule that hides the link.
- **Do not scope in a page.** A page-level `where` covers that page only, and the action endpoint does not go through pages at all — a record hidden from the list would still be deletable.
- **Do not scope in the table schema.** The table layer runs after the resource query and describes presentation; the record pages never see it.
- **`query()` is static and runs per call.** It is not memoized, so it is safe to call twice, and an override doing expensive work will do it twice.
- **Outside a panel there is no configuration to apply.** `configurationIn(null)` is `null`, so `query()` called from a console command returns the resource's own query plus tenancy.

## See also

- [Creating resources](creating-resources.md)
- [Model binding](model-binding.md)
- [Per-panel configuration](per-panel-configuration.md)
- [Nested resources](nested-resources.md)
- [Soft deletes](soft-deletes.md)
- [Global search](global-search.md)
- [Resource authorization](authorization.md)
- [Tables](../tables/overview.md)
- [Tenancy resource scoping](../tenancy/resource-scoping.md)
- [Scope leak troubleshooting](../troubleshooting/tenancy-scope-leaks.md)
