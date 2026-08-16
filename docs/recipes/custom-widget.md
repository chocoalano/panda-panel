# Custom Widget

A dashboard widget whose body you draw yourself, in a Vue single-file component. This page builds a low-stock board for the products from [Product Resource](product-resource.md) — a list of products under their reorder point, with a filter of its own, loaded lazily and refreshed on a timer. Reach for `PandaPanel\Widgets\CustomWidget` when what you are showing is not a row of figures, a chart, or a table; reach for one of the other three first when it is.

## A minimal working example

```bash
php artisan make:panel-widget LowStock --panel=Admin --type=custom
```

Two files. `app/Panels/Admin/Widgets/LowStock.php`:

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Widgets;

use App\Models\Product;
use PandaPanel\Widgets\CustomWidget;

final class LowStock extends CustomWidget
{
    protected static int $sort = 0;

    protected static string $component = 'Panels/Admin/Widgets/LowStock';

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return [
            'products' => Product::query()
                ->where('stock', '<', 10)
                ->orderBy('stock')
                ->limit(5)
                ->get(['id', 'name', 'sku', 'stock'])
                ->map(static fn (Product $product): array => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'stock' => $product->stock,
                ])
                ->all(),
        ];
    }
}
```

And `resources/js/pages/Panels/Admin/Widgets/LowStock.vue`:

```vue
<script setup lang="ts">
defineProps<{
    products: Array<{ id: number; name: string; sku: string; stock: number }>;
}>();
</script>

<template>
    <div class="flex h-full flex-col gap-3 rounded-lg border p-4">
        <h3 class="text-sm font-medium">Low stock</h3>

        <ul v-if="products.length" class="flex flex-col gap-2 text-sm">
            <li
                v-for="product in products"
                :key="product.id"
                class="flex items-baseline justify-between gap-3"
            >
                <span class="truncate">{{ product.name }}</span>
                <span class="tabular-nums text-muted-foreground">
                    {{ product.stock }}
                </span>
            </li>
        </ul>

        <p v-else class="text-sm text-muted-foreground">
            Everything is above its reorder point.
        </p>
    </div>
</template>
```

```bash
npm run build     # or: npm run dev
```

The Admin panel already calls `discoverWidgets(app_path('Panels/Admin/Widgets'))`, so the widget appears on `/admin` with no registration.

## The generator

```bash
php artisan make:panel-widget
    {name}                # the widget class name
    --panel=              # required
    --type=stats          # stats, table, chart, or custom
    --force
```

| `--type` | Base class | What you implement | Extra file |
| --- | --- | --- | --- |
| `stats` | `PandaPanel\Widgets\StatsWidget` | `stats(): array` of `Stat` | — |
| `table` | `PandaPanel\Widgets\TableWidget` | `table(TableSchema $table)` and `query(): Builder` | — |
| `chart` | `PandaPanel\Widgets\ChartWidget` | `labels(): array` and `series(): array` | — |
| `custom` | `PandaPanel\Widgets\CustomWidget` | `data(): array` | the `.vue` component |

`--type=custom` is the only one that writes a second file, because a custom widget without its component renders nothing but the fallback. The component name it writes is `Panels/{Panel}/Widgets/{Class}`, and the file goes under `panda-panel.frontend.pages_path`, which defaults to `resources/js/pages/Panels`.

An unknown `--type` is an error naming the four valid ones, not a silent fallback to `stats`.

## The class

`CustomWidget` extends `PandaPanel\Widgets\Widget` and adds one property and one method.

| Member | Signature | Default |
| --- | --- | --- |
| `$component` | `protected static string $component` | `''` |
| `type()` | `static type(): WidgetType` | `WidgetType::Custom` |
| `component()` | `static component(): string` | returns `$component` |
| `data()` | `abstract data(): array` | still abstract — you must write it |
| `toDefinition()` | `toDefinition(): array` | the base definition plus `component` |

`component()` throws `RuntimeException` when `$component` is still the empty default:

```text
The custom widget [App\Panels\Admin\Widgets\LowStock] must declare a $component.
```

That is the one place in this path that throws rather than degrading, and it is a developer error caught at serialization time, before anything reaches a browser.

### Everything a widget already has

All of these are inherited from `Widget` and apply to every type:

```php
protected static int $sort = 0;
protected static int|string|array $columnSpan = 1;
protected static bool $lazy = false;
protected static ?string $heading = null;
protected static ?string $description = null;
protected static ?int $pollingInterval = null;

