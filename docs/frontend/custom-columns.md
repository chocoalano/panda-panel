# Custom Columns

`PandaPanel\Tables\Columns\CustomColumn` is a table column drawn by a Vue component of your own. You reach for one when the value is not text, a number, a badge, a date, an image, an icon or a colour — a progress bar, a sparkline, a stacked pair of labels, a health indicator.

The PHP class still decides what the cell *is*. The component name comes from the class, never from a request, and the frontend resolves it through a build-time glob, so a name the build never saw cannot be reached however it arrives.

## A minimal working example

Declare the column:

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Tables\Columns\CustomColumn;
use PandaPanel\Tables\TableSchema;

public static function table(TableSchema $schema): TableSchema
{
    return $schema->columns([
        CustomColumn::make('accountAge')
            ->label('Account age')
            ->component('Panels/Admin/Columns/AccountAge')
            ->state(static fn (Model $record): array => [
                'days' => (int) $record->created_at->diffInDays(now()),
                'label' => $record->created_at->diffForHumans(),
            ]),
    ]);
}
```

Write the component at exactly that path under `resources/js/pages/`:

```vue
<!-- resources/js/pages/Panels/Admin/Columns/AccountAge.vue -->
<script setup lang="ts">
import { computed } from 'vue';

/** Whatever `state()` returned, as untyped JSON. */
const props = defineProps<{ state: unknown }>();

const reading = computed(() => {
    const value = props.state;

    if (typeof value !== 'object' || value === null) {
        return null;
    }

    const { days, label } = value as { days?: unknown; label?: unknown };

    return typeof days === 'number' && typeof label === 'string'
        ? { days, label }
        : null;
});
</script>

<template>
    <span v-if="reading" class="whitespace-nowrap">{{ reading.label }}</span>
    <span v-else class="text-muted-foreground">—</span>
</template>
```

```bash
npm run build     # or: npm run dev
```

## The class

`CustomColumn` extends `PandaPanel\Tables\Columns\Column`, so every column method you already know still applies. Two methods are its own.

| Member | Signature | Default |
| --- | --- | --- |
| `type()` | `public function type(): ColumnType` | `ColumnType::Custom` |
| `component()` | `component(string $component): self` | `''` |
| `state()` | `state(Closure $callback): self` | null — falls back to the attribute |
| `toCell()` | `toCell(Model $record): mixed` | the state closure, or `resolveValue()` |

### `component()`

```php
public function component(string $component): self
```

A **build-time registry key**: the path below `resources/js/pages/`, without the `.vue` extension. Never markup, never a filesystem path, never a class name.

```php
CustomColumn::make('health')->component('Panels/Admin/Columns/HealthBar');
// resolves resources/js/pages/Panels/Admin/Columns/HealthBar.vue
```

The default is the empty string, which resolves to nothing and renders the column's placeholder. There is no exception for it — a column that draws a placeholder is a working table with one dull column, and that is a better failure than a page that will not render.

### `state()`

```php
/**
 * @param  Closure(Model): mixed  $callback
 */
public function state(Closure $callback): self
```

Builds the cell from the whole record rather than from one attribute:

```php
use App\Models\Order;
use PandaPanel\Tables\Columns\CustomColumn;

CustomColumn::make('fulfilment')
    ->component('Panels/Admin/Columns/Fulfilment')
    ->state(static fn (Order $record): array => [
        'shipped' => $record->shipped_items,
        'total' => $record->total_items,
        'late' => $record->due_at?->isPast() ?? false,
    ]);
```

Whatever it returns is what the component receives as `state`, and it must serialize to scalars, arrays and nulls like every other cell. Closures, models and enums do not cross.

Without `state()`, the column falls back to `resolveValue()` — the attribute named by `make()`, with dot notation for relations, `default()` applied, and `formatUsing()` applied if declared:

```php
CustomColumn::make('profile.score')
    ->component('Panels/Admin/Columns/ScoreRing');
