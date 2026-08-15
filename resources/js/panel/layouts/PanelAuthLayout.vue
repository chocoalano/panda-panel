<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Toaster } from '@/components/ui/sonner';
import { resolveIcon } from '@/panel/icons/registry';
import type { PanelDefinition } from '@/panel/types/panel';

/**
 * The frame a panel's own auth pages sit in.
 *
 * Separate from the panel shell because none of the shell applies to a guest:
 * there is no navigation to draw, no notifications to count, and no user
 * menu. What is left is the panel's identity, which is the whole reason a
 * panel has a front door of its own rather than sharing the application's.
 */
const props = defineProps<{
    panel: PanelDefinition;
    title: string;
    description?: string;
}>();

const icon = computed(() => resolveIcon(props.panel.icon));
</script>

<template>
    <div class="flex min-h-svh flex-col items-center justify-center gap-6 p-6">
        <div class="flex w-full max-w-sm flex-col gap-6">
            <Link
                :href="`/${panel.path}`"
                class="flex items-center gap-2 self-center font-medium"
            >
                <span
                    class="flex size-9 items-center justify-center rounded-md bg-primary text-primary-foreground"
                >
                    <component :is="icon" v-if="icon" class="size-5" />
                </span>
                <span>{{ panel.brandName }}</span>
            </Link>

            <div class="flex flex-col gap-2 text-center">
                <h1 class="text-xl font-medium">{{ title }}</h1>
                <p v-if="description" class="text-sm text-muted-foreground">
                    {{ description }}
                </p>
            </div>

            <slot />
        </div>

        <Toaster />
    </div>
</template>
