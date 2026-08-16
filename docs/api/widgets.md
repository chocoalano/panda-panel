# Widgets

The reference for every class in `PandaPanel\Widgets`: the base `Widget`, the
four concrete types, the value objects a widget builds its payload from, and
the registration and resolution machinery around them. Reach for this page when
you know what a widget is and need the exact signature, default value, or
serialized key. For the narrative — what each type is for, when to make one
lazy — start at [Widgets Overview](../widgets/overview.md).

A widget computes everything on the server and sends a serialized description
to Vue: scalars, arrays and nulls, with no closures and no class names in it.

## A minimal working example

```bash
php artisan make:panel-widget UserStats --panel=Admin --type=stats
```

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Widgets;

use App\Models\User;
use PandaPanel\Widgets\Enums\StatColor;
use PandaPanel\Widgets\StatsWidget;
use PandaPanel\Widgets\Support\Stat;

final class UserStats extends StatsWidget
{
    protected static int $sort = 10;

    protected static ?string $heading = 'Accounts';

    /** @return list<Stat> */
    public function stats(): array
    {
        return [
            Stat::make('Total users', User::query()->count())->icon('users'),

            Stat::make('Verified', User::query()->whereNotNull('email_verified_at')->count())
                ->icon('shield')
                ->color(StatColor::Success),
        ];
    }
}
```

```php
use PandaPanel\Core\Panel;

public function panel(Panel $panel): Panel
{
    return $panel
        ->path('admin')
        ->discoverWidgets(app_path('Panels/Admin/Widgets'));
}
```

`GET /admin` renders the dashboard with the widget on it.

## `PandaPanel\Widgets\Widget`

The abstract base of all four types. Implements
`PandaPanel\Contracts\WidgetContract`.

### Static configuration

| Property | Type | Default | Read through |
| --- | --- | --- | --- |
| `$sort` | `int` | `0` | `sort()` |
| `$columnSpan` | `int\|string\|array<string, int\|string>` | `1` | `columnSpan()` |
| `$lazy` | `bool` | `false` | `isLazy()` |
| `$heading` | `?string` | `null` | `heading()` |
| `$description` | `?string` | `null` | `description()` |
| `$pollingInterval` | `?int` | `null` | `pollingInterval()` |

```php
use PandaPanel\Widgets\StatsWidget;

final class QueueDepth extends StatsWidget
{
    protected static int $sort = 5;

    protected static int|string|array $columnSpan = ['default' => 1, 'md' => 2, 'lg' => 3, 'xl' => 4];

    protected static bool $lazy = true;

    protected static ?string $heading = 'Queue';

    protected static ?string $description = 'Jobs waiting, by connection.';

    protected static ?int $pollingInterval = 30;

    // ...
}
```

Widgets sort by `[sort(), id()]`, so two widgets with the same `$sort` fall back
to alphabetical id rather than to registration order.

### Methods

| Method | Signature | Notes |
| --- | --- | --- |
| `type()` | `abstract public static function type(): WidgetType` | Implemented by each base type |
| `data()` | `abstract public function data(): array` | The payload the renderer receives |
| `id()` | `public static function id(): string` | `Str::kebab(class_basename(static::class))` |
| `sort()` | `public static function sort(): int` | |
| `isLazy()` | `public static function isLazy(): bool` | |
| `heading()` | `public static function heading(): ?string` | |
| `description()` | `public static function description(): ?string` | |
| `pollingInterval()` | `public static function pollingInterval(): ?int` | Seconds |
| `canView()` | `public static function canView(): bool` | Defaults to `true` |
| `columnSpan()` | `public static function columnSpan(): array` | Normalized per breakpoint |
| `filterSchema()` | `public function filterSchema(): ?FormSchema` | Defaults to `null` |
| `filtersInModal()` | `public static function filtersInModal(): bool` | Defaults to `false` |
| `withPageContext()` | `public function withPageContext(PageContext $context): static` | Called by the page |
| `withFilters()` | `public function withFilters(array $filters): static` | Called by the page |
| `context()` | `protected function context(): PageContext` | Throws without one |
| `filter()` | `protected function filter(string $name, mixed $default = null): mixed` | |
| `filters()` | `protected function filters(): array` | |
| `toDefinition()` | `public function toDefinition(): array` | The serialized widget |
| `toArray()` | `public function toArray(): array` | Alias of `toDefinition()` |

#### `id()`

```php
public static function id(): string
```

Kebab-case of the class basename, and stable across runs so widget order and
the deferred-data keys agree.

```php
UserStats::id();     // 'user-stats'
RecentUsers::id();   // 'recent-users'
```

#### `canView()`

```php
public static function canView(): bool
```

Checked by `WidgetCollection` **before** the widget is constructed, so an
unauthorized widget never runs `data()` and therefore never runs a query.

```php
use Illuminate\Support\Facades\Auth;

