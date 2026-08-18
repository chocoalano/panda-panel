<script setup lang="ts">
import { computed, ref } from 'vue';

import { Card, CardContent } from '@/components/ui/card';

import type {
    ChartOptions,
    ChartSeriesDefinition,
    ChartVariant,
    StatColor,
} from '@/panel/types/widget';

const props = withDefaults(
    defineProps<{
        variant: ChartVariant;
        labels: string[];
        series: ChartSeriesDefinition[];
        /** A closed set of settings, never a configuration tree. */
        options?: ChartOptions;
        maxHeight?: number;
    }>(),
    {
        options: () => ({
            legend: true,
            grid: true,
            stacked: false,
            filled: false,
            curved: false,
            labels: false,
            min: null,
            max: null,
            prefix: null,
            suffix: null,
        }),
        maxHeight: 220,
    },
);

const WIDTH = 600;
const HEIGHT = 220;

const PADDING = {
    top: 16,
    right: 8,
    bottom: 12,
    left: 8,
};

const GRID_LINES = 4;

const activeIndex = ref<number | null>(null);

const SERIES_CLASSES: Record<StatColor, string> = {
    default: 'text-foreground/70',
    success: 'text-emerald-500',
    warning: 'text-amber-500',
    danger: 'text-red-500',
    info: 'text-sky-500',
};

const plot = computed(() => ({
    width: WIDTH - PADDING.left - PADDING.right,
    height: HEIGHT - PADDING.top - PADDING.bottom,
}));

const allValues = computed(() =>
    props.series.flatMap((item) =>
        item.values.filter((value) => Number.isFinite(value)),
    ),
);

const pointCount = computed(() =>
    Math.max(
        props.labels.length,
        ...props.series.map((item) => item.values.length),
    ),
);

const isEmpty = computed(
    () =>
        props.series.length === 0 ||
        allValues.value.length === 0 ||
        pointCount.value === 0,
);

/**
 * Chart domain.
 *
 * Zero stays visible whenever possible so bars have a meaningful baseline.
 * A small amount of breathing room prevents the highest line from touching
 * the top edge of the chart.
 */
const domain = computed(() => {
    // A pinned axis wins over the data. A chart read against a target needs
    // the same scale every week, which is the whole reason to pin one.
    if (props.options.min !== null && props.options.max !== null) {
        return { min: props.options.min, max: props.options.max };
    }

    if (allValues.value.length === 0) {
        return {
            min: props.options.min ?? 0,
            max: props.options.max ?? 1,
        };
    }

    const rawMin = props.options.min ?? Math.min(...allValues.value);
    const rawMax = props.options.max ?? Math.max(...allValues.value);

    let min = Math.min(0, rawMin);
    let max = Math.max(0, rawMax);

    if (min === max) {
        return {
            min,
            max: max + 1,
        };
    }

    const range = max - min;
    const padding = range * 0.08;

    if (max > 0) {
        max += padding;
    }

    if (min < 0) {
        min -= padding;
    }

    return {
        min,
        max,
    };
});

const categoryWidth = computed(() => {
    if (pointCount.value === 0) {
        return plot.value.width;
    }

    return plot.value.width / pointCount.value;
});

function categoryX(index: number): number {
    return PADDING.left + categoryWidth.value * index + categoryWidth.value / 2;
}

function y(value: number): number {
    const range = domain.value.max - domain.value.min;

    if (range === 0) {
        return PADDING.top + plot.value.height / 2;
    }

    return (
        PADDING.top + ((domain.value.max - value) / range) * plot.value.height
    );
}

const baselineY = computed(() => y(0));

/**
 * Horizontal visual guides.
 *
 * No axis labels are rendered here because this widget is intended as a
 * compact dashboard chart rather than a full analytical chart.
 */
const gridLines = computed(() =>
    Array.from({ length: GRID_LINES }, (_, index) => {
        const progress = GRID_LINES <= 1 ? 0 : index / (GRID_LINES - 1);

        return PADDING.top + progress * plot.value.height;
    }),
);

