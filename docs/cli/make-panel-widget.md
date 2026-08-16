# `make:panel-widget`

Generates a dashboard widget of one of four kinds: stats, table, chart, or
custom. Reach for it when a panel needs a figure, a short table, a graph, or a
bespoke Vue component on a dashboard or on a page.

```bash
php artisan make:panel-widget UserStats --panel=Admin --type=stats
```

```text
INFO  Created [app/Panels/Admin/Widgets/UserStats.php]
```

The panel's `discoverWidgets()` path already covers `app/Panels/Admin/Widgets`,
so the widget appears on the panel dashboard on the next request.

## Signature

```text
make:panel-widget
    {name : The widget class name}
    {--panel= : The panel it belongs to}
    {--type=stats : stats, table, chart, or custom}
    {--force}
```

| Argument / option | Default | Effect |
| --- | --- | --- |
| `name` | required | Studly-cased. |
| `--panel=` | required | The panel to generate into, studly-cased. Omitting it fails the command. |
| `--type=` | `stats` | One of `stats`, `table`, `chart`, `custom`. Anything else fails and writes nothing. |
| `--force` | off | Overwrite files that already exist. |

```bash
php artisan make:panel-widget UserStats --panel=Admin --type=stats
php artisan make:panel-widget RecentUsers --panel=Admin --type=table
php artisan make:panel-widget UserGrowth --panel=Admin --type=chart
php artisan make:panel-widget ServerHealth --panel=Admin --type=custom
```

An unknown type is refused rather than guessed at:

```text
ERROR  Unknown widget type [hologram]. Valid types are: stats, table, chart, custom.
```

## What each type writes

| `--type` | Base class | PHP file | Vue file |
| --- | --- | --- | --- |
| `stats` | `PandaPanel\Widgets\StatsWidget` | `app/Panels/{Panel}/Widgets/{Class}.php` | — |
| `table` | `PandaPanel\Widgets\TableWidget` | same | — |
| `chart` | `PandaPanel\Widgets\ChartWidget` | same | — |
| `custom` | `PandaPanel\Widgets\CustomWidget` | same | `resources/js/pages/Panels/{Panel}/Widgets/{Class}.vue` |

Only `custom` gets a Vue file, and it is not optional for that type: a custom
widget without its component renders the fallback rather than anything you
wrote. The other three are drawn by components the package publishes.

## What every widget inherits

`PandaPanel\Widgets\Widget` is the base of all four:

| Property | Type | Default | Meaning |
| --- | --- | --- | --- |
| `$sort` | `int` | `0` | Order on the dashboard, ascending. |
| `$columnSpan` | `int\|string\|array<string, int\|string>` | `1` (`['default' => 1, 'md' => 2, 'lg' => 2, 'xl' => 2]` on table and chart) | Grid width, per breakpoint. |
| `$lazy` | `bool` | `false` | Deliver as a deferred Inertia prop so the dashboard paints first. |
| `$heading` | `?string` | `null` | Heading above the widget. |
| `$description` | `?string` | `null` | Line under the heading. |
| `$pollingInterval` | `?int` | `null` | Seconds between self-refreshes. Null means never. |

```php
use PandaPanel\Widgets\StatsWidget;

final class UserStats extends StatsWidget
{
    protected static int $sort = 10;

    protected static bool $lazy = true;

    protected static ?string $heading = 'Accounts';

    protected static ?string $description = 'Everyone who has ever signed up.';

    protected static ?int $pollingInterval = 60;
    // ...
}
```

## `--type=stats`

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Widgets;

use PandaPanel\Widgets\StatsWidget;
use PandaPanel\Widgets\Support\Stat;

final class UserStats extends StatsWidget
{
    protected static int $sort = 0;

    /**
     * Use aggregates. Hydrating a collection to count it is how a dashboard
     * becomes the slowest page in the application.
     *
     * @return list<Stat>
     */
    public function stats(): array
    {
        return [
            Stat::make('Example', 0),
        ];
    }
}
```

`stats()` is the one abstract method. A `Stat` is built fluently:

```php
use App\Models\User;
use PandaPanel\Widgets\Enums\StatColor;
use PandaPanel\Widgets\Support\Stat;