public static function canView(): bool
{
    return Auth::user()?->can('viewRevenue') ?? false;
}
```

#### `columnSpan()`

```php
/** @return array{default: int|string, md: int|string, lg: int|string, xl: int|string} */
public static function columnSpan(): array
```

Runs `$columnSpan` through `ColumnSpan::normalize()`.

```php
protected static int|string|array $columnSpan = 'full';

UserStats::columnSpan();
// ['default' => 'full', 'md' => 'full', 'lg' => 'full', 'xl' => 'full']
```

#### `withFilters()` and `filter()`

```php
/** @param  array<string, mixed>  $filters */
public function withFilters(array $filters): static

protected function filter(string $name, mixed $default = null): mixed

/** @return array<string, mixed> */
protected function filters(): array
```

`filter()` returns the default when the value is `null` **or** the empty
string, so a cleared control reads as absent rather than as `''`.

```php
$widget = (new UserGrowth)->withFilters(['months' => '12']);

// Inside the widget:
$months = (int) $this->filter('months', 6);   // 12
```

The values are already narrowed by the schema that declared them — a key the
schema never declared is not in there, whatever the query string said.

#### `filterSchema()` and `filtersInModal()`

```php
public function filterSchema(): ?FormSchema

public static function filtersInModal(): bool
```

```php
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\FormSchema;

public function filterSchema(): FormSchema
{
    return FormSchema::make()->schema([
        Select::make('months')
            ->label('Window')
            ->options(['6' => 'Last 6 months', '12' => 'Last 12 months'])
            ->default('6'),
    ]);
}

public static function filtersInModal(): bool
{
    return true;
}
```

The state lives in the query string under `widgets[{id}][{field}]` and is
persisted per page in the session. See [Widget Filters](../widgets/filters.md).

#### `withPageContext()` and `context()`

```php
public function withPageContext(PageContext $context): static

/** @throws \LogicException */
protected function context(): PageContext
```

Only resource pages hand over a context. `context()` throws rather than
returning an empty one, because a widget that reads a record it was never given
is on the wrong page.

```php
public function stats(): array
{
    return [
        Stat::make('Rows on this tab', $this->context()->count()),
    ];
}
```

#### `toDefinition()`

```php
/** @return array<string, mixed> */
public function toDefinition(): array
```

| Key | Type | Value |
| --- | --- | --- |
| `id` | `string` | `static::id()` |
| `type` | `string` | `static::type()->value` |
| `sort` | `int` | |
| `columnSpan` | `array{default, md, lg, xl}` | |
| `lazy` | `bool` | |
| `heading` | `?string` | |
| `description` | `?string` | |
| `polling` | `?int` | Seconds, from `pollingInterval()` |
| `filters` | `?array{inModal: bool, form: array}` | Null when no `filterSchema()` |
| `data` | `?array` | `null` for a lazy widget |
| `component` | `string` | `CustomWidget` only |

```php
(new UserStats)->toDefinition()['id'];   // 'user-stats'
```

## `PandaPanel\Widgets\StatsWidget`

```php
public static function type(): WidgetType   // WidgetType::Stats

/** @return list<Stat> */
abstract public function stats(): array;

