# Custom Entries

`PandaPanel\Infolists\Components\CustomEntry` draws a value with a Vue component of your own. You reach for it when none of the ten built-in entry types says what the value is — a gauge, a sparkline, a map, a diff.

It is a different way of *drawing* a value, not a way of fetching one: the entry still resolves against the record on the server, and the component receives the result as data.

## A minimal custom entry

Two files. The PHP side names a component and supplies the value:

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Infolists\Components\CustomEntry;

CustomEntry::make('score')
    ->label('Health score')
    ->component('Panels/Admin/Entries/Gauge')
    ->config(['max' => 100])
    ->state(static fn (Model $record): array => [
        'value' => $record->getAttribute('health_score'),
        'trend' => $record->getAttribute('health_trend'),
    ]);
```

The Vue side draws it, at `resources/js/pages/Panels/Admin/Entries/Gauge.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue';

/**
 * The value is whatever the PHP entry resolved, so it arrives as untyped
 * JSON and is narrowed here rather than asserted.
 */
const props = defineProps<{
    value: unknown;
    config: Record<string, unknown>;
}>();

const max = computed(() =>
    typeof props.config.max === 'number' ? props.config.max : 100,
);

const reading = computed(() => {
    const value = props.value;

    if (typeof value !== 'object' || value === null) {
        return null;
    }

    const { value: score } = value as { value?: unknown };

    return typeof score === 'number' ? score : null;
});

const percent = computed(() =>
    reading.value === null
        ? 0
        : Math.min(100, Math.round((reading.value / max.value) * 100)),
);
</script>

<template>
    <div v-if="reading !== null" class="flex items-center gap-2">
        <div class="h-1.5 w-24 overflow-hidden rounded-full bg-muted">
            <div
                class="h-full rounded-full bg-primary"
                :style="{ width: `${percent}%` }"
            />
        </div>
        <span class="tabular-nums">{{ reading }} / {{ max }}</span>
    </div>
    <span v-else class="text-muted-foreground">—</span>
</template>
```

Run the build, and the view page draws the gauge where the entry sits.

## The API

`CustomEntry` extends `Entry`, so `label()`, `placeholder()`, `helperText()`, `columnSpan()`, `columnSpanFull()`, `formatUsing()`, `visible()` and `action()` all work as they do everywhere. It adds three:

| Method | Signature | Default |
| --- | --- | --- |
| `component()` | `component(string $component): self` | `''` — nothing resolves, the placeholder shows |
| `config()` | `config(array $config): self` | `[]` |
| `state()` | `state(Closure $callback): self` | `null` |
| `type()` | `type(): EntryType` | `EntryType::Custom` |
| `toValue()` | `toValue(Model $record): mixed` | The `state()` result, or the resolved attribute |

### `component()`

A build-time registry key — the path below `resources/js/pages/`, without the extension:

| File | Name |
| --- | --- |
| `resources/js/pages/Panels/Admin/Entries/Gauge.vue` | `Panels/Admin/Entries/Gauge` |
| `resources/js/pages/Panels/App/Entries/Sparkline.vue` | `Panels/App/Entries/Sparkline` |

Never markup, never a path, never built from a request value — the same rule custom columns, fields, and widgets follow.

### `config()`

Static options the component reads. Serialized as JSON, so it holds data and not behaviour:

```php
CustomEntry::make('score')
    ->component('Panels/Admin/Entries/Gauge')
    ->config(['max' => 10, 'unit' => 'points', 'showTrend' => true]);
```

A closure in `config()` will not survive the encode. Anything that depends on the record belongs in `state()`.

### `state()`

Builds the value from the whole record rather than from one attribute, for a renderer that needs more than a column holds:

```php
public function state(Closure $callback): self
// Closure(Model): mixed
```

```php
use Illuminate\Database\Eloquent\Model;

CustomEntry::make('activity')
    ->component('Panels/Admin/Entries/Sparkline')
    ->state(static fn (Model $record): array => $record->logins()
        ->latest()
        ->limit(30)
        ->pluck('count')
        ->all());
```

Without `state()`, the entry resolves its name like any other — `data_get()` plus `formatUsing()` — so a custom renderer over a plain column needs no callback at all:

```php
CustomEntry::make('meta')->component('Panels/Admin/Entries/MetaCard');
```

`state()` and `formatUsing()` are alternatives rather than a chain: when `state()` is set it answers the value and `formatUsing()` is never reached.

## The component contract

`InfolistEntry.vue` draws the label, the helper text, and the action button. Your component draws the value and nothing else. It receives three props:

| Prop | Type | Meaning |
| --- | --- | --- |
| `entry` | `CustomEntryDefinition` | The whole definition — name, label, placeholder, `columnSpan`, `action` |
| `value` | `unknown` | What `state()` or the resolved attribute returned |
| `config` | `Record<string, unknown>` | What `config()` declared |

It emits nothing. An infolist is read-only, so there is no `update:modelValue` and no state to write back — that is what distinguishes a custom entry from a [custom field](../forms/custom-fields.md).

Narrow everything you read. Props cross the wire as untyped JSON, and a shape that does not match should render an em dash rather than throw inside the view page.

The TypeScript definition to import when you want it typed:

```ts
import type { CustomEntryDefinition } from '@/panel/types/infolist';

