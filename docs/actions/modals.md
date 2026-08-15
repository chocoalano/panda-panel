# Action Modals

Everything about how an action's dialog behaves lives on a `PandaPanel\Actions\Support\Modal` held beside the action, so the two are not one class with thirty setters. You reach for it when an action needs more than a yes/no confirmation: a wider dialog, a slide-over, custom explanatory content, or a second action reachable only from inside the first.

An action that never opens a dialog carries no modal at all — `Action::toArray()` sends `null`, and the frontend falls back to its own defaults.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Orders\Tables;

use App\Models\Order;
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Enums\ModalWidth;
use PandaPanel\Actions\Support\Modal;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

final class OrdersTable
{
    public static function configure(TableSchema $table): TableSchema
    {
        return $table
            ->columns([TextColumn::make('reference')->searchable()])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->modalHeading('Approve this order')
                    ->modalDescription('The customer is notified immediately.')
                    ->modalSubmitLabel('Approve it')
                    ->modalWidth(ModalWidth::Large)
                    ->modal(static function (Modal $modal): void {
                        $modal->stickyFooter()->closeByClickingAway(false);
                    })
                    ->requiresConfirmation()
                    ->action(static fn (Order $record) => $record->approve()),
            ]);
    }
}
```

## Three ways a dialog gets opened

A dialog appears for any of three reasons, and they compose rather than exclude each other:

| Reason | Declared with | What is inside |
| --- | --- | --- |
| Confirmation | `requiresConfirmation()` | the heading, description, and a submit button |
| Custom content | `modalContent()` | a Vue component of this application's own |
| A form | `schema()` or `form()` | the form, fetched when the dialog opens |

An action with both content and a form shows the content above the form, which is how "here is what this will do" ends up beside the fields that decide it.

## The shortcuts on `Action`

The most-reached-for settings are one call each, so the common case never mentions `Modal`.

| Method | Signature | Delegates to |
| --- | --- | --- |
| `modalWidth()` | `modalWidth(ModalWidth $width): static` | `Modal::width()` |
| `slideOver()` | `slideOver(bool $slideOver = true): static` | `Modal::slideOver()` |
| `modalHeading()` | `modalHeading(string $heading): static` | `Modal::heading()` |
| `modalDescription()` | `modalDescription(string $description): static` | `Modal::description()` |
| `modalSubmitLabel()` | `modalSubmitLabel(string $label): static` | `Modal::submitLabel()` |
| `modalContent()` | `modalContent(string $component, array $config = []): static` | `Modal::content()` |
| `modal()` | `modal(Closure $callback): static` | the whole object — `fn (Modal $modal): void` |

Readers: `getModal(): Modal` builds one lazily on first access, and `hasModal(): bool` says whether one was ever configured.

```php
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Enums\ModalWidth;

Action::make('approve')
    ->modalWidth(ModalWidth::TwoExtraLarge)
    ->slideOver()
    ->modalHeading('Approve this order')
    ->modalSubmitLabel('Approve it');
```

## The `Modal` object

`modal()` hands you the whole thing, which is where the less common settings live.

```php
use PandaPanel\Actions\Support\Modal;

Action::make('note')->modal(static function (Modal $modal): void {
    $modal->stickyHeader()
        ->stickyFooter()
        ->closeByClickingAway(false)
        ->closeByEscaping(false)
        ->autofocus(false)
        ->cancelLabel('Not now');
});
```

| Method | Signature | Default |
| --- | --- | --- |
| `make` | `static make(): self` | — |
| `width` | `width(ModalWidth $width): self` | `ModalWidth::Medium` |
| `slideOver` | `slideOver(bool $slideOver = true): self` | `false` |
| `stickyHeader` | `stickyHeader(bool $sticky = true): self` | `false` |
| `stickyFooter` | `stickyFooter(bool $sticky = true): self` | `false` |
| `closeByClickingAway` | `closeByClickingAway(bool $close = true): self` | `true` |
| `closeByEscaping` | `closeByEscaping(bool $close = true): self` | `true` |
| `autofocus` | `autofocus(bool $autofocus = true): self` | `true` |
| `heading` | `heading(string $heading): self` | `null` |
| `description` | `description(string $description): self` | `null` |
| `submitLabel` | `submitLabel(string $label): self` | `null` |
| `cancelLabel` | `cancelLabel(string $label): self` | `null` |
| `withoutCancel` | `withoutCancel(bool $without = true): self` | cancel is shown |
| `content` | `content(string $component, array $config = []): self` | `null` |

Readers: `getHeading(): ?string`, `getSubmitLabel(): ?string`, `toArray(): array`.

`closeByClickingAway(false)` is worth reaching for on a long form, where losing what was typed to a stray click is the failure people actually hit. `ImportAction` sets it for that reason.

## Widths

```php
use PandaPanel\Actions\Enums\ModalWidth;

ModalWidth::Small          // 'sm'      → sm:max-w-sm
ModalWidth::Medium         // 'md'      → sm:max-w-md   (default)
ModalWidth::Large          // 'lg'      → sm:max-w-lg
ModalWidth::ExtraLarge     // 'xl'      → sm:max-w-xl
ModalWidth::TwoExtraLarge  // '2xl'     → sm:max-w-2xl
ModalWidth::FourExtraLarge // '4xl'     → sm:max-w-4xl
ModalWidth::Screen         // 'screen'  → sm:max-w-[95vw]
```

A closed set because each case maps to a literal Tailwind class on the frontend; an interpolated width would compile to nothing. A slide-over ignores the width and is always `w-full sm:max-w-xl` — its size is the side panel's, not the dialog's.

## Slide-overs

```php
Action::make('note')->slideOver();
```

A slide-over is the same content in a different place. The frontend swaps a `Dialog` for a `Sheet` and nothing inside has to know which it is in — the form, the content component, and the registered actions are identical.

## Custom content

```php
use PandaPanel\Actions\Action;

