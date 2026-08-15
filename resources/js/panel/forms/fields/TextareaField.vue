<script setup lang="ts">
import { Textarea } from '@/components/ui/textarea';
import FieldWrapper from '@/panel/forms/fields/FieldWrapper.vue';
import type { TextareaFieldDefinition } from '@/panel/types/form';

defineProps<{
    field: TextareaFieldDefinition;
    modelValue: unknown;
    error?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
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
        <Textarea
            :id="field.name"
            :model-value="typeof modelValue === 'string' ? modelValue : ''"
            :placeholder="field.placeholder ?? undefined"
            :disabled="field.disabled"
            :rows="field.rows"
            :maxlength="field.maxLength ?? undefined"
            :aria-invalid="error ? true : undefined"
            @update:model-value="
                (value) => emit('update:modelValue', String(value))
            "
        />
    </FieldWrapper>
</template>
