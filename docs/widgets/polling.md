# Polling

A polling widget refreshes itself on an interval without the reader doing anything. You reach for it when the number moves while somebody is watching it — a queue depth, an active-sessions count, orders in the last hour — and going stale would be misleading rather than merely old.

Polling is off by default. It is a request every interval for every open tab, which is worth it for a queue depth and absurd for a total that changes twice a day, so it is opt-in per widget rather than a setting somebody turns on once and forgets.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Widgets;

use Illuminate\Support\Facades\DB;
use PandaPanel\Widgets\StatsWidget;
use PandaPanel\Widgets\Support\Stat;

final class QueueDepth extends StatsWidget
{
    protected static ?int $pollingInterval = 15;

    protected static ?string $heading = 'Queue';

    /**
     * @return list<Stat>
     */
    public function stats(): array
    {
        return [
            Stat::make('Waiting', DB::table('jobs')->count()),
        ];
    }
}
```

Every fifteen seconds the page reloads its widget props and the figure updates in place.

## The API

| Member | Signature | Default |
| --- | --- | --- |
| `$pollingInterval` | `protected static ?int` | `null` |
| `pollingInterval()` | `public static function pollingInterval(): ?int` | `$pollingInterval` |

```php
use App\Panels\Admin\Widgets\QueueDepth;

QueueDepth::pollingInterval();   // 15
```

The value is in **seconds**. `null` means no polling, and so does any value `<= 0` — the frontend checks both before starting a timer.

It is serialized into the widget definition as `polling`:

```php
[
    'id' => 'queue-depth',
    'polling' => 15,     // seconds, or null
    // ...
]
```

## What a poll actually does

`WidgetShell.vue` starts a `setInterval` when the widget mounts and clears it before unmount. Each tick is an Inertia partial reload:

```ts
router.reload({ only: props.reloadProps });
```

The props a poll asks for are decided by the page, not by the widget — a widget's data *is* a prop of the page it sits on:

| Page | Props reloaded |
| --- | --- |
| `Dashboard`, standalone `Page` | `['widgets', 'widgetData']` |
| resource pages | `['headerWidgets', 'footerWidgets', 'widgetData']` |

Two consequences follow, and both are intentional.

**A poll refreshes every widget on the page**, not only the one whose timer fired. The props are per page, so re-resolving one widget means re-resolving the set. Three widgets polling at 15, 30 and 60 seconds means the whole set is recomputed on every one of those ticks.

**Everything else on the page is preserved.** `only` keeps the request to the widget props, and a partial reload keeps the rest of the client state — which is what stops a refresh from throwing away a half-typed filter or a scroll position.

There is no per-widget endpoint. One would have to re-resolve the page's authorization, its filters and its page context before it could say anything true, which is the page's job.

## Choosing an interval

| Interval | Reasonable for |
| --- | --- |
| 5–15s | queue depth, active jobs, live session counts |
| 30–60s | today's orders, sign-ups, error counts |
| 300s+ | anything a reader would not notice going stale |
| `null` | totals, lifetime counts, monthly aggregates |

The cost is a request per interval per open tab, and the whole page's widget props are recomputed each time. A dashboard with one widget at 5 seconds is a dashboard doing twelve full widget resolutions a minute per viewer.

```php
/**
 * A minute. These are counts of a table that changes when somebody signs up,
 * which is often enough to be worth watching and rare enough that a shorter
 * interval would be a request for nothing.
 */
protected static ?int $pollingInterval = 60;
```

## Mixing polling with other features

### With filters

A poll is a reload of the current URL, so the query string — and therefore every [filter](filters.md) — is unchanged. A widget filtered to "last 7 days" keeps answering for the last 7 days when it polls.

### With lazy widgets

A poll asks for `widgetData`, which is exactly the prop a [lazy](lazy-loading.md) widget's payload lives in, so lazy payloads are re-resolved on every poll. A widget that is both lazy and polling runs its slow query every interval; pick one.

### With authorization

The whole page is re-rendered on the server, so `canView()` runs again. A widget that starts refusing mid-session disappears at the next poll rather than showing a stale figure.

### With table widgets

A `TableWidget` re-runs `query()` on every poll, at the page and sort the URL currently holds. A polling table widget is a query on an interval; be sure it is indexed.

## Testing

The interval is part of the definition, so assert it there:

```php
it('polls only the widgets that asked to', function (): void {
    $this->actingAs($this->admin)->get('/admin')
        ->assertInertia(function (AssertableInertia $page): void {
            $polling = array_column($page->toArray()['props']['widgets'], 'polling', 'id');

            expect($polling['user-stats'])->toBe(60)
                ->and($polling['recent-users'])->toBeNull();
        });
});
```

The timer itself is frontend behaviour and is not exercised by the PHP test suite.

## Gotchas

- The interval is seconds, not milliseconds. `pollingInterval = 500` is once every eight minutes, not twice a second.
- `$pollingInterval` is static. It cannot vary per user or per filter value.
- One widget polling reloads them all. If a dashboard has an expensive widget, do not put a fast poller beside it.
- The timer is a plain `setInterval`. There is no jitter, no backoff on failure, and no pause when the tab is hidden — a background tab keeps polling.
- Each mounted widget with an interval runs its own timer, so two widgets at 15 seconds means two reloads every 15 seconds, not one.
- Polling is not broadcasting. For push updates, use the panel's notification and broadcasting integration instead of a short interval. See [Broadcasting](../notifications/broadcasting.md).
- A poll is a full server render of the page's widget props: authorization, filters and queries all run again. That is what makes it correct, and what makes it worth measuring.

## See also

- [Widgets overview](overview.md)
- [Lazy loading](lazy-loading.md)
- [Filters](filters.md)
- [Authorization](authorization.md)
- [Table widgets](tables.md)
- [Dashboards](../panels/dashboards.md)
- [Broadcasting](../notifications/broadcasting.md)
