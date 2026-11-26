<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { CircleHelp } from '@lucide/vue';
import { useElementBounding } from '@vueuse/core';
import {
    computed,
    nextTick,
    onMounted,
    ref,
    shallowRef,
    useId,
    watch,
} from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
} from '@/components/ui/popover';
import { useTranslator } from '@/composables/useTranslator';
import { usePanel } from '@/panel/composables/usePanel';
import { usePanelPage } from '@/panel/composables/usePanelPage';
import {
    findTourTarget,
    rememberTour,
    shouldAutoStart,
    tourStorageKey,
} from '@/panel/tour/tour';
import TourStepActions from '@/panel/tour/TourStepActions.vue';

/**
 * The guided tour of the page being shown, and the button that opens it.
 *
 * Draws nothing at all on a page that declared no tour. On one that did, it
 * opens by itself the first time this reader meets it, and from the button
 * after that.
 *
 * A step about something on screen is a popover against it, with the rest
 * dimmed. A step about nothing — or about something that is not there — is a
 * dialog in the middle.
 */
const page = usePage();
const metadata = usePanelPage();
const { panel } = usePanel();
const { t } = useTranslator();

const tour = computed(() => metadata.value?.tour ?? null);

/** Which step is open; null while the tour is closed. */
const position = ref<number | null>(null);
const target = shallowRef<HTMLElement | null>(null);

const step = computed(() =>
    position.value === null
        ? null
        : (tour.value?.steps[position.value] ?? null),
);

const progress = computed(() =>
    t('tour.progress', {
        current: (position.value ?? 0) + 1,
        total: tour.value?.steps.length ?? 0,
    }),
);

const first = computed(() => position.value === 0);
const last = computed(
    () => position.value === (tour.value?.steps.length ?? 0) - 1,
);

const titleId = useId();
const descriptionId = useId();

const trigger = ref<InstanceType<typeof Button> | null>(null);
const actions = ref<InstanceType<typeof TourStepActions> | null>(null);

function storageKey(key: string): string {
    // `auth` is the application's shared prop, not the panel's, so nothing
    // about its shape is assumed.
    const { auth } = page.props as {
        auth?: { user?: { id?: unknown } | null };
    };
    const id = auth?.user?.id;

    return tourStorageKey(
        panel.value?.id ?? '',
        typeof id === 'string' || typeof id === 'number' ? id : null,
        key,
    );
}

function show(index: number): void {
    const next = tour.value?.steps[index];

    if (next === undefined) {
        close();

        return;
    }

    // Found before the step is drawn, so a popover never opens against the
    // previous step's anchor.
    const found = findTourTarget(next.target);

    found?.scrollIntoView({ block: 'center', inline: 'nearest' });

    target.value = found;
    position.value = index;
}

function close(): void {
    position.value = null;
    target.value = null;
}

/**
 * Closed by the reader, so focus goes back to where the tour is reopened —
 * without scrolling there, because they asked to close it, not to move.
 */
function dismiss(): void {
    close();

    void nextTick(() =>
        (trigger.value?.$el as HTMLElement | undefined)?.focus({
            preventScroll: true,
        }),
    );
}

function forward(): void {
    if (last.value) {
        dismiss();

        return;
    }

    show((position.value ?? 0) + 1);
}

function start(): void {
    if (tour.value !== null) {
        rememberTour(storageKey(tour.value.key));
        show(0);
    }
}

function startOnFirstVisit(): void {
    if (tour.value === null) {
        return;
    }

    const key = storageKey(tour.value.key);

    // Remembered as it opens rather than as it closes: following the thing a
    // step points at leaves the page, and that is not a reason to start over.
    if (shouldAutoStart(key) && rememberTour(key)) {
        show(0);
    }
}

function onOpenChange(open: boolean): void {
    if (!open) {
        dismiss();
    }
}

function focusActions(event: Event): void {
    event.preventDefault();
    actions.value?.focus();
}

// After the tick, so everything beside the header is in the document to be
// pointed at.
onMounted(() => void nextTick(startOnFirstVisit));

// A visit that keeps this component but changes the page.
watch(
    () => tour.value?.key,
    () => {
        close();
        void nextTick(startOnFirstVisit);
    },
);

const bounds = useElementBounding(target);

/** A little outside the target, so the ring does not cover its edge. */
const highlight = computed(() => ({
    top: `${bounds.top.value - 4}px`,
    left: `${bounds.left.value - 4}px`,
    width: `${bounds.width.value + 8}px`,
    height: `${bounds.height.value + 8}px`,
}));
</script>

<template>
    <Button
        v-if="tour"
        ref="trigger"
        type="button"
        variant="ghost"
        size="icon-sm"
        :aria-label="t('tour.start')"
        :title="t('tour.start')"
        @click="start"
    >
        <CircleHelp />
    </Button>

    <!--
        One element dims everything and takes the clicks, so a page cannot be
        changed from underneath the step describing it. The hole in the dimming
        is the shadow of the box drawn around the target.
    -->
    <Teleport to="body">
        <div
            v-if="step && target"
            class="fixed inset-0 z-40 overflow-hidden"
            aria-hidden="true"
        >
            <div
                data-tour-highlight
                class="absolute rounded-md shadow-[0_0_0_200vmax_rgb(0_0_0/0.5)] outline-2 outline-primary"
                :style="highlight"
            />
        </div>
    </Teleport>

    <Popover
        v-if="step && target"
        :key="position ?? 0"
        :open="true"
        @update:open="onOpenChange"
    >
        <PopoverAnchor :reference="target" class="hidden" />
        <PopoverContent
            :side="step.side"
            :side-offset="12"
            :collision-padding="12"
            class="flex w-80 max-w-[calc(100vw-2rem)] flex-col gap-3"
            :aria-labelledby="titleId"
            :aria-describedby="step.description ? descriptionId : undefined"
            @interact-outside.prevent
            @open-auto-focus="focusActions"
            @close-auto-focus.prevent
        >
            <div class="flex flex-col gap-1">
                <p class="text-xs text-muted-foreground">{{ progress }}</p>
                <h2 :id="titleId" class="text-sm font-semibold">
                    {{ step.title }}
                </h2>
                <p
                    v-if="step.description"
                    :id="descriptionId"
                    class="text-sm text-muted-foreground"
                >
                    {{ step.description }}
                </p>
            </div>

            <TourStepActions
                ref="actions"
                :first="first"
                :last="last"
                @back="show((position ?? 0) - 1)"
                @next="forward"
                @skip="dismiss"
            />
        </PopoverContent>
    </Popover>

    <Dialog v-else-if="step" :open="true" @update:open="onOpenChange">
        <DialogContent
            @interact-outside.prevent
            @open-auto-focus="focusActions"
            @close-auto-focus.prevent
        >
            <DialogHeader>
                <p class="text-xs text-muted-foreground">{{ progress }}</p>
                <DialogTitle>{{ step.title }}</DialogTitle>
                <!-- A dialog is announced with a description; a step that
                     says nothing more is described by where it is. -->
                <DialogDescription
                    :class="step.description ? undefined : 'sr-only'"
                >
                    {{ step.description ?? progress }}
                </DialogDescription>
            </DialogHeader>

            <TourStepActions
                ref="actions"
                :first="first"
                :last="last"
                @back="show((position ?? 0) - 1)"
                @next="forward"
                @skip="dismiss"
            />
        </DialogContent>
    </Dialog>
</template>
