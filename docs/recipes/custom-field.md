# Custom Field

A star-rating control on the product form from [Product Resource](product-resource.md), drawn by a Vue component of the application's own, validated on the server like any other field, and shown again on the view page by a matching infolist entry. Read this page when no built-in control fits — a rating, a map picker, a colour ramp, a bespoke card inside a form. There is no generator for this: a custom field is one PHP call and one `.vue` file, and a stub would be longer than either.

## A minimal working example

Add the column the field writes to:

```php
// database/migrations/xxxx_xx_xx_xxxxxx_add_rating_to_products_table.php

Schema::table('products', function (Blueprint $table): void {
    $table->unsignedTinyInteger('rating')->nullable();
});
```

Declare the field:

```php
use PandaPanel\Forms\Components\CustomField;

CustomField::make('rating')
    ->label('Editorial rating')
    ->component('Panels/Admin/Fields/StarRating')
    ->config(['max' => 5])
    ->rules(['integer', 'between:1,5']);
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
            class="text-lg leading-none"
            :class="star <= value ? 'text-primary' : 'text-muted-foreground'"
            @click="emit('update:modelValue', star)"
        >
            ★
        </button>
    </div>
</template>
```

Rebuild, and the field renders:

```bash
npm run build     # or: npm run dev
```

## Where it goes on the form

`CustomField` is a `Field`, so it sits anywhere an ordinary field does:

```php
// app/Panels/Admin/Resources/Products/Forms/ProductForm.php

use PandaPanel\Forms\Components\CustomField;
use PandaPanel\Forms\Layouts\Section;

Section::make('Editorial')
    ->columns(2)
    ->schema([
        CustomField::make('rating')
            ->label('Editorial rating')
            ->helperText('One to five. Leave blank if the product has not been reviewed.')
            ->component('Panels/Admin/Fields/StarRating')
            ->config(['max' => 5])
            ->rules(['integer', 'between:1,5'])
            ->columnSpan(2),
    ]);
```

Add `rating` to the model's `$fillable`, and the field saves through the ordinary create and edit lifecycle. Nothing else is wired.

## `CustomField`

| Member | Signature | Default |
| --- | --- | --- |
| `make()` | `static make(string $name): static` | inherited from `Field` |
| `type()` | `type(): FieldType` | `FieldType::Custom`, serialized as `custom` |
| `component()` | `component(string $component): self` | `''` |
| `config()` | `config(array $config): self` | `[]` |

The serialized node carries `componentName` and `config` alongside every key an ordinary field has:

```php
CustomField::make('rating')
    ->component('Panels/Admin/Fields/StarRating')
    ->config(['max' => 5])
    ->toArray(null, 'create');
// ['type' => 'custom', 'componentName' => 'Panels/Admin/Fields/StarRating', 'config' => ['max' => 5], …]
```

### `config()`

```php
/**
 * @param  array<string, mixed>  $config
 */
public function config(array $config): self
```

Settings your component reads, serialized straight through. Scalars, arrays and nulls only — it is configuration, not behaviour, and a closure in it will not survive. The framework never interprets it.

```php
CustomField::make('rating')
    ->component('Panels/Admin/Fields/StarRating')
    ->config([
        'max' => 5,
        'allowHalf' => false,
        'labels' => ['Poor', 'Fair', 'Good', 'Great', 'Excellent'],
    ]);
```

The last call wins; `config()` replaces rather than merges.

### Everything a field already has

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Enums\ConditionOperator;

CustomField::make('rating')
    ->component('Panels/Admin/Fields/StarRating')
    ->label('Editorial rating')
    ->helperText('One to five.')
    ->required()
    ->default(3)
    ->columnSpan(2)
    ->rules(['integer', 'between:1,5'])
    ->rulesUsing(static fn (?Model $record): array => $record === null ? ['nullable'] : [])
    ->visibleWhen('is_published', ConditionOperator::Truthy)
    ->hiddenOn(['create'])
    ->disabledOn(['view'])
    ->live(onBlur: false, debounce: 300)
    ->inlineLabel()
    ->dehydrateTo('editorial_rating')
    ->dehydrateStateUsing(static fn (mixed $state): ?int => $state === null ? null : (int) $state);
