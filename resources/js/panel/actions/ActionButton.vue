<script setup lang="ts">
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { safeUrl } from '@/lib/utils';
import { resolveIcon } from '@/panel/icons/registry';
import type { ActionDefinition } from '@/panel/types/action';

const props = defineProps<{
    action: ActionDefinition;
    disabled?: boolean;
    size?: 'default' | 'sm' | 'icon-sm';
}>();

const emit = defineEmits<{ run: [action: ActionDefinition] }>();

const icon = computed(() => resolveIcon(props.action.icon));
const url = computed(() => safeUrl(props.action.url));
</script>

<template>
    <Button
        v-if="action.type === 'link' && url"
        as="a"
        :href="url"
        :variant="action.variant"
        :size="size ?? 'sm'"
        :data-tour="`action:${action.name}`"
    >
        <component :is="icon" v-if="icon" />
        {{ action.label }}
    </Button>

    <Button
        v-else
        type="button"
        :variant="action.variant"
        :size="size ?? 'sm'"
        :disabled="disabled"
        :data-tour="`action:${action.name}`"
        @click="emit('run', action)"
    >
        <component :is="icon" v-if="icon" />
        {{ action.label }}
    </Button>
</template>
