# Custom Fields

`PandaPanel\Forms\Components\CustomField` is a field drawn by a Vue component of your own. Everything else about it is an ordinary field: it validates with the rules the schema declares, dehydrates like any other, and can be hidden or made conditional — the custom part is only how it draws. You reach for it when no built-in control fits: a star rating, a map picker, a colour ramp.

## A minimal example

Declare the field:

```php
use PandaPanel\Forms\Components\CustomField;
use PandaPanel\Forms\FormSchema;

public static function form(FormSchema $schema): FormSchema
{
    return $schema->schema([
        CustomField::make('rating')
            ->component('Panels/Admin/Fields/StarRating')
            ->config(['max' => 5])
            ->rules(['integer', 'between:1,5'])
            ->required(),
    ]);
}
```

Write the component at `resources/js/pages/Panels/Admin/Fields/StarRating.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    modelValue: unknown;
    config: Record<string, unknown>;
    disabled?: boolean;
    error?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: number] }>();

/** The value arrives as untyped JSON, so it is narrowed rather than asserted. */
const value = computed(() =>
    typeof props.modelValue === 'number' ? props.modelValue : 0,
);

const max = computed(() =>
    typeof props.config.max === 'number' ? props.config.max : 5,
);

const stars = computed(() =>
    Array.from({ length: max.value }, (_, index) => index + 1),
);
</script>

<template>
    <div class="flex gap-1">
        <button
            v-for="star in stars"
            :key="star"
            type="button"
            :disabled="disabled"
            :aria-label="`${star} of ${max}`"
            class="text-lg"
            :class="star <= value ? 'text-primary' : 'text-muted-foreground'"
            @click="emit('update:modelValue', star)"
        >
            ★
        </button>
    </div>
</template>
```

Rebuild the assets, and the field renders.

```bash
npm run build     # or: npm run dev
```

## `CustomField`

| Method | Signature | Default |
| --- | --- | --- |
| `make()` | `static make(string $name): static` | |
| `component()` | `component(string $component): self` | `''` |
| `config()` | `config(array<string, mixed> $config): self` | `[]` |

`type()` is `FieldType::Custom`, serialized as `custom`. The field carries `componentName` and `config` alongside every key an ordinary field does.

```php
CustomField::make('rating')
    ->component('Panels/Admin/Fields/StarRating')
    ->config(['max' => 5])
    ->toArray(null, 'create');
// ['type' => 'custom', 'componentName' => 'Panels/Admin/Fields/StarRating', 'config' => ['max' => 5], …]
```

`config()` takes scalars and arrays, like every other serialized value. It is read by your component and by nothing else — the framework never interprets it.

Because it is a `Field`, everything on `Field` works:

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\CustomField;
use PandaPanel\Forms\Enums\ConditionOperator;

CustomField::make('rating')
    ->component('Panels/Admin/Fields/StarRating')
    ->label('Editorial rating')
    ->helperText('One to five.')
    ->default(3)
    ->columnSpan(2)
    ->rules(['integer', 'between:1,5'])
    ->visibleWhen('status', ConditionOperator::Equals, 'published')
    ->dehydrateStateUsing(static fn (mixed $value, ?Model $record): int => (int) $value);
