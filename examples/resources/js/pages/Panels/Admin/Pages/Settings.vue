<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PageHeader from '@/panel/components/PageHeader.vue';
import type { PageMetadata } from '@/panel/types/page';

/**
 * A page with its own component. It receives the same metadata every panel
 * page does, so the layout, breadcrumbs, and heading work identically to a
 * page using the generic renderer.
 */
defineProps<{
    page: PageMetadata;
    settings: Array<{ label: string; value: string }>;
}>();
</script>

<template>
    <Head :title="page.title" />

    <div class="flex max-w-3xl flex-col gap-6">
        <PageHeader :heading="page.heading" :subheading="page.subheading" />

        <dl class="divide-y rounded-lg border">
            <div
                v-for="setting in settings"
                :key="setting.label"
                class="grid gap-1 px-4 py-3 sm:grid-cols-3 sm:gap-4"
            >
                <dt class="text-sm text-muted-foreground">
                    {{ setting.label }}
                </dt>
                <dd class="text-sm sm:col-span-2">{{ setting.value }}</dd>
            </div>
        </dl>
    </div>
</template>
