# Model Binding

Panda Panel does not use Laravel's implicit route model binding. A `{record}` segment arrives as a raw key and is resolved by the resource, through the same `query()` every other read goes through. This page covers the lookup methods, where the key comes from, and what a page does with the record once it has one.

## The minimal case

Nothing to configure. A record page receives the key as a string and asks the resource for the record:

```php
use App\Panels\Admin\Resources\Posts\PostResource;

$post = PostResource::resolveRecord(7);          // Model, or ModelNotFoundException
$maybe = PostResource::findRecord(7);            // Model|null
$many = PostResource::findRecords([7, 8, 9]);    // Collection<int, Model>
```

All three start from `Resource::query()`, so a record the resource scope excludes does not exist as far as the resource is concerned. That is the whole reason binding is not left to Laravel: implicit binding resolves from the model, which would reach a record the panel's own query deliberately hides.

## Why it is not implicit binding

```php
public static function resolveRecord(int|string $key): Model
{
    return static::recordQuery()->findOrFail($key);
}
```

`recordQuery()` is `query()` with one narrow exception: a resource that declares `$softDeletes = true` has `SoftDeletingScope` lifted, and nothing else. Tenant, module, and permission scopes still apply exactly as they do to a live record. Without the lift a deleted record could never be opened, and so could never be restored — the only route to it is the one the default scope hides.

The index does not lift it. That is the whole difference between the list and the lookup: an index shows what is current, a record page was asked for one record by key and should answer about it.

## The lookup methods

| Method | Signature | Returns | Used by |
| --- | --- | --- | --- |
| `resolveRecord()` | `public static function resolveRecord(int\|string $key): Model` | the record, or throws `ModelNotFoundException` (a 404) | record pages |
| `findRecord()` | `public static function findRecord(int\|string $key): ?Model` | the record or `null` | the action endpoints |
| `findRecords()` | `public static function findRecords(array $keys): Collection` | the records that resolved | bulk actions |
| `recordQuery()` | `protected static function recordQuery(): Builder` | `query()` with the soft-delete scope lifted where declared | the three above |
| `resolveSingularRecord()` | `public static function resolveSingularRecord(): Model` | `query()->firstOrFail()` | [singular resources](singular-resources.md) |

The nullable form exists because callers decide for themselves what a missing record means: the record action endpoint answers 404, while a bulk operation compares the count it got back with the number of keys it was sent and refuses the whole selection if they differ.

```php
// PanelActionController, roughly:
$records = $resource::findRecords($keys);

abort_if($records->count() !== count($keys), 404, 'Some records could not be found.');
```

## Where the key comes from

| Surface | Key | Shape |
| --- | --- | --- |
| A record page | the `{record}` route segment | always a string |
| `actions/record`, `actions/cell`, `actions/infolist` | the `record` field in the payload | string or int, anything else is 422 |
| `actions/bulk` | the `records` array | non-scalar entries are dropped |
| A nested resource | `{parentRecord}` and `{record}` | the parent is bound by middleware before the resource query runs |

Keys are validated as scalars before they reach a lookup. Passing an array through would turn `find()` into a collection lookup and quietly change the meaning of the request.

**The primary key is the key.** Lookups use `find()` and `whereKey()`, and URLs are built from `$record->getKey()`. A model overriding `getRouteKeyName()` does not change what the panel puts in a URL or resolves from one — panel URLs are id-based.

## Using the record on a page

`ViewRecord` and `EditRecord` already resolve it. A custom page adds the concern:

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Posts\Pages;

use App\Panels\Admin\Resources\Posts\PostResource;
use Inertia\Inertia;
use Inertia\Response;
use PandaPanel\Resources\Concerns\InteractsWithRecord;
use PandaPanel\Resources\Pages\ResourcePage;

final class AuditPost extends ResourcePage
{
    use InteractsWithRecord;

    protected static string $resource = PostResource::class;

    protected static ?string $routePath = '{record}/audit';

    public function render(string $record): Response
    {
        $post = $this->resolveRecord($record);

        return Inertia::render('panel/Page', [
            'page' => [
                'title' => 'Audit',
                'heading' => 'Audit',
                'subheading' => null,
                'breadcrumbs' => [],
                'headerActions' => [],
                'scope' => self::renderHookScope(),
            ],
            'revisions' => $post->revisions()->count(),
        ]);
    }
}
```

`PandaPanel\Resources\Concerns\InteractsWithRecord` gives the page four members:

```php
protected function resolveRecord(int|string|null $key = null): Model;
protected function getRecord(): Model;
protected function hasRecord(): bool;
protected function authorizeRecord(Model $record): bool;
```

- **`resolveRecord()`** resolves, authorizes, and remembers. Call it once at the top of `render()`. A `null` key means a singular resource: its route carries no `{record}` because there is nothing to choose between, so the resource resolves its one record itself.
- **`getRecord()`** returns what was resolved. It throws `LogicException` rather than returning null — reaching for a record before resolving one is a programming error, not a state to handle.
- **`hasRecord()`** answers whether one has been resolved yet.
- **`authorizeRecord()`** decides the ability. It is `canView()` by default, because a page showing a record the user may not view would be a leak whatever else it does.

The record is held for the request, so a page reading it in three places runs one query.

### Asking for a different ability

`EditRecord` overrides the ability, and any page may:

```php
use Illuminate\Database\Eloquent\Model;

protected function authorizeRecord(Model $record): bool
{
    return static::$resource::canEdit($record);
}
```

A failed check is a 403 from `abort_unless()` inside `resolveRecord()`, before the page has rendered anything.

## Naming a record

Breadcrumbs, headings, sub-navigation, and global search results all ask the resource what one record is called:

```php
public static function recordTitle(Model $record): string
```

The default reads `$recordTitleAttribute`, which is `'name'` when the resource does not say otherwise, and falls back to the primary key when the value is not a scalar.

```php
final class PostResource extends Resource
{
    protected static ?string $recordTitleAttribute = 'title';
}
```

Override the method when the title is built rather than stored:

```php
use Illuminate\Database\Eloquent\Model;

public static function recordTitle(Model $record): string
{
    return sprintf('#%s — %s', $record->getKey(), $record->getAttribute('title'));
}
```

## Notes

- **A record outside the resource query is a 404, not a 403.** It is not that you may not have it; as far as this resource is concerned it does not exist. The same holds for a panel that narrowed the resource with [per-panel configuration](per-panel-configuration.md).
- **`resolveRecord()` throws, `findRecord()` does not.** `ModelNotFoundException` is what turns into the 404, so a custom page calling `findRecord()` is responsible for its own `abort_if()`.
- **`getRecord()` before `resolveRecord()` is a `LogicException`,** and the message names the page class. Resolve at the top of `render()`.
- **`resolveRecord()` on the concern is not `Resource::resolveRecord()`.** The concern's version also authorizes and memoizes; the resource's version only looks up. Inside a page, use the concern's.
- **Custom route keys are not supported.** Panel URLs carry the primary key.
- **A nested resource needs its parent bound first.** The middleware does that from the `{parentRecord}` segment; an action endpoint takes it from the `parent` field in the payload. See [Nested resources](nested-resources.md).

## See also

- [Resource queries](queries.md)
- [CRUD pages](crud-pages.md)
- [Resource pages](resource-pages.md)
- [Soft deletes](soft-deletes.md)
- [Singular resources](singular-resources.md)
- [Nested resources](nested-resources.md)
- [Resource authorization](authorization.md)
- [Resource API reference](api.md)
- [Actions](../actions/overview.md)
