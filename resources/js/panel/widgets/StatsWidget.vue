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

const props = defineProps<{
    stats: StatDefinition[];
}>();

const COLOR_CLASSES: Record<
    StatColor,
    {
        icon: string;
        iconBackground: string;
        glow: string;
        dot: string;
    }
> = {
    default: {
        icon: 'text-foreground',
        iconBackground: 'bg-muted',
        glow: 'bg-foreground/[0.04]',
        dot: 'bg-foreground/60',
    },

    success: {
        icon: 'text-emerald-600 dark:text-emerald-400',
        iconBackground: 'bg-emerald-500/10 ring-emerald-500/10',
        glow: 'bg-emerald-500/[0.08]',
        dot: 'bg-emerald-500',
    },

    warning: {
        icon: 'text-amber-600 dark:text-amber-400',
        iconBackground: 'bg-amber-500/10 ring-amber-500/10',
        glow: 'bg-amber-500/[0.08]',
        dot: 'bg-amber-500',
    },

    danger: {
        icon: 'text-red-600 dark:text-red-400',
        iconBackground: 'bg-red-500/10 ring-red-500/10',
        glow: 'bg-red-500/[0.08]',
        dot: 'bg-red-500',
    },

    info: {
        icon: 'text-sky-600 dark:text-sky-400',
        iconBackground: 'bg-sky-500/10 ring-sky-500/10',
        glow: 'bg-sky-500/[0.08]',
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
        label: 'Increased',
    },

    down: {
        icon: ArrowDownRight,
        text: 'text-red-700 dark:text-red-400',
        background: 'bg-red-500/[0.08] ring-red-500/15',
        label: 'Decreased',
    },

    neutral: {
        icon: ArrowRight,
        text: 'text-muted-foreground',
        background: 'bg-muted/60 ring-border/60',
        label: 'Unchanged',
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
    <div class="grid grid-cols-[repeat(auto-fit,minmax(240px,1fr))] gap-4">
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
                'group relative isolate block min-w-0 overflow-hidden rounded-xl border border-border/60 bg-card p-0 shadow-[0_1px_2px_0_rgb(0_0_0/0.025)] transition-[border-color,box-shadow,transform] duration-200 ease-out hover:-translate-y-0.5 hover:border-border hover:shadow-md hover:shadow-black/4 dark:hover:shadow-black/20',
                stat.url ? 'cursor-pointer' : '',
            ]"
        >
            <!--
                Soft ambient accent.

                Very subtle on purpose.
                It gives each metric a semantic identity without
                turning the entire card green/red/blue.
            -->
            <div
                class="pointer-events-none absolute -top-16 -right-16 size-40 rounded-full opacity-70 blur-3xl transition-all duration-300 group-hover:scale-110 group-hover:opacity-100"
                :class="COLOR_CLASSES[stat.color].glow"
            />

            <div class="relative flex h-full flex-col p-5">
                <!-- Top -->
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0 space-y-1">
                        <p
                            class="truncate text-[13px] leading-none font-medium tracking-tight text-muted-foreground"
                        >
                            {{ stat.label }}
                        </p>
                    </div>

                    <div
                        v-if="stat.resolvedIcon"
                        class="flex size-9 shrink-0 items-center justify-center rounded-xl ring-1 transition-transform duration-200 ring-inset group-hover:scale-105"
                        :class="[
                            COLOR_CLASSES[stat.color].iconBackground,
                            COLOR_CLASSES[stat.color].icon,
                        ]"
                    >
                        <component
                            :is="stat.resolvedIcon"
                            class="size-4.25"
                            :stroke-width="1.8"
                        />
                    </div>
                </div>

                <!-- Metric -->
                <div class="mt-5">
                    <p
                        class="text-[2.15rem] leading-none font-semibold tracking-[-0.045em] text-foreground tabular-nums @[320px]:text-[2.4rem]"
                    >
                        {{ stat.display }}
                    </p>
                </div>

                <!-- Sparkline -->
                <svg
                    v-if="stat.chart.length > 1"
                    class="mt-4 h-6 w-full"
                    viewBox="0 0 100 24"
                    preserveAspectRatio="none"
                    aria-hidden="true"
                >
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

                <!-- Trend -->
                <div v-if="stat.trend" class="mt-4 flex items-center gap-2">
                    <div
                        class="inline-flex h-6 shrink-0 items-center gap-1 rounded-md px-1.5 text-[11px] leading-none font-semibold tabular-nums ring-1 ring-inset"
                        :class="[
                            TREND_CLASSES[stat.trend.direction].text,
                            TREND_CLASSES[stat.trend.direction].background,
                        ]"
                    >
                        <component
                            :is="TREND_CLASSES[stat.trend.direction].icon"
                            class="size-3"
                            :stroke-width="2"
                        />

                        <span> {{ stat.trend.value }}% </span>
                    </div>

                    <span class="truncate text-[11px] text-muted-foreground/70">
                        {{ TREND_CLASSES[stat.trend.direction].label }}
                    </span>
                </div>

                <!-- Description -->
                <div
                    v-if="stat.description"
                    class="mt-5 flex items-start gap-2 border-t border-border/50 pt-4"
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
