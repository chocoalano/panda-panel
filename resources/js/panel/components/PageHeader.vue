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
        <!--
            `text-xl` rather than `text-2xl`, and a tighter gap. The breadcrumb
            above already says where the user is, so the heading is a label
            rather than a title — and every pixel it does not take is a row of
            data on screen, which is the trade an ERP screen wants.
        -->
        <div class="flex min-w-0 flex-col gap-0.5">
            <h1 class="truncate text-xl font-semibold tracking-tight">
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