// state is $record->profile->score
```

### Inherited methods worth knowing

Everything on `Column` works on a `CustomColumn`. The ones that change what the frontend does with your component:

| Method | Signature | Effect |
| --- | --- | --- |
| `label` | `label(string $label): static` | the header text; defaults to a headline of the name |
| `placeholder` | `placeholder(string $placeholder): static` | drawn in place of the component when the name does not resolve |
| `default` | `default(mixed $default): static` | stands in for a null attribute, before `state()` |
| `formatUsing` | `formatUsing(Closure $callback): static` | applies to the attribute path, not to a `state()` return |
| `alignment` | `alignment(Alignment\|string $alignment): static` | `start`, `center`, `end`, `justify` |
| `headerAlignment` | `headerAlignment(Alignment\|string $alignment): static` | defaults to the cell alignment |
| `width` | `width(string $width): static` | a CSS length, applied inline — a Tailwind class cannot be built here |
| `visible` | `visible(bool $visible = true): static` | drops the column from the definition |
| `toggleable` | `toggleable(bool $toggleable = true): static` | offers it in the column manager |
| `frozen` | `frozen(ColumnPin\|bool $pin = true): static` | pins it to an edge while the table scrolls |
| `sortable` | `sortable(bool $sortable = true, ?string $column = null): static` | needs a real database column; pass one when the name is not it |
| `sortUsing` | `sortUsing(Closure $callback): static` | for an order the schema cannot express as a column name |
| `searchable` | `searchable(bool $searchable = true, ?array $columns = null, bool $individually = false): static` | same rule — the search runs in SQL |
| `tooltip` | `tooltip(Closure\|string $tooltip): static` | per-row tooltip |
| `extraAttributes` | `extraAttributes(Closure\|array $attributes): static` | per-row cell attributes |
| `url` | `url(Closure $callback): static` | makes the cell a link |
| `action` | `action(Action $action): static` | makes the cell run an action |

Sorting and searching are worth stating plainly: they happen in the database, against a column. A `state()` closure runs in PHP after the rows are fetched, so a value it computes is not something the query can order by. Use `sortUsing()` when the order has to be expressed as a query.

## What the component receives

Exactly one prop:

```ts
defineProps<{ state: unknown }>();
```

`DataTableCell.vue` renders it as:

```vue
<component :is="customComponent" v-if="customComponent" :state="value" />
<span v-else class="text-muted-foreground">{{ placeholder }}</span>
```

`unknown`, not a typed shape, and that is on purpose: the value crosses the wire as JSON, so it is narrowed rather than asserted. A shape that does not match must render an empty cell instead of throwing inside the table — one bad row would otherwise take the page down.

The component is loaded on demand with `defineAsyncComponent`. A custom column is rare, and bundling every one of them into the panel's main chunk would cost every page that has none.

If you want a typed prop, narrow it yourself and keep the entry point `unknown`:

```vue
<script setup lang="ts">
import { computed } from 'vue';

interface Fulfilment {
    shipped: number;
    total: number;
    late: boolean;
}

const props = defineProps<{ state: unknown }>();

function isFulfilment(value: unknown): value is Fulfilment {
    if (typeof value !== 'object' || value === null) {
        return false;
    }

    const candidate = value as Record<string, unknown>;

    return (
        typeof candidate.shipped === 'number' &&
        typeof candidate.total === 'number' &&
        typeof candidate.late === 'boolean'
    );
}

const fulfilment = computed(() =>
    isFulfilment(props.state) ? props.state : null,
);
</script>
```

## The serialized definition

`CustomColumn::toArray()` is the base column definition plus one key:

```php
[
    'name' => 'accountAge',
    'label' => 'Account age',
    'type' => 'custom',
    'sortable' => false,
    'searchable' => false,
    'individuallySearchable' => false,
    'visible' => true,
    'toggleable' => true,
    'alignment' => 'start',
    'headerAlignment' => 'start',
    'placeholder' => null,
    'headerTooltip' => null,
    'wrapHeader' => false,
    'width' => null,
    'frozen' => null,
    'component' => 'Panels/Admin/Columns/AccountAge',
]
```

Its TypeScript mirror:

```ts
export interface CustomColumnDefinition extends BaseColumnDefinition {
    type: 'custom';
    component: string;
}