/** @return array{stats: list<array<string, mixed>>} */
public function data(): array
```

```php
use App\Models\Order;
use PandaPanel\Widgets\Enums\StatColor;
use PandaPanel\Widgets\StatsWidget;
use PandaPanel\Widgets\Support\Stat;

final class OrderStats extends StatsWidget
{
    /** @return list<Stat> */
    public function stats(): array
    {
        return [
            Stat::make('Orders', Order::query()->count())->icon('receipt'),

            Stat::make('Revenue', (float) Order::query()->sum('total'))
                ->format(prefix: '£', decimals: 2)
                ->color(StatColor::Success),
        ];
    }
}
```

Count in the database. Hydrating a collection to count it is the usual way a
dashboard becomes the slowest page in an application.

## `PandaPanel\Widgets\TableWidget`

```php
protected static int|string|array $columnSpan = ['default' => 1, 'md' => 2, 'lg' => 2, 'xl' => 2];

protected static string $emptyMessage = 'Nothing to show yet.';

protected static int $perPage = 5;

public static function type(): WidgetType   // WidgetType::Table

abstract public function table(TableSchema $table): TableSchema;

/** @return Builder<covariant Model> */
abstract public function query(): Builder;

public static function stateNamespace(): string
```

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
    protected static string $emptyMessage = 'No one has signed up yet.';

    protected static int $perPage = 5;

    public function table(TableSchema $table): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable(),
                DateTimeColumn::make('created_at')->label('Joined')->relative()->sortable(),
            ])
            ->defaultSort('created_at', SortDirection::Descending);
    }

    /** @return Builder<User> */
    public function query(): Builder
    {
        return User::query()->select(['id', 'name', 'email', 'created_at']);
    }
}
```

`data()` runs the schema through `PandaPanel\Tables\TableQuery` — the same one a
resource index uses — and returns:

| Key | Type | Notes |
| --- | --- | --- |
| `columns` | `array` | From `TableSchema::toArray()['columns']` |
| `rows` | `list<array>` | One `TableSchema::toRow()` per record |
| `emptyMessage` | `string` | `static::$emptyMessage` |
| `state` | `array` | Search, sort, direction, perPage, filters, columns, group |
| `pagination` | `array{page, perPage, total, lastPage, from, to}` | |
| `namespace` | `string` | `static::stateNamespace()` |
| `searchable` | `bool` | `TableSchema::isSearchable()` |

`stateNamespace()` returns `'widgets.'.Str::kebab(class_basename(static::class))`,
so this widget's table state lives at `?widgets[recent-users][page]=2`. That
namespacing is what makes two table widgets on one dashboard possible.

```php
RecentUsers::stateNamespace();   // 'widgets.recent-users'
```

Per-page options are pinned to `[$perPage]`, so a table widget has no page-size
control. It is a summary you can sort and search, not a second index: no bulk
actions, no column manager, no filter tabs.

## `PandaPanel\Widgets\ChartWidget`

```php
protected static int|string|array $columnSpan = ['default' => 1, 'md' => 2, 'lg' => 2, 'xl' => 2];

protected static ChartVariant $variant = ChartVariant::Bar;

protected static int $maxHeight = 220;

public static function type(): WidgetType   // WidgetType::Chart

/** @return list<string> */
abstract public function labels(): array;

/** @return list<ChartSeries> */
abstract public function series(): array;

public function options(): ChartOptions
```

```php
use PandaPanel\Widgets\ChartWidget;
use PandaPanel\Widgets\Enums\ChartVariant;
use PandaPanel\Widgets\Enums\StatColor;
use PandaPanel\Widgets\Support\ChartOptions;
use PandaPanel\Widgets\Support\ChartSeries;

final class UserGrowth extends ChartWidget
{
    protected static ChartVariant $variant = ChartVariant::Area;

    protected static int $maxHeight = 200;

    /** @return list<string> */
    public function labels(): array
    {
        return ['Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug'];
    }

    /** @return list<ChartSeries> */
    public function series(): array
    {
        return [
            ChartSeries::make('Sign-ups', [4, 9, 7, 12, 18, 21])->color(StatColor::Info),
        ];
    }

    public function options(): ChartOptions
    {
        return ChartOptions::make()->legend(false)->curved()->filled();
    }
}
```

