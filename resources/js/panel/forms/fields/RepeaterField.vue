<script setup lang="ts">
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import FieldWrapper from '@/panel/forms/fields/FieldWrapper.vue';
import FormComponentRenderer from '@/panel/forms/FormComponentRenderer.vue';
import type {
    FormValue,
    FormValues,
    RepeaterFieldDefinition,
} from '@/panel/types/form';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

const props = defineProps<{
    field: RepeaterFieldDefinition;
    modelValue: unknown;
    error?: string;
    /**
     * The whole form's errors. A repeater needs them keyed as the server
     * sends them — `items.0.title` — so a message lands on the entry that
     * produced it rather than on the repeater as a whole.
     */
    errors: Record<string, string>;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: FormValues[]] }>();

/**
 * A list of entries, each edited by the same sub-schema.
 *
 * The entries are rendered by the ordinary component renderer against their
 * own values, so a field inside a repeater behaves exactly as it does
 * outside one — including conditions, which read the entry rather than the
 * form. That last part is why the sub-schema's field names are plain: inside
 * an entry, `type` means this entry's type.
 */
const collapsed = ref<Set<number>>(new Set());

const items = computed<FormValues[]>(() => {
    if (!Array.isArray(props.modelValue)) {
        return [];
    }

    return props.modelValue.map((entry) =>
        typeof entry === 'object' && entry !== null && !Array.isArray(entry)
            ? (entry as FormValues)
            : {},
    );
});

const canAdd = computed(
    () =>
        props.field.addable &&
        !props.field.disabled &&
        (props.field.maxItems === null ||
            items.value.length < props.field.maxItems),
);

const canDelete = computed(
    () =>
        props.field.deletable &&
        !props.field.disabled &&
        (props.field.minItems === null ||
            items.value.length > props.field.minItems),
);

/**
 * The label an entry wears, from the server when it declared one and a
 * position otherwise.
 */
function itemLabel(index: number): string {
    return props.field.itemLabels[index] ?? `Item ${index + 1}`;
}

function errorsFor(index: number): Record<string, string> {
    const prefix = `${props.field.name}.${index}.`;
    const scoped: Record<string, string> = {};

    for (const [key, message] of Object.entries(props.errors)) {
        if (key.startsWith(prefix)) {
            scoped[key.slice(prefix.length)] = message;
        }
    }

    return scoped;
}

function change(index: number, name: string, value: FormValue): void {
    emit(
        'update:modelValue',
        items.value.map((entry, position) =>
            position === index ? { ...entry, [name]: value } : entry,
        ),
    );
}

function add(): void {
    emit('update:modelValue', [
        ...items.value,
        { ...props.field.emptyItem } as FormValues,
    ]);
}

function remove(index: number): void {
    emit(
        'update:modelValue',
        items.value.filter((_, position) => position !== index),
    );
}

function move(index: number, offset: number): void {
    const target = index + offset;

    if (target < 0 || target >= items.value.length) {
        return;
    }

    const next = [...items.value];

    [next[index], next[target]] = [next[target], next[index]];

    emit('update:modelValue', next);
}

function toggle(index: number): void {
    const next = new Set(collapsed.value);

    if (next.has(index)) {
        next.delete(index);
    } else {
        next.add(index);
    }

    collapsed.value = next;
}
</script>

<template>
    <FieldWrapper
        :name="field.name"
        :inline-label="field.inlineLabel"
        :label="field.label"
        :required="field.required"
        :helper-text="field.helperText"
        :error="error"
    >
        <div class="flex flex-col gap-3">
            <div
                v-for="(item, index) in items"
                :key="index"
                class="rounded-md border border-input"
            >
                <div
                    class="flex items-center gap-2 border-b border-input bg-muted/40 px-3 py-2"
                >
                    <button
                        v-if="field.collapsible"
                        type="button"
                        class="text-sm font-medium"
                        :aria-expanded="!collapsed.has(index)"
                        @click="toggle(index)"
                    >
                        {{ itemLabel(index) }}
                    </button>
                    <span v-else class="text-sm font-medium">
                        {{ itemLabel(index) }}
                    </span>

                    <div class="ml-auto flex items-center gap-1">
                        <Button
                            v-if="field.reorderable"
                            type="button"
                            variant="ghost"
                            size="sm"
                            :disabled="field.disabled || index === 0"
                            :aria-label="`Move ${itemLabel(index)} up`"
                            @click="move(index, -1)"
                        >
                            ↑
                        </Button>
                        <Button
                            v-if="field.reorderable"
                            type="button"
                            variant="ghost"
                            size="sm"
                            :disabled="
                                field.disabled || index === items.length - 1
                            "
                            :aria-label="`Move ${itemLabel(index)} down`"
                            @click="move(index, 1)"
                        >
                            ↓
                        </Button>
                        <Button
                            v-if="canDelete"
                            type="button"
                            variant="ghost"
                            size="sm"
                            :aria-label="`Remove ${itemLabel(index)}`"
                            @click="remove(index)"
                        >
                            {{ t('forms.remove') }}
                        </Button>
                    </div>
                </div>

                <div
                    v-show="!collapsed.has(index)"
                    class="flex flex-col gap-4 p-3"
                >
                    <FormComponentRenderer
                        v-for="(node, position) in field.schema"
                        :key="position"
                        :node="node"
                        :values="item"
                        :errors="errorsFor(index)"
                        @change="(name, value) => change(index, name, value)"
                    />
                </div>
            </div>

            <p v-if="items.length === 0" class="text-sm text-muted-foreground">
                {{ t('forms.no_entries') }}
            </p>

            <Button
                v-if="canAdd"
                type="button"
                variant="outline"
                size="sm"
                class="w-fit"
                @click="add"
            >
                {{ field.addLabel }}
            </Button>
        </div>
    </FieldWrapper>
</template>