public function stats(): array
{
    return [
        Stat::make('Users', User::query()->count())
            ->description('All time')
            ->icon('users')
            ->color(StatColor::Info)
            ->trend('up', 12.5)
            ->chart([4, 9, 6, 11, 14])
            ->format(suffix: ' accounts')
            ->url('/admin/users'),
    ];
}
```

| Method | Signature |
| --- | --- |
| `make` | `static make(string $label, string\|int\|float $value): self` |
| `description` | `description(string $description): self` |
| `icon` | `icon(string $icon): self` |
| `color` | `color(StatColor $color): self` |
| `trend` | `trend(string $direction, float $value): self` |
| `chart` | `chart(array $values): self` |
| `url` | `url(string $url): self` |
| `format` | `format(?string $prefix = null, ?string $suffix = null, ?int $decimals = null): self` |

## `--type=table`

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Widgets;

use Illuminate\Support\Collection;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;
use PandaPanel\Widgets\TableWidget;

final class RecentUsers extends TableWidget
{
    protected static int $sort = 0;

    public function table(TableSchema $table): TableSchema
    {
        return $table->columns([
            TextColumn::make('id')->label('ID'),
        ]);
    }

    /**
     * @return Collection<int, covariant \Illuminate\Database\Eloquent\Model>
     */
    public function rows(): Collection
    {
        return new Collection;
    }
}
```

**This does not run as generated.** `TableWidget` declares two abstract
methods, `table()` and `query()`, and the stub implements `table()` and a
`rows()` that overrides nothing:

```text
PHP Fatal error: Class App\Panels\Admin\Widgets\RecentUsers contains 1 abstract
method and must therefore be declared abstract or implement the remaining
methods (PandaPanel\Widgets\TableWidget::query)
```

Replace `rows()` with `query()`:

```php
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Tables\Columns\DateTimeColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Enums\SortDirection;
use PandaPanel\Tables\TableSchema;
use PandaPanel\Widgets\TableWidget;

final class RecentUsers extends TableWidget
{
    protected static int $sort = 20;

    protected static string $emptyMessage = 'No one has signed up yet.';

    protected static int $perPage = 5;

    public function table(TableSchema $table): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                DateTimeColumn::make('created_at')->label('Joined')->relative()->sortable(),
            ])
            ->defaultSort('created_at', SortDirection::Descending);
    }

    /**
     * @return Builder<User>
     */
    public function query(): Builder
    {
        return User::query()->select(['id', 'name', 'created_at']);
    }
}
```

A query rather than a collection, because the table builder searches, sorts and
pages it — the same `TableSchema` and `TableQuery` a resource index uses.
`$perPage` defaults to `5`, and `$emptyMessage` to `'Nothing to show yet.'`.

## `--type=chart`

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Widgets;

use PandaPanel\Widgets\ChartWidget;
use PandaPanel\Widgets\Support\ChartSeries;

final class UserGrowth extends ChartWidget
{
    protected static int $sort = 0;

    /**
     * Set to true when the query is slow enough that the dashboard should
     * paint before it finishes.
     */
    protected static bool $lazy = false;

    protected static string $variant = 'bar';

    /**
     * @return list<string>
     */
    public function labels(): array
    {
        return [];
    }

    /**
     * @return list<ChartSeries>
     */
    public function series(): array
    {
        return [];
    }
}
```

**This does not run as generated either.** `ChartWidget::$variant` is typed
`PandaPanel\Widgets\Enums\ChartVariant`, and a subclass may not redeclare a
typed static property with a different type:

```text
PHP Fatal error: Type of App\Panels\Admin\Widgets\UserGrowth::$variant must be
PandaPanel\Widgets\Enums\ChartVariant (as in class PandaPanel\Widgets\ChartWidget)
```

Either delete the line — `ChartVariant::Bar` is already the default — or write
the enum:

```php
use PandaPanel\Widgets\Enums\ChartVariant;

protected static ChartVariant $variant = ChartVariant::Area;
```

| Case | Value |
| --- | --- |
| `ChartVariant::Bar` | `bar` |
| `ChartVariant::Line` | `line` |
| `ChartVariant::Area` | `area` |
| `ChartVariant::Doughnut` | `doughnut` |

A working chart:

```php
use App\Models\User;
use PandaPanel\Widgets\ChartWidget;
use PandaPanel\Widgets\Enums\ChartVariant;
use PandaPanel\Widgets\Enums\StatColor;
use PandaPanel\Widgets\Support\ChartOptions;
use PandaPanel\Widgets\Support\ChartSeries;

final class UserGrowth extends ChartWidget
{
    protected static ChartVariant $variant = ChartVariant::Area;

    protected static int $maxHeight = 200;

    public function options(): ChartOptions
    {
        return ChartOptions::make()->legend(false)->curved()->filled();
    }

    /**
     * @return list<string>
     */
    public function labels(): array
    {
        return ['Jan', 'Feb', 'Mar'];
    }

