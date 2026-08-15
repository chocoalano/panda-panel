<script setup lang="ts">
import { CircleAlert } from '@lucide/vue';

/**
 * Shown when a custom widget names a component that is not in the build.
 *
 * Neutral rather than alarming, and silent in production: one mistyped
 * component name should not take a dashboard down or fill the console.
 */
const props = defineProps<{
    component: string;
}>();

if (import.meta.env.DEV) {
    console.warn(
        `[panel] No widget component is registered for "${props.component}". ` +
            'Add it under resources/js/pages/Panels/**/Widgets/.',
    );
}
</script>

<template>
    <div
        class="flex items-center gap-2 rounded-lg border border-dashed p-4 text-sm text-muted-foreground"
    >
        <CircleAlert class="size-4" />
        This widget is unavailable.
    </div>
</template>
