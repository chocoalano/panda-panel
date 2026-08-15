<script setup lang="ts">
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { resolveIcon } from '@/panel/icons/registry';
import type { ActionDefinition } from '@/panel/types/action';

const props = defineProps<{
    action: ActionDefinition;
    disabled?: boolean;
    size?: 'default' | 'sm' | 'icon-sm';
}>();

const emit = defineEmits<{ run: [action: ActionDefinition] }>();

const icon = computed(() => resolveIcon(props.action.icon));
</script>

<template>
    <Button
        v-if="action.type === 'link' && action.url"
        as="a"
        :href="action.url"
        :variant="action.variant"
        :size="size ?? 'sm'"
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
        @click="emit('run', action)"
    >
        <component :is="icon" v-if="icon" />
        {{ action.label }}
    </Button>
</template>
