# Lazy Widgets

A lazy widget ships its definition with the page and its data in a follow-up request. You reach for it when a widget's query is slow enough that holding the whole dashboard behind it is the wrong trade — a grouped aggregate over a year of rows, a count across a join, anything you would not want in the critical path of a first paint.

The mechanism is Inertia's deferred props. One deferred prop carries every lazy widget's payload on the page, keyed by widget id, and the renderer shows a skeleton until it lands.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Widgets;

use PandaPanel\Widgets\ChartWidget;
use PandaPanel\Widgets\Support\ChartSeries;

final class UserGrowth extends ChartWidget
{
    protected static bool $lazy = true;

    public function labels(): array { /* ... */ }

    public function series(): array { /* ... */ }
}
```

That is the whole API. The page renders immediately with a skeleton in that cell, and the chart appears when its data arrives.

## The API

| Member | Signature | Default |
| --- | --- | --- |
| `$lazy` | `protected static bool` | `false` |
| `isLazy()` | `public static function isLazy(): bool` | `$lazy` |

```php
use App\Panels\Admin\Widgets\UserGrowth;

UserGrowth::isLazy();   // true
```

Lazy is off by default because it costs a second request. A widget that answers in a few milliseconds should just answer.

## What happens on the wire

### The first response

The definition is serialized without its data:

```php
[
    'id' => 'user-growth',
    'type' => 'chart',
    'lazy' => true,
    'heading' => 'Sign-ups',
    'data' => null,          // withheld
    // ...
]
```

and the deferred prop is **absent** from the props object entirely. Not null — absent. `Inertia::defer()` excludes it from the initial payload, which is why every Vue component that reads it declares it optional:

```vue
withDefaults(
    defineProps<{
        widgets: WidgetDefinition[];
        widgetData?: WidgetData | null;
    }>(),
    { widgetData: null },
);
```

A required prop here would warn on the first paint of every dashboard.

### The follow-up request

Inertia issues a partial reload asking for that prop alone:

```text
GET /admin
X-Inertia: true
X-Inertia-Version: {asset version}
X-Inertia-Partial-Component: panel/Dashboard
X-Inertia-Partial-Data: widgetData
```

and the response carries every lazy widget's payload keyed by id:

```json
{
  "props": {
    "widgetData": {
      "user-growth": { "variant": "area", "labels": ["Apr"], "series": [], "options": {}, "maxHeight": 200 }
    }
  }
}
```

The renderer reads `widget.data ?? resolved[widget.id] ?? null`, so an eager widget uses its inline data and a lazy one fills in from the deferred payload.

## What the reader sees meanwhile

`WidgetRenderer.vue` draws a skeleton sized to the type it is waiting for:

| Type | Skeleton |
| --- | --- |
| stats | three cards |
| table | five rows |
| chart | four rows |
| custom | three rows |

The widget's heading, description and filters are drawn immediately — they are part of the definition, not the data — so the page does not reflow when the payload lands. A custom widget's own component is not mounted until then, so its props are never undefined.

## One prop, all widgets

```php
public function deferred(): mixed
```

`PandaPanel\Pages\WidgetCollection::deferred()` returns a single `Inertia::defer()` closure covering every lazy widget on the page, or `null` when none are lazy — so a page with nothing to defer does not advertise a second request it will never make.

Resource pages merge their header and footer collections for this:

```php
$header = WidgetCollection::for($this->headerWidgets(), $context);
$footer = WidgetCollection::for($this->footerWidgets(), $context);

return [
    'headerWidgets' => $header->definitions(),
    'footerWidgets' => $footer->definitions(),
    'widgetData' => $header->merge($footer)->deferred(),
];
```

One prop rather than one per widget is a deliberate trade: one extra request for the page instead of five, at the cost of the widgets landing together rather than one at a time.

## Authorization still runs first

`canView()` is checked before the widget is constructed, so a widget the user may not see is neither in the definitions nor in the deferred payload, and its `data()` never runs:

```php
WidgetCollection::for([ForbiddenStatsWidget::class])->definitions();   // []
WidgetCollection::for([ForbiddenStatsWidget::class])->deferred();      // null
```

Deferring does not weaken this. See [Authorization](authorization.md).

## Testing a lazy widget

Assert the withholding on the first response and the payload on the follow-up. This is how the package tests it:

```php
it('withholds a lazy widget payload from the first response', function (): void {
    $this->get('/admin')->assertInertia(function (AssertableInertia $page): void {
        $widget = collect($page->toArray()['props']['widgets'])
            ->firstWhere('id', UserGrowth::id());

        expect($widget['lazy'])->toBeTrue()
            ->and($widget['data'])->toBeNull();
    });
});

it('omits the deferred prop entirely from the first response', function (): void {
    $this->get('/admin')->assertInertia(function (AssertableInertia $page): void {
        expect($page->toArray()['props'])->not->toHaveKey('widgetData');
    });
});

it('resolves the lazy payload on the follow-up request', function (): void {
    // A partial reload must send the asset version, otherwise Inertia answers 409
    // and asks the browser to do a full visit instead.
    $version = $this->get('/admin')->viewData('page')['version'];

    $this->get('/admin', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $version,
        'X-Inertia-Partial-Component' => 'panel/Dashboard',
        'X-Inertia-Partial-Data' => 'widgetData',
    ])
        ->assertOk()
        ->assertJsonPath('props.widgetData.'.UserGrowth::id().'.variant', 'area');
});
```

You can also call the widget directly, which bypasses laziness entirely — `data()` does not know or care:

```php
expect((new UserGrowth)->data()['maxHeight'])->toBe(200);
```

## Gotchas

- The deferred prop is shared, so the slowest lazy widget on the page decides when all of them appear. Two widgets, one fast and one slow, are better as one lazy and one eager.
- `X-Inertia-Version` must be sent on the follow-up request, or Inertia answers `409` and forces a full visit. The browser does this for you; a hand-written test must not forget it.
- A [poll](polling.md) reloads `['widgets', 'widgetData']`, so lazy payloads are re-resolved on every poll. A widget that is both lazy and polling runs its slow query every interval.
- `$lazy` is static. A widget cannot decide to be lazy for some users.
- Laziness changes when the query runs, not whether it runs. It is not a cache and not a budget — a slow query is still slow, just off the critical path.
- Lazy data is never stored by `panel:cache`. The manifest caches class names only. See [Caching](../concepts/caching.md).

## See also

- [Widgets overview](overview.md)
- [Polling](polling.md)
- [Authorization](authorization.md)
- [Dashboards](../panels/dashboards.md)
- [Server metadata to Vue](../concepts/metadata-to-vue.md)
- [Prefetching](../pages-navigation/prefetching.md)
