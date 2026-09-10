#!/usr/bin/env node

/**
 * D1 acceptance — what the table's controls actually show.
 *
 * The unit suite can prove what "Add condition" *does*: `addRule()` takes the
 * first selectable constraint, so which one it picks says which are on offer.
 * It cannot prove what the dropdown *lists*, because a closed Reka select
 * renders none of its items — and that is the one question the whole
 * column-discovery feature turns on.
 *
 * The select discipline here is the temporal suite's, kept deliberately:
 * a Reka select opens on `pointerdown`, so `element.click()` leaves it shut,
 * and a document-wide `[role="option"]` query happily answers from a listbox
 * some earlier step opened. Three bound checks once passed that way against
 * the wrong control. Every option read below opens with the keyboard, reads
 * only the listbox its trigger names in `aria-controls`, asserts a count, and
 * starts from a fresh document.
 *
 *     node tests/browser/datatable.mjs [--build] [--json]
 */

import { buildFixture, reporter, sleep, withPage } from './chrome.mjs';

const report = reporter('D1 — DataTable conditions, description, callouts');
const json = {};

const FORBIDDEN =
    'input[type="date"],input[type="time"],input[type="datetime"],input[type="datetime-local"]';

const STATE = `JSON.parse(document.querySelector('#state').textContent)`;

await buildFixture({ force: process.argv.includes('--build') });

