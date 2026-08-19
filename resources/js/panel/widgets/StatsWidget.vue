<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowDownRight, ArrowRight, ArrowUpRight } from '@lucide/vue';
import { computed } from 'vue';

import { Card } from '@/components/ui/card';
import { safeUrl } from '@/lib/utils';
import { resolveIcon } from '@/panel/icons/registry';

import type {
    StatColor,
    StatDefinition,
    StatTrend,
} from '@/panel/types/widget';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

const props = defineProps<{
    stats: StatDefinition[];
}>();

const COLOR_CLASSES: Record<
    StatColor,
    {
        icon: string;
        iconBackground: string;
        accent: string;
        dot: string;
    }
> = {
    default: {
        icon: 'text-foreground',
        iconBackground: 'bg-muted ring-border/70',
        accent: 'border-l-muted-foreground/30',
        dot: 'bg-foreground/60',
    },

    success: {
        icon: 'text-emerald-600 dark:text-emerald-400',
        iconBackground: 'bg-emerald-500/10 ring-emerald-500/10',
        accent: 'border-l-emerald-500',
        dot: 'bg-emerald-500',
    },

    warning: {
        icon: 'text-amber-600 dark:text-amber-400',
        iconBackground: 'bg-amber-500/10 ring-amber-500/10',
        accent: 'border-l-amber-500',
        dot: 'bg-amber-500',
    },

    danger: {
        icon: 'text-red-600 dark:text-red-400',
        iconBackground: 'bg-red-500/10 ring-red-500/10',
        accent: 'border-l-red-500',
        dot: 'bg-red-500',
    },

    info: {
        icon: 'text-sky-600 dark:text-sky-400',
        iconBackground: 'bg-sky-500/10 ring-sky-500/10',
        accent: 'border-l-sky-500',
        dot: 'bg-sky-500',
    },
};

const TREND_CLASSES: Record<
    StatTrend['direction'],
    {
        icon: typeof ArrowUpRight;
        text: string;
        background: string;
        label: string;
    }
> = {
    up: {
        icon: ArrowUpRight,
        text: 'text-emerald-700 dark:text-emerald-400',
        background: 'bg-emerald-500/[0.08] ring-emerald-500/15',
        label: 'widgets.increased',
    },

    down: {
        icon: ArrowDownRight,
        text: 'text-red-700 dark:text-red-400',
        background: 'bg-red-500/[0.08] ring-red-500/15',
        label: 'widgets.decreased',
    },

    neutral: {
        icon: ArrowRight,
        text: 'text-muted-foreground',
        background: 'bg-muted/60 ring-border/60',
        label: 'widgets.unchanged',
    },
};

const resolvedStats = computed(() =>
    props.stats.map((stat) => ({
        ...stat,
        url: safeUrl(stat.url),
        resolvedIcon: stat.icon ? resolveIcon(stat.icon) : undefined,
    })),
);

/**
 * The sparkline under a figure.
 *
 * A single number says nothing about whether it is a good week; the shape of
 * the last few periods is the context it is missing. Drawn inline rather than
 * through the chart widget: this is a decoration on a figure, not a chart
 * somebody reads values off.
 */
function sparkline(values: number[]): string {
    if (values.length < 2) {
        return '';
    }

    const min = Math.min(...values);
    const max = Math.max(...values);
    const range = max - min || 1;
    const step = 100 / (values.length - 1);

    return values
        .map((value, index) => {
            const x = index * step;
            const y = 24 - ((value - min) / range) * 24;

            return `${index === 0 ? 'M' : 'L'}${x.toFixed(2)},${y.toFixed(2)}`;
        })
        .join(' ');
}
</script>

<template>
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
        <!--
            A stat with a URL is a link to what it counts. `Link` rather than
            an anchor so it is an Inertia navigation like every other, and the
            destination authorizes for itself when it is followed.
        -->
        <component
            :is="stat.url ? Link : Card"
            v-for="stat in resolvedStats"
            :key="stat.label"
            :href="stat.url ?? undefined"
            :class="[
                'group relative block min-w-0 overflow-hidden rounded-lg border border-border/70 bg-background/70 p-4 shadow-xs transition-all hover:-translate-y-0.5 hover:border-border hover:bg-background hover:shadow-sm',
                COLOR_CLASSES[stat.color].accent,
                stat.url
                    ? 'cursor-pointer focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none'
                    : '',
            ]"
        >
            <div class="flex h-full min-w-0 flex-col">
                <!-- Top -->
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0 space-y-1">
                        <p
                            class="truncate text-sm leading-5 font-medium text-muted-foreground"
                        >
                            {{ stat.label }}
                        </p>
                    </div>

                    <div
                        v-if="stat.resolvedIcon"
                        class="flex size-9 shrink-0 items-center justify-center rounded-lg ring-1 ring-inset transition-transform group-hover:scale-105"
                        :class="[
                            COLOR_CLASSES[stat.color].iconBackground,
                            COLOR_CLASSES[stat.color].icon,
                        ]"
                    >
                        <component
                            :is="stat.resolvedIcon"
                            class="size-4"
                            :stroke-width="1.8"
                        />
                    </div>
                </div>

                <!-- Metric -->
                <div
                    class="mt-5 flex min-w-0 flex-wrap items-end gap-x-3 gap-y-2"
                >
                    <p
                        class="min-w-0 wrap-break-word text-3xl leading-none font-semibold tracking-tight text-foreground tabular-nums"
                    >
                        {{ stat.display }}
                    </p>

                    <!-- Trend -->
                    <div
                        v-if="stat.trend"
                        class="inline-flex h-6 shrink-0 items-center gap-1 rounded-md px-1.5 text-xs leading-none font-medium tabular-nums ring-1 ring-inset"
                        :class="[
                            TREND_CLASSES[stat.trend.direction].text,
                            TREND_CLASSES[stat.trend.direction].background,
                        ]"
                        :aria-label="
                            t(TREND_CLASSES[stat.trend.direction].label)
                        "
                    >
                        <component
                            :is="TREND_CLASSES[stat.trend.direction].icon"
                            class="size-3"
                            :stroke-width="2"
                        />

                        <span>{{ stat.trend.value }}%</span>
                    </div>
                </div>

                <!-- Sparkline -->
                <svg
                    v-if="stat.chart.length > 1"
                    class="mt-4 h-7 w-full"
                    viewBox="0 0 100 24"
                    preserveAspectRatio="none"
                    aria-hidden="true"
                >
                    <line
                        x1="0"
                        x2="100"
                        y1="23.5"
                        y2="23.5"
                        stroke="currentColor"
                        stroke-width="1"
                        class="text-border/60"
                        vector-effect="non-scaling-stroke"
                    />
                    <path
                        :d="sparkline(stat.chart)"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.5"
                        stroke-linejoin="round"
                        stroke-linecap="round"
                        vector-effect="non-scaling-stroke"
                        :class="COLOR_CLASSES[stat.color].icon"
                    />
                </svg>

                <!-- Description -->
                <div
                    v-if="stat.description"
                    class="mt-4 flex items-start gap-2 border-t border-border/60 pt-3"
                >
                    <span
                        class="mt-1.5 size-1.5 shrink-0 rounded-full"
                        :class="COLOR_CLASSES[stat.color].dot"
                    />

                    <p
                        class="line-clamp-2 text-xs leading-5 text-muted-foreground"
                    >
                        {{ stat.description }}
                    </p>
                </div>
            </div>
        </component>
    </div>
</template>