    /**
     * @return list<ChartSeries>
     */
    public function series(): array
    {
        return [
            ChartSeries::make('Sign-ups', [4, 9, 6])->color(StatColor::Info),
        ];
    }
}
```

`ChartSeries::make(string $label, array $values): self` and
`ChartSeries::color(StatColor $color): self` are the whole series API.
`$maxHeight` is `220` pixels by default: a chart with no height of its own
grows with its container, and a dashboard of them is a page of charts nobody
can compare.

## `--type=custom`

```bash
php artisan make:panel-widget ServerHealth --panel=Admin --type=custom
```

```text
INFO  Created [app/Panels/Admin/Widgets/ServerHealth.php]
INFO  Created [resources/js/pages/Panels/Admin/Widgets/ServerHealth.vue]
```

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Widgets;

use PandaPanel\Widgets\CustomWidget;

final class ServerHealth extends CustomWidget
{
    protected static int $sort = 0;

    protected static string $component = 'Panels/Admin/Widgets/ServerHealth';

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return [];
    }
}
```

```vue
<script setup lang="ts">
defineProps<{
    // Mirror whatever the PHP widget's data() returns.
}>();
</script>

<template>
    <div class="flex h-full flex-col gap-3 rounded-lg border p-4">
        <h3 class="text-sm font-medium">ServerHealth</h3>
    </div>
</template>
```

Whatever `data()` returns arrives as the component's props:

```php
public function data(): array
{
    return ['queue' => 12, 'failed' => 0];
}
```

```vue
<script setup lang="ts">
defineProps<{
    queue: number;
    failed: number;
}>();
</script>
```

The component name is the path below `resources/js/pages/`, and custom widget
components resolve through a build-time `import.meta.glob` over
`resources/js/pages/Panels/**/Widgets/*.vue`. A component outside that shape is
not in the bundle and cannot be reached, however its name arrives.

## Custom stubs

```bash
php artisan vendor:publish --tag=panda-panel-stubs
```

| Stub | Used for | Placeholders |
| --- | --- | --- |
| `stubs/panel/widget-stats.stub` | `--type=stats` | `panel`, `class`, `component` |
| `stubs/panel/widget-table.stub` | `--type=table` | `panel`, `class`, `component` |
| `stubs/panel/widget-chart.stub` | `--type=chart` | `panel`, `class`, `component` |
| `stubs/panel/widget-custom.stub` | `--type=custom` | `panel`, `class`, `component` |
| `stubs/panel/widget-component.stub` | the Vue file for `--type=custom` | `label` |

`component` is only used by the custom stub; the other three ignore it.
Publishing is also how to fix the chart and table stubs once for the whole
project rather than per generated file.

## Exit codes

| Outcome | Code |
| --- | --- |
| At least one file created | `0` |
| Every file already existed and was skipped | `1` |
| `--panel` missing | `1`, with `The --panel option is required.` |
| `--type` unknown | `1`, and nothing is written |

## Gotchas

- **The generated chart and table widgets do not compile.** Both are described
  above with the one-line fix. The generator's test asserts the class extends
  the right base and that Pint passes; neither of those loads the class.
- **`{{ label }}` in the generated Vue file is a stub placeholder, not a
  binding.** It is replaced with the class name at generation time, and there
  is no `label` prop behind it.
- **Widgets are discovered, not registered.** They must live under a directory
  the panel's `discoverWidgets()` names, and a cached manifest hides new ones
  entirely — run `php artisan panel:clear`.
- **A discovered widget goes on the panel dashboard.** To put one on a specific
  page instead, list it in that page's `widgets()`.
- **A custom widget needs a frontend build.** The `.vue` file is a new source
  file; until `npm run dev` or `npm run build` has seen it, the widget renders
  the fallback.
- **Icons in stats need registering.** `Stat::icon('users')` draws nothing until
  `php artisan panel:icons` has put `users` in the registry.
- **`--lazy` is not an option.** Laziness is a property on the class; the chart
  stub is the only one that writes it out, and it writes `false`.

## See also

- [make:panel](make-panel.md), [make:panel-page](make-panel-page.md)
- [panel:icons](panel-icons.md), [panel:clear](panel-clear.md)
- [Widgets overview](../widgets/overview.md)
- [Stats widgets](../widgets/stats.md), [Table widgets](../widgets/tables.md), [Chart widgets](../widgets/charts.md)
- [Custom Vue widgets](../widgets/custom-vue.md)
- [Lazy loading](../widgets/lazy-loading.md), [Polling](../widgets/polling.md), [Layout](../widgets/layout.md)
- [Widget filters](../widgets/filters.md), [Widget authorization](../widgets/authorization.md)
- [Component registries](../concepts/component-registries.md)
- [Publish tags](publish-tags.md)