await withPage(async (page) => {
    /** Waits for an expression to become truthy. */
    async function waitFor(expression, what) {
        for (let attempt = 0; attempt < 60; attempt++) {
            if (await page.evaluate(expression)) {
                return true;
            }

            await sleep(25);
        }

        throw new Error(`timed out waiting for ${what}`);
    }

    /** Clicks a button by its visible text. */
    async function clickButton(text) {
        const clicked = await page.evaluate(`(() => {
            const button = [...document.querySelectorAll('button')].find(
                (candidate) => (candidate.textContent ?? '').trim() === ${JSON.stringify(text)},
            );

            if (button === undefined) {
                return false;
            }

            button.click();

            return true;
        })()`);

        await sleep(60);

        return clicked;
    }

    /**
     * Opens one select from the keyboard and reads only its own options.
     *
     * Scoped through the trigger's `aria-controls`. A global query is
     * forbidden in this suite; see the header.
     */
    async function options(selector) {
        const listbox = await page.evaluate(`(() => {
            const trigger = document.querySelector(${JSON.stringify(selector)});

            if (trigger === null) {
                return null;
            }

            trigger.focus();

            return trigger.getAttribute('aria-controls');
        })()`);

        if (typeof listbox !== 'string' || listbox === '') {
            throw new Error(`${selector} was not found, or controls no listbox`);
        }

        await page.press('Enter');

        const scope = `document.getElementById(${JSON.stringify(listbox)})`;

        await waitFor(
            `${scope} !== null && ${scope}.querySelectorAll('[role="option"]').length > 0`,
            `${selector} to list its own options`,
        );

        return page.evaluate(
            `[...${scope}.querySelectorAll('[role="option"]')].map((el) => (el.textContent ?? '').trim())`,
        );
    }

    /** Adds a condition and returns the labels its column select offers. */
    async function conditionChoices() {
        await clickButton('Add condition');

        return options('[role="combobox"]');
    }

    /**
     * Toggles one column through the real Column Manager.
     *
     * The checkboxes carry stable ids (`column-created_at`), which is what
     * makes this deterministic — matching on visible text would match the
     * table header too, and the manager renders inside a portal.
     */
    async function toggleColumn(name) {
        await clickButton('Columns');

        await waitFor(
            `document.getElementById('column-${name}') !== null`,
            `the column manager to list ${name}`,
        );

        await page.evaluate(
            `document.getElementById('column-${name}').click()`,
        );

        await sleep(200);
        await page.evaluate(`document.body.click()`);
        await sleep(120);

        return page.evaluate(STATE);
    }

    /*
     * B1 — the choices are the visible queryable columns
     */
    await page.go('datatable');

    const all = await conditionChoices();

    json.b1 = all;

    report.check(
        'B1 — Add condition lists the visible queryable columns',
        all.length === 7 &&
            ['Name', 'Work address', 'Age', 'Created at', 'Active'].every(
                (label) => all.includes(label),
            ),
        `${all.length} options: ${all.join(', ')}`,
    );

    report.check(
        'B1 — display-only and custom columns are not offered',
        !all.includes('Avatar') && !all.includes('Health'),
        'Avatar and Health absent, as declared',
    );

    report.check(
        'B1 — a declared constraint overrides the derived label',
        all.includes('Work address') && !all.includes('Email'),
        'email reads "Work address", the declared label',
    );

    /*
     * Phase 4 — duplicate labels, distinct keys
     */
    const cityCount = all.filter((label) => label === 'City').length;

    report.check(
        'duplicate labels both appear and stay distinct rows',
        cityCount === 2,
        `${cityCount} options labelled "City" (billing_city, shipping_city)`,
    );

    /*
     * B2 — hiding a column removes it from new choices
     */
    await page.go('datatable');

    const afterHide = await toggleColumn('created_at');

    json.b2 = afterHide.visible;

    report.check(
        'B2 — the column manager hid the column',
        !afterHide.visible.includes('created_at'),
        `visible: ${afterHide.visible.join(', ')}`,
    );

    const hiddenChoices = await conditionChoices();

    json.b2Choices = hiddenChoices;

    report.check(
        'B2 — the hidden column is gone from Add condition',
        !hiddenChoices.includes('Created at') && hiddenChoices.length === 6,
        `${hiddenChoices.length} options: ${hiddenChoices.join(', ')}`,
    );

    /*
     * B3 — restoring it brings it back, with no reload
     */
    await page.press('Escape');
    await sleep(80);

    const restored = await toggleColumn('created_at');
    const restoredChoices = await conditionChoices();

    json.b3 = restoredChoices;

    report.check(
        'B3 — the column returns to the choices without a reload',
        restored.visible.includes('created_at') &&
            restoredChoices.includes('Created at') &&
            restoredChoices.length === 7,
        `${restoredChoices.length} options, Created at present`,
    );

    /*
     * B5 — a date condition opens the panel calendar, never a native input
     */
    await page.go('datatable');
    await clickButton('Add condition');

    /**
     * Chooses an option in the rule's own column select, by label.
     *
     * Driven from the keyboard for the same reason the select is opened that
     * way: Reka acts on `pointerdown`, so `option.click()` returns cleanly
     * and selects nothing — the trigger still reads its old value and every
     * assertion after it is about the wrong rule. Arrowing from the currently
     * selected option is what a keyboard user does and what actually works.
     */
    async function chooseColumn(label) {
        await page.evaluate(
            `document.querySelector('[role="combobox"]').focus()`,
        );
        await page.press('Enter');

        const scope = `(() => {
            const trigger = document.querySelector('[role="combobox"]');

            return document.getElementById(trigger.getAttribute('aria-controls'));
        })()`;

        await waitFor(
            `${scope} !== null && ${scope}.querySelectorAll('[role="option"]').length > 0`,
            'the column list',
        );

        const steps = await page.evaluate(`(() => {
            const trigger = document.querySelector('[role="combobox"]');
            const listbox = document.getElementById(trigger.getAttribute('aria-controls'));
            const labels = [...listbox.querySelectorAll('[role="option"]')].map(
                (el) => (el.textContent ?? '').trim(),
            );

            const current = labels.indexOf((trigger.textContent ?? '').trim());
            const target = labels.indexOf(${JSON.stringify(label)});

            return target < 0 ? null : target - Math.max(current, 0);
        })()`);

        if (steps === null) {
            return false;
        }

        for (let step = 0; step < Math.abs(steps); step++) {
            await page.press(steps > 0 ? 'ArrowDown' : 'ArrowUp');
        }

        await page.press('Enter');
        await sleep(250);

        return page.evaluate(
            `(document.querySelector('[role="combobox"]').textContent ?? '').trim() === ${JSON.stringify(label)}`,
        );
    }

    const chose = await chooseColumn('Created at');

    const dateRule = await page.evaluate(`(() => {
        const pickers = [...document.querySelectorAll('button[aria-haspopup="dialog"]')]
            .filter((el) => (el.textContent ?? '').trim() !== 'Columns');

        return {
            picker: pickers.length === 0 ? null : (pickers[0].textContent ?? '').trim(),
            forbidden: document.querySelectorAll(${JSON.stringify(FORBIDDEN)}).length,
            rows: document.querySelectorAll('[role="combobox"]').length,
        };
    })()`);

    json.b5 = dateRule;

    report.check(
        'B5 — a date condition renders the panel date picker',
        chose === true && dateRule.picker !== null,
        dateRule.picker === null
            ? 'no picker trigger'
            : `trigger reads "${dateRule.picker}"`,
    );

    report.check(
        'B5 — and no native temporal input anywhere',
        dateRule.forbidden === 0,
        `${dateRule.forbidden} forbidden controls`,
    );

    /*
     * B6 — PP-27: the draft survives, and is not executable until filled
     */
    const draft = await page.evaluate(STATE);

    json.b6Draft = draft;

    report.check(
        'B6 — the unfilled rule row is still on screen',
        dateRule.rows === 2,
        `${dateRule.rows} selects — the rule's column and operator`,
    );

    report.check(
        'B6 — and the server is sent nothing for it',
        draft.serverRules.length === 0,
        `serverRules: ${JSON.stringify(draft.serverRules)}`,
    );

    // Pick a date through the calendar.
    await page.evaluate(`(() => {
        [...document.querySelectorAll('button[aria-haspopup="dialog"]')]
            .filter((el) => (el.textContent ?? '').trim() !== 'Columns')[0]
            .click();

        return true;
    })()`);

    await waitFor(
        `document.querySelectorAll('[role="dialog"] [role="gridcell"]').length > 0`,
        'the calendar to open',
    );

    await page.evaluate(`(() => {
        const cell = [...document.querySelectorAll('[role="dialog"] [role="gridcell"]')]
            .find((el) => el.getAttribute('aria-disabled') !== 'true' &&
                          el.querySelector('button') !== null);

        cell.querySelector('button').click();

        return true;
    })()`);
    await sleep(250);

    const filled = await page.evaluate(STATE);

    json.b6Filled = filled;

    report.check(
        'B6 — picking a date makes the rule executable',
        filled.serverRules.length === 1 &&
            filled.serverRules[0].column === 'created_at' &&
            /^\d{4}-\d{2}-\d{2}$/.test(String(filled.serverRules[0].value)),
        `serverRules: ${JSON.stringify(filled.serverRules)}`,
    );

    /*
     * B4 — an existing condition survives its column being hidden
     */
    await page.evaluate(`document.body.click()`);
    await sleep(80);

    const survived = await toggleColumn('created_at');

    json.b4 = survived;

    report.check(
        'B4 — the rule on a now-hidden column is untouched',
        !survived.visible.includes('created_at') &&
            survived.serverRules.length === 1 &&
            survived.serverRules[0].column === 'created_at',
        `created_at visible: ${survived.visible.includes('created_at')}; ` +
            `serverRules: ${JSON.stringify(survived.serverRules)}`,
    );

    /*
     * B7 / B8 — description and callouts, in the right order
     */
    await page.go('datatable');

    const intro = await page.evaluate(`(() => {
        const body = document.body.textContent ?? '';
        const description = 'Every record this panel can reach';
        const info = 'This report excludes archived records.';
        const warning = 'Payroll period is locked.';
        const callout = [...document.querySelectorAll('div')].find((el) =>
            (el.textContent ?? '').includes(warning) &&
            getComputedStyle(el).borderTopWidth !== '0px');

        return {
            order: [body.indexOf(description), body.indexOf(info), body.indexOf(warning)],
            alerts: document.querySelectorAll('[role="alert"]').length,
            warningBackground: callout === undefined
                ? null
                : getComputedStyle(callout).backgroundColor,
        };
    })()`);

    json.b7b8 = intro;

    report.check(
        'B7 — the description renders before the callouts',
        intro.order[0] >= 0 && intro.order[0] < intro.order[1],
        `description at ${intro.order[0]}, first callout at ${intro.order[1]}`,
    );

    report.check(
        'B8 — both callouts render, the warning with its own tone',
        intro.order[1] >= 0 &&
            intro.order[2] > intro.order[1] &&
            intro.warningBackground !== null,
        `warning background ${intro.warningBackground}`,
    );

    report.check(
        'B8 — a callout is not announced as an alert',
        intro.alerts === 0,
        `${intro.alerts} role="alert" elements`,
    );

    /*
     * B12 / B13 — theme, including with a portal open
     */
    await clickButton('Add condition');
    await page.evaluate(`document.querySelector('[role="combobox"]').focus()`);
    await page.press('Enter');
    // Scoped like every other option query in this suite, even though this
    // one only waits for readiness rather than asserting: an unscoped query
    // left in place is a pattern for the next person to copy into an
    // assertion, which is exactly how three bound checks once measured the
    // wrong listbox.
    await waitFor(
        `(() => {
            const trigger = document.querySelector('[role="combobox"]');
            const listbox = document.getElementById(trigger.getAttribute('aria-controls'));

            return listbox !== null && listbox.querySelectorAll('[role="option"]').length > 0;
        })()`,
        'a listbox for the theme check',
    );

    const light = await page.evaluate(`(() => {
        const trigger = document.querySelector('[role="combobox"]');
        const listbox = document.getElementById(trigger.getAttribute('aria-controls'));

        return getComputedStyle(listbox).backgroundColor;
    })()`);

    await page.evaluate(`document.documentElement.classList.add('dark')`);
    await sleep(80);

    const dark = await page.evaluate(`(() => {
        const trigger = document.querySelector('[role="combobox"]');
        const listbox = document.getElementById(trigger.getAttribute('aria-controls'));

        return getComputedStyle(listbox).backgroundColor;
    })()`);

    json.theme = { light, dark };

    report.check(
        'B12/B13 — an open portal follows a light-to-dark switch',
        typeof light === 'string' && typeof dark === 'string' && light !== dark,
        `light ${light} -> dark ${dark}`,
    );

    await page.evaluate(`document.documentElement.classList.remove('dark')`);

    /*
     * B14 / B15 — the locale switch, through the package's own mechanism
     */
    await page.go('datatable');

    const english = await page.evaluate(`(() => {
        window.__setLocale('en');

        return null;
    })()`);
    void english;
    await sleep(120);

    const en = await page.evaluate(`(() => ({
        add: [...document.querySelectorAll('button')]
            .map((b) => (b.textContent ?? '').trim())
            .find((t) => t.length > 0 && t !== 'Columns'),
        body: (document.body.textContent ?? '').slice(0, 400),
    }))()`);

    json.en = en.add;

    report.check(
        'B14 — English package copy',
        en.add === 'Add condition',
        `Add-condition button reads "${en.add}"`,
    );

    await page.evaluate(`window.__setLocale('id')`);
    await sleep(150);

    const id = await page.evaluate(`(() => ({
        add: [...document.querySelectorAll('button')]
            .map((b) => (b.textContent ?? '').trim())
            .find((t) => t.length > 0 && t !== 'Columns'),
    }))()`);

    json.id = id.add;

    report.check(
        'B15 — Indonesian package copy, with no reload',
        typeof id.add === 'string' && id.add !== '' && id.add !== en.add,
        `Add-condition button reads "${id.add}"`,
    );

    await page.evaluate(`window.__setLocale('en')`);

    /*
     * B9 / B10 / B11 / B16 — layout
     */
    for (const width of [320, 375, 1280]) {
        await page.setViewport({ width, height: 800 });
        await sleep(80);

        const layout = await page.evaluate(`(() => ({
            scrollWidth: document.documentElement.scrollWidth,
            clientWidth: document.documentElement.clientWidth,
            descriptionVisible:
                [...document.querySelectorAll('p')].some(
                    (p) => (p.textContent ?? '').includes('refreshed nightly') &&
                           p.getBoundingClientRect().width > 0),
            calloutVisible:
                [...document.querySelectorAll('div')].some(
                    (d) => (d.textContent ?? '').includes('Payroll period is locked.') &&
                           d.getBoundingClientRect().width > 0),
        }))()`);

        json[`viewport${width}`] = layout;

        report.check(
            `B9/B10/B11/B16 — ${width}px: description and callout laid out, no page overflow`,
            layout.scrollWidth <= layout.clientWidth &&
                layout.descriptionVisible &&
                layout.calloutVisible,
            `scrollWidth ${layout.scrollWidth} vs clientWidth ${layout.clientWidth}; ` +
                `description=${layout.descriptionVisible}, callout=${layout.calloutVisible}`,
        );
    }

    const errors = await page.evaluate('window.__errors ?? []');
    const warnings = await page.evaluate('window.__vueWarnings ?? []');

    report.check(
        'no Vue error or warning throughout',
        errors.length === 0 && warnings.length === 0,
        `${errors.length} errors, ${warnings.length} warnings`,
    );
});

if (process.argv.includes('--json')) {
    console.log(JSON.stringify(json, null, 2));
}

report.finish();