```

A custom field declares no rules of its own, so anything it needs comes from `rules()` and `rulesUsing()`. See [Validation](validation.md).

## The component contract

The panel wraps your component in `FieldWrapper`, which draws the label, the helper text, the required marker, and the error. Your component draws the control and nothing else. It receives:

| Prop | Type | Meaning |
| --- | --- | --- |
| `field` | the whole field definition | Name, label, placeholder, conditions — everything serialized |
| `modelValue` | `unknown` | The current value |
| `config` | `Record<string, unknown>` | What `config()` declared |
| `disabled` | `boolean` | `disabled()` or `disabledOn()` |
| `error` | `string \| undefined` | The message for this field, if any |

and emits one event:

```ts
emit('update:modelValue', value);
```

The value you emit is what the form holds, submits, and validates. Narrow everything you read: props cross the wire as untyped JSON, and a shape that does not match should render an empty control rather than throw inside the form.

## Where components are found

The registry is a build-time allowlist — `resources/js/panel/forms/registry.ts` globs four directories:

```text
resources/js/pages/Panels/**/Fields/*.vue      ← CustomField
resources/js/pages/Panels/**/Schemas/*.vue     ← CustomComponent
resources/js/pages/Panels/**/Entries/*.vue     ← custom infolist entries
resources/js/pages/Panels/**/Modals/*.vue      ← custom action modals
```

The name PHP sends is the path below `pages/`, without the extension:

| File | Name |
| --- | --- |
| `resources/js/pages/Panels/Admin/Fields/StarRating.vue` | `Panels/Admin/Fields/StarRating` |
| `resources/js/pages/Panels/App/Schemas/Banner.vue` | `Panels/App/Schemas/Banner` |

The name is a registry key, never markup and never a path — the same rule custom columns and widgets follow, and for the same reason: a name resolved from data would be a way to render something the build never saw. The name always originates from a registered PHP component, and the lookup is the second lock rather than the first.

A component is loaded on demand. A custom field is rare, and bundling every one of them into the panel's main chunk would cost every page that has none.

## Custom layouts

`PandaPanel\Forms\Layouts\CustomComponent` is the counterpart for content and arrangement rather than input.

```php
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Layouts\CustomComponent;

CustomComponent::make('Panels/Admin/Schemas/Banner')
    ->config(['dismissible' => true])
    ->schema([TextInput::make('name')]);
```

It holds no value and submits nothing, but it may hold components — and the fields inside behave exactly as they would anywhere else. Its children are rendered by the panel and passed in as the default slot, so your component decides where they go without having to know how to render any of them:

```vue
<script setup lang="ts">
defineProps<{ config: Record<string, unknown> }>();
</script>

<template>
    <section class="rounded-lg border p-4">
        <p class="mb-3 text-sm text-muted-foreground">
            Fill this in carefully.
        </p>

        <div class="flex flex-col gap-4">
            <slot />
        </div>
    </section>
</template>
```

A component that ignores the slot is a component with no fields in it, which is a legitimate thing to be.

## When a name resolves to nothing

| Case | What renders |
| --- | --- |
| `CustomField` with an unknown name | The wrapper, with "This field has no renderer." in place of the control |
| `CustomComponent` with an unknown name | Its children, without the wrapper |

Neither throws: one mistyped component cannot take a form down, and the fields around it stay editable. A custom *component* still renders its children because the wrapper is decoration and the fields inside it are the form.

In development the registry warns once per name in the browser console, naming the directory the file has to live under. In production it does not — this is a build problem, and a console message on a live panel helps nobody. The three causes are a typo, a file outside the globbed directory, and a build that has not been re-run.

## Adding a whole new field type

`CustomField` is the supported extension point. Adding a new `FieldType` is a change to the framework itself and takes three edits that must land together:

1. The PHP class, extending `Field` and returning a new `FieldType` case.
2. The TypeScript definition, added to the union in `resources/js/panel/types/form.ts`.
3. A branch in `resources/js/panel/forms/FormField.vue`.

The renderer's switch is exhaustive over the union, so a definition without a renderer is a compile error — and `FormFieldTypeTest` reads the TypeScript file and fails when a PHP `FieldType` case never reached the union, which the compiler cannot see. Everything an application needs is reachable through `CustomField` without touching any of it.

## Notes

- **The component name is not a path.** It cannot start with `@/`, cannot end in `.vue`, and cannot be built from a request value.
- **Rebuild after adding a component.** The glob is evaluated at build time; a new file is invisible until Vite has seen it.
- **The panel's Vue sources are yours.** They are published into `resources/js/` — by `php artisan panel:install`, or `php artisan vendor:publish --tag=panda-panel-assets` — which is what makes the registry globs able to see your components at all. `php artisan panel:assets` reports which published files a package update has left out of date. See [Frontend assets](../frontend/assets.md).
- **`config()` is data, not behaviour.** It is serialized as JSON; a closure in it will not survive.
- **The `field` prop is the whole definition.** Read `field.name` for input ids, `field.placeholder`, or `field.conditions` if your control needs them — but visibility is already applied before your component is reached.
- **A custom field can be `live()`.** The form emits changes the same way, so `afterStateUpdated()` and a schema rebuild work exactly as they do for a built-in field.
- **Validation is the schema's.** A custom control that constrains its own input is a courtesy; the rules on the PHP side are what decide.

## See also

- [Frontend custom fields](../frontend/custom-fields.md)
- [Component registries](../concepts/component-registries.md)
- [Form layouts](layouts.md)
- [Prime components](prime-components.md)
- [Validation](validation.md)
- [Live fields](live-fields.md)
- [Custom columns](../frontend/custom-columns.md)
- [Custom widgets](../frontend/custom-widgets.md)
- [Frontend assets](../frontend/assets.md)
