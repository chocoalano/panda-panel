# Custom Fields

Four places where a schema names a Vue component of your own instead of using a built-in one: a form **field**, a form **layout**, an infolist **entry**, and an action **modal's** content. All four resolve through the same build-time registry, so the rule is one rule — a name is the path below `resources/js/pages/`, without the extension, and the component has to be under the right directory for the build to have seen it.

Reach for this page when no built-in control fits: a star rating, a map picker, a colour ramp, a bespoke summary card inside a form, or an explanation above a dangerous action's fields.

## A minimal working example

```php
use PandaPanel\Forms\Components\CustomField;
use PandaPanel\Forms\FormSchema;

public static function form(FormSchema $schema): FormSchema
{
    return $schema->schema([
        CustomField::make('rating')
            ->label('Rating')
            ->component('Panels/Admin/Fields/StarRating')
            ->config(['max' => 5])
            ->rules(['integer', 'between:1,5'])
            ->required(),
    ]);
}
```

```vue
<!-- resources/js/pages/Panels/Admin/Fields/StarRating.vue -->
<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    modelValue: unknown;
    config: Record<string, unknown>;
    disabled?: boolean;
    error?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: number] }>();

/** The value crosses as JSON, so it is narrowed rather than asserted. */
const value = computed(() =>
    typeof props.modelValue === 'number' ? props.modelValue : 0,
);

const max = computed(() =>
    typeof props.config.max === 'number' ? props.config.max : 5,
);
</script>

<template>
    <div class="flex gap-1">
        <button
            v-for="star in max"
            :key="star"
            type="button"
            :disabled="disabled"
            :aria-label="`${star} of ${max}`"
            :class="star <= value ? 'text-primary' : 'text-muted-foreground'"
            @click="emit('update:modelValue', star)"
        >
            ★
        </button>
    </div>
</template>
```

```bash
npm run build     # or: npm run dev
```

## The four seams

| Seam | PHP class | Directory | Registry key example |
| --- | --- | --- | --- |
| Field | `PandaPanel\Forms\Components\CustomField` | `Panels/{Panel}/Fields/` | `Panels/Admin/Fields/StarRating` |
| Layout | `PandaPanel\Forms\Layouts\CustomComponent` | `Panels/{Panel}/Schemas/` | `Panels/Admin/Schemas/PricingCard` |
| Entry | `PandaPanel\Infolists\Components\CustomEntry` | `Panels/{Panel}/Entries/` | `Panels/Admin/Entries/Timeline` |
| Modal content | `PandaPanel\Actions\Support\Modal::content()` | `Panels/{Panel}/Modals/` | `Panels/Admin/Modals/DeleteWarning` |

One registry serves all four:

```ts
import {
    resolveFormComponent,
    hasFormComponent,
} from '@/panel/forms/registry';

hasFormComponent('Panels/Admin/Fields/StarRating');      // boolean
resolveFormComponent('Panels/Admin/Fields/StarRating');  // loader or null
```

| Function | Signature |
| --- | --- |
| `hasFormComponent` | `(name: string) => boolean` |
| `resolveFormComponent` | `(name: string) => (() => Promise<{ default: Component }>) \| null` |

The glob is four patterns merged:

```text
resources/js/pages/Panels/**/Fields/*.vue
resources/js/pages/Panels/**/Schemas/*.vue
resources/js/pages/Panels/**/Entries/*.vue
resources/js/pages/Panels/**/Modals/*.vue
```

One registry rather than four that would say the same thing: what they have in common is that a *name* resolves to a component the build saw, and where the name was declared changes nothing about the rule.

## `CustomField`

A field drawn by your component. Everything else about it is an ordinary field — it validates with the rules the schema declares, dehydrates like any other, and can be hidden or made conditional. The custom part is only how it draws.

```php
use PandaPanel\Forms\Components\CustomField;

CustomField::make('rating')
    ->component('Panels/Admin/Fields/StarRating')
    ->config(['max' => 5, 'allowHalf' => false]);
```

| Member | Signature | Default |
| --- | --- | --- |
| `make` | `static make(string $name): static` | inherited from `Field` |
| `type()` | `type(): FieldType` | `FieldType::Custom` |
| `component()` | `component(string $component): self` | `''` |
| `config()` | `config(array $config): self` | `[]` |

### `config()`

```php
/**
 * @param  array<string, mixed>  $config
 */
public function config(array $config): self
```

Settings the component reads, serialized straight through. Scalars, arrays and nulls only, like every other serialized value — it is configuration, not behaviour:

```php
CustomField::make('location')
    ->component('Panels/Admin/Fields/MapPicker')
    ->config([
        'center' => ['lat' => -6.2, 'lng' => 106.8],
        'zoom' => 11,
        'tiles' => config('services.maps.style'),
    ]);
```

The last call wins; `config()` replaces rather than merges.

### Everything a field already has

`CustomField` extends `PandaPanel\Forms\Components\Field`, so all of these apply unchanged:

```php
use PandaPanel\Forms\Enums\ConditionOperator;

CustomField::make('rating')
    ->component('Panels/Admin/Fields/StarRating')
    ->label('Overall rating')
    ->helperText('One to five.')
    ->required()
    ->disabled()
    ->default(3)
    ->columnSpan(2)
    ->rules(['integer', 'between:1,5'])
    ->visibleWhen('published', ConditionOperator::Truthy)
    ->hiddenOn(['create'])
    ->live(onBlur: true, debounce: 500)
    ->inlineLabel()
    ->dehydrateStateUsing(static fn (mixed $state): int => (int) $state);
```

Validation is the server's. A custom component that never emits an invalid value is a convenience; the rules on the field are the control.

### What the component receives

`CustomFieldRenderer.vue` wraps your component in the standard `FieldWrapper` — label, required marker, helper text and error message — and binds five props:

```ts
defineProps<{
    field: CustomFieldDefinition;
    modelValue: unknown;
    config: Record<string, unknown>;
    disabled: boolean;
    error?: string;
}>();

defineEmits<{ 'update:modelValue': [value: FormValue] }>();
```

| Prop | Type | Notes |
| --- | --- | --- |
| `field` | `CustomFieldDefinition` | the whole serialized field: `name`, `label`, `placeholder`, `helperText`, `required`, `disabled`, `columnSpan`, `conditions`, `live`, `validation`, `componentName`, `config` |
| `modelValue` | `unknown` | the current working value |
| `config` | `Record<string, unknown>` | what `config()` sent — the same object as `field.config` |
| `disabled` | `boolean` | honour it; the server also refuses a disabled field's value |
| `error` | `string \| undefined` | the validation message, already rendered by the wrapper |

Emit `update:modelValue` to write. Do not render your own label, required marker or error — the wrapper has already drawn them.

Its TypeScript definition:

```ts
export interface CustomFieldDefinition extends BaseFieldDefinition {
    type: 'custom';
    componentName: string;
    config: Record<string, unknown>;
}
```

### When it does not resolve

The wrapper renders and the control is replaced by one line:

```text
This field has no renderer.
```

The fields around it stay editable, because one mistyped component must not take a form down. In development the registry also logs once per name:

```text
[panel] The form component [Panels/Admin/Fields/Typo] is not in the build-time
registry, so a fallback is drawn instead. It has to live under
resources/js/pages/Panels/{Panel}/ — check the path and the spelling, then rebuild.
```

## `CustomComponent` — a custom layout

The counterpart of `CustomField` for arrangement rather than input. It may still hold ordinary fields, and they behave exactly as they would anywhere else.

```php
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Layouts\CustomComponent;

CustomComponent::make('Panels/Admin/Schemas/PricingCard')
    ->config(['currency' => 'IDR'])
    ->schema([
        TextInput::make('price')->required(),
        TextInput::make('compare_at_price'),
    ]);
```

| Member | Signature | Default |
| --- | --- | --- |
| `make` | `static make(string $component): self` | the registry key is the constructor argument |
| `schema` | `schema(array $components): self` | `[]` |
| `config` | `config(array $config): self` | `[]` |
| `children` | `children(): array` | the components it holds |
| `fields` | `fields(): array` | every field beneath it, flattened |

Note the difference from `CustomField`: the component name is the argument to `make()`, not a separate `component()` call.

The serialized node:

```php
[
    'component' => 'custom',
    'componentName' => 'Panels/Admin/Schemas/PricingCard',
    'config' => ['currency' => 'IDR'],
    'schema' => [/* the children, each serialized */],
]
```

Your component receives `config` and a default slot holding the already-rendered children:

```vue
<!-- resources/js/pages/Panels/Admin/Schemas/PricingCard.vue -->
<script setup lang="ts">
defineProps<{ config: Record<string, unknown> }>();
</script>

<template>
    <section class="rounded-lg border p-4">
        <h3 class="mb-3 text-sm font-medium">
            Pricing ({{ config.currency }})
        </h3>

        <div class="flex flex-col gap-4">
            <slot />
        </div>
    </section>
</template>
```

A component that ignores the slot is a component with no fields in it, which is a legitimate thing to be.

**An unregistered layout still renders its children.** The wrapper is decoration; the fields inside it are the form, and losing them because a component was renamed would be a far worse failure than losing the frame around them.

## `CustomEntry` — a custom infolist entry

```php
use App\Models\Order;
use PandaPanel\Infolists\Components\CustomEntry;

CustomEntry::make('timeline')
    ->label('Fulfilment timeline')
    ->component('Panels/Admin/Entries/Timeline')
    ->config(['compact' => true])
    ->state(static fn (Order $record): array => $record->events
        ->map(static fn ($event): array => [
            'at' => $event->created_at->toIso8601String(),
            'label' => $event->label,
        ])
        ->all());
```