public static function id(): string;                 // kebab of the class basename
public static function canView(): bool;              // true
public static function columnSpan(): array;          // normalized to default/md/lg/xl
public function filterSchema(): ?FormSchema;         // null
public static function filtersInModal(): bool;       // false
```

## The whole widget

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Widgets;

use App\Models\Product;
use App\Panels\Admin\Resources\Products\ProductResource;
use Illuminate\Support\Facades\Auth;
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Widgets\CustomWidget;

/**
 * Products under their reorder point, worst first.
 */
final class LowStock extends CustomWidget
{
    protected static int $sort = 20;

    protected static int|string|array $columnSpan = ['default' => 1, 'md' => 2, 'lg' => 2, 'xl' => 2];

    protected static ?string $heading = 'Low stock';

    protected static ?string $description = 'Products at or below the threshold.';

    protected static string $component = 'Panels/Admin/Widgets/LowStock';

    /**
     * Deferred, so a scan of the products table does not hold up the first
     * paint of the whole dashboard.
     */
    protected static bool $lazy = true;

    /**
     * Stock moves when an order is placed, which is often enough to be worth
     * watching. Polling is a request every interval for every open tab, so
     * it is opt-in per widget rather than a setting somebody turns on once.
     */
    protected static ?int $pollingInterval = 120;

    /**
     * Hidden entirely — never drawn, and `data()` never runs — for anybody
     * the products policy will not show a list to.
     */
    public static function canView(): bool
    {
        return Auth::check() && ProductResource::canViewAny();
    }

    /**
     * A threshold the reader chooses. The schema is the whitelist: a key it
     * never declared is not in `filter()`, whatever the query string said.
     */
    public function filterSchema(): FormSchema
    {
        return FormSchema::make()->schema([
            Select::make('threshold')
                ->label('Threshold')
                ->options([
                    '5' => 'Under 5',
                    '10' => 'Under 10',
                    '25' => 'Under 25',
                ])
                ->default('10'),
        ]);
    }

    /**
     * Every key here becomes a prop on the component.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $threshold = max(1, min(100, (int) $this->filter('threshold', 10)));

        $products = Product::query()
            ->where('stock', '<', $threshold)
            ->orderBy('stock')
            ->limit(8)
            ->get(['id', 'name', 'sku', 'stock']);

        return [
            'threshold' => $threshold,
            // A URL the server produced, so the destination authorizes for
            // itself when it is followed.
            'indexUrl' => ProductResource::url(),
            'products' => $products
                ->map(static fn (Product $product): array => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'stock' => $product->stock,
                    'url' => ProductResource::url('edit', $product),
                ])
                ->all(),
        ];
    }
}
```

### `data()` becomes props, one key at a time

`CustomWidget.vue` binds the payload with `v-bind`:

```vue
<component :is="resolved" v-if="resolved" v-bind="data" />
```

So `['threshold' => …, 'products' => …]` gives you `threshold` and `products` props, not one `data` prop. Declare them with `defineProps` and the pair stays honest — a key the component does not declare lands as a fall-through attribute on the root element instead.

Scalars, arrays and nulls only. Serialize models yourself, as above: a serialized Eloquent model carries whatever happened to be loaded, and what crosses to Vue should be a description.

### The component

```vue
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
    threshold: number;
    indexUrl: string;
    products: Array<{
        id: number;
        name: string;
        sku: string;
        stock: number;
        url: string;
    }>;
}>();
</script>

<template>
    <div class="flex h-full flex-col gap-3">
        <ul v-if="products.length" class="flex flex-col gap-2 text-sm">
            <li
                v-for="product in products"
                :key="product.id"
                class="flex items-baseline justify-between gap-3"
            >
                <Link :href="product.url" class="truncate hover:underline">
                    {{ product.name }}
                </Link>
                <span
                    class="tabular-nums"
                    :class="
                        product.stock === 0
                            ? 'text-destructive'
                            : 'text-muted-foreground'
                    "
                >
                    {{ product.stock }}
                </span>
            </li>
        </ul>

        <p v-else class="text-sm text-muted-foreground">
            Nothing is under {{ threshold }}.
        </p>

        <Link
            :href="indexUrl"
            class="mt-auto text-xs text-muted-foreground hover:underline"
        >
            All products
        </Link>
    </div>
</template>
```

The widget shell has already drawn the border, the heading, the description, and the filter control. A component that draws its own heading draws it twice.

## Where a widget can appear

### The panel's dashboard

Discovery, or an explicit list, or both — they merge:

```php
$panel
    ->discoverWidgets(app_path('Panels/Admin/Widgets'))
    ->widgets([LowStock::class]);
```

