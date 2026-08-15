<script setup lang="ts">
import { usePanelStyling } from '@/panel/composables/usePanelStyling';

defineProps<{
    heading: string;
    subheading?: string | null;
}>();

const { hook } = usePanelStyling();
</script>

<template>
    <div
        class="flex flex-wrap items-start justify-between gap-3"
        :class="hook('page-header')"
    >
        <div class="flex flex-col gap-1">
            <h1 class="text-2xl font-semibold tracking-tight">
                {{ heading }}
            </h1>
            <p v-if="subheading" class="text-sm text-muted-foreground">
                {{ subheading }}
            </p>
        </div>

        <!--
            Header actions render here once the action system defines its
            serialized shape. Until then the slot stays empty rather than
            showing a button that does nothing.
        -->
        <div class="flex items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
