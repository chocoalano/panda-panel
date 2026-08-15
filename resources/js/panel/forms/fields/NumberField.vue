<script setup lang="ts">
import { Input } from '@/components/ui/input';
import FieldWrapper from '@/panel/forms/fields/FieldWrapper.vue';
import type { NumberFieldDefinition } from '@/panel/types/form';

defineProps<{
    field: NumberFieldDefinition;
    modelValue: unknown;
    error?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: number | null] }>();

function onInput(value: string | number): void {
    const raw = String(value).trim();

    emit('update:modelValue', raw === '' ? null : Number(raw));
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
        <Input
            :id="field.name"
            type="number"
            :model-value="typeof modelValue === 'number' ? modelValue : ''"
            :placeholder="field.placeholder ?? undefined"
            :disabled="field.disabled"
            :min="field.min ?? undefined"
            :max="field.max ?? undefined"
            :step="field.step ?? undefined"
            :aria-invalid="error ? true : undefined"
            @update:model-value="onInput"
        />
    </FieldWrapper>
</template>
