# Custom Vue Widgets

A custom widget is a widget whose body you draw yourself, in a Vue single-file component. You reach for one when the shape of what you are showing is not a row of figures, a chart the closed [`ChartOptions`](charts.md#chartoptions) set can express, or a table — a status board, a map, a progress ring, a feed.

The PHP class still owns the data. The component name comes from the class, never from a request, and the frontend resolves it through a build-time glob, so a name that was not compiled in cannot be reached however it arrives.

## A minimal working example

```bash
php artisan make:panel-widget SystemInfo --panel=Admin --type=custom
```

That writes two files. The class:

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Widgets;

use Illuminate\Foundation\Application;
use PandaPanel\Widgets\CustomWidget;

final class SystemInfo extends CustomWidget
{
    protected static string $component = 'Panels/Admin/Widgets/SystemInfo';

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return [
            'laravel' => Application::VERSION,
            'php' => PHP_VERSION,
            'environment' => app()->environment(),
            'debug' => (bool) config('app.debug'),
        ];
    }
}
```

And the component, at `resources/js/pages/Panels/Admin/Widgets/SystemInfo.vue`:

```vue
<script setup lang="ts">
defineProps<{
    laravel: string;
    php: string;
    environment: string;
    debug: boolean;
}>();
</script>

<template>
    <div class="flex h-full flex-col gap-3 rounded-lg border p-4">
        <h3 class="text-sm font-medium">System</h3>
        <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
            <dt class="text-muted-foreground">Laravel</dt>
            <dd class="tabular-nums">{{ laravel }}</dd>
            <dt class="text-muted-foreground">PHP</dt>
            <dd class="tabular-nums">{{ php }}</dd>
            <dt class="text-muted-foreground">Environment</dt>
            <dd>{{ environment }}</dd>
            <dt class="text-muted-foreground">Debug</dt>
            <dd>{{ debug ? 'On' : 'Off' }}</dd>
        </dl>
    </div>
</template>
```

Rebuild the frontend (`npm run dev` or `npm run build`) and it appears on the dashboard.

## The class

`PandaPanel\Widgets\CustomWidget` extends `PandaPanel\Widgets\Widget`.

| Member | Signature | Default |
| --- | --- | --- |
| `$component` | `protected static string` | `''` |
| `type()` | `public static function type(): WidgetType` | `WidgetType::Custom` |
| `component()` | `public static function component(): string` | `$component` |
| `data()` | `abstract public function data(): array` | inherited, still abstract |
| `toDefinition()` | `public function toDefinition(): array` | the base definition plus `component` |

### `$component`

A path under `resources/js/pages/`, without the `.vue` extension:

```php
protected static string $component = 'Panels/Admin/Widgets/ServerHealth';
```

The generator writes this for you as `Panels/{Panel}/Widgets/{Class}`.

### `component()`

```php
public static function component(): string
```

Returns `$component`, and throws `RuntimeException` when it is still the empty default:

```text
The custom widget [App\Panels\Admin\Widgets\ServerHealth] must declare a $component.
```

Override it only if the name has to be computed — it is static, so it still cannot depend on the request.

### `data()`

```php
/** @return array<string, mixed> */
abstract public function data(): array
```

Scalars, arrays and nulls. Every key becomes a prop on your component, because the renderer binds the payload with `v-bind`:

```vue
<component :is="resolved" v-bind="data" />
```

So a `data()` returning `['laravel' => ..., 'php' => ...]` gives you `laravel` and `php` props, not a single `data` prop. Declare them with `defineProps` and the pair stays honest.

Serialize models yourself — pass arrays, not Eloquent objects:

```php
use App\Models\Order;