`data()` returns `variant`, `labels`, `series`, `options` and `maxHeight`. One
label per point, shared by every series.

Charts are drawn by a dependency-free inline SVG that was compiled in. What
crosses the wire is a description of a chart, never configuration for a charting
library. Anything `ChartOptions` cannot express is a `CustomWidget`.

## `PandaPanel\Widgets\CustomWidget`

```php
/** A path under `resources/js/pages/`, e.g. `Panels/Admin/Widgets/ServerHealth`. */
protected static string $component = '';

public static function type(): WidgetType   // WidgetType::Custom

/** @throws \RuntimeException when $component is empty */
public static function component(): string

public function toDefinition(): array   // parent's, plus 'component'
```

```php
use Illuminate\Foundation\Application;
use PandaPanel\Widgets\CustomWidget;

final class SystemInfo extends CustomWidget
{
    protected static string $component = 'Panels/Admin/Widgets/SystemInfo';

    /** @return array<string, mixed> */
    public function data(): array
    {
        return [
            'laravel' => Application::VERSION,
            'php' => PHP_VERSION,
            'environment' => app()->environment(),
        ];
    }
}
```

```vue
<script setup lang="ts">
defineProps<{
    data: { laravel: string; php: string; environment: string };
}>();
</script>

<template>
    <dl class="space-y-1 text-sm">
        <div><dt>Laravel</dt><dd>{{ data.laravel }}</dd></div>
        <div><dt>PHP</dt><dd>{{ data.php }}</dd></div>
        <div><dt>Environment</dt><dd>{{ data.environment }}</dd></div>
    </dl>
</template>
```

The component must live under `resources/js/pages/Panels/**/Widgets/*.vue`. The
frontend resolves it through a build-time `import.meta.glob`, so a name that was
not compiled in cannot be reached however it arrives; an unknown name renders a
neutral fallback and warns once in development. See
[Custom Vue widgets](../widgets/custom-vue.md).

## `PandaPanel\Widgets\Support\Stat`

`final readonly`. The fluent methods return a new instance, so a stat cannot be
mutated after the widget handed it over.

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

| Method | Signature |
| --- | --- |
| `make()` | `public static function make(string $label, string\|int\|float $value): self` |
| `description()` | `public function description(string $description): self` |
| `icon()` | `public function icon(string $icon): self` |
| `color()` | `public function color(StatColor $color): self` |
| `trend()` | `public function trend(string $direction, float $value): self` |
| `chart()` | `public function chart(array $values): self` |
| `url()` | `public function url(string $url): self` |
| `format()` | `public function format(?string $prefix = null, ?string $suffix = null, ?int $decimals = null): self` |
| `display()` | `public function display(): string` |
| `toArray()` | `public function toArray(): array` |

```php
use App\Panels\Admin\Resources\Users\UserResource;
use PandaPanel\Widgets\Enums\StatColor;
use PandaPanel\Widgets\Support\Stat;

Stat::make('Revenue', 12045)
    ->format(prefix: '£', decimals: 2)     // display: "£12,045.00"
    ->icon('receipt')
    ->color(StatColor::Success)
    ->trend('up', 12.4)                    // 'up' | 'down' | 'neutral'
    ->chart([4, 9, 7, 12, 18, 21])         // a sparkline under the figure
    ->url(UserResource::url());
```

`display()` formats on the server, where what the number means is known:

```php
Stat::make('Revenue', 1204.5)->format(prefix: '£', decimals: 2)->display();
// '£1,204.50'

Stat::make('Uptime', '99.9%')->display();
// '99.9%'  — a string value is left exactly as the widget wrote it
```

Without `decimals`, a float formats to 2 and an int to 0.

`toArray()` sends `label`, `value`, `display`, `description`, `icon`, `color`,
`trend`, `chart`, `url`. `prefix`, `suffix` and `decimals` are not serialized
separately — they exist to produce `display`.

## `PandaPanel\Widgets\Support\ChartSeries`