```

A custom field declares no rules of its own, so everything it must be comes from `rules()` and `rulesUsing()`. A component that cannot produce an invalid value is a convenience; the rules on the PHP side are the control.

## What the component receives

`CustomFieldRenderer.vue` wraps your component in the standard `FieldWrapper` — label, required marker, helper text, error message — and binds five props:

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
| `config` | `Record<string, unknown>` | the same object as `field.config` |
| `disabled` | `boolean` | honour it; the server also refuses a disabled field's value |
| `error` | `string \| undefined` | already rendered by the wrapper |

```ts
export interface CustomFieldDefinition extends BaseFieldDefinition {
    type: 'custom';
    componentName: string;
    config: Record<string, unknown>;
}
```

Do not draw your own label, required marker, or error message — the wrapper has already drawn them. Emit `update:modelValue` to write; what you emit is what the form holds, submits, and validates.

## Making it live

A `live()` custom field emits changes the same way a built-in one does, so `afterStateUpdated()` and a schema rebuild work unchanged:

```php
CustomField::make('rating')
    ->component('Panels/Admin/Fields/StarRating')
    ->config(['max' => 5])
    ->live()
    ->afterStateUpdated(static function (mixed $state, mixed $previous, ?Model $record): void {
        // Runs on the server, on the form-state endpoint.
    });
```

```php
public function live(bool $onBlur = false, ?int $debounce = null): static
public function afterStateUpdated(Closure $callback): static
```

Every round trip costs a request, so `live()` on a control the user drags is a request per pixel unless `onBlur` or `debounce` is set.

## A custom layout

`PandaPanel\Forms\Layouts\CustomComponent` is the counterpart for arrangement rather than input. It holds no value and submits nothing, but it may hold ordinary fields — and they behave exactly as they would anywhere else.

```php
use PandaPanel\Forms\Components\NumberInput;
use PandaPanel\Forms\Layouts\CustomComponent;

CustomComponent::make('Panels/Admin/Schemas/PricingCard')
    ->config(['currency' => 'USD'])
    ->schema([
        NumberInput::make('price_cents')->label('Price (cents)')->integer()->min(0),
        NumberInput::make('compare_at_cents')->label('Compare at (cents)')->integer()->min(0),
    ]);
```

| Member | Signature | Default |
| --- | --- | --- |
| `make()` | `static make(string $component): self` | the registry key is the constructor argument |
| `schema()` | `schema(array $components): self` | `[]` |
| `config()` | `config(array $config): self` | `[]` |
| `children()` | `children(): array` | the components it holds |
| `fields()` | `fields(): array` | every field beneath it, flattened |

Note the difference from `CustomField`: the component name is the argument to `make()`, not a separate `component()` call. They read alike and mean different things.

`resources/js/pages/Panels/Admin/Schemas/PricingCard.vue`:

```vue
<script setup lang="ts">
defineProps<{ config: Record<string, unknown> }>();
</script>

<template>
    <section class="rounded-lg border p-4">
        <h3 class="mb-3 text-sm font-medium">Pricing ({{ config.currency }})</h3>

        <div class="flex flex-col gap-4">
            <slot />
        </div>
    </section>
</template>
```

The children are rendered by the panel and passed in as the default slot, so your component decides where they go without having to know how to render any of them. A component that ignores the slot is a component with no fields in it, which is a legitimate thing to be.

## The same value on the view page

The form's renderer does not apply to an infolist. `PandaPanel\Infolists\Components\CustomEntry` is the read-only counterpart:

```php
use App\Models\Product;
use PandaPanel\Infolists\Components\CustomEntry;

CustomEntry::make('rating')
    ->label('Editorial rating')
    ->component('Panels/Admin/Entries/RatingStars')
    ->config(['max' => 5])
    ->placeholder('Not reviewed')
    ->state(static fn (Product $record): array => [
        'value' => (int) ($record->rating ?? 0),
        'reviewed' => $record->rating !== null,
    ]);
