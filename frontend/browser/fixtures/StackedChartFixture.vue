<script setup lang="ts">
import ChartWidget from '@/panel/widgets/ChartWidget.vue';
import type { ChartOptions, ChartSeriesDefinition } from '@/panel/types/widget';

/**
 * Stacked bars, laid out by a real engine.
 *
 * A stacked column is drawn at a running total, and the axis was scaled to the
 * individual values that total is made of — so a column taller than its
 * tallest segment was placed outside the plot. In Vitest that is a negative
 * `y` attribute; here it is a rectangle a browser actually paints above the
 * chart, which is the thing the defect *is*.
 *
 * Four charts rather than one: a positive stack, a negative stack, a mixed
 * one, and the grouped chart that must not move. They are laid out apart so
 * one chart's overflow can never be read as another's.
 *
 * Every one of these mounts the real published `ChartWidget` against the real
 * stylesheet. Nothing here restyles or reimplements anything.
 */
const OPTIONS: ChartOptions = {
    legend: false,
    grid: true,
    stacked: true,
    filled: false,
    curved: false,
    labels: false,
    min: null,
    max: null,
    prefix: null,
    suffix: null,
};

function series(
    label: string,
    values: Array<number | null>,
): ChartSeriesDefinition {
    return { label, color: 'default', values } as ChartSeriesDefinition;
}

/** S1 — 60 + 120 = 180 against a domain that used to stop at 120. */
const positive = [series('A', [60]), series('B', [120])];

/** S2 — Jan totals 180, Feb totals 100. */
const categories = [series('A', [60, 80]), series('B', [120, 20])];

/** S3 — the same failure from the other end: −50 + −80 = −130. */
const negative = [series('A', [-50]), series('B', [-80])];

/** S4 — one signed running total: 100, then 60, then 80. */
const mixed = [series('A', [100]), series('B', [-40]), series('C', [20])];
</script>

<template>
    <div class="space-y-12 p-6">
        <div id="chart-positive" data-chart="positive">
            <ChartWidget
                variant="bar"
                :labels="['Jan']"
                :series="positive"
                :options="OPTIONS"
            />
        </div>

        <div id="chart-categories" data-chart="categories">
            <ChartWidget
                variant="bar"
                :labels="['Jan', 'Feb']"
                :series="categories"
                :options="OPTIONS"
            />
        </div>

        <div id="chart-negative" data-chart="negative">
            <ChartWidget
                variant="bar"
                :labels="['Jan']"
                :series="negative"
                :options="OPTIONS"
            />
        </div>

        <div id="chart-mixed" data-chart="mixed">
            <ChartWidget
                variant="bar"
                :labels="['Jan']"
                :series="mixed"
                :options="OPTIONS"
            />
        </div>

        <div id="chart-grouped" data-chart="grouped">
            <ChartWidget
                variant="bar"
                :labels="['Jan']"
                :series="positive"
                :options="{ ...OPTIONS, stacked: false }"
            />
        </div>
    </div>
</template>