`final readonly`, like `Stat`.

```php
/** @param  list<int|float>  $values */
public function __construct(
    public string $label,
    public array $values,
    public StatColor $color = StatColor::Default,
) {}

/** @param  list<int|float>  $values */
public static function make(string $label, array $values): self

public function color(StatColor $color): self

/** @return array{label: string, values: list<int|float>, color: string} */
public function toArray(): array
```

```php
use PandaPanel\Widgets\Enums\StatColor;
use PandaPanel\Widgets\Support\ChartSeries;

ChartSeries::make('Sign-ups', [4, 9, 7])->color(StatColor::Info)->toArray();
// ['label' => 'Sign-ups', 'values' => [4, 9, 7], 'color' => 'info']
```

## `PandaPanel\Widgets\Support\ChartOptions`

A closed set of settings, not an arbitrary options tree. Mutable and fluent:
each method returns the same instance.

| Method | Signature | Default |
| --- | --- | --- |
| `make()` | `public static function make(): self` | |
| `legend()` | `public function legend(bool $legend = true): self` | `true` |
| `grid()` | `public function grid(bool $grid = true): self` | `true` |
| `stacked()` | `public function stacked(bool $stacked = true): self` | `false` |
| `filled()` | `public function filled(bool $filled = true): self` | `false` |
| `curved()` | `public function curved(bool $curved = true): self` | `false` |
| `labels()` | `public function labels(bool $labels = true): self` | `false` |
| `range()` | `public function range(?float $min, ?float $max): self` | `null`, `null` |
| `format()` | `public function format(?string $prefix = null, ?string $suffix = null): self` | `null`, `null` |
| `toArray()` | `public function toArray(): array` | |

```php
use PandaPanel\Widgets\Support\ChartOptions;

ChartOptions::make()
    ->legend(false)
    ->stacked()
    ->range(0, 100)
    ->format(suffix: '%')
    ->toArray();
// ['legend' => false, 'grid' => true, 'stacked' => true, 'filled' => false,
//  'curved' => false, 'labels' => false, 'min' => 0.0, 'max' => 100.0,
//  'prefix' => null, 'suffix' => '%']
```

`range()` is worth setting when a chart is read against a target rather than
against itself: an axis that rescales to the data makes every week look the same
shape.

## `PandaPanel\Widgets\Support\ColumnSpan`

```php
/**
 * @param  int|string|array<string, int|string>  $span
 * @return array{default: int|string, md: int|string, lg: int|string, xl: int|string}
 */
public static function normalize(int|string|array $span, string $context = 'A widget'): array
```

Breakpoints are `default`, `md`, `lg`, `xl`; the maximum span is `4`; `'full'`
passes through.

```php
use PandaPanel\Widgets\Support\ColumnSpan;

ColumnSpan::normalize(2);
// ['default' => 2, 'md' => 2, 'lg' => 2, 'xl' => 2]

ColumnSpan::normalize(['default' => 1, 'lg' => 2]);
// ['default' => 1, 'md' => 1, 'lg' => 2, 'xl' => 2]  — md inherits default

ColumnSpan::normalize(99);
// ['default' => 4, 'md' => 4, 'lg' => 4, 'xl' => 4]  — clamped
```

Two things raise `PandaPanel\Exceptions\PanelSchemaException`:

```php
ColumnSpan::normalize('ful');
// declares a column span of [ful], which is neither a number nor "full"

ColumnSpan::normalize(['default' => 1, 'sm' => 2]);
// declares a column span at [sm] ... It has: default, md, lg, xl
```

A number out of range is somebody asking for more columns than the grid has, and
four is the honest answer. A word is a typo, and clamping it to 1 would produce a
quarter-width widget with nothing to say why.

## `PandaPanel\Widgets\Support\WidgetFilters`

`final readonly`. Built by `Page::resolveFilters()`; a widget receives only its
own slice through `withFilters()`.