`PandaPanel\Pages\Dashboard` draws every widget the panel knows about, ordered by `$sort`.

### A dashboard that names its own

```php
use PandaPanel\Pages\Dashboard;
use PandaPanel\Widgets\Widget;

final class CatalogDashboard extends Dashboard
{
    protected static ?string $title = 'Catalog';

    protected static ?string $slug = 'catalog';

    /**
     * @return list<class-string<Widget>>
     */
    public function widgets(): array
    {
        return [LowStock::class];
    }
}
```

```php
$panel->dashboards([Dashboard::class, CatalogDashboard::class]);
```

The first is the panel root; the rest are registered as ordinary pages, each with its own route, navigation entry, and filters.

### A resource page

```php
use PandaPanel\Widgets\Widget;

final class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    /**
     * @return list<class-string<Widget>>
     */
    public function headerWidgets(): array
    {
        return [LowStock::class];
    }

    /**
     * @return list<class-string<Widget>>
     */
    public function footerWidgets(): array
    {
        return [];
    }
}
```

A widget placed here gets a `PandaPanel\Widgets\PageContext`, which is what separates it from a dashboard widget: a list page hands over its own query, a record page hands over its record.

```php
protected function context(): PageContext

// on PageContext
public function record(): ?Model
public function query(): ?Builder     // the resource's own query, as the table ran it
public function count(): int          // memoized
```

```php
public function data(): array
{
    // The list the user is actually looking at, filters and all.
    return ['showing' => $this->context()->count()];
}
```

`context()` throws when there is none rather than returning an empty one: a widget that reads a record it was never given is on the wrong page, and saying so is more useful than a zero.

## Filters

Two levels, and they compose. A dashboard filters every widget on it at once; a widget carries a filter that only makes sense for it. A widget reads them merged, with its own winning.

```php
public function filterSchema(): ?FormSchema        // null for most widgets
public static function filtersInModal(): bool      // false — true when the form is bigger than the widget

protected function filter(string $name, mixed $default = null): mixed
protected function filters(): array
```

`filter()` returns the default for a value that is null or an empty string, so a cleared control reads as "not set" rather than as `''`.

The state lives in the query string under `widgets[{widget-id}][{name}]`, the same place table state does and for the same reasons: a filtered dashboard is a link somebody can send, and the back button means what it says. Everything goes back through the declaring schema, which is the whitelist — a key the schema never declared is discarded exactly as an unknown field is on a form.

Clamp anyway. The schema narrows the *keys*; the value is still whatever the option list allowed, and `max(1, min(100, …))` in `data()` costs nothing.

## Authorization

```php
public static function canView(): bool
```

Checked **before** `data()` is ever called, so an unauthorized widget never runs its queries. That ordering is the point: a widget that was merely hidden would still cost a query and could still leak counts through timing.

It is static and takes no arguments, so it reads the authenticated user itself. The page a widget appears on authorizes independently; neither substitutes for the other.

## Lazy loading

```php
protected static bool $lazy = true;
```

A lazy widget is serialized without its data and the payload arrives as a deferred Inertia prop, so a slow aggregate does not hold up the first paint of the whole dashboard.

Two consequences worth knowing:

The `data` key on the definition is `null` on the first response, and the `widgetData` prop is **absent** entirely — not null. Any Vue component reading it must declare it optional, or Vue warns about a missing required prop on the first paint.

The follow-up request is an ordinary Inertia partial reload and must carry the asset version, or Inertia answers 409 and asks the browser to do a full visit instead. The framework's own client does this; a hand-written test has to:

```php
$version = $this->get('/admin')->viewData('page')['version'];

$this->get('/admin', [
    'X-Inertia' => 'true',
    'X-Inertia-Version' => $version,
    'X-Inertia-Partial-Component' => 'panel/Dashboard',
    'X-Inertia-Partial-Data' => 'widgetData',
])->assertOk();
```

## Polling

```php
protected static ?int $pollingInterval = 120;   // seconds, or null
```

Null by default. The frontend reloads the props the *page* gave it rather than asking for one widget, because a widget's data is a prop of the page it is on. A queue depth is worth watching every fifteen seconds; a total that changes twice a day is a request for nothing.

## Column span

```php
protected static int|string|array $columnSpan = ['default' => 1, 'md' => 2, 'lg' => 2, 'xl' => 2];
```

An int, a string, or a map keyed by breakpoint. `Widget::columnSpan()` normalizes whatever you wrote into all four keys, and a malformed value throws naming your class rather than rendering a broken grid.

