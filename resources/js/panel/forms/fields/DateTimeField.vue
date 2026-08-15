<script setup lang="ts">
import { Input } from '@/components/ui/input';
import FieldWrapper from '@/panel/forms/fields/FieldWrapper.vue';
import type { DateTimeFieldDefinition } from '@/panel/types/form';

defineProps<{
    field: DateTimeFieldDefinition;
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
            The server sends and expects `Y-m-d H:i`, which is a space where
            `datetime-local` wants a `T`. Translated on the boundary rather
            than either side changing its mind: a browser will not accept the
            space, and a column would rather not hold the `T`.
        -->
        <Input
            :id="field.name"
            type="datetime-local"
            :step="field.seconds ? 1 : undefined"
            :model-value="
                typeof modelValue === 'string'
                    ? modelValue.replace(' ', 'T')
                    : ''
            "
            :disabled="field.disabled"
            :min="field.minDate?.replace(' ', 'T') ?? undefined"
            :max="field.maxDate?.replace(' ', 'T') ?? undefined"
            :aria-invalid="error ? true : undefined"
            @update:model-value="
                (value) =>
                    emit(
                        'update:modelValue',
                        String(value) === ''
                            ? null
                            : String(value).replace('T', ' '),
                    )
            "
        />
    </FieldWrapper>
</template>