| Member | Signature | Default |
| --- | --- | --- |
| `make` | `static make(string $name): static` | inherited from `Entry` |
| `type()` | `type(): EntryType` | `EntryType::Custom` |
| `component()` | `component(string $component): self` | `''` |
| `config()` | `config(array $config): self` | `[]` |
| `state()` | `state(Closure $callback): self` | null — falls back to the attribute |
| `toValue()` | `toValue(Model $record): mixed` | the state closure, or `resolveValue()` |

Inherited from `Entry`: `label()`, `placeholder()`, `helperText()`, `columnSpan()`, `columnSpanFull()`, `formatUsing()`, `visible()`, `action()`.

A custom entry is a different way of *drawing* a value rather than a way of fetching one — `state()` exists for a renderer that needs more than a single column holds.

The component receives three props:

```ts
defineProps<{
    entry: CustomEntryDefinition;
    value: unknown;
    config: Record<string, unknown>;
}>();
```

```ts
export interface CustomEntryDefinition extends BaseEntryDefinition {
    type: 'custom';
    value: unknown;
    componentName: string;
    config: Record<string, unknown>;
}
```

On an unknown name the entry falls back to its `placeholder`, or an em dash.

## `Modal::content()` — custom content in an action dialog

```php
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Support\Modal;

Action::make('archive')
    ->label('Archive')
    ->modal(function (Modal $modal): void {
        $modal
            ->heading('Archive this order')
            ->content('Panels/Admin/Modals/ArchiveWarning', [
                'retentionDays' => 30,
            ]);
    });
```

`Action::modal(Closure $callback): static` hands you the action's modal to configure; the return value is ignored.

```php
/**
 * @param  array<string, mixed>  $config
 */
public function content(string $component, array $config = []): self
```

A build-time registry key under `Panels/{Panel}/Modals/`, never markup. It renders **above** whatever else the modal holds, so a form action can explain itself in its own words before the fields that decide it.

The component receives:

```ts
defineProps<{
    config: Record<string, unknown>;
    action: ActionDefinition;
}>();
```

`ActionModal.vue` binds both and adds a `mb-4` class. An unregistered name renders nothing rather than throwing — one mistyped name cannot take the dialog down with it, and the form underneath still works.

The serialized modal carries the name as `componentName` alongside `config`, `heading`, `description`, `submitLabel`, `cancelLabel`, `width`, `slideOver` and the rest.

## Where the files must live

The directory name is what the glob matches, and nothing else is scanned:

```text
resources/js/pages/Panels/Admin/Fields/StarRating.vue      → Panels/Admin/Fields/StarRating
resources/js/pages/Panels/Admin/Schemas/PricingCard.vue    → Panels/Admin/Schemas/PricingCard
resources/js/pages/Panels/Admin/Entries/Timeline.vue       → Panels/Admin/Entries/Timeline
resources/js/pages/Panels/Admin/Modals/ArchiveWarning.vue  → Panels/Admin/Modals/ArchiveWarning
```

The `{Panel}` segment is a convention, not a rule — the glob is `pages/Panels/**/{Fields,…}/*.vue`, so any depth works as long as the directory name matches. Keeping one directory per panel is what makes a component's owner obvious.

Every pattern ends in `*.vue`, not `**/*.vue`: only direct children of the kind directory are registered. `Fields/Inputs/StarRating.vue` is not.

## Gotchas

- **The component is loaded on demand.** All four use `defineAsyncComponent`, because a custom component is rare and bundling every one into the main chunk would cost every page that has none.
- **A new file needs a rebuild.** `import.meta.glob` is evaluated at build time. This is the most common reason a component that exists renders the fallback.
- **The glob is relative, never aliased.** Vite's dev server resolves an aliased glob to nothing at all while the production build resolves it normally — every custom component would render the fallback in development and work once built.
- **Case matters.** `Panels/Admin/Fields/starRating` and `Panels/Admin/Fields/StarRating` are different keys, and on a case-insensitive filesystem the mistake will not show up until CI.
- **`config()` replaces.** Two calls are not merged; the last one is the configuration.
- **A custom field is still validated on the server.** `rules()` is the control. A component that cannot produce an invalid value is a nicety.
- **Do not draw the label twice.** `FieldWrapper` already renders the label, the required marker, the helper text and the error.
- **`CustomComponent::make()` takes the component name, `CustomField::make()` takes the field name.** They read alike and mean different things.
- **Nothing renderable crosses the wire.** PHP sends a name and serializable config. There is no way to send markup or a template from the server, which is why the allowlist can be trusted.

## See also

- [Custom Fields (forms guide)](../forms/custom-fields.md)
- [Form Layouts](../forms/layouts.md), [Prime Components](../forms/prime-components.md)
- [Custom Entries](../infolists/custom-entries.md)
- [Action Modals](../actions/modals.md), [Action Forms](../actions/forms.md)
- [Custom Columns](custom-columns.md), [Custom Widgets](custom-widgets.md)
- [Component Registries](../concepts/component-registries.md)
- [Vue Component Tree](component-tree.md)