## The test

```php
<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\User;
use App\Panels\Admin\Widgets\LowStock;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

/**
 * @return array<string, mixed>|null
 */
function lowStockWidget(string $url = '/admin'): ?array
{
    return collect(test()->get($url)->viewData('page')['props']['widgets'])
        ->firstWhere('id', LowStock::id());
}

it('is serialized with its component name', function (): void {
    $widget = lowStockWidget();

    expect($widget)->not->toBeNull()
        ->and($widget['type'])->toBe('custom')
        ->and($widget['component'])->toBe('Panels/Admin/Widgets/LowStock');
});

it('withholds a lazy payload from the first response', function (): void {
    $widget = lowStockWidget();

    expect($widget['lazy'])->toBeTrue()
        ->and($widget['data'])->toBeNull();

    // Absent, not null: the key only exists once the follow-up lands.
    expect(test()->get('/admin')->viewData('page')['props'])
        ->not->toHaveKey('widgetData');
});

it('resolves the payload on the follow-up request', function (): void {
    Product::factory()->create(['name' => 'Keyboard', 'stock' => 2]);
    Product::factory()->create(['name' => 'Monitor', 'stock' => 500]);

    $version = $this->get('/admin')->viewData('page')['version'];

    $this->get('/admin', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $version,
        'X-Inertia-Partial-Component' => 'panel/Dashboard',
        'X-Inertia-Partial-Data' => 'widgetData',
    ])
        ->assertOk()
        ->assertJsonPath('props.widgetData.'.LowStock::id().'.products.0.name', 'Keyboard')
        ->assertJsonCount(1, 'props.widgetData.'.LowStock::id().'.products');
});

it('narrows to the threshold the filter asked for', function (): void {
    Product::factory()->create(['name' => 'Keyboard', 'stock' => 7]);

    $widget = (new LowStock)->withFilters(['threshold' => '5']);

    expect($widget->data()['products'])->toBeEmpty();
});

it('is hidden, and never queries, for somebody who may not list products', function (): void {
    $this->actingAs(User::factory()->create());

    // The panel refuses a non-administrator outright; canView() is the
    // second lock, for a widget reused somewhere less protected.
    expect(LowStock::canView())->toBeFalse();
});
```

```bash
php artisan test --compact --filter=LowStock
```

`tests/Feature/Panel/WidgetRenderingTest.php` is the framework's own version of these, against the four example widgets in `examples/app/Panels/Admin/Widgets/`.

## Gotchas

- **A new `.vue` file needs a rebuild.** The registry is an `import.meta.glob` over `resources/js/pages/Panels/**/Widgets/*.vue`, evaluated at build time. This is the most common reason a widget renders the fallback.
- **The glob matches direct children only.** `Widgets/Boards/LowStock.vue` is not registered; `Widgets/LowStock.vue` is.
- **An unknown component name renders `WidgetFallback`, not an error.** One mistyped name must not take the dashboard down. In development the registry warns once per name in the console; in production it does not.
- **Every `data()` key is a prop.** A key your `defineProps` does not declare becomes a fall-through attribute on the root element, which is silent and confusing.
- **`canView()` runs before `data()`.** Do not put the authorization check inside `data()` — by then the query has run.
- **`$lazy` changes the payload shape.** `data` is null on the first response and `widgetData` is absent; a component that requires either will warn on first paint.
- **Widget ids come from the class basename.** Two widgets called `LowStock` in different namespaces collide as `low-stock`, which matters for filter state and for the deferred-data keys.
- **`context()` throws off a resource page.** A widget that reads the page context belongs in `headerWidgets()` or `footerWidgets()`, never on a dashboard.

## See also

- [Product Resource](product-resource.md) — the data this widget summarizes
- [Admin Panel Example](admin-panel.md) — four widgets, one of each type
- [Widgets Overview](../widgets/overview.md)
- [Custom Vue Widgets](../widgets/custom-vue.md)
- [Custom Widgets (frontend)](../frontend/custom-widgets.md)
- [Stats Widgets](../widgets/stats.md), [Chart Widgets](../widgets/charts.md), [Table Widgets](../widgets/tables.md)
- [Widget Filters](../widgets/filters.md), [Lazy Loading](../widgets/lazy-loading.md), [Polling](../widgets/polling.md)
- [Widget Layout](../widgets/layout.md), [Widget Authorization](../widgets/authorization.md)
- [Dashboards](../panels/dashboards.md)
- [Component Registries](../concepts/component-registries.md)
- [make:panel-widget](../cli/make-panel-widget.md)
