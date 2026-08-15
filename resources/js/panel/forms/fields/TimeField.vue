<script setup lang="ts">
import { Input } from '@/components/ui/input';
import FieldWrapper from '@/panel/forms/fields/FieldWrapper.vue';
import type { TimeFieldDefinition } from '@/panel/types/form';

defineProps<{
    field: TimeFieldDefinition;
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
        <!--
            `step` is what makes a browser show seconds at all: without it the
            control rounds to the minute and a value the server sent with
            seconds would be quietly truncated on the first edit.
        -->
        <Input
            :id="field.name"
            type="time"
            :step="field.seconds ? 1 : undefined"
            :model-value="typeof modelValue === 'string' ? modelValue : ''"
            :disabled="field.disabled"
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
