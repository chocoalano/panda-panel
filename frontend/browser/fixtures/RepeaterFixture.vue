<script setup lang="ts">
import { ref } from 'vue';
import FormRenderer from '@/panel/forms/FormRenderer.vue';
import type {
    FieldDefinition,
    FormComponentDefinition,
} from '@/panel/types/form';

/**
 * A collapsible, reorderable repeater with three entries.
 *
 * The focus calibration target: F01 moves focus to a surviving entry after a
 * removal, and `document.activeElement` after a DOM mutation is exactly the
 * kind of thing a DOM implementation can model plausibly and wrongly. Asking
 * a browser is the point.
 */
function field(overrides: Record<string, unknown> = {}): FieldDefinition {
    return {
        component: 'field',
        name: 'name',
        label: 'Name',
        type: 'text',
        inputType: 'text',
        maxLength: null,
        value: '',
        placeholder: null,
        helperText: null,
        required: false,
        disabled: false,
        inlineLabel: false,
        columnSpan: 'full',
        conditions: { visibleWhen: [], hiddenWhen: [] },
        live: null,
        validation: { required: false },
        ...overrides,
    } as unknown as FieldDefinition;
}

const schema = ref<FormComponentDefinition[]>([
    field({
        name: 'contacts',
        label: 'Contacts',
        type: 'repeater',
        value: [{ name: 'A' }, { name: 'B' }, { name: 'C' }],
        schema: [field()],
        itemLabels: ['A', 'B', 'C'],
        emptyItem: { name: '' },
        addable: true,
        deletable: true,
        reorderable: true,
        collapsible: true,
        addLabel: 'Add',
        minItems: null,
        maxItems: null,
    }) as unknown as FormComponentDefinition,
]);
</script>

<template>
    <FormRenderer :form="{ schema, columns: 1 }" submit-url="/fixture" />
</template>