```php
public static function none(): self

/** @param  array<string, FormSchema>  $widgetSchemas  keyed by widget id */
public static function fromRequest(
    Request $request,
    ?FormSchema $dashboardSchema = null,
    array $widgetSchemas = [],
    ?string $sessionKey = null,
): self

/** @return array<string, mixed> */
public function for(string $widgetId): array

/** @return array<string, mixed> */
public function dashboard(): array
```

```php
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Widgets\Support\WidgetFilters;

$filters = WidgetFilters::fromRequest(
    request(),                                     // ?filters[months]=6&widgets[user-growth][months]=24
    FormSchema::make()->schema([Select::make('months')->options(['6' => '6'])->default('6')]),
    ['user-growth' => FormSchema::make()->schema([Select::make('months')->options(['24' => '24'])])],
    'panel.admin.page.dashboard',
);

$filters->dashboard();          // ['months' => '6']
$filters->for('user-growth');   // ['months' => '24']  — the widget's own wins
$filters->for('recent-users');  // ['months' => '6']   — the page's
```

Rules the resolver follows:

| Situation | Result |
| --- | --- |
| Key not declared by the schema | Discarded |
| Parameter group absent, session has a value | The stored value |
| Parameter group absent, nothing stored | The field's default |
| Parameter group present, field missing | `null` — a cleared filter stays cleared |

## `PandaPanel\Widgets\PageContext`

`final`. What a widget on a resource page is allowed to know about that page.

```php
public static function forRecord(Model $record): self

/** @param  Closure(): Builder<covariant Model>  $query */
public static function forQuery(Closure $query): self

public function record(): ?Model

/** @return Builder<covariant Model>|null */
public function query(): ?Builder

public function count(): int
```

```php
use PandaPanel\Widgets\PageContext;

PageContext::forRecord($order);
PageContext::forQuery(static fn () => OrderResource::query());
```

`count()` is memoized and returns `0` when there is no query, so a page with four
widgets that never mention the count runs no extra query, and one where three of
them do runs it once.

| Page | Context it hands over |
| --- | --- |
| `ListRecords` | `forQuery()`, scoped to the active tab |
| `ViewRecord`, `EditRecord` | `forRecord()` |
| `ManageRelatedRecords` | `forRecord()` — the owner record |
| `CreateRecord` | None |
| `Page`, `Dashboard` | None |

## `PandaPanel\Pages\WidgetCollection`

`final readonly`. Resolves a page's widget classes into props. Authorization
runs first and once.

```php
/**
 * @param  list<class-string<Widget>>  $classes
 */
public static function for(
    array $classes,
    ?PageContext $context = null,
    ?WidgetFilters $filters = null,
): self

public function merge(self $other): self

/**
 * @param  list<class-string<Widget>>  $classes
 * @return array<string, FormSchema>
 */
public static function filterSchemas(array $classes): array

/** @return list<array<string, mixed>> */
public function definitions(): array

public function deferred(): mixed
```

```php
use PandaPanel\Pages\WidgetCollection;

$collection = WidgetCollection::for([UserStats::class, RecentUsers::class]);

$collection->definitions();   // [['id' => 'recent-users', ...], ['id' => 'user-stats', ...]]
$collection->deferred();      // null when nothing is lazy
```

`deferred()` returns `Inertia::defer()` over `{widgetId: data}` for the lazy
widgets, or `null` when there are none so the page does not advertise a second
request it will never make.

## `PandaPanel\Core\WidgetRegistry`

`final`. One per panel, keyed by widget id.

```php
/** @param  class-string<WidgetContract>  $widget */
public function register(string $widget): void

public function has(string $id): bool

/** @return class-string<WidgetContract>|null */
public function byId(string $id): ?string

/** @return list<class-string<WidgetContract>> */
public function all(): array

public function count(): int
```

```php
use PandaPanel\Core\PanelManager;

$registry = app(PanelManager::class)->widgets(panel('admin'));

$registry->all();                  // sorted class names
$registry->byId('user-stats');     // 'App\Panels\Admin\Widgets\UserStats'
$registry->has('nope');            // false
```

Registering two classes whose ids collide raises
`PanelRegistrationException::duplicateWidgetId()` — ids come from the class
basename, so `Admin\Widgets\UserStats` and `Reports\UserStats` in the same panel
is a conflict.

