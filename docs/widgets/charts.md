# Chart Widgets

A chart widget draws one or more series of numbers against a shared set of labels. You reach for one when the shape of the data is the point — growth over months, volume per day, one metric against another — and a single figure would not say it.

Charts are drawn by a dependency-free inline SVG renderer that ships with the package. What crosses the wire is a *description* of a chart: its labels, its series, a closed set of options, and a height. No charting library is installed, and no configuration crosses as behaviour.

## A minimal working example

```bash
php artisan make:panel-widget UserGrowth --panel=Admin --type=chart
```

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Widgets;

use PandaPanel\Widgets\ChartWidget;
use PandaPanel\Widgets\Support\ChartSeries;

final class UserGrowth extends ChartWidget
{
    protected static ?string $heading = 'Sign-ups';

    /**
     * @return list<string>
     */
    public function labels(): array
    {
        return ['Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep'];
    }

    /**
     * @return list<ChartSeries>
     */
    public function series(): array
    {
        return [
            ChartSeries::make('Sign-ups', [12, 19, 14, 31, 28, 44]),
        ];
    }
}
```

That is a bar chart, six points, one series, on the panel dashboard.

## The class

`PandaPanel\Widgets\ChartWidget` extends `PandaPanel\Widgets\Widget`.

| Member | Signature | Default |
| --- | --- | --- |
| `$columnSpan` | `protected static int\|string\|array` | `['default' => 1, 'md' => 2, 'lg' => 2, 'xl' => 2]` |
| `$variant` | `protected static ChartVariant` | `ChartVariant::Bar` |
| `$maxHeight` | `protected static int` | `220` |
| `type()` | `public static function type(): WidgetType` | `WidgetType::Chart` |
| `labels()` | `abstract public function labels(): array` | — |
| `series()` | `abstract public function series(): array` | — |
| `options()` | `public function options(): ChartOptions` | `ChartOptions::make()` |
| `data()` | `public function data(): array` | see below |

Unlike `StatsWidget`, `ChartWidget` overrides `$columnSpan` — a chart squeezed into one column of a four-column grid is unreadable, so the default is half the grid from `md` up.

```php
use PandaPanel\Widgets\Enums\ChartVariant;

protected static ChartVariant $variant = ChartVariant::Area;

protected static int $maxHeight = 200;
```

`$maxHeight` is the plot height in pixels. A chart with no height of its own grows with its container, and a dashboard of them ends up a page of charts nobody can compare.

### `labels()`

```php
/** @return list<string> */
abstract public function labels(): array
```

One label per point, shared by every series, drawn under the plot and used as the tooltip heading. When a series has more values than there are labels, the extra points are still plotted and their label is blank.

### `series()`

```php
/** @return list<ChartSeries> */
abstract public function series(): array
```

### `options()`

```php
public function options(): ChartOptions
```

Override to say something other than the defaults. See [ChartOptions](#chartoptions).

### `data()`

```php
public function data(): array
```

```php
[
    'variant' => 'area',
    'labels' => ['Apr', 'May', 'Jun'],
    'series' => [['label' => 'Sign-ups', 'values' => [12, 19, 14], 'color' => 'info']],
    'options' => ['legend' => false, 'grid' => true, /* ... */],
    'maxHeight' => 200,
]
```

`labels()` and `series()` are each called once per render. A widget that computes both from one query should memoize — see the full example below.

## Variants

`PandaPanel\Widgets\Enums\ChartVariant` is closed, because each case maps to a shape the compiled-in renderer knows how to draw.

| Case | Value | Drawn as |
| --- | --- | --- |
| `ChartVariant::Bar` | `'bar'` | grouped bars, one group per label |
| `ChartVariant::Line` | `'line'` | a stroked path per series |
| `ChartVariant::Area` | `'area'` | a stroked path per series, filled underneath |
| `ChartVariant::Doughnut` | `'doughnut'` | bars — see the note below |

`Area` is `Line` with `filled` forced on; you do not also need `ChartOptions::filled()` for it.

`Doughnut` is accepted by the type and serialized, but the bundled SVG renderer has no arc drawing in it: anything that is not `line` or `area` falls through to the bar path. If you need a real ring or pie, that is a [custom Vue widget](custom-vue.md), which is honest about being bespoke.

## `ChartSeries`

`PandaPanel\Widgets\Support\ChartSeries` is a `final readonly` value object, immutable like `Stat`.

```php
public function __construct(
    public string $label,
    public array $values,                       // list<int|float>
    public StatColor $color = StatColor::Default,
) {}