function linePath(values: number[]): string {
    const points = values.map((value, index) => ({
        x: categoryX(index),
        y: y(value),
    }));

    if (points.length === 0) {
        return '';
    }

    if (!props.options.curved || points.length < 3) {
        return points
            .map(
                (point, index) =>
                    `${index === 0 ? 'M' : 'L'}${point.x},${point.y}`,
            )
            .join(' ');
    }

    // A monotone-ish curve through the midpoints: smooth without the
    // overshoot a plain cubic gives, which on a count chart would draw
    // negative values that were never in the data.
    let path = `M${points[0].x},${points[0].y}`;

    for (let index = 1; index < points.length; index++) {
        const previous = points[index - 1];
        const current = points[index];
        const midX = (previous.x + current.x) / 2;

        path += ` C${midX},${previous.y} ${midX},${current.y} ${current.x},${current.y}`;
    }

    return path;
}

/**
 * The line closed down to the baseline, for a filled area.
 *
 * Area is a shape rather than a different chart, which is why it reuses the
 * line's own path and only closes it.
 */
function areaPath(values: number[]): string {
    const line = linePath(values);

    if (line === '') {
        return '';
    }

    const lastX = categoryX(values.length - 1);

    return `${line} L${lastX},${baselineY.value} L${categoryX(0)},${baselineY.value} Z`;
}

/**
 * Where a bar starts when the series are stacked.
 *
 * Stacking is about the total, so each bar begins where the ones before it
 * ended rather than at the baseline.
 */
function stackBase(seriesIndex: number, pointIndex: number): number {
    let total = 0;

    for (let index = 0; index < seriesIndex; index++) {
        const value = props.series[index]?.values[pointIndex];

        if (typeof value === 'number' && Number.isFinite(value)) {
            total += value;
        }
    }

    return total;
}

const isLine = computed(
    () => props.variant === 'line' || props.variant === 'area',
);

/** Stacked series share one column, so it takes the whole group's width. */
const stackedBarWidth = computed(() => categoryWidth.value * 0.72);

const isFilled = computed(
    () => props.variant === 'area' || props.options.filled,
);

/**
 * Grouped bar geometry.
 */
const barGap = computed(() => {
    const available = categoryWidth.value * 0.72;

    return Math.min(4, available * 0.04);
});

const barWidth = computed(() => {
    const count = Math.max(props.series.length, 1);
    const available = categoryWidth.value * 0.72;

    const gaps = barGap.value * Math.max(count - 1, 0);

    return Math.max(2, (available - gaps) / count);
});

function barX(seriesIndex: number, pointIndex: number): number {
    const count = Math.max(props.series.length, 1);

    const totalWidth =
        barWidth.value * count + barGap.value * Math.max(count - 1, 0);

    const groupStart = PADDING.left + pointIndex * categoryWidth.value;

    return (
        groupStart +
        (categoryWidth.value - totalWidth) / 2 +
        seriesIndex * (barWidth.value + barGap.value)
    );
}

function barY(value: number): number {
    return Math.min(y(value), baselineY.value);
}

function barHeight(value: number): number {
    return Math.abs(baselineY.value - y(value));
}

function hitAreaX(index: number): number {
    return PADDING.left + index * categoryWidth.value;
}

function hasValue(item: ChartSeriesDefinition, index: number): boolean {
    const value = item.values[index];

    return typeof value === 'number' && Number.isFinite(value);
}

const activeLabel = computed(() => {
    if (activeIndex.value === null) {
        return '';
    }

    return props.labels[activeIndex.value] ?? `Data ${activeIndex.value + 1}`;
});

const activeItems = computed(() => {
    if (activeIndex.value === null) {
        return [];
    }

    return props.series
        .map((item) => ({
            ...item,
            value: item.values[activeIndex.value as number],
        }))
        .filter(
            (
                item,
            ): item is ChartSeriesDefinition & {
                value: number;
            } => typeof item.value === 'number' && Number.isFinite(item.value),
        );
});

const numberFormatter = new Intl.NumberFormat(undefined, {
    maximumFractionDigits: 2,
});

function formatValue(value: number): string {
    return (
        (props.options.prefix ?? '') +
        numberFormatter.format(value) +
        (props.options.suffix ?? '')
    );
}

/**
 * Tooltip follows the active category rather than the raw pointer position.
 *
 * This makes the interaction stable and prevents the tooltip from shaking
 * while the user moves across the same data point.
 */