Action::make('approve')
    ->modalContent('Panels/Admin/Modals/Explanation', ['tone' => 'warning'])
    ->requiresConfirmation()
    ->action(static fn ($record) => $record->approve());
```

The first argument is a **build-time registry key**, never markup — the same rule custom columns, fields, and widgets follow. The component must live under `resources/js/pages/Panels/{Panel}/Modals/` for the build's glob to see it:

```vue
<!-- resources/js/pages/Panels/Admin/Modals/Explanation.vue -->
<script setup lang="ts">
import type { ActionDefinition } from '@/panel/types/action';

defineProps<{
    config: Record<string, unknown>;
    action: ActionDefinition | null;
}>();
</script>

<template>
    <div class="rounded-md border p-3 text-sm">
        <p>Approving notifies the customer and locks the order.</p>
        <p v-if="config.tone === 'warning'" class="mt-2 font-medium">
            This cannot be undone.
        </p>
    </div>
</template>
```

Two props are passed: `config`, exactly the array given to `modalContent()`, and `action`, the serialized action definition. A name that was not compiled in renders nothing rather than throwing, so one mistyped key cannot take the dialog down with it.

The content renders above whatever else the modal holds, so a form action can explain itself in its own words.

## Actions inside a dialog

```php
Action::registerModalActions(array $actions): static
Action::getModalActions(): array          // array<string, Action>
Action::getModalAction(string $name): ?Action
```

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;

Action::make('review')
    ->modalHeading('Review this order')
    ->modalContent('Panels/Admin/Modals/OrderSummary')
    ->registerModalActions([
        Action::make('approve')
            ->label('Approve')
            ->action(static fn (Model $record) => $record->approve()),
        Action::make('reject')
            ->label('Reject')
            ->action(static fn (Model $record) => $record->reject()),
    ]);
```

An action registered this way is reachable **only** through the action that declared it. It is not rendered beside the trigger, and it is not found by the table's own lookup — so "runnable from this dialog" never becomes "runnable by name from anywhere". `InfolistSchema::allActions()` is the one place they are walked into the lookup, and only through their parent.

They are serialized against the same record as the parent, so a registered action the user may not run is absent rather than a button that answers 403:

```php
$parent = Action::make('review')->registerModalActions([
    Action::make('visible'),
    Action::make('refused')->authorize(static fn (): bool => false),
]);

array_column($parent->toArray($order)['modalActions'], 'name');   // ['visible']
```

## Confirmation versus a modal

`requiresConfirmation()` is not a modal setting — it lives on the action and produces the copy the dialog shows when there is nothing else in it.

```php
Action::requiresConfirmation(
    bool $requires = true,
    ?string $heading = null,
    ?string $description = null,
    ?string $button = null,
): static
```

| Slot | Falls back to |
| --- | --- |
| heading | `"{Label}?"` |
| description | `This cannot be undone.` |
| button | `"{Label}"` |

When both are present, the modal's own copy wins: the frontend reads `modal.heading` first and the confirmation's heading second, and the submit button reads `modal.submitLabel`, then the confirmation's button, then the label.

## What crosses the wire

`Modal::toArray()` is what lands in `Action::toArray()['modal']`:

```php
[
    'width' => 'lg',
    'slideOver' => false,
    'stickyHeader' => false,
    'stickyFooter' => true,
    'closeByClickingAway' => false,
    'closeByEscaping' => true,
    'autofocus' => true,
    'heading' => 'Approve this order',
    'description' => null,
    'submitLabel' => 'Approve it',
    'cancelLabel' => null,
    'cancel' => true,
    'componentName' => null,
    'config' => [],
]
```

Scalars and a registry key. Nothing in it is executable. The TypeScript mirror is `ModalDefinition` in `resources/js/panel/types/action.ts`.

For a form action, `GET {panel}/actions/form` also returns the same `modal` array alongside the schema, plus `title` (the modal heading, or the action's label) and `submitLabel` (the modal's submit label, or the action's label).

## Notes

- **Configuring a modal makes an action non-inert.** `isInert()` returns false as soon as a modal exists, so `Action::make('x')->modalHeading('…')` passes the schema check. Its `type()` is still `callback`, so the dialog renders a submit button that posts to the action endpoint and answers 400. A modal-only action needs `registerModalActions()`, `action()`, or a form to be worth pressing.
- **Registered modal actions render only in a dialog without a form.** They sit in the dialog footer, which the frontend draws only when there is no form to submit. Pair them with `modalContent()` or a confirmation, not with `schema()`.
- **The modal is built lazily.** `getModal()` creates one on first access, which is why `hasModal()` and not `getModal()` is the way to ask whether one was configured. Most actions never open a dialog and are not carrying a modal's worth of defaults.
- **`withoutCancel()` leaves a dialog whose only way out is a decision** — unless `closeByEscaping()` and `closeByClickingAway()` are still on, which they are by default.
- **A slide-over ignores `modalWidth()`.** Set one anyway if the action may later stop being a slide-over; it costs nothing.
- **Custom content is a name, not markup.** It is resolved through the same build-time registry as custom fields and entries, and a name that was not compiled in cannot be reached however it arrives.

## See also

- [Action basics](overview.md)
- [Action forms](forms.md)
- [Custom actions](custom-actions.md)
- [Import and export actions](import-export.md)
- [Infolist actions](infolist-actions.md)
- [Component registries](../concepts/component-registries.md)
- [Custom fields](../forms/custom-fields.md)
- [Record actions on a table](../tables/record-actions.md)