/** @param list<int|float> $values */
public static function make(string $label, array $values): self

public function color(StatColor $color): self

/** @return array{label: string, values: list<int|float>, color: string} */
public function toArray(): array
```

```php
use PandaPanel\Widgets\Enums\StatColor;
use PandaPanel\Widgets\Support\ChartSeries;

public function series(): array
{
    return [
        ChartSeries::make('Sign-ups', [12, 19, 14])->color(StatColor::Info),
        ChartSeries::make('Cancellations', [2, 4, 1])->color(StatColor::Danger),
    ];
}
```

The colour comes from the same closed `PandaPanel\Widgets\Enums\StatColor` a stat uses — `Default`, `Success`, `Warning`, `Danger`, `Info` — mapped to literal Tailwind classes on the frontend. Two series left at `Default` are drawn in the same colour, so name a colour per series whenever there is more than one.

Non-finite values (`NAN`, `INF`) are excluded from the axis domain and from the tooltip, and a chart whose series contain nothing finite renders "No data for this period." They are not excluded from the drawn path, so send finite numbers — cast a nullable aggregate to `0` rather than letting `null` become `NAN`.

## `ChartOptions`

`PandaPanel\Widgets\Support\ChartOptions` is a mutable fluent builder — each method returns `$this`.

| Method | Signature | Default | Effect |
| --- | --- | --- | --- |
| `make()` | `public static function make(): self` | — | A new instance with the defaults below. |
| `legend()` | `public function legend(bool $legend = true): self` | `true` | Draws the series key above the plot. |
| `grid()` | `public function grid(bool $grid = true): self` | `true` | Draws four dashed horizontal grid lines. |
| `stacked()` | `public function stacked(bool $stacked = true): self` | `false` | Stacks bars into one column per label instead of side by side. |
| `filled()` | `public function filled(bool $filled = true): self` | `false` | Fills under a line. Implied by `ChartVariant::Area`. |
| `curved()` | `public function curved(bool $curved = true): self` | `false` | Smooths a line. Needs at least three points. |
| `labels()` | `public function labels(bool $labels = true): self` | `false` | Requests per-point value labels. See the note below. |
| `range()` | `public function range(?float $min, ?float $max): self` | `null`, `null` | Pins the value axis. |
| `format()` | `public function format(?string $prefix = null, ?string $suffix = null): self` | `null`, `null` | What a value wears when written out. |
| `toArray()` | `public function toArray(): array` | — | The serialized options. |

```php
use PandaPanel\Widgets\Support\ChartOptions;

