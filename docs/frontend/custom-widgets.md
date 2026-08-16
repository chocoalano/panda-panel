# Custom Widgets

`PandaPanel\Widgets\CustomWidget` is a widget whose body you draw yourself, in a Vue single-file component. Reach for one when what you are showing is not a row of figures, a chart, or a table — a status board, a progress ring, a map, a feed, a system-information card.

This page is the frontend half: what the component receives, where it has to live, and what the shell has already drawn around it. The PHP half — filters, polling, lazy loading, authorization — is [Custom Vue Widgets](../widgets/custom-vue.md).

## A minimal working example

```bash
php artisan make:panel-widget SystemInfo --panel=Admin --type=custom
```

Two files. The class:

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Widgets;

use Illuminate\Foundation\Application;
use PandaPanel\Widgets\CustomWidget;

final class SystemInfo extends CustomWidget
{
    protected static int $sort = 40;

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

```bash
npm run build     # or: npm run dev
```

## The class

`CustomWidget` extends `PandaPanel\Widgets\Widget` and adds one property and one method.

| Member | Signature | Default |
| --- | --- | --- |
| `$component` | `protected static string $component` | `''` |
| `type()` | `public static function type(): WidgetType` | `WidgetType::Custom` |
| `component()` | `public static function component(): string` | returns `$component` |
| `data()` | `abstract public function data(): array` | still abstract — you must write it |
| `toDefinition()` | `public function toDefinition(): array` | the base definition plus `component` |

### `$component`

A path under `resources/js/pages/`, without the `.vue` extension:

```php
protected static string $component = 'Panels/Admin/Widgets/ServerHealth';
```

`make:panel-widget --type=custom` writes it for you as `Panels/{Panel}/Widgets/{Class}`, and writes the matching `.vue` file — the component is not optional for this type, because a custom widget without one renders only the fallback.

### `component()`

```php
public static function component(): string
```

Returns `$component`, and throws `RuntimeException` when it is still the empty default:

```text
The custom widget [App\Panels\Admin\Widgets\ServerHealth] must declare a $component.
```

That is the one place in this path that throws rather than degrading, and it is a developer error caught at serialization time — before anything reaches a browser. Override it only if the name must be computed; it is static, so it still cannot depend on the request.

### `data()`

```php
/** @return array<string, mixed> */
abstract public function data(): array;
```

Every key becomes a **prop** on your component, because `CustomWidget.vue` binds the payload with `v-bind`:

```vue
<component :is="resolved" v-if="resolved" v-bind="data" />
```

So `['laravel' => …, 'php' => …]` gives you `laravel` and `php` props, not one `data` prop. Declare them with `defineProps` and the pair stays honest — a key the component does not declare lands as a fall-through attribute on the root element instead.

Scalars, arrays and nulls only. Serialize models yourself:

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

The base widget definition with one extra key:

```php
[
    'id' => 'system-info',
    'type' => 'custom',
    'sort' => 40,
    'columnSpan' => ['default' => 1, 'md' => 1, 'lg' => 1, 'xl' => 1],
    'lazy' => false,
    'heading' => null,
    'description' => null,
    'polling' => null,
    'filters' => null,
    'data' => ['laravel' => '…', 'php' => '…'],
    'component' => 'Panels/Admin/Widgets/SystemInfo',
]
```

A lazy widget is serialized with `data => null`; the payload arrives separately as a deferred prop keyed by widget id.

### Inherited statics worth setting

```php
use PandaPanel\Widgets\CustomWidget;

final class ServerHealth extends CustomWidget
{
    protected static string $component = 'Panels/Admin/Widgets/ServerHealth';

    protected static int $sort = 10;

    protected static int|string|array $columnSpan = ['default' => 1, 'md' => 2, 'lg' => 1, 'xl' => 2];

    protected static ?string $heading = 'Server health';

    protected static ?string $description = 'Updated every fifteen seconds.';

    protected static bool $lazy = true;

    protected static ?int $pollingInterval = 15;

    public static function canView(): bool
    {
        return auth()->user()?->can('viewInfrastructure') ?? false;
    }

    public function data(): array
    {
        return ['load' => sys_getloadavg()[0] ?? 0.0];
    }
}
```

| Member | Type | Default |
| --- | --- | --- |
| `$sort` | `int` | `0` |
| `$columnSpan` | `int\|string\|array` | `1` |
| `$lazy` | `bool` | `false` |
| `$heading` | `?string` | `null` |
| `$description` | `?string` | `null` |
| `$pollingInterval` | `?int` seconds | `null` |
| `canView()` | `static canView(): bool` | `true` |

## What the shell has already drawn

Your component renders the **body only**. `WidgetShell.vue` wraps every widget — of every type — and provides:

- the heading and description;
- the filter form, inline or in a dialog;
- the polling timer, which reloads the page's widget props rather than asking for one widget;
- the grid cell and its column span, from `WidgetGrid.vue`;
- the `panel-widget` CSS hook class.

So do not draw a heading inside your component if you set `$heading` on the class, and size to the cell you were given rather than to the viewport. `h-full` on your root element is usually right, because the grid row is as tall as its tallest widget.

While a lazy widget's payload is in flight, the shell shows a skeleton and your component is **not mounted at all**. It mounts once the data lands, so props are never undefined.

## Where the component must live

```text
resources/js/pages/Panels/**/Widgets/*.vue
```

```ts
import {
    resolveWidgetComponent,
    hasWidgetComponent,
} from '@/panel/widgets/registry';

hasWidgetComponent('Panels/Admin/Widgets/SystemInfo');     // boolean
resolveWidgetComponent('Panels/Admin/Widgets/SystemInfo'); // loader or null
```

| Function | Signature |
| --- | --- |
| `hasWidgetComponent` | `(name: string) => boolean` |
| `resolveWidgetComponent` | `(name: string) => (() => Promise<{ default: Component }>) \| null` |

The registry key is the path below `pages/` without the extension, which is why `$component` reads as `Panels/Admin/Widgets/SystemInfo`. The pattern ends in `*.vue`, so `Widgets/Charts/Revenue.vue` is not registered.

The directory is the one `PandaPanel\Support\FrontendPaths::pages()` writes to, configured by `panda-panel.frontend.pages_path`. Moving that config moves the generator's output; the glob is a literal string inside `resources/js/panel/widgets/registry.ts` and has to be moved with it.

## When a name does not resolve

`WidgetFallback.vue` renders instead: a dashed box with an alert icon reading *This widget is unavailable.* Neutral rather than alarming, because one mistyped component name must not take a dashboard down.

In development the registry logs once per name:

```text
[panel] The widget component [Panels/Admin/Widgets/Typo] is not in the build-time
registry, so a fallback is drawn instead. It has to live under
resources/js/pages/Panels/{Panel}/Widgets/ — check the path and the spelling,
then rebuild.
```

Production is silent. This is a build problem, and a console message on a live panel helps nobody. The three causes are indistinguishable from the screen:

1. a typo in `$component` or in the filename;
2. the file is outside `resources/js/pages/Panels/**/Widgets/`;
3. the build has not been re-run since the file was added.

The server cannot check any of this. It cannot see the bundle, so it serializes the name it was given and lets the frontend answer.

## Filters and polling from the component's side

Both are the shell's, not yours. A widget with a filter schema gets its controls drawn by `WidgetFilters.vue`, whose values go into the query string — a filtered dashboard is a link somebody can send, and the back button means what it says. The server narrows whatever arrives through the schema that declared it and calls `data()` again.

A poll is a partial reload of the page's widget props:

```ts
router.reload({ only: ['widgets', 'widgetData'] });
```

So your component receives new props and re-renders. It does not need to fetch anything, and it must not: a second endpoint answering for one widget would have to re-resolve the page's authorization, its filters and its context to say anything true.

## Gotchas

- **`data()` keys are props, not a payload object.** A component declaring `defineProps<{ data: … }>()` will never receive anything.
- **A prop your component does not declare becomes an attribute.** Vue falls through undeclared props to the root element, so a typo shows up as a stray HTML attribute rather than an error.
- **`$component` is static.** A widget that must pick between two components per user is two widgets with different `canView()`.
- **A new file needs a rebuild.** `import.meta.glob` is evaluated at build time. This is the most common reason a widget that exists renders the fallback.
- **The glob is relative, never aliased.** Vite's dev server resolves an aliased glob to nothing at all while the production build resolves it normally, so an aliased pattern would mean every custom widget falls back in development and works once built.
- **`make:panel-widget --type=custom` always writes the `.vue` file.** For the other three types it does not, because they have shipped renderers.
- **Anything you import must already be in the application's frontend dependencies.** The package installs no charting or UI library beyond what the panel itself ships.
- **The widget's own authorization is `canView()`, and the page it sits on authorizes independently.** Drawing something in a component is not a permission.

## See also

- [Custom Vue Widgets](../widgets/custom-vue.md)
- [Widgets overview](../widgets/overview.md), [Layout](../widgets/layout.md), [Filters](../widgets/filters.md), [Polling](../widgets/polling.md), [Lazy loading](../widgets/lazy-loading.md)
- [Custom Columns](custom-columns.md), [Custom Fields](custom-fields.md)
- [Vue Component Tree](component-tree.md)
- [Component Registries](../concepts/component-registries.md)
- [make:panel-widget](../cli/make-panel-widget.md)