## Registration and placement

### On the panel

```php
/** @param  list<class-string>  $widgets */
public function widgets(array $widgets): self

public function discoverWidgets(string ...$paths): self

/** @return list<class-string> */
public function getWidgets(): array

/** @return list<string> */
public function getWidgetDiscoveryPaths(): array

/** @param  class-string<Page>  $page */
public function dashboard(string $page): self

/** @param  array<array-key, class-string<Page>>  $pages */
public function dashboards(array $pages): self
```

```php
use App\Panels\Admin\Pages\AccountsDashboard;
use App\Panels\Admin\Widgets\RevenueChart;
use PandaPanel\Core\Panel;
use PandaPanel\Pages\Dashboard;

return $panel
    ->widgets([RevenueChart::class])                        // explicit
    ->discoverWidgets(app_path('Panels/Admin/Widgets'))     // and/or discovered
    ->dashboards([
        Dashboard::class,           // the panel root
        AccountsDashboard::class,   // its own route, navigation item, and filters
    ]);
```

Explicit registration merges with discovery; a class named in both appears once.
`dashboards()` makes the first entry the panel root and registers the rest as
pages; passing an empty array leaves the current dashboard alone.

### On a page

```php
/** @return list<class-string<Widget>> */
public function widgets(): array

public function filterSchema(): ?FormSchema

protected function filterSessionKey(): string

protected function resolveFilters(): WidgetFilters

protected function resolveWidgets(?WidgetFilters $filters = null): WidgetCollection
```

`PandaPanel\Pages\Dashboard::widgets()` overrides the default and returns every
widget in the panel's registry. `filterSessionKey()` is
`'panel.{panelId}.page.{slug}'`, so two dashboards remember their filters
separately.

```php
use PandaPanel\Pages\Page;
use PandaPanel\Widgets\Widget;

final class Reports extends Page
{
    /** @return list<class-string<Widget>> */
    public function widgets(): array
    {
        return [RevenueChart::class, TopProducts::class];
    }
}
```

A page renders `widgets`, `widgetData` and `filters` props.

### On a resource page

```php
/** @return list<class-string<Widget>> */
public function headerWidgets(): array

/** @return list<class-string<Widget>> */
public function footerWidgets(): array

/** @return array<string, mixed> */
protected function widgetProps(?PageContext $context = null): array
```

```php
use PandaPanel\Resources\Pages\ListRecords;
use PandaPanel\Widgets\Widget;

final class ListOrders extends ListRecords
{
    /** @return list<class-string<Widget>> */
    public function headerWidgets(): array
    {
        return [OrderStats::class];
    }
}
```

`widgetProps()` ships `headerWidgets`, `footerWidgets` and one shared
`widgetData` deferred prop covering both.

### Discovery

```php
/** @return list<class-string<WidgetContract>> */
public function widgets(Panel $panel): array
```

```php
use PandaPanel\Discovery\PanelDiscoverer;

app(PanelDiscoverer::class)->widgets(panel('admin'));
```

Scans `getWidgetDiscoveryPaths()` for classes implementing `WidgetContract`.
Abstract base classes, the `Support/` value objects and the enums are all
skipped, because none of them implements the contract concretely.

## Enums

```php
enum PandaPanel\Widgets\Enums\WidgetType: string
{
    case Stats = 'stats';
    case Table = 'table';
    case Chart = 'chart';
    case Custom = 'custom';
}

enum PandaPanel\Widgets\Enums\StatColor: string
{
    case Default = 'default';
    case Success = 'success';
    case Warning = 'warning';
    case Danger = 'danger';
    case Info = 'info';
}

enum PandaPanel\Widgets\Enums\ChartVariant: string
{
    case Bar = 'bar';
    case Line = 'line';
    case Area = 'area';
    case Doughnut = 'doughnut';
}
```

All three are closed because the frontend maps each case to a literal class or a
compiled-in renderer. A free-form colour name would compile to nothing.

## `PandaPanel\Contracts\WidgetContract`

