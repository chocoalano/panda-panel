<script setup lang="ts">
import { Button } from '@/components/ui/button';
import ActionButton from '@/panel/actions/ActionButton.vue';
import type { ActionDefinition } from '@/panel/types/action';

/**
 * Appears only while rows are selected. The count comes from the selection
 * itself, so it cannot claim more rows than were chosen.
 */
defineProps<{
    actions: ActionDefinition[];
    selected: Array<string | number>;
    processing: boolean;
}>();

const emit = defineEmits<{
    run: [action: ActionDefinition];
    clear: [];
}>();
</script>

<template>
    <div
        v-if="selected.length > 0 && actions.length > 0"
        class="flex flex-wrap items-center gap-2 rounded-lg border bg-muted/40 px-3 py-2"
    >
        <p class="text-sm">
            {{ selected.length }}
            {{ selected.length === 1 ? 'row' : 'rows' }} selected
        </p>

        <div class="ml-auto flex items-center gap-2">
            <ActionButton
                v-for="action in actions"
                :key="action.name"
                :action="action"
                :disabled="processing"
                @run="emit('run', action)"
            />
            <Button variant="ghost" size="sm" @click="emit('clear')">
                Clear
            </Button>
        </div>
    </div>
</template>
