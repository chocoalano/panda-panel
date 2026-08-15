<script setup lang="ts">
import { computed } from 'vue';
import FormComponentRenderer from '@/panel/forms/FormComponentRenderer.vue';
import type { FormValue, FormValues, GridDefinition } from '@/panel/types/form';

const props = defineProps<{
    grid: GridDefinition;
    values: FormValues;
    errors: Record<string, string>;
}>();

const emit = defineEmits<{ change: [name: string, value: FormValue] }>();

/**
 * Column counts map to literal classes. An interpolated `md:grid-cols-${n}`
 * would compile to nothing.
 */
const GRID_CLASSES: Record<number, string> = {
    1: 'grid-cols-1',
    2: 'grid-cols-1 md:grid-cols-2',
    3: 'grid-cols-1 md:grid-cols-3',
    4: 'grid-cols-1 md:grid-cols-2 lg:grid-cols-4',
};

const SPAN_CLASSES: Record<number, string> = {
    1: 'col-span-1',
    2: 'md:col-span-2',
    3: 'md:col-span-3',
    4: 'md:col-span-4',
};

const gridClass = computed(
    () => GRID_CLASSES[props.grid.columns] ?? GRID_CLASSES[1],
);

function spanClass(node: { component: string; columnSpan?: number }): string {
    const span =
        node.component === 'field'
            ? (node.columnSpan ?? 1)
            : props.grid.columns;

    return SPAN_CLASSES[Math.min(span, props.grid.columns)] ?? SPAN_CLASSES[1];
}
</script>

<template>
    <div class="grid gap-4" :class="gridClass">
        <div
            v-for="(node, index) in grid.schema"
            :key="index"
            :class="spanClass(node)"
        >
            <FormComponentRenderer
                :node="node"
                :values="values"
                :errors="errors"
                @change="(name, value) => emit('change', name, value)"
            />
        </div>
    </div>
</template>
