<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import FormComponentRenderer from '@/panel/forms/FormComponentRenderer.vue';
import { resolveIcon } from '@/panel/icons/registry';
import type { FormValue, FormValues, TabsDefinition } from '@/panel/types/form';

const props = defineProps<{
    tabs: TabsDefinition;
    values: FormValues;
    errors: Record<string, string>;
}>();

const emit = defineEmits<{ change: [name: string, value: FormValue] }>();

const active = ref(props.tabs.tabs[0]?.key ?? '');

/**
 * A tab holding a rejected field is opened for the user.
 *
 * Without this, submitting a long form and being told nothing happened is
 * exactly what it looks like: the message is on a panel that is not on
 * screen. The server sends each tab's field names for this reason.
 */
const failing = computed(() =>
    props.tabs.tabs.find((tab) =>
        tab.fields.some((name) => props.errors[name] !== undefined),
    ),
);

watch(failing, (tab) => {
    if (tab !== undefined) {
        active.value = tab.key;
    }
});

/**
 * Persisted in the URL when the schema asked for it, so a reload — or a link
 * someone was sent — opens where it was left.
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

function hasError(fields: string[]): boolean {
    return fields.some((name) => props.errors[name] !== undefined);
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap gap-1 border-b" role="tablist">
            <button
                v-for="tab in tabs.tabs"
                :id="`tab-${tab.key}`"
                :key="tab.key"
                type="button"
                role="tab"
                :aria-selected="active === tab.key"
                :aria-controls="`panel-${tab.key}`"
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
                <span
                    v-if="hasError(tab.fields)"
                    class="size-1.5 rounded-full bg-destructive"
                    aria-label="This tab has errors"
                />
            </button>
        </div>

        <div
            v-for="tab in tabs.tabs"
            v-show="active === tab.key"
            :id="`panel-${tab.key}`"
            :key="tab.key"
            role="tabpanel"
            :aria-labelledby="`tab-${tab.key}`"
            class="flex flex-col gap-4"
        >
            <FormComponentRenderer
                v-for="(node, index) in tab.schema"
                :key="index"
                :node="node"
                :values="values"
                :errors="errors"
                @change="(name, value) => emit('change', name, value)"
            />
        </div>
    </div>
</template>
