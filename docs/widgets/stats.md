# Stats Widgets

A stats widget is a row of figures: counts, totals, averages, each with an optional icon, colour, trend, sparkline and link. You reach for one when the answer is a number — how many users, how much revenue, how many jobs waiting — and the reader needs it at a glance rather than as a list they have to read.

## A minimal working example

```bash
php artisan make:panel-widget UserStats --panel=Admin --type=stats
```

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Widgets;

use App\Models\User;
use PandaPanel\Widgets\StatsWidget;
use PandaPanel\Widgets\Support\Stat;

final class UserStats extends StatsWidget
{
    /**
     * @return list<Stat>
     */
    public function stats(): array
    {
        return [
            Stat::make('Total users', User::query()->count()),
        ];
    }
}
```

With the panel discovering `app/Panels/Admin/Widgets`, that renders on the dashboard immediately.

## The class

`PandaPanel\Widgets\StatsWidget` extends `PandaPanel\Widgets\Widget` and adds exactly one abstract method.

```php
public static function type(): WidgetType          // WidgetType::Stats
abstract public function stats(): array            // list<Stat>
public function data(): array                      // ['stats' => list<array>]
```

Everything else — `$sort`, `$columnSpan`, `$lazy`, `$heading`, `$description`, `$pollingInterval`, `canView()`, `filterSchema()` — comes from `Widget` and is described in [Overview](overview.md). `StatsWidget` does not override `$columnSpan`, so it inherits the base default of `1`. A row of three or four figures usually wants more:

```php
protected static int|string|array $columnSpan = ['default' => 1, 'md' => 2, 'lg' => 3, 'xl' => 4];
```

The figures inside the widget lay themselves out in an auto-fitting grid with a 240px minimum per card, so the span controls how much of the page's grid the whole row occupies, not how many figures sit side by side.

## `Stat`

`PandaPanel\Widgets\Support\Stat` is a `final readonly` value object. Every fluent method returns a new instance, so a stat cannot be changed after the widget handed it over.

```php
public function __construct(
    public string $label,
    public string|int|float $value,
    public ?string $description = null,
    public ?string $icon = null,
    public StatColor $color = StatColor::Default,
    public ?array $trend = null,
    public array $chart = [],
    public ?string $url = null,
    public ?string $prefix = null,
    public ?string $suffix = null,
    public ?int $decimals = null,
) {}
```

You will almost always build one with `make()` instead.

### `make()`

```php
public static function make(string $label, string|int|float $value): self
```

```php
use PandaPanel\Widgets\Support\Stat;

Stat::make('Total users', 1_204);
Stat::make('Uptime', '99.9%');       // a string is left exactly as written
```

### `description()`

```php
public function description(string $description): self
```

A line of context under the figure, separated by a rule and a coloured dot.

```php
Stat::make('New this month', 42)->description('September 2026');
```

### `icon()`

```php
public function icon(string $icon): self
```

An icon key, resolved through the panel's icon registry — the same registry navigation and actions use. A key that is not in the registry renders no icon rather than throwing.

```php
Stat::make('Verified', 980)->icon('shield');
```

Run `php artisan panel:icons` after using a key the registry does not have yet. See [Icons](../frontend/icons.md).

### `color()`

```php
public function color(StatColor $color): self
```

`PandaPanel\Widgets\Enums\StatColor` is closed, because the frontend maps each case to literal Tailwind classes; a free-form colour name would compile to nothing.

| Case | Value | Rendered as |
| --- | --- | --- |
| `StatColor::Default` | `'default'` | foreground / muted |
| `StatColor::Success` | `'success'` | emerald |
| `StatColor::Warning` | `'warning'` | amber |
| `StatColor::Danger` | `'danger'` | red |
| `StatColor::Info` | `'info'` | sky |

```php
use PandaPanel\Widgets\Enums\StatColor;

Stat::make('Failed jobs', 3)->icon('circle-alert')->color(StatColor::Danger);
```

The colour tints the icon, its background, a soft ambient glow behind the card, and the description dot.

### `trend()`

```php
/** @param 'up'|'down'|'neutral' $direction */
public function trend(string $direction, float $value): self
```

Renders a badge next to the figure: an arrow, the value with a `%` sign appended by the renderer, and the word *Increased*, *Decreased* or *Unchanged*.

```php
Stat::make('Revenue', 12_045)->trend('up', 12.4);     // "↗ 12.4% Increased"
```

The direction decides the colour, not the sign of the value — a *down* trend is red whether the number is `12.4` or `-12.4`. Pass the magnitude and say which way it went.

### `chart()`

```php
/** @param list<int|float> $values */
public function chart(array $values): self
```

A sparkline drawn under the figure as an inline SVG path. It needs at least two values; one value or none draws nothing.

```php
Stat::make('Sign-ups', 412)->chart([4, 9, 7, 12, 18, 21]);
```

This is decoration on a figure, not a chart somebody reads values off — there are no axes, no labels and no tooltip. When the reader needs to read values, use a [chart widget](charts.md).

Compute it in one query. Six queries for a sparkline is not a trade worth making:

```php
use App\Models\User;
use Illuminate\Support\Facades\Date;

$start = Date::now()->startOfMonth()->subMonths(5);

$rows = User::query()
    ->where('created_at', '>=', $start)
    ->get(['created_at'])
    ->groupBy(static fn (User $user): string => $user->created_at?->format('Y-m') ?? '')
    ->map->count();