public function data(): array
{
    return [
        'orders' => Order::query()
            ->latest()
            ->limit(5)
            ->get(['id', 'reference', 'total'])
            ->map(static fn (Order $order): array => [
                'id' => $order->id,
                'reference' => $order->reference,
                'total' => (string) $order->total,
            ])
            ->all(),
    ];
}
```

### `toDefinition()`

Adds one key to the base definition:

```php
[
    'id' => 'system-info',
    'type' => 'custom',
    'component' => 'Panels/Admin/Widgets/SystemInfo',
    // ...sort, columnSpan, lazy, heading, description, polling, filters, data
]
```

## Where the component must live

The frontend resolves names through a build-time `import.meta.glob` over:

```text
resources/js/pages/Panels/**/Widgets/*.vue
```

The registry key is the path below `pages/` without the extension, which is why `$component` is written as `Panels/Admin/Widgets/SystemInfo`. Two consequences follow:

- A component anywhere else — `resources/js/components/`, a nested `Widgets/Parts/` directory — is not in the glob and will not resolve.
- The glob is a build-time allowlist. A name that was not compiled in cannot reach anything, whatever arrives in a request.

The directory is the same one `PandaPanel\Support\FrontendPaths::pages()` writes to, configured by `panda-panel.frontend.pages_path`:

```php
'frontend' => [
    'panel_path' => 'js/panel',
    'pages_path' => 'js/pages/Panels',
],
```

Changing `pages_path` moves where the generator writes, but the glob in `resources/js/panel/widgets/registry.ts` is a literal string in the bundle — move one and you must move the other. See [Component registries](../concepts/component-registries.md).

## When a name does not resolve

The renderer draws a neutral fallback rather than throwing: a dashed box reading "This widget is unavailable." One mistyped component name does not take a dashboard down.

In development it also logs once, per name, to the console:

```text
[panel] The widget component [Panels/Admin/Widgets/Typo] is not in the build-time
registry, so a fallback is drawn instead. It has to live under
resources/js/pages/Panels/{Panel}/Widgets/ — check the path and the spelling, then rebuild.
```

Production is silent, because this is a build problem and a console message on a live panel helps nobody. The three reasons a name fails to resolve are indistinguishable from the screen:

1. a typo in `$component` or in the filename;
2. the file is outside `resources/js/pages/Panels/**/Widgets/`;
3. the build has not been re-run since the file was added.

The backend does not check any of this. It cannot see the bundle, so it serializes the name it was given and lets the frontend answer — a test in the package asserts exactly that.

## What the shell already draws

Your component renders the *body* only. `WidgetShell.vue` wraps every widget and already provides:

- the `$heading` and `$description`;
- the filter form, inline or in a dialog;
- the polling timer;
- the grid cell and its column span;
- the `panel-widget` CSS hook class.

So do not re-draw a heading inside your component if you set `$heading` on the class, and size to the cell you are given rather than to the viewport.

While a [lazy](lazy-loading.md) custom widget's payload is in flight, the shell shows a three-row skeleton and your component is not mounted at all — it mounts once the data lands, so props are never undefined.

## Filters and polling

Custom widgets use the same machinery as every other type:

```php
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Widgets\CustomWidget;

final class ServerHealth extends CustomWidget
{
    protected static string $component = 'Panels/Admin/Widgets/ServerHealth';

    protected static ?int $pollingInterval = 15;

    protected static bool $lazy = true;

    public function filterSchema(): FormSchema
    {
        return FormSchema::make()->schema([
            Select::make('region')
                ->options(['eu' => 'Europe', 'us' => 'United States'])
                ->default('eu'),
        ]);
    }

    public function data(): array
    {
        return ['region' => (string) $this->filter('region', 'eu')];
    }
}
```

A poll is a partial reload of the page's widget props, so your component receives new props and re-renders; it does not need to fetch anything itself. See [Polling](polling.md) and [Filters](filters.md).

## Notes

- The component name is compiled in, and the payload is data. A custom widget is still not a place to send behaviour from the server — no handler, no callback, no class name crosses.
- `$component` is static. A widget that must pick between two components per user is two widgets with different `canView()`.
- The generator refuses to overwrite an existing file. Pass `--force` when you mean to.
- `make:panel-widget --type=custom` always writes the `.vue` file too, because a custom widget without its component renders only the fallback.
- Anything you import in the component must already be in the application's frontend dependencies. The package installs no charting or UI library beyond what the panel itself ships.

## See also

- [Widgets overview](overview.md)
- [Chart widgets](charts.md)
- [Filters](filters.md)
- [Lazy loading](lazy-loading.md)
- [Polling](polling.md)
- [Component registries](../concepts/component-registries.md)
- [Frontend assets](../frontend/assets.md)
- [make:panel-widget](../cli/make-panel-widget.md)