/** A custom column's state is whatever its component was written to take. */
export type CustomCell = unknown;
```

## Where the component must live

The registry is an `import.meta.glob` over one pattern:

```text
resources/js/pages/Panels/**/Columns/*.vue
```

```ts
import {
    resolveColumnComponent,
    hasColumnComponent,
} from '@/panel/tables/registry';

hasColumnComponent('Panels/Admin/Columns/AccountAge');     // boolean
resolveColumnComponent('Panels/Admin/Columns/AccountAge'); // loader or null
```

| Function | Signature |
| --- | --- |
| `hasColumnComponent` | `(name: string) => boolean` |
| `resolveColumnComponent` | `(name: string) => (() => Promise<{ default: Component }>) \| null` |

The key is the path below `pages/` without the extension, which is why `component()` is written as `Panels/Admin/Columns/AccountAge`. Two consequences follow:

- A component anywhere else — `resources/js/components/`, a nested `Columns/Parts/` directory — is not matched by the glob and will not resolve. The pattern ends in `*.vue`, not `**/*.vue`.
- The glob is a build-time allowlist. A name that was not compiled in cannot reach anything, whatever arrives in a request.

## The table's other component seam: empty states

A table may also replace its whole empty state with a component, through a registry of the same shape:

```php
use PandaPanel\Tables\TableSchema;

public static function table(TableSchema $schema): TableSchema
{
    return $schema->emptyStateComponent('Panels/Admin/EmptyStates/NoOrders');
}
```

```vue
<!-- resources/js/pages/Panels/Admin/EmptyStates/NoOrders.vue -->
<script setup lang="ts">
defineProps<{
    emptyState: {
        heading: string;
        description: string | null;
        icon: string | null;
        component: string | null;
        actions: unknown[];
    };
}>();
</script>
```

| Piece | Value |
| --- | --- |
| PHP | `TableSchema::emptyStateComponent(string $component): self` |
| Glob | `resources/js/pages/Panels/**/EmptyStates/*.vue` |
| Resolver | `resolveEmptyStateComponent(name: string)` from `@/panel/tables/registryEmptyStates` |
| Prop | `emptyState` — the serialized empty state, heading, description, icon and actions included |
| On an unknown name | the table draws its ordinary empty state |

## When a name does not resolve

The cell renders its `placeholder` — an em dash by default — rather than throwing. One mistyped component cannot take a table down, and every other column in the row stays readable.

The column registry does **not** log a development warning; only the form and widget registries do. If a custom column is drawing a placeholder for every row, check in this order:

1. the spelling of `component()` against the file path, including case;
2. that the file is a direct child of a `Columns/` directory under `resources/js/pages/Panels/`;
3. that the build has run since the file was added.

You can also ask the registry directly from a component or the browser console during development:

```ts
import { hasColumnComponent } from '@/panel/tables/registry';

hasColumnComponent('Panels/Admin/Columns/AccountAge');
```

## Gotchas

- **`state()` runs per row, in PHP, after the query.** A closure that touches a relation on every row is an N+1. Eager-load in the resource's query.
- **A `state()` return is not passed through `formatUsing()`.** `formatUsing()` applies to the attribute path that `resolveValue()` reads; a state closure is the whole answer.
- **Sorting and searching are SQL.** `sortable()` on a computed column will order by an attribute that may not exist. Either name the real column or use `sortUsing()`.
- **The glob is not aliased.** The pattern in `registry.ts` is relative (`../../pages/Panels/**/Columns/*.vue`) because Vite's dev server resolves an aliased glob to nothing at all while the production build resolves it normally — the worst possible failure mode.
- **A new file needs a rebuild.** `import.meta.glob` is evaluated at build time. The dev server picks up new files; a production bundle built before the file existed does not contain it.
- **Nothing renderable crosses the wire.** The server sends a name and data. There is no way to send markup, a template, or a component from PHP, and that is the point.
- **Column width is inline, not a class.** `width('12rem')` becomes a style; an interpolated Tailwind class would not exist in the bundle.

## See also

- [Columns](../tables/columns.md)
- [Editable Columns](../tables/editable-columns.md), [Pinned Columns](../tables/pinned-columns.md)
- [Tables overview](../tables/overview.md)
- [Custom Fields](custom-fields.md), [Custom Widgets](custom-widgets.md)
- [Vue Component Tree](component-tree.md)
- [Component Registries](../concepts/component-registries.md)
- [Published Asset Structure](assets.md)
