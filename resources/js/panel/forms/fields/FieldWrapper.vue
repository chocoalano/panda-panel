<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';

defineProps<{
    name: string;
    label: string;
    required: boolean;
    helperText: string | null;
    error?: string;
    /** Renders the label after the control, for checkboxes and toggles. */
    inline?: boolean;
    /**
     * Renders the label beside the control rather than above it, for a form
     * that reads as a list of settings. Ignored when `inline` already puts
     * the label after the control — a checkbox's label belongs next to the
     * box whichever way the rest of the form is laid out.
     */
    inlineLabel?: boolean;
}>();
</script>

<template>
    <div v-if="inline" class="flex items-start gap-3">
        <slot />
        <div class="flex flex-col gap-1">
            <Label :for="name" class="font-normal">
                {{ label }}
                <span v-if="required" class="text-destructive">*</span>
            </Label>
            <p v-if="helperText" class="text-xs text-muted-foreground">
                {{ helperText }}
            </p>
            <InputError :message="error" />
        </div>
    </div>

    <div
        v-else-if="inlineLabel"
        class="grid grid-cols-1 items-start gap-x-4 gap-y-1.5 sm:grid-cols-[12rem_1fr]"
    >
        <Label :for="name" class="sm:pt-2">
            {{ label }}
            <span v-if="required" class="text-destructive">*</span>
        </Label>
        <div class="flex flex-col gap-1.5">
            <slot />
            <p v-if="helperText" class="text-xs text-muted-foreground">
                {{ helperText }}
            </p>
            <InputError :message="error" />
        </div>
    </div>

    <div v-else class="flex flex-col gap-1.5">
        <Label :for="name">
            {{ label }}
            <span v-if="required" class="text-destructive">*</span>
        </Label>
        <slot />
        <p v-if="helperText" class="text-xs text-muted-foreground">
            {{ helperText }}
        </p>
        <InputError :message="error" />
    </div>
</template>
