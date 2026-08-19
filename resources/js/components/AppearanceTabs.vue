<script setup lang="ts">
import { Monitor, Moon, Sun } from '@lucide/vue';
import { useAppearance } from '@/composables/useAppearance';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

const { appearance, updateAppearance } = useAppearance();

// The labels are read through `t` rather than held on the tuple, so a
// locale change redraws them: the array is built once, a computed is not.
const tabs = [
    { value: 'light', Icon: Sun, key: 'ui.appearance_light' },
    { value: 'dark', Icon: Moon, key: 'ui.appearance_dark' },
    { value: 'system', Icon: Monitor, key: 'ui.appearance_system' },
] as const;
</script>

<template>
    <div
        class="inline-flex gap-1 rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800"
    >
        <button
            v-for="{ value, Icon, key } in tabs"
            :key="value"
            :class="[
                'flex items-center rounded-md px-3.5 py-1.5 transition-colors',
                appearance === value
                    ? 'bg-white shadow-xs dark:bg-neutral-700 dark:text-neutral-100'
                    : 'text-neutral-500 hover:bg-neutral-200/60 hover:text-black dark:text-neutral-400 dark:hover:bg-neutral-700/60',
            ]"
            @click="updateAppearance(value)"
        >
            <component :is="Icon" class="-ml-1 h-4 w-4" />
            <span class="ml-1.5 text-sm">{{ t(key) }}</span>
        </button>
    </div>
</template>
