<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';
import { usePanelStyling } from '@/panel/composables/usePanelStyling';
import type { WidgetDefinition } from '@/panel/types/widget';
import WidgetFilters from '@/panel/widgets/WidgetFilters.vue';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

/**
 * What every widget wears: its heading, its filters, and its refresh.
 *
 * Kept out of the four renderers so a stats widget and a chart widget cannot
 * grow different ideas of where a heading goes — and so adding something
 * every widget has means editing one file.
 *
 * Polling reloads the props the page gave it rather than asking for one
 * widget. A widget's data *is* a prop of the page it sits on; a second
 * endpoint that answered for one widget would have to re-resolve the page's
 * authorization, its filters, and its context to say anything true.
 */
const props = defineProps<{
    widget: WidgetDefinition;
    /** The props a poll reloads. The page knows them; the widget does not. */
    reloadProps: string[];
}>();

let timer: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    const seconds = props.widget.polling;

    if (seconds === null || seconds <= 0) {
        return;
    }

    timer = setInterval(() => {
        // `only` so a poll costs the widgets and nothing else. A partial
        // reload already keeps the rest of the page's state, which is what
        // stops a refresh from throwing away a half-typed filter.
        router.reload({ only: props.reloadProps });
    }, seconds * 1000);
});

onBeforeUnmount(() => {
    if (timer !== null) {
        clearInterval(timer);
    }
});

const { hook } = usePanelStyling();
</script>

<template>
    <div class="flex flex-col gap-3" :class="hook('widget')">
        <div
            v-if="widget.heading || widget.description || widget.filters"
            class="flex flex-wrap items-start justify-between gap-3"
        >
            <div v-if="widget.heading || widget.description">
                <p v-if="widget.heading" class="text-sm font-medium">
                    {{ widget.heading }}
                </p>
                <p
                    v-if="widget.description"
                    class="text-sm text-muted-foreground"
                >
                    {{ widget.description }}
                </p>
            </div>

            <WidgetFilters
                v-if="widget.filters"
                :form="widget.filters.form"
                :namespace="`widgets[${widget.id}]`"
                :in-modal="widget.filters.inModal"
                :title="widget.heading ?? t('widgets.filters')"
            />
        </div>

        <slot />
    </div>
</template>
