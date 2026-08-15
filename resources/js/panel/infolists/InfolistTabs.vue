<script setup lang="ts">
import { ref, watch } from 'vue';
import { resolveIcon } from '@/panel/icons/registry';
import InfolistNode from '@/panel/infolists/InfolistNode.vue';
import type { ActionDefinition } from '@/panel/types/action';
import type { InfolistTabsDefinition } from '@/panel/types/infolist';

const props = defineProps<{ tabs: InfolistTabsDefinition }>();

const emit = defineEmits<{ run: [action: ActionDefinition] }>();

const active = ref(props.tabs.tabs[0]?.key ?? '');

/**
 * Persisted in the URL when the schema asked for it, so a reload — or a link
 * somebody was sent — opens where it was left.
 */
watch(active, (key) => {
    if (!props.tabs.persistTab || typeof window === 'undefined') {
        return;
    }

    const url = new URL(window.location.href);

    url.searchParams.set('tab', key);
    window.history.replaceState({}, '', url);
});

if (props.tabs.persistTab && typeof window !== 'undefined') {
    const requested = new URL(window.location.href).searchParams.get('tab');

    // Only a key the schema declares. A URL naming a tab that does not exist
    // opens the first one rather than nothing at all.
    if (
        requested !== null &&
        props.tabs.tabs.some((tab) => tab.key === requested)
    ) {
        active.value = requested;
    }
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap gap-1 border-b" role="tablist">
            <button
                v-for="tab in tabs.tabs"
                :id="`infolist-tab-${tab.key}`"
                :key="tab.key"
                type="button"
                role="tab"
                :aria-selected="active === tab.key"
                :aria-controls="`infolist-panel-${tab.key}`"
                class="-mb-px flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm transition-colors"
                :class="
                    active === tab.key
                        ? 'border-primary text-foreground'
                        : 'border-transparent text-muted-foreground hover:text-foreground'
                "
                @click="active = tab.key"
            >
                <component
                    :is="resolveIcon(tab.icon)"
                    v-if="resolveIcon(tab.icon)"
                    class="size-4"
                />
                {{ tab.label }}
                <span
                    v-if="tab.badge"
                    class="rounded-full bg-muted px-1.5 text-xs text-muted-foreground"
                >
                    {{ tab.badge }}
                </span>
            </button>
        </div>

        <div
            v-for="tab in tabs.tabs"
            v-show="active === tab.key"
            :id="`infolist-panel-${tab.key}`"
            :key="tab.key"
            role="tabpanel"
            :aria-labelledby="`infolist-tab-${tab.key}`"
            class="flex flex-col gap-4"
        >
            <InfolistNode
                v-for="(child, index) in tab.schema"
                :key="index"
                :node="child"
                :columns="tab.columns"
                @run="(action) => emit('run', action)"
            />
        </div>
    </div>
</template>
