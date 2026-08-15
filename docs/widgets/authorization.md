# Widget Authorization

A widget decides for itself whether the current user may see it, by overriding one static method. You reach for it whenever a widget reports something not everybody on the panel should read — revenue on a dashboard shared with support staff, a queue depth only operators need, anything scoped to a role.

The check runs **before** the widget is constructed and therefore before `data()`, so an unauthorized widget never executes its queries. That ordering is the point: a widget that were merely hidden would still cost a query and could still leak counts through timing.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Widgets;

use PandaPanel\Widgets\StatsWidget;
use PandaPanel\Widgets\Support\Stat;

final class RevenueStats extends StatsWidget
{
    public static function canView(): bool
    {
        return auth()->user()?->can('view-revenue') === true;
    }

    /**
     * @return list<Stat>
     */
    public function stats(): array
    {
        return [Stat::make('Revenue', 12045)->format(prefix: '£')];
    }
}
```

A user without the ability sees no gap where the widget was — it is absent from the response entirely.

## `canView()`

```php
public static function canView(): bool
```

Defined on `PandaPanel\Widgets\Widget`, returning `true`. A widget is open by default and restricts itself by overriding this; the page it appears on authorizes independently.

Anything Laravel offers works, because it is ordinary PHP running inside the request:

```php
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

// A gate or ability
public static function canView(): bool
{
    return Gate::allows('view-operations');
}

// A role flag on the user model
public static function canView(): bool
{
    return Auth::user()?->is_admin === true;
}

// A guest-proof check, for a widget that might be reused elsewhere
public static function canView(): bool
{
    return Auth::user() instanceof User;
}

// Configuration rather than a user
public static function canView(): bool
{
    return config('services.stripe.key') !== null;
}
```

It is **static** and takes **no arguments**. It runs before the widget object exists, so it can see the request, the session and the authenticated user, but it cannot see the widget's page context or its filters. Per-record decisions are covered below.

## Where the check runs

`PandaPanel\Pages\WidgetCollection` calls it in two places, both before anything else happens:

```php
public static function for(array $classes, ?PageContext $context = null, ?WidgetFilters $filters = null): self
{
    foreach ($classes as $class) {
        if (! $class::canView()) {
            continue;               // never constructed, never queried
        }

        $widget = new $class;
        // ...
    }
}
```

```php
/** @return array<string, FormSchema> */
public static function filterSchemas(array $classes): array
```

`filterSchemas()` skips refused widgets too, so an unauthorized widget's [filter](filters.md) fields are not part of the query-string whitelist either — a parameter for a widget the user may not see is discarded rather than narrowed.

The package proves the ordering rather than assuming it. Its fixture widget throws from `stats()`:

```php
final class ForbiddenStatsWidget extends StatsWidget
{
    public static function canView(): bool
    {
        return false;
    }

    public function stats(): array
    {
        throw new RuntimeException('Data resolved for an unauthorized widget.');
    }
}
```

```php
$collection = WidgetCollection::for([ForbiddenStatsWidget::class]);

expect($collection->definitions())->toBe([])
    ->and($collection->deferred())->toBeNull();
```

Reaching the exception at all would fail the test.

## What a refused widget leaves behind

Nothing. It is dropped from the list before serialization, so the response contains:

- no definition — no id, no heading, no description, no column span;
- no entry in the deferred `widgetData` payload;
- no filter schema in the page's whitelist;
- no query in the log.

This holds for [lazy](lazy-loading.md) widgets too: deferring changes when data is resolved, not whether authorization ran.

## Two layers, and they are separate

| Layer | Method | Enforced by |
| --- | --- | --- |
| The page | `Page::canAccess()` | the route, via `abort_unless(static::canAccess(), 403)` in `Page::render()` |
| The widget | `Widget::canView()` | `WidgetCollection::for()` |

A dashboard that refuses the user 403s at the panel root rather than rendering an empty shell:

```php
use PandaPanel\Pages\Dashboard;