```

| Member | Signature | Default |
| --- | --- | --- |
| `make()` | `static make(string $name): static` | inherited from `Entry` |
| `type()` | `type(): EntryType` | `EntryType::Custom` |
| `component()` | `component(string $component): self` | `''` |
| `config()` | `config(array $config): self` | `[]` |
| `state()` | `state(Closure $callback): self` | null — falls back to the attribute |
| `toValue()` | `toValue(Model $record): mixed` | the state closure, or `resolveValue()` |

The component receives three props and emits nothing:

```ts
defineProps<{
    entry: CustomEntryDefinition;
    value: unknown;
    config: Record<string, unknown>;
}>();
```

`resources/js/pages/Panels/Admin/Entries/RatingStars.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    value: unknown;
    config: Record<string, unknown>;
}>();

const reading = computed(() => {
    const value = props.value;

    if (typeof value !== 'object' || value === null) {
        return null;
    }

    const { value: score, reviewed } = value as {
        value?: unknown;
        reviewed?: unknown;
    };

    return typeof score === 'number' && typeof reviewed === 'boolean'
        ? { score, reviewed }
        : null;
});

const max = computed(() =>
    typeof props.config.max === 'number' ? props.config.max : 5,
);
</script>

<template>
    <span
        v-if="reading?.reviewed"
        class="tracking-widest"
        :aria-label="`${reading.score} of ${max}`"
    >
        {{ '★'.repeat(reading.score) }}{{ '☆'.repeat(max - reading.score) }}
    </span>
    <span v-else class="text-muted-foreground">—</span>
</template>
```

## Where the files must live

The registry is a build-time allowlist. `resources/js/panel/forms/registry.ts` globs four directories, one per seam:

```text
resources/js/pages/Panels/**/Fields/*.vue      ← CustomField
resources/js/pages/Panels/**/Schemas/*.vue     ← CustomComponent
resources/js/pages/Panels/**/Entries/*.vue     ← CustomEntry
resources/js/pages/Panels/**/Modals/*.vue      ← Modal::content()
```

The name PHP sends is the path below `resources/js/pages/`, without the extension:

| File | Name |
| --- | --- |
| `resources/js/pages/Panels/Admin/Fields/StarRating.vue` | `Panels/Admin/Fields/StarRating` |
| `resources/js/pages/Panels/Admin/Schemas/PricingCard.vue` | `Panels/Admin/Schemas/PricingCard` |
| `resources/js/pages/Panels/Admin/Entries/RatingStars.vue` | `Panels/Admin/Entries/RatingStars` |

Two functions read the registry, should you need them:

```ts
import { hasFormComponent, resolveFormComponent } from '@/panel/forms/registry';

hasFormComponent('Panels/Admin/Fields/StarRating');      // boolean
resolveFormComponent('Panels/Admin/Fields/StarRating');  // loader, or null
```

The `{Panel}` segment is a convention, not a rule — the glob is `pages/Panels/**/{Fields,…}/*.vue`, so any depth works as long as the directory name matches. Every pattern ends in `*.vue`, not `**/*.vue`: only direct children of the kind directory are registered, so `Fields/Inputs/StarRating.vue` is not.

The root is configurable through `panda-panel.frontend.pages_path`, which defaults to `js/pages/Panels`. Moving it means changing the glob too.

## When a name resolves to nothing

| Case | What renders |
| --- | --- |
| `CustomField` with an unknown name | The wrapper, with "This field has no renderer." in place of the control |
| `CustomComponent` with an unknown name | Its children, without the wrapper |
| `CustomEntry` with an unknown name | The entry's `placeholder`, or an em dash |

Nothing throws: one mistyped component cannot take a form down, and the fields around it stay editable. A custom *layout* still renders its children because the wrapper is decoration and the fields inside it are the form.

In development the registry logs once per name:

```text
[panel] The form component [Panels/Admin/Fields/Typo] is not in the build-time
registry, so a fallback is drawn instead. It has to live under
resources/js/pages/Panels/{Panel}/ — check the path and the spelling, then rebuild.
```

In production it does not — this is a build problem, and a console message on a live panel helps nobody. The three causes are a typo, a file outside the globbed directory, and a build that has not been re-run.

## The test

The PHP half is testable without a browser, which is where the rules actually live:

```php
<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\User;
use App\Panels\Admin\Resources\Products\ProductResource;
use PandaPanel\Core\PanelManager;
use PandaPanel\Forms\Components\CustomField;