const tooltipStyle = computed(() => {
    if (activeIndex.value === null) {
        return {};
    }

    const left = (categoryX(activeIndex.value) / WIDTH) * 100;

    let translateX = '-50%';

    if (activeIndex.value === 0) {
        translateX = '0';
    } else if (activeIndex.value === pointCount.value - 1) {
        translateX = '-100%';
    }

    return {
        left: `${left}%`,
        transform: `translateX(${translateX})`,
    };
});

function activate(index: number): void {
    activeIndex.value = index;
}

function deactivate(): void {
    activeIndex.value = null;
}
</script>

<template>
    <Card
        class="overflow-hidden rounded-lg border-border/60 bg-card py-0 shadow-xs"
    >
        <CardContent class="p-5">
            <!-- Legend -->
            <div
                v-if="series.length && options.legend"
                class="mb-5 flex flex-wrap items-center gap-x-5 gap-y-2"
            >
                <div
                    v-for="item in series"
                    :key="item.label"
                    class="flex items-center gap-2 text-xs font-medium text-muted-foreground"
                >
                    <span
                        class="size-2 rounded-full bg-current shadow-[0_0_0_2px_var(--card)] ring-1 ring-border/60"
                        :class="SERIES_CLASSES[item.color]"
                    />

                    <span>
                        {{ item.label }}
                    </span>
                </div>
            </div>

            <!-- Empty state -->
            <div
                v-if="isEmpty"
                class="flex min-h-48 items-center justify-center rounded-lg border border-dashed border-border/60 bg-muted/10"
            >
                <p class="text-sm text-muted-foreground">
                    No data for this period.
                </p>
            </div>

            <!-- Chart -->
            <div v-else class="relative isolate min-w-0">
                <!-- Floating tooltip -->
                <Transition
                    enter-active-class="transition duration-150 ease-out"
                    enter-from-class="scale-[0.98] opacity-0"
                    enter-to-class="scale-100 opacity-100"
                    leave-active-class="transition duration-100 ease-in"
                    leave-from-class="scale-100 opacity-100"
                    leave-to-class="scale-[0.98] opacity-0"
                >
                    <div
                        v-if="activeIndex !== null && activeItems.length"
                        class="pointer-events-none absolute top-2 z-30 w-52 overflow-hidden rounded-lg border border-border/70 bg-popover/95 text-popover-foreground shadow-lg shadow-black/5 backdrop-blur-md dark:shadow-black/30"
                        :style="tooltipStyle"
                    >
                        <!-- Tooltip heading -->
                        <div
                            class="border-b border-border/60 bg-muted/25 px-3 py-2.5"
                        >
                            <p
                                class="text-[11px] font-medium text-muted-foreground"
                            >
                                {{ activeLabel }}
                            </p>
                        </div>

                        <!-- Tooltip content -->
                        <div class="space-y-2.5 px-3 py-3">
                            <div
                                v-for="item in activeItems"
                                :key="item.label"
                                class="flex items-center justify-between gap-4"
                            >
                                <div class="flex min-w-0 items-center gap-2">
                                    <span
                                        class="size-2 shrink-0 rounded-full bg-current"
                                        :class="SERIES_CLASSES[item.color]"
                                    />

                                    <span
                                        class="truncate text-xs text-muted-foreground"
                                    >
                                        {{ item.label }}
                                    </span>
                                </div>

                                <span
                                    class="shrink-0 text-sm font-semibold tabular-nums"
                                >
                                    {{ formatValue(item.value) }}
                                </span>
                            </div>
                        </div>
                    </div>
                </Transition>

                <svg
                    :viewBox="`0 0 ${WIDTH} ${HEIGHT}`"
                    class="w-full overflow-visible select-none"
                    :style="{ height: `${maxHeight}px` }"
                    role="img"
                    :aria-label="`${variant} chart`"
                    preserveAspectRatio="none"
                    @pointerleave="deactivate"
                >
                    <!-- Horizontal grid -->
                    <g
                        v-if="options.grid"
                        aria-hidden="true"
                        class="text-border/50"
                    >
                        <line
                            v-for="gridY in gridLines"
                            :key="gridY"
                            :x1="PADDING.left"
                            :x2="WIDTH - PADDING.right"
                            :y1="gridY"
                            :y2="gridY"
                            stroke="currentColor"
                            stroke-width="1"
                            stroke-dasharray="3 5"
                            vector-effect="non-scaling-stroke"
                        />
                    </g>

                    <!-- Active vertical crosshair -->
                    <line
                        v-if="activeIndex !== null"
                        :x1="categoryX(activeIndex)"
                        :x2="categoryX(activeIndex)"
                        :y1="PADDING.top"
                        :y2="HEIGHT - PADDING.bottom"
                        stroke="currentColor"
                        stroke-width="1"
                        stroke-dasharray="3 4"
                        class="text-foreground/15"
                        vector-effect="non-scaling-stroke"
                    />

                    <!-- LINE and AREA -->
                    <template v-if="isLine">
                        <g v-if="isFilled">
                            <path
                                v-for="item in series"
                                :key="`area-${item.label}`"
                                :d="areaPath(item.values)"
                                fill="currentColor"
                                class="opacity-15"
                                :class="SERIES_CLASSES[item.color]"
                            />
                        </g>

                        <path
                            v-for="item in series"
                            :key="item.label"
                            :d="linePath(item.values)"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linejoin="round"
                            stroke-linecap="round"
                            :class="SERIES_CLASSES[item.color]"
                            vector-effect="non-scaling-stroke"
                        />

                        <!-- Active dots -->
                        <template v-if="activeIndex !== null">
                            <circle
                                v-for="item in series"
                                v-show="hasValue(item, activeIndex)"
                                :key="`active-${item.label}`"
                                :cx="categoryX(activeIndex)"
                                :cy="y(item.values[activeIndex])"
                                r="4"
                                fill="currentColor"
                                stroke="var(--background)"
                                stroke-width="2.5"
                                :class="SERIES_CLASSES[item.color]"
                                vector-effect="non-scaling-stroke"
                            />
                        </template>
                    </template>

                    <!-- BAR -->
                    <template v-else>
                        <template
                            v-for="(item, seriesIndex) in series"
                            :key="item.label"
                        >
                            <rect
                                v-for="(value, pointIndex) in item.values"
                                :key="`${item.label}-${pointIndex}`"
                                :x="
                                    options.stacked
                                        ? barX(0, pointIndex)
                                        : barX(seriesIndex, pointIndex)
                                "
                                :y="
                                    options.stacked
                                        ? y(
                                              stackBase(
                                                  seriesIndex,
                                                  pointIndex,
                                              ) + value,
                                          )
                                        : barY(value)
                                "
                                :width="
                                    options.stacked ? stackedBarWidth : barWidth
                                "
                                :height="barHeight(value)"
                                rx="3"
                                fill="currentColor"
                                :class="[
                                    SERIES_CLASSES[item.color],
                                    activeIndex !== null &&
                                    activeIndex !== pointIndex
                                        ? 'opacity-35'
                                        : 'opacity-90',
                                ]"
                                class="transition-opacity duration-150"
                            />
                        </template>
                    </template>

                    <!--
                        Invisible category hit areas.

                        These make interaction much easier than requiring
                        users to hit a 2px line or narrow bar exactly.
                    -->
                    <rect
                        v-for="(_, index) in pointCount"
                        :key="`hit-area-${index}`"
                        :x="hitAreaX(index - 1)"
                        :y="0"
                        :width="categoryWidth"
                        :height="HEIGHT"
                        fill="transparent"
                        tabindex="0"
                        role="button"
                        :aria-label="labels[index - 1] ?? `Data ${index}`"
                        @pointerenter="activate(index - 1)"
                        @focus="activate(index - 1)"
                        @blur="deactivate"
                    />
                </svg>

                <!-- X labels -->
                <div
                    class="mt-2 grid text-[11px] text-muted-foreground/70"
                    :style="{
                        gridTemplateColumns: `repeat(${pointCount}, minmax(0, 1fr))`,
                    }"
                >
                    <span
                        v-for="(_, index) in pointCount"
                        :key="`label-${index}`"
                        class="truncate px-1 text-center"
                    >
                        {{ labels[index - 1] ?? '' }}
                    </span>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