final class FinanceDashboard extends Dashboard
{
    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-finance') === true;
    }
}
```

Before either of them, the panel's own middleware and access rules decide whether the request reaches a panel page at all. See [Panel access](../panels/access.md) and [Page authorization](../pages-navigation/authorization.md).

The layers are independent on purpose. A widget that must not be seen should refuse in `canView()` even when the only page it is currently placed on already refuses — the placement can change, the widget's own rule cannot be forgotten.

## Per-record and per-query decisions

`canView()` cannot make them. It runs before `withPageContext()`, so there is no record and no query to consult. Two honest options:

**Decide on the page.** A resource page names its widgets, and can name fewer:

```php
use PandaPanel\Resources\Pages\ListRecords;

final class ListOrders extends ListRecords
{
    public function headerWidgets(): array
    {
        return auth()->user()?->can('view-revenue') === true
            ? [OrderStats::class, RevenueStats::class]
            : [OrderStats::class];
    }
}
```

**Decide inside `data()`.** The widget still renders, and reports nothing:

```php
use PandaPanel\Widgets\StatsWidget;

final class RecordFinance extends StatsWidget
{
    public function stats(): array
    {
        $record = $this->context()->record();

        if ($record === null || auth()->user()?->can('view', $record) !== true) {
            return [];
        }

        return [/* ... */];
    }
}
```

The second leaves an empty widget with its heading on screen. When the widget's existence is itself sensitive, use the first.

## Linked destinations authorize themselves

A `Stat::url()` produces a link, not a grant:

```php
use App\Panels\Admin\Resources\Users\UserResource;

Stat::make('Total users', User::query()->count())->url(UserResource::url());
```

Following it is an ordinary panel navigation, and the destination runs its own authorization. A widget that a user may see can therefore link to a page they may not — they get a 403 at the destination, which is correct. If the link should not be offered at all, guard the stat:

```php
$stat = Stat::make('Total users', $count);

return [
    UserResource::canViewAny() ? $stat->url(UserResource::url()) : $stat,
];
```

## Testing

```php
use PandaPanel\Pages\WidgetCollection;

it('omits a widget the user may not view', function (): void {
    $collection = WidgetCollection::for([
        CountingStatsWidget::class,
        ForbiddenStatsWidget::class,
    ]);

    expect(array_column($collection->definitions(), 'id'))
        ->toBe([CountingStatsWidget::id()]);
});
```

End to end, assert against the page's props:

```php
$this->actingAs($support)->get('/admin')
    ->assertInertia(fn (AssertableInertia $page) => expect(
        array_column($page->toArray()['props']['widgets'], 'id')
    )->not->toContain('revenue-stats'));
```

## Gotchas

- `canView()` may be called more than once in a request — once while collecting filter schemas, once while resolving the widgets. Keep it cheap; do not put a query in it.
- It runs again on every [poll](polling.md), so a widget that starts refusing mid-session disappears at the next tick rather than going stale.
- `auth()->user()` can be `null` if a widget is ever rendered outside the panel's auth middleware. Use `?->` and compare with `=== true`, as the examples do.
- Returning `false` removes the widget; it does not disable it. There is no read-only or greyed-out state.
- Widget authorization says nothing about the data inside the widget. A widget the user may see, showing a query that was never scoped to them, is still a leak — scope `query()` and the aggregates in `stats()`.
- A widget registered in a panel is only reachable through that panel's pages. Registering the same class in two panels means the check runs in both contexts, so write it to be true of the user rather than of the panel.

## See also

- [Widgets overview](overview.md)
- [Filters](filters.md)
- [Lazy loading](lazy-loading.md)
- [Polling](polling.md)
- [Panel access](../panels/access.md)
- [Page authorization](../pages-navigation/authorization.md)
- [Resource authorization](../resources/authorization.md)
- [Authorization concepts](../concepts/authorization.md)
- [Testing authorization](../testing/authorization.md)