```

### `url()`

```php
public function url(string $url): self
```

Makes the whole card a link. The frontend renders it as an Inertia `Link`, so it is an ordinary panel navigation and the destination authorizes for itself when it is followed — a stat that links to a resource the user may not see is a 403 at the destination, not a leak at the card.

```php
use App\Panels\Admin\Resources\Users\UserResource;

Stat::make('Total users', User::query()->count())->url(UserResource::url());
```

Build the URL on the server. `Resource::url()` and `Page::url()` both produce panel-relative paths. See [Resource URLs](../resources/urls-routes.md).

### `format()`

```php
public function format(?string $prefix = null, ?string $suffix = null, ?int $decimals = null): self
```

What the figure wears and how precisely it is written. Formatting happens on the server because a figure is a number *and* how it should be read: `1,204`, `£1,204` and `1,204 ms` are three different statements.

```php
Stat::make('Revenue', 12045.5)->format(prefix: '£', decimals: 2);   // "£12,045.50"
Stat::make('Latency', 187)->format(suffix: ' ms');                   // "187 ms"
Stat::make('Conversion', 0.0731)->format(suffix: '%', decimals: 1);  // "0.1%"
```

Each argument is independent; passing only one leaves the others alone.

### `display()`

```php
public function display(): string
```

The figure as it will be read. Called for you during serialization; call it yourself only in tests.

| Value | `decimals` | Result |
| --- | --- | --- |
| `int` | omitted | `number_format($value, 0)` |
| `float` | omitted | `number_format($value, 2)` |
| `int` or `float` | given | `number_format($value, $decimals)` |
| `string` | any | returned unchanged, prefix and suffix ignored |

```php
Stat::make('Revenue', 1204.5)->format(prefix: '£', decimals: 2)->display();   // '£1,204.50'
Stat::make('Uptime', '99.9%')->display();                                     // '99.9%'
```

A widget that formatted its own value has already said what it wants, so the object does not second-guess it.

### `toArray()`

```php
public function toArray(): array
```

```php
[
    'label' => 'Revenue',
    'value' => 1204.5,        // the raw value, for anything that needs to compute
    'display' => '£1,204.50', // what is drawn
    'description' => null,
    'icon' => 'receipt',
    'color' => 'success',
    'trend' => ['direction' => 'up', 'value' => 12.4],   // or null
    'chart' => [4, 9, 7],                                 // [] when none
    'url' => '/admin/orders',                             // or null
]
```

`prefix`, `suffix` and `decimals` are not in the payload. They exist to produce `display` and nothing else reads them.

## A full example

This is `examples/app/Panels/Admin/Widgets/UserStats.php`, trimmed to the parts that matter:

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Widgets;

use App\Models\User;
use App\Panels\Admin\Resources\Users\UserResource;
use Illuminate\Support\Facades\Date;
use PandaPanel\Widgets\Enums\StatColor;
use PandaPanel\Widgets\StatsWidget;
use PandaPanel\Widgets\Support\Stat;

final class UserStats extends StatsWidget
{
    protected static int $sort = 10;

    protected static int|string|array $columnSpan = ['default' => 1, 'md' => 2, 'lg' => 3, 'xl' => 4];

    protected static ?int $pollingInterval = 60;

    /**
     * @return list<Stat>
     */
    public function stats(): array
    {
        $startOfMonth = Date::now()->startOfMonth();

        return [
            Stat::make('Total users', User::query()->count())
                ->color(StatColor::Info)
                ->icon('users')
                ->url(UserResource::url()),

            Stat::make('Verified', User::query()->whereNotNull('email_verified_at')->count())
                ->icon('shield')
                ->color(StatColor::Success)
                ->description('Confirmed email address'),

            Stat::make('New this month', User::query()->where('created_at', '>=', $startOfMonth)->count())
                ->icon('user')
                ->color(StatColor::Info)
                ->description($startOfMonth->format('F Y'))
                ->chart($this->signUpsPerMonth()),
        ];
    }
}
```

Three figures, three `count(*)` aggregates, plus one grouped query for the sparkline. The package's own test asserts that query count, because a stats widget that hydrates collections to count them is the usual way a dashboard becomes the slowest page in an application.

## Filtered stats

`stats()` can read the widget's or the page's filters through `$this->filter()`:

```php
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\FormSchema;

public function filterSchema(): FormSchema
{
    return FormSchema::make()->schema([
        Select::make('window')
            ->options(['7' => 'Last 7 days', '30' => 'Last 30 days'])
            ->default('30'),
    ]);
}

public function stats(): array
{
    $days = (int) $this->filter('window', 30);

    return [
        Stat::make("Sign-ups, {$days}d", User::query()
            ->where('created_at', '>=', now()->subDays($days))
            ->count()),
    ];
}
```

See [Filters](filters.md).

## Notes

- `stats()` may return an empty array. The widget still renders its heading and filters; the grid below is simply empty.
- `Stat` is immutable. `$stat->icon('users');` on its own does nothing — you must keep the return value.
- The sparkline scales to its own minimum and maximum, so it shows shape, not magnitude. Two sparklines side by side are not comparable.
- A stat with a `url()` becomes an `<a>`-like Inertia link covering the whole card, so nothing else inside it can be independently clickable.
- Use aggregates. `User::query()->count()` is one query; `User::all()->count()` loads the table.

## See also

- [Widgets overview](overview.md)
- [Chart widgets](charts.md)
- [Filters](filters.md)
- [Polling](polling.md)
- [Column span and layout](layout.md)
- [Icons](../frontend/icons.md)
- [Resource URLs](../resources/urls-routes.md)
