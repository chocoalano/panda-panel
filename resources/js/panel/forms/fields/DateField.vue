<script setup lang="ts">
import { Input } from '@/components/ui/input';
import FieldWrapper from '@/panel/forms/fields/FieldWrapper.vue';
import type { DateFieldDefinition } from '@/panel/types/form';

defineProps<{
    field: DateFieldDefinition;
    modelValue: unknown;
    error?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string | null] }>();
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
            type="date"
            :model-value="typeof modelValue === 'string' ? modelValue : ''"
            :disabled="field.disabled"
            :min="field.minDate ?? undefined"
            :max="field.maxDate ?? undefined"
            :aria-invalid="error ? true : undefined"
            @update:model-value="
                (value) =>
                    emit(
                        'update:modelValue',
                        String(value) === '' ? null : String(value),
                    )
            "
        />
    </FieldWrapper>
</template>
