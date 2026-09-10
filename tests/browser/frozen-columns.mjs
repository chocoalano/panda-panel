#!/usr/bin/env node

/**
 * Frozen columns, in a browser, on a phone.
 *
 * The package has no browser test runner and ADR 001 says so on purpose —
 * this is not one. It is a script, and since V1 it shares its driver with
 * `verify.mjs` (see `chrome.mjs`): it builds the fixture in
 * `frontend/browser`, serves it, drives a Chrome that is already on the
 * machine over the DevTools protocol, and asks the layout engine the four
 * questions nothing else can answer.
 *
 *   1. does the table actually overflow at 360px,
 *   2. do the pinned headers stay `position: sticky` there,
 *   3. do they hold their place while the lane scrolls under them,
 *   4. is a pinned cell opaque, and does the last one carry its seam.
 *
 * Every one of those is a width the browser decided. A unit test can assert
 * the arithmetic over those widths — `useFrozenColumns.test.ts` does — and
 * can assert nothing about whether the browser agrees.
 *
 *     node tests/browser/frozen-columns.mjs
 *
 * No dependency, and none to install: Node's own WebSocket client speaks
 * CDP, and Chrome is the one already installed. `PANDA_CHROME` names a
 * different binary.
 */

import { buildFixture, reporter, withPage } from './chrome.mjs';

const VIEWPORT = { width: 360, height: 800 };

/**
 * What the page is asked, in the page.
 *
 * Nothing here knows a column name: it reads the pinned cells out of the
 * markup by the classes the renderer writes, so the same script covers any
 * table the package draws.
 */
const PROBE = `(async () => {
    const lane = document.querySelector('[data-slot="table-container"]');
    const headerRow = document.querySelector('thead tr');
    const heads = [...headerRow.querySelectorAll('th')];

    const pinnedStart = heads.filter(
        (head) => getComputedStyle(head).position === 'sticky'
            && head.style.left !== ''
    );
    const pinnedEnd = heads.filter(
        (head) => getComputedStyle(head).position === 'sticky'
            && head.style.right !== ''
    );
    const scrolling = heads.filter((head) => !pinnedStart.includes(head) && !pinnedEnd.includes(head));

    const frame = () => new Promise((done) => requestAnimationFrame(() => requestAnimationFrame(done)));

    const before = heads.map((head) => head.getBoundingClientRect().x);

    lane.scrollLeft = 240;
    await frame();

    const after = heads.map((head) => head.getBoundingClientRect().x);

    const bodyCell = document.querySelector('tbody tr td.panel-table-frozen-cell');
    const bodyRow = bodyCell?.closest('tr');
    const lastStart = pinnedStart[pinnedStart.length - 1];
    const edge = lastStart ? getComputedStyle(lastStart, '::after') : null;

    return {
        viewport: { width: window.innerWidth, height: window.innerHeight },
        overflows: lane.scrollWidth > lane.clientWidth,
        scrollWidth: lane.scrollWidth,
        scrolled: lane.scrollLeft,
        laneWidth: lane.clientWidth,
        pinnedStartCount: pinnedStart.length,
        pinnedEndCount: pinnedEnd.length,
        startPositions: pinnedStart.map((head) => getComputedStyle(head).position),
        startLabels: pinnedStart.map((head) => head.textContent.trim()),
        startOffsets: pinnedStart.map((head) => head.style.left),
        startHeld: pinnedStart.map((head) => {
            const index = heads.indexOf(head);
            return Math.abs(after[index] - before[index]) < 0.5;
        }),
        scrollingMoved: scrolling.some((head) => {
            const index = heads.indexOf(head);
            return Math.abs(after[index] - before[index]) > 1;
        }),
        headerBackground: pinnedStart.map((head) => getComputedStyle(head).backgroundColor),
        rowBackground: bodyRow ? getComputedStyle(bodyRow).backgroundColor : null,
        cellBackground: bodyCell ? getComputedStyle(bodyCell).backgroundColor : null,
        cellPlate: bodyCell ? getComputedStyle(bodyCell, '::before').backgroundColor : null,
        edgeContent: edge ? edge.content : null,
        edgeWidth: edge ? edge.width : null,
        edgeBorder: edge ? edge.borderLeftWidth : null,
        warnings: window.__vueWarnings ?? [],
    };
})()`;

const report = reporter(
    `Frozen columns at ${VIEWPORT.width}×${VIEWPORT.height}`,
);

const check = (name, passed, detail) => report.check(name, passed, detail);

async function main() {
    await buildFixture({ force: process.argv.includes('--build') });

    const page = await withPage(
        async (browser) => {
            await browser.go('frozen-columns');

            return browser.evaluate(PROBE);
        },
        { viewport: { ...VIEWPORT, mobile: true } },
    );

    if (process.argv.includes('--json')) {
        console.log(JSON.stringify(page, null, 2));
    }

    check(
        `renders at ${VIEWPORT.width}px`,
        page.viewport.width === VIEWPORT.width,
        `viewport ${page.viewport.width}px`,
    );
    check(
        'the table overflows its lane',
        page.overflows === true,
        `scrollWidth ${page.scrollWidth}px > clientWidth ${page.laneWidth}px`,
    );
    check(
        'two leading columns are pinned',
        page.pinnedStartCount >= 2,
        `${page.pinnedStartCount} pinned: ${page.startLabels.join(', ')}`,
    );
    check(
        'both are position: sticky',
        page.startPositions.slice(0, 2).every((value) => value === 'sticky'),
        page.startPositions.join(', '),
    );
    check(
        'the first sits at the edge and the second behind it',
        page.startOffsets[0] === '0px' && parseFloat(page.startOffsets[1]) > 0,
        page.startOffsets.join(', '),
    );
    check(
        'the lane scrolled under them',
        page.scrolled > 0 && page.scrollingMoved === true,
        `scrollLeft ${page.scrolled}`,
    );
    check(
        'neither pinned header moved',
        page.startHeld.slice(0, 2).every(Boolean),
        page.startHeld.join(', '),
    );
    check(
        'the trailing side is pinned independently',
        page.pinnedEndCount >= 1,
        `${page.pinnedEndCount} pinned to the end`,
    );
    check(
        'a pinned header is opaque',
        page.headerBackground.every((colour) => colour !== 'rgba(0, 0, 0, 0)'),
        page.headerBackground.join(', '),
    );
    check(
        'a pinned body cell inherits an opaque row background',
        page.cellBackground !== 'rgba(0, 0, 0, 0)' &&
            page.rowBackground !== 'rgba(0, 0, 0, 0)',
        `row ${page.rowBackground}, cell ${page.cellBackground}`,
    );
    check(
        'and is backed by an opaque plate',
        page.cellPlate !== 'rgba(0, 0, 0, 0)',
        String(page.cellPlate),
    );
    check(
        'the last pinned column carries the seam',
        page.edgeContent === '""' &&
            parseFloat(page.edgeWidth) > 0 &&
            parseFloat(page.edgeBorder) > 0,
        `content ${page.edgeContent}, width ${page.edgeWidth}, border ${page.edgeBorder}`,
    );
    check(
        'no recursive update warning',
        page.warnings.every(
            (warning) => !warning.includes('recursive updates'),
        ),
        page.warnings.length === 0 ? 'no warnings' : page.warnings.join(' | '),
    );
    report.finish();
}

main().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