```php
interface WidgetContract
{
    public static function id(): string;

    public static function type(): WidgetType;

    public static function canView(): bool;

    /** @return array<string, mixed> */
    public function toArray(): array;
}
```

Implemented by `PandaPanel\Widgets\Widget`. It is what discovery and the
registries type against — extend a base class rather than implementing it
directly.

## Artisan

```bash
php artisan make:panel-widget {name} --panel=Admin [--type=stats] [--force]
```

| Option | Values | Default |
| --- | --- | --- |
| `--panel` | The panel directory name | Required |
| `--type` | `stats`, `table`, `chart`, `custom` | `stats` |
| `--force` | Overwrite an existing file | Off |

```bash
php artisan make:panel-widget RecentOrders --panel=Admin --type=table
php artisan make:panel-widget ServerHealth --panel=Admin --type=custom
```

The class lands in `app/Panels/{Panel}/Widgets/{Name}.php`. For `--type=custom`
the matching Vue component is written too, under
`resources/js/pages/Panels/{Panel}/Widgets/{Name}.vue`, with `$component` already
set to `Panels/{Panel}/Widgets/{Name}` — a custom widget without its component
would render the fallback.

## Exceptions

| Exception | Raised when |
| --- | --- |
| `PanelSchemaException::unusableColumnSpan()` | `$columnSpan` is a string that is neither numeric nor `'full'` |
| `PanelSchemaException::unknownBreakpoints()` | `$columnSpan` names a breakpoint outside `default`, `md`, `lg`, `xl` |
| `PanelRegistrationException::duplicateWidgetId()` | Two widget classes in one panel share an id |
| `RuntimeException` | `CustomWidget::component()` with an empty `$component` |
| `LogicException` | `Widget::context()` on a widget rendered without page context |

## Gotchas

- **`canView()` runs before construction.** `WidgetCollection::for()` skips the
  class entirely, so `data()` never runs and no query is issued. Authorization by
  hiding would still cost the query.
- **`widgetData` is absent, not null, in the first response.** The deferred prop
  key does not exist until the follow-up request lands, so a Vue component reading
  it must declare the prop optional or Vue warns about a missing required prop on
  the first paint.
- **Filters and page context do not mix.** A dashboard or standalone page gives
  its widgets filters and no context; a resource page gives context and no
  filters. `context()` on a dashboard widget throws, and `filter()` on a resource
  page widget returns the default.
- **Widget ids come from the class basename.** Rename the class and every
  bookmarked `?widgets[old-id][...]` URL stops matching, and a stored filter under
  the old key is orphaned.
- **A table widget has no page-size control.** `data()` pins
  `perPageOptions([$perPage])`, so raising the page size means raising
  `$perPage`.
- **Polling reloads the page's props, not one widget.** A widget's data *is* a
  prop of the page it sits on, so `$pollingInterval` costs a partial reload of
  that page every interval for every open tab.
- **A lazy widget's definition still ships immediately.** Heading, column span,
  filters and polling arrive with the first paint; only `data` is deferred.
- **`Stat::display()` leaves strings alone.** `format()` only applies to `int`
  and `float` values, because a widget that formatted its own value has said what
  it wants.
- **Column-span classes are written out in full on the frontend.** An
  interpolated `md:col-span-${n}` would not exist in the bundle, which is why the
  span vocabulary is closed and out-of-range numbers clamp.

## See also

- [Widgets Overview](../widgets/overview.md)
- [Stats](../widgets/stats.md)
- [Table Widgets](../widgets/tables.md)
- [Charts](../widgets/charts.md)
- [Custom Vue Widgets](../widgets/custom-vue.md)
- [Widget Filters](../widgets/filters.md)
- [Lazy Loading](../widgets/lazy-loading.md)
- [Polling](../widgets/polling.md)
- [Layout and Column Spans](../widgets/layout.md)
- [Widget Authorization](../widgets/authorization.md)
- [Pages](pages.md)
- [Tables](tables.md)
- [Forms](forms.md)
- [Core Classes](core.md)
- [Contracts](contracts.md)
- [Exceptions](exceptions.md)