beforeEach(function (): void {
    app(PanelManager::class)->setCurrentPanel(panel('admin'));

    $this->actingAs(User::factory()->admin()->create());
});

it('serializes the field with its component name and config', function (): void {
    $definition = CustomField::make('rating')
        ->component('Panels/Admin/Fields/StarRating')
        ->config(['max' => 5])
        ->toArray(null, 'create');

    expect($definition['type'])->toBe('custom')
        ->and($definition['componentName'])->toBe('Panels/Admin/Fields/StarRating')
        ->and($definition['config'])->toBe(['max' => 5]);
});

it('is an ordinary field as far as the schema is concerned', function (): void {
    panelForm(ProductResource::class)->assertHasField('rating');
});

it('validates on the server, whatever the control emitted', function (): void {
    $this->post('/admin/products/create', [
        'name' => 'Keyboard',
        'sku' => 'KB-001',
        'price_cents' => 12900,
        'stock' => 1,
        'rating' => 9,
    ])->assertInvalid(['rating']);

    expect(Product::query()->count())->toBe(0);
});

it('saves a value the rules accept', function (): void {
    $this->post('/admin/products/create', [
        'name' => 'Keyboard',
        'sku' => 'KB-001',
        'price_cents' => 12900,
        'stock' => 1,
        'rating' => 4,
    ])->assertRedirect();

    expect(Product::query()->firstWhere('sku', 'KB-001')?->rating)->toBe(4);
});
```

The component itself is ordinary Vue and is tested with whatever the application already uses for Vue; the framework's own frontend contract is asserted by `tests/Feature/Panel/FrontendContractTest.php`, which checks that every PHP field type has a renderer on the other side.

## Adding a whole new field type

`CustomField` is the supported extension point. Adding a new `FieldType` is a change to the framework itself and takes three edits that must land together:

1. The PHP class, extending `Field` and returning a new `FieldType` case.
2. The TypeScript definition, added to the union in `resources/js/panel/types/form.ts`.
3. A branch in `resources/js/panel/forms/FormField.vue`.

The renderer's switch is exhaustive over the union, so a definition without a renderer is a compile error — and `FormFieldTypeTest` reads the TypeScript file and fails when a PHP `FieldType` case never reached the union, which the compiler cannot see. Everything an application needs is reachable through `CustomField` without touching any of it.

## Gotchas

- **A new file needs a rebuild.** `import.meta.glob` is evaluated at build time. This is the most common reason a component that plainly exists renders the fallback.
- **The component name is not a path.** It cannot start with `@/`, cannot end in `.vue`, and cannot be built from a request value. Nothing renderable ever crosses the wire — PHP sends a name and serializable config, which is why the allowlist can be trusted.
- **Case matters.** `Panels/Admin/Fields/starRating` and `.../StarRating` are different keys, and on a case-insensitive filesystem the mistake will not show up until CI.
- **`CustomComponent::make()` takes the component name; `CustomField::make()` takes the field name.** They read alike and mean different things.
- **`config()` replaces.** Two calls are not merged; the last one is the configuration.
- **The panel's Vue sources are yours.** They are published into `resources/js/` by `php artisan panel:install`, which is what lets the registry globs see your components at all. `php artisan panel:assets` reports which published files a package update has left out of date.
- **Visibility is already applied.** A field hidden by `visibleWhen()` never reaches your component, so it does not have to check.

## See also

- [Product Resource](product-resource.md) — the form this field is added to
- [User Resource](user-resource.md) — `CustomColumn`, the same seam on a table
- [Custom Fields (forms guide)](../forms/custom-fields.md)
- [Custom Fields (frontend)](../frontend/custom-fields.md)
- [Custom Entries](../infolists/custom-entries.md)
- [Custom Columns](../frontend/custom-columns.md)
- [Custom Widgets](../frontend/custom-widgets.md)
- [Component Registries](../concepts/component-registries.md)
- [Form Layouts](../forms/layouts.md), [Prime Components](../forms/prime-components.md)
- [Validation](../forms/validation.md), [Live Fields](../forms/live-fields.md)
- [Action Modals](../actions/modals.md)
- [Frontend Assets](../frontend/assets.md)