public function options(): ChartOptions
{
    return ChartOptions::make()
        ->legend(false)
        ->curved()
        ->filled();
}
```

### `range()`

```php
ChartOptions::make()->range(0, 100);
ChartOptions::make()->range(0, null);     // pin the floor, let the ceiling follow the data
```

Worth setting when a chart is read against a target rather than against itself: an axis that rescales to the data makes every week look the same shape.

When both ends are given, they are used exactly. When one or neither is given, the renderer derives the missing end from the data, keeps zero visible so bars have a meaningful baseline, and adds 8% breathing room so the highest point does not touch the top edge.

### `format()`

```php
ChartOptions::make()->format(prefix: '£');
ChartOptions::make()->format(suffix: '%');
```

Applied to the values shown in the tooltip. The number itself is grouped by `Intl.NumberFormat` with at most two fraction digits.

### `labels()`

`labels(true)` is serialized as `options.labels`, but the bundled renderer does not currently draw per-point value labels — values are shown in the tooltip that follows the pointer or keyboard focus across categories. Setting it is harmless and forward-compatible; do not rely on it to put numbers on the plot today.

Note the name collision: `ChartWidget::labels()` returns the x-axis labels and is required, while `ChartOptions::labels()` is this boolean.

## A full example

This is `examples/app/Panels/Admin/Widgets/UserGrowth.php`, which is filtered, lazy, and builds both `labels()` and `series()` from one query:

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Widgets;

use App\Models\User;
use Illuminate\Support\Facades\Date;
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Widgets\ChartWidget;
use PandaPanel\Widgets\Enums\ChartVariant;
use PandaPanel\Widgets\Enums\StatColor;
use PandaPanel\Widgets\Support\ChartOptions;
use PandaPanel\Widgets\Support\ChartSeries;

final class UserGrowth extends ChartWidget
{
    protected static int $sort = 30;

    protected static bool $lazy = true;

    protected static ChartVariant $variant = ChartVariant::Area;

    protected static ?string $heading = 'Sign-ups';

    protected static ?string $description = 'New accounts per month.';

    protected static int $maxHeight = 200;

    /** @var list<string> */
    private array $labels = [];

    public function filterSchema(): FormSchema
    {
        return FormSchema::make()->schema([
            Select::make('months')
                ->label('Window')
                ->options([
                    '6' => 'Last 6 months',
                    '12' => 'Last 12 months',
                    '24' => 'Last 24 months',
                ])
                ->default('6'),
        ]);
    }

    public function options(): ChartOptions
    {
        return ChartOptions::make()->legend(false)->curved()->filled();
    }

    /**
     * @return list<string>
     */
    public function labels(): array
    {
        $this->build();

        return $this->labels;
    }

    /**
     * @return list<ChartSeries>
     */
    public function series(): array
    {
        $counts = $this->build();

        return [
            ChartSeries::make('Sign-ups', array_values($counts))->color(StatColor::Info),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function build(): array
    {
        $months = max(1, min(24, (int) $this->filter('months', 6)));
        $start = Date::now()->startOfMonth()->subMonths($months - 1);

        // One grouped query rather than one per month.
        $rows = User::query()
            ->where('created_at', '>=', $start)
            ->get(['created_at'])
            ->groupBy(static fn (User $user): string => $user->created_at?->format('Y-m') ?? '')
            ->map->count();

        $labels = [];
        $counts = [];

        for ($offset = 0; $offset < $months; $offset++) {
            $month = $start->copy()->addMonths($offset);
            $key = $month->format('Y-m');

            $labels[] = $month->format('M');
            $counts[$key] = (int) ($rows[$key] ?? 0);
        }

        $this->labels = $labels;

        return $counts;
    }
}
```

Note the clamp on the filter value. The select declares three options, and the schema already discards anything it did not declare, but the widget still bounds what it does with the value — a chart is not the place to trust a query-string integer.

## Gotchas

- The `widget-chart` stub the generator writes declares `protected static string $variant = 'bar';`, which does not match the parent's `protected static ChartVariant $variant`. Change it to `protected static ChartVariant $variant = ChartVariant::Bar;` (and import the enum) after generating, or publish your own stub with `php artisan vendor:publish --tag=panda-panel-stubs`.
- `labels()` and `series()` are called separately during serialization. If both run a query, memoize — otherwise every chart costs two.
- `$variant`, `$maxHeight`, `$heading` and `$description` are static. A chart that must change its type per user is a custom widget.
- `ChartOptions::labels()` is serialized but not drawn; `ChartOptions::stacked()` affects bars only.
- `ChartVariant::Doughnut` renders through the bar path. It is not a ring.
- A series shorter than `labels()` simply stops; there is no interpolation and no padding. Build both from the same loop.
- Anything the closed option set cannot express belongs in a [custom Vue widget](custom-vue.md). That is the deliberate boundary, not a missing feature.

## See also

- [Widgets overview](overview.md)
- [Stats widgets](stats.md)
- [Custom Vue widgets](custom-vue.md)
- [Filters](filters.md)
- [Lazy loading](lazy-loading.md)
- [Column span and layout](layout.md)
