#!/usr/bin/env node

/**
 * R1 acceptance — stacked columns stay inside the plot they are drawn in.
 *
 * The defect is geometric: a stacked column is drawn at a running total, and
 * the axis was scaled to the individual values that total is made of. Vitest
 * proves the arithmetic deterministically; this file proves that what a
 * browser lays out and paints is inside the chart, which is the claim the
 * defect actually falsifies.
 *
 * Measured in SVG user units read back off the rendered elements rather than
 * in CSS pixels: the chart scales with its container, and the plot box is
 * defined in the viewBox the component draws in. Reading both from the same
 * coordinate system is what makes the comparison meaningful.
 *
 *     node tests/browser/stacked.mjs [--build] [--json]
 */

import { buildFixture, reporter, withPage } from './chrome.mjs';

const report = reporter('R1 — stacked chart domain');
const json = {};

/**
 * The bar rectangles of one chart, in the SVG's own coordinate system.
 *
 * The bars carry `rx="3"`; the invisible category hit areas do not, which is
 * what separates them without depending on paint order or on a class name.
 */
const BARS = (container) => `(() => {
    const root = document.querySelector(${JSON.stringify(container)});

    if (root === null) {
        return null;
    }

    const svg = root.querySelector('svg');

    if (svg === null) {
        return null;
    }

    const box = svg.getAttribute('viewBox').split(/\\s+/).map(Number);
    const bars = [...root.querySelectorAll('rect[rx="3"]')].map((rect) => {
        const y = Number(rect.getAttribute('y'));
        const height = Number(rect.getAttribute('height'));

        return {
            x: Number(rect.getAttribute('x')),
            y,
            width: Number(rect.getAttribute('width')),
            height,
            bottom: y + height,
            /** What the engine actually laid out, not what the attribute says. */
            painted: rect.getBBox().height,
        };
    });

    return {
        viewBox: { x: box[0], y: box[1], width: box[2], height: box[3] },
        bars,
    };
})()`;

/**
 * The plot box, from the component's own constants.
 *
 * PADDING.top = 16 and PADDING.bottom = 12 against a 220-unit viewBox, so a
 * rectangle inside the plot has 16 <= y and y + height <= 208.
 */
const PLOT = { top: 16, bottom: 208 };

/** A hair of tolerance for the engine's own rounding. */
const EPSILON = 0.01;

function inside(bars) {
    return bars.every(
        (bar) =>
            Number.isFinite(bar.y) &&
            Number.isFinite(bar.height) &&
            bar.y >= PLOT.top - EPSILON &&
            bar.bottom <= PLOT.bottom + EPSILON,
    );
}

function describe(bars) {
    return bars
        .map(
            (bar) =>
                `y ${bar.y.toFixed(2)}..${bar.bottom.toFixed(2)} ` +
                `(h ${bar.height.toFixed(2)})`,
        )
        .join(', ');
}

await buildFixture({ force: process.argv.includes('--build') });

await withPage(async (page) => {
    await page.go('stacked-chart');

    for (const [name, container] of [
        ['positive', '#chart-positive'],
        ['multiple categories', '#chart-categories'],
        ['negative', '#chart-negative'],
        ['mixed sign', '#chart-mixed'],
        ['grouped', '#chart-grouped'],
    ]) {
        const measured = await page.evaluate(BARS(container));

        if (measured === null) {
            report.check(`${name} — chart rendered`, false, 'not found');

            continue;
        }

        json[name] = measured;

        const { bars } = measured;

        report.check(
            `${name} — every bar inside the plot`,
            bars.length > 0 && inside(bars),
            `plot y ${PLOT.top}..${PLOT.bottom}; bars ${describe(bars)}`,
        );

        report.check(
            `${name} — no NaN or Infinity in the geometry`,
            bars.length > 0 &&
                bars.every(
                    (bar) =>
                        Number.isFinite(bar.x) &&
                        Number.isFinite(bar.width) &&
                        Number.isFinite(bar.y) &&
                        Number.isFinite(bar.height),
                ),
            `${bars.length} bars`,
        );

        report.check(
            `${name} — the engine painted every bar`,
            bars.length > 0 &&
                bars.every(
                    (bar) => Math.abs(bar.painted - bar.height) < 0.5,
                ),
            bars
                .map((bar) => `${bar.painted.toFixed(2)}`)
                .join(', ') + ' painted',
        );
    }

    // Stacking is what makes a column a column: the segments share an x and
    // take the whole group's width, where grouped bars sit side by side.
    const stacked = json.positive?.bars ?? [];
    const grouped = json.grouped?.bars ?? [];

    report.check(
        'stacking still shares one column',
        stacked.length === 2 &&
            Math.abs(stacked[0].x - stacked[1].x) < EPSILON &&
            Math.abs(stacked[0].width - stacked[1].width) < EPSILON,
        stacked.map((bar) => `x ${bar.x.toFixed(2)} w ${bar.width.toFixed(2)}`).join(' | '),
    );

    report.check(
        'grouped bars still sit side by side',
        grouped.length === 2 && Math.abs(grouped[0].x - grouped[1].x) > 1,
        grouped.map((bar) => `x ${bar.x.toFixed(2)} w ${bar.width.toFixed(2)}`).join(' | '),
    );

    // The segments of a stack meet: each begins exactly where the one before
    // it ended. A gap here is the negative-placement defect.
    const negative = json.negative?.bars ?? [];

    report.check(
        'negative segments meet end to end',
        negative.length === 2 &&
            Math.abs(negative[1].y - negative[0].bottom) < EPSILON,
        negative.length === 2
            ? `first ends ${negative[0].bottom.toFixed(2)}, ` +
                  `second starts ${negative[1].y.toFixed(2)}`
            : 'not measured',
    );

    const positive = json.positive?.bars ?? [];

    report.check(
        'positive segments meet end to end',
        positive.length === 2 &&
            Math.abs(positive[0].y - positive[1].bottom) < EPSILON,
        positive.length === 2
            ? `upper ends ${positive[1].bottom.toFixed(2)}, ` +
                  `lower starts ${positive[0].y.toFixed(2)}`
            : 'not measured',
    );

    const errors = await page.evaluate('window.__errors ?? []');
    const warnings = await page.evaluate('window.__vueWarnings ?? []');

    report.check(
        'no Vue error or warning while rendering',
        errors.length === 0 && warnings.length === 0,
        `${errors.length} errors, ${warnings.length} warnings`,
    );
});

if (process.argv.includes('--json')) {
    console.log(JSON.stringify(json, null, 2));
}

report.finish();