defineProps<{
    entry: CustomEntryDefinition;
    value: unknown;
    config: Record<string, unknown>;
}>();
```

## Where components are found

The registry is `resources/js/panel/forms/registry.ts`, shared by custom fields, custom form layouts, custom entries, and custom action modals. Its globs are a build-time allowlist:

```text
resources/js/pages/Panels/**/Fields/*.vue      ← CustomField
resources/js/pages/Panels/**/Schemas/*.vue     ← CustomComponent
resources/js/pages/Panels/**/Entries/*.vue     ← CustomEntry
resources/js/pages/Panels/**/Modals/*.vue      ← custom action modals
```

One registry rather than four that would say the same thing: what they have in common is that a *name* resolves to a component the build saw, and where that name was declared changes nothing about the rule. A name that was not compiled in cannot be reached however it arrives.

Components load on demand. A custom entry is rare, and bundling every one of them would cost every view page that has none.

The globs can only see your components because the panel's Vue sources live in your application — published by `php artisan panel:install`, or `php artisan vendor:publish --tag=panda-panel-assets`. See [Frontend assets](../frontend/assets.md).

## When a name resolves to nothing

The entry renders its placeholder, or an em dash when it declared none. Nothing throws: one mistyped component cannot take a view page down, and the entries around it still render.

In development the registry warns once per name in the browser console, naming the directory the file has to live under. In production it does not — this is a build problem, and a console message on a live panel helps nobody. The three causes are a typo, a file outside the globbed directory, and a build that has not been re-run.

## Adding a whole new entry type

`CustomEntry` is the supported extension point. Adding a new `EntryType` is a change to the framework itself and takes three edits that must land together:

1. The PHP class, extending `Entry` and returning a new `PandaPanel\Infolists\Enums\EntryType` case.
2. The TypeScript definition, added to the `EntryDefinition` union in `resources/js/panel/types/infolist.ts`.
3. A branch in `resources/js/panel/infolists/InfolistEntry.vue`.

The renderer's switch is exhaustive over the union, so a definition with no branch is a compile error — and `InfolistEntryTest` reads the TypeScript file and fails when a PHP `EntryType` case never reached the union, which the compiler cannot see. Everything an application needs is reachable through `CustomEntry` without touching any of it.

## Testing

The entry serializes without a page or a request:

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Infolists\Components\CustomEntry;

it('sends a registry key and its state', function (): void {
    $entry = CustomEntry::make('score')
        ->component('Panels/Admin/Entries/Gauge')
        ->config(['max' => 10])
        ->state(static fn (Model $record): int => 7);

    $definition = $entry->toArray(new Project);

    expect($definition['type'])->toBe('custom')
        ->and($definition['componentName'])->toBe('Panels/Admin/Entries/Gauge')
        ->and($definition['config'])->toBe(['max' => 10])
        ->and($definition['value'])->toBe(7);
});
```

Note the key is `componentName`, not `component` — `component` is already taken by the node discriminant, which is `'entry'` for every entry.

## Notes

- **The component name is not a path.** It cannot start with `@/`, cannot end in `.vue`, and cannot be built from a request value.
- **Rebuild after adding a component.** The glob is evaluated at build time; a new file is invisible until Vite has seen it.
- **`state()` runs per record, on the server.** A query inside it is a query on the view page — fine for one record, but it is still a query, and a relation is usually cheaper eager loaded.
- **The value must survive `json_encode`.** A model returned from `state()` is serialized as its attributes, including any the record happens to hold; return an array of exactly what the component needs instead.
- **`visible()` still applies.** A hidden custom entry is not serialized at all, so the component is never asked to render nothing.
- **An action beside a custom entry works normally.** It is drawn by the wrapper, not by your component, and runs through the infolist endpoint like any other. See [Actions in infolists](actions.md).

## See also

- [Entries](entries.md)
- [Entry reference](entry-reference.md)
- [InfolistSchema basics](overview.md)
- [Actions in infolists](actions.md)
- [Custom form fields](../forms/custom-fields.md)
- [Component registries](../concepts/component-registries.md)
- [Frontend custom fields](../frontend/custom-fields.md)
- [Frontend assets](../frontend/assets.md)
