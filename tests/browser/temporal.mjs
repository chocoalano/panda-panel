#!/usr/bin/env node

/**
 * DT01-DT05 acceptance — every date and time is picked with a panel control.
 *
 * Three renderers created a browser-native temporal input, and the third had
 * no literal attribute to search for: the query builder bound
 * `:type="inputTypeFor(rule)"`, which resolved to `date` because a date
 * constraint declares that as its *semantic* type. A grep for `type="date"`
 * came back clean while a native date picker was on screen.
 *
 * That is exactly why the guard has to be run in a browser as well as over
 * the source. A unit test renders `<input type="time">` as an element with an
 * attribute; only an engine draws the control a person actually meets, opens
 * the popover, resolves the theme through a portal, and decides what fits
 * across 320 pixels.
 *
 *     node tests/browser/temporal.mjs [--build] [--json]
 */

import { buildFixture, reporter, sleep, withPage } from './chrome.mjs';

const report = reporter('DT01-DT05 — temporal controls');
const json = {};

/** The controls this remediation exists to make impossible. */
const FORBIDDEN =
    'input[type="date"],input[type="time"],input[type="datetime"],input[type="datetime-local"]';

const COUNT_FORBIDDEN = `(() => {
    const found = [...document.querySelectorAll(${JSON.stringify(FORBIDDEN)})];

    return {
        total: found.length,
        types: found.map((el) => el.getAttribute('type')),
    };
})()`;

/** The fixture's own view of what each field currently holds. */
const STATE = `JSON.parse(document.querySelector('#state').textContent)`;

/** Reads the visible text and attributes of one control. */
const READ = (selector) => `(() => {
    const el = document.querySelector(${JSON.stringify(selector)});

    if (el === null) {
        return null;
    }

    const rect = el.getBoundingClientRect();

    return {
        text: (el.textContent ?? '').trim(),
        describedBy: el.getAttribute('aria-describedby'),
        invalid: el.getAttribute('aria-invalid'),
        id: el.id === '' ? null : el.id,
        width: Math.round(rect.width * 100) / 100,
        height: Math.round(rect.height * 100) / 100,
    };
})()`;

await buildFixture({ force: process.argv.includes('--build') });

await withPage(async (page) => {
    await page.go('temporal');

    /*
     * B1 — the policy itself
     */
    const forbidden = await page.evaluate(COUNT_FORBIDDEN);

    json.forbidden = forbidden;

    report.check(
        'B1 — no native temporal input anywhere in the document',
        forbidden.total === 0,
        forbidden.total === 0
            ? 'date 0, time 0, datetime 0, datetime-local 0'
            : `found ${forbidden.types.join(', ')}`,
    );

    /*
     * B2 / B3 — the panel's own controls are what render
     */
    const dateTrigger = await page.evaluate(
        READ('#date-field button[aria-haspopup="dialog"]'),
    );

    report.check(
        'B2 — the date field renders a panel trigger showing its value',
        dateTrigger !== null && /Sep|Sept/.test(dateTrigger.text),
        dateTrigger === null ? 'not found' : `trigger reads "${dateTrigger.text}"`,
    );

    // The unit tests cannot assert this: a closed Reka select resolves its
    // label from items that only exist while the listbox is open, so what the
    // trigger *shows* is a question for an engine.
    const hour = await page.evaluate(READ('#time-field [aria-label="Hour"]'));
    const minute = await page.evaluate(READ('#time-field [aria-label="Minute"]'));

    report.check(
        'B3 — the time field renders selects showing 09 and 05',
        hour?.text === '09' && minute?.text === '05',
        `hour "${hour?.text ?? '?'}", minute "${minute?.text ?? '?'}"`,
    );

    const second = await page.evaluate(
        READ('#seconds-field [aria-label="Second"]'),
    );

    report.check(
        'B3 — a seconds field renders a third select',
        second?.text === '07',
        `second "${second?.text ?? 'absent'}"`,
    );

    const noSecond = await page.evaluate(
        READ('#time-field [aria-label="Second"]'),
    );

    report.check(
        'B3 — a field without seconds renders no seconds select',
        noSecond === null,
        noSecond === null ? 'absent, as declared' : 'present',
    );

    /*
     * B4 — the datetime composes both halves
     */
    const dtDate = await page.evaluate(
        READ('#datetime-field button[aria-haspopup="dialog"]'),
    );
    const dtHour = await page.evaluate(
        READ('#datetime-field [aria-label="Hour"]'),
    );

    report.check(
        'B4 — the datetime field renders a date trigger and a time group',
        dtDate !== null && dtHour !== null,
        `date "${dtDate?.text ?? '?'}", hour "${dtHour?.text ?? '?'}"`,
    );

    const before = await page.evaluate(STATE);

    json.initial = before;

    report.check(
        'B4 — it reads a stored datetime back without changing it',
        before.datetime === '2026-09-11 10:00',
        `state.datetime = ${JSON.stringify(before.datetime)}`,
    );

    /*
     * B13 — the description reaches the control that takes focus
     */
    report.check(
        'B13 — the date trigger carries aria-describedby',
        typeof dateTrigger?.describedBy === 'string' &&
            dateTrigger.describedBy !== '',
        `describedby = ${JSON.stringify(dateTrigger?.describedBy)}`,
    );

    report.check(
        'B13 — every time select carries aria-describedby',
        [hour, minute].every(
            (part) =>
                typeof part?.describedBy === 'string' && part.describedBy !== '',
        ),
        `hour ${JSON.stringify(hour?.describedBy)}, ` +
            `minute ${JSON.stringify(minute?.describedBy)}`,
    );

    // The wrapper is not what a reader lands on. Before this change the
    // attribute was on it and on nothing else.
    const wrapperDescribed = await page.evaluate(`(() => {
        const el = document.querySelector('#date-field [aria-describedby]');

        return el === null ? null : el.tagName.toLowerCase();
    })()`);

    report.check(
        'B13 — the first described element is focusable, not a div',
        wrapperDescribed === 'button',
        `first described element is <${wrapperDescribed}>`,
    );

    /*
     * B12 — two entries of one field do not collide
     */
    const repeated = await page.evaluate(`(() => {
        const ids = [...document.querySelectorAll('#repeated [id]')]
            .map((el) => el.id)
            .filter((id) => id !== '');

        return { ids, unique: new Set(ids).size };
    })()`);

    json.repeated = repeated;

    report.check(
        'B12 — repeater entries produce unique ids',
        repeated.ids.length > 0 && repeated.unique === repeated.ids.length,
        `${repeated.ids.length} ids, ${repeated.unique} distinct`,
    );

    const entryValues = await page.evaluate(`(() => {
        const groups = [...document.querySelectorAll('#repeated [role="group"]')];

        return groups.map((group) =>
            [...group.querySelectorAll('[aria-label="Hour"], [aria-label="Minute"]')]
                .map((el) => (el.textContent ?? '').trim())
                .join(':'),
        );
    })()`);

    report.check(
        'B12 — each entry shows its own value',
        entryValues[0] === '01:02' && entryValues[1] === '03:04',
        entryValues.join(' | '),
    );

    /*
     * B14 — the query builder picks a date the same way
     */
    const queryPicker = await page.evaluate(`(() => {
        const el = document.querySelector(
            '#query-builder button[aria-haspopup="dialog"]',
        );

        return el === null ? null : (el.textContent ?? '').trim();
    })()`);

    report.check(
        'B14 — a date constraint renders the panel date picker',
        queryPicker !== null,
        queryPicker === null ? 'not found' : `trigger reads "${queryPicker}"`,
    );

    const queryForbidden = await page.evaluate(`
        document.querySelectorAll('#query-builder').length === 0
            ? -1
            : document.querySelectorAll(
                  '#query-builder ' + ${JSON.stringify(FORBIDDEN)}.split(',').join(', #query-builder ')
              ).length
    `);

    report.check(
        'B14 — and no native input inside the query builder',
        queryForbidden === 0,
        `${queryForbidden} forbidden controls`,
    );

    /*
     * B15 — PP-27: the draft row survives while the rule is incomplete
     */
    report.check(
        'B15 — an incomplete date rule is still on screen',
        Array.isArray(before.rules) &&
            before.rules.length === 1 &&
            before.rules[0].value === null,
        `rules = ${JSON.stringify(before.rules)}`,
    );

    /*
     * B2 — the calendar opens, and it is the panel's
     */
    await page.evaluate(
        `document.querySelector('#date-field button[aria-haspopup="dialog"]').click()`,
    );

    const calendar = await page.evaluate(`(() => {
        const grid = document.querySelector('[role="dialog"] table, [role="dialog"] [role="grid"]');

        if (grid === null) {
            return null;
        }

        const style = getComputedStyle(grid.closest('[role="dialog"]'));

        return {
            cells: grid.querySelectorAll('td, [role="gridcell"]').length,
            background: style.backgroundColor,
            forbidden: document.querySelectorAll(${JSON.stringify(FORBIDDEN)}).length,
        };
    })()`);

    json.calendarLight = calendar;

    report.check(
        'B2 — the popover opens a real calendar grid',
        calendar !== null && calendar.cells > 20,
        calendar === null ? 'no grid' : `${calendar.cells} day cells`,
    );

    report.check(
        'B1 — still no native temporal input while a popover is open',
        calendar?.forbidden === 0,
        `${calendar?.forbidden ?? '?'} forbidden controls with the calendar open`,
    );

    /*
     * B9 — the portal follows the theme while it is open
     */
    await page.evaluate(`document.documentElement.classList.add('dark')`);

    const dark = await page.evaluate(`(() => {
        const dialog = document.querySelector('[role="dialog"]');

        return dialog === null
            ? null
            : getComputedStyle(dialog).backgroundColor;
    })()`);

    json.calendarDark = dark;

    report.check(
        'B9 — the open calendar follows a light-to-dark switch',
        typeof dark === 'string' &&
            typeof calendar?.background === 'string' &&
            dark !== calendar.background,
        `light ${calendar?.background} -> dark ${dark}`,
    );

    await page.evaluate(`document.documentElement.classList.remove('dark')`);
    await page.evaluate(`document.body.click()`);

    /*
     * B5 — the hour select is operable from the keyboard
     */
    const keyboard = await page.evaluate(`(() => {
        const trigger = document.querySelector('#time-field [aria-label="Hour"]');

        trigger.focus();

        return {
            focused: document.activeElement === trigger,
            tabIndex: trigger.tabIndex,
            role: trigger.getAttribute('role'),
            expanded: trigger.getAttribute('aria-expanded'),
        };
    })()`);

    json.keyboard = keyboard;

    report.check(
        'B5 — the hour control is focusable and announces a combobox',
        keyboard.focused && keyboard.role === 'combobox',
        `focused=${keyboard.focused} role=${keyboard.role} ` +
            `tabindex=${keyboard.tabIndex}`,
    );

    await page.press('Enter');

    // Scoped through `aria-controls` like every other option read here, even
    // though this is the first select opened on a fresh page: a global
    // `[role="option"]` query is the exact mistake that let three bound
    // checks pass against the wrong listbox, and leaving one in place is
    // leaving the next person a pattern to copy.
    const opened = await page.evaluate(`(() => {
        const trigger = document.querySelector('#time-field [aria-label="Hour"]');
        const listbox = document.getElementById(
            trigger.getAttribute('aria-controls'),
        );

        return {
            expanded: trigger.getAttribute('aria-expanded'),
            options:
                listbox === null
                    ? -1
                    : listbox.querySelectorAll('[role="option"]').length,
        };
    })()`);

    report.check(
        'B5 — Enter opens the hour list with 24 options',
        opened.expanded === 'true' && opened.options === 24,
        `expanded=${opened.expanded}, ${opened.options} options`,
    );

    await page.press('Escape');

    /*
     * B6 / B7 — the bound the old control could not enforce
     */
    const bounds = await options('#datetime-field [aria-label="Hour"]');

    json.hoursOnFreeDate = bounds;

    // The chosen date is the 11th, which is between the bounds — every hour
    // is legal there. This is the control case for the two below, and the
    // length assertion is what stops an unopened listbox passing as "nothing
    // is disabled".
    report.check(
        'B6 — a date between the bounds leaves every hour selectable',
        bounds.length === 24 && bounds.every((option) => !option.disabled),
        `${bounds.length} options, ` +
            `${bounds.filter((o) => o.disabled).length} disabled on 2026-09-11`,
    );

    await page.press('Escape');

    /*
     * B6 — the minimum, on the day it applies to
     *
     * This is the finding. A `datetime-local` input honoured a full
     * `min="2026-09-10T09:30"`; a calendar handed `min.slice(0, 10)` honours
     * only the day, so the 10th became selectable and every hour on it came
     * with it. Moving the date onto the boundary day is what makes the
     * difference visible.
     */
    /**
     * Picks a day from a field's calendar.
     *
     * Two evaluations rather than one: the popover's content is rendered on
     * the next tick, so a script that clicks the trigger and then looks for a
     * grid cell in the same expression finds nothing. A select's listbox does
     * render synchronously, which is why the option lists below are read in a
     * single call and this is not.
     */
    async function pickDay(container, day) {
        await page.evaluate(
            `document.querySelector('${container} button[aria-haspopup="dialog"]').click()`,
        );

        await waitFor(
            `document.querySelectorAll('[role="dialog"] [role="gridcell"]').length > 0`,
            `${container} to open its calendar`,
        );

        return page.evaluate(`(() => {
            const cell = [
                ...document.querySelectorAll('[role="dialog"] [role="gridcell"]'),
            ].find((el) => (el.textContent ?? '').trim() === '${day}');

            if (cell === undefined) {
                return false;
            }

            cell.querySelector('button').click();

            return true;
        })()`);
    }

    /**
     * Opens one select and reads what it offers.
     *
     * Split into two evaluations for the same reason `pickDay` is: the
     * listbox is rendered on the next tick. Reading it in the same expression
     * that opens it returns an empty list, which would have looked exactly
     * like "no option is disabled" — a passing bound check that had measured
     * nothing at all.
     */
    /**
     * Waits for an expression to become truthy.
     *
     * Every overlay here — the calendar popover and the three listboxes — is
     * rendered into a portal on a later tick, and how much later depends on
     * what the page was doing. A check that read straight after the click
     * sometimes found an empty list, which is indistinguishable from "no
     * option is disabled": a bound assertion that measured nothing and
     * reported a pass. Polling is what removes that failure mode rather than
     * making it rarer.
     */
    async function waitFor(expression, what) {
        for (let attempt = 0; attempt < 40; attempt++) {
            if (await page.evaluate(expression)) {
                return true;
            }

            await sleep(25);
        }

        throw new Error(`timed out waiting for ${what}`);
    }

    /**
     * Opens one select from the keyboard and reads what *that* select offers.
     *
     * Two things here are deliberate and both were learned the hard way.
     *
     * A Reka select opens on `pointerdown`, so `element.click()` leaves
     * `aria-expanded="false"` and opens nothing. Every earlier version of this
     * suite "read 24 options" that belonged to a listbox some other step had
     * opened — three bound checks passed against the wrong control. Focus and
     * Enter is what a keyboard user does, and it is what actually opens it.
     *
     * The options are then read through the trigger's own `aria-controls`
     * rather than a document-wide query, so a stray listbox cannot answer for
     * this one.
     */
    async function options(selector) {
        // A fresh document rather than closing overlays by clicking: the
        // clicks that would close them are themselves clicks.
        await page.go('temporal');

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

        return page.evaluate(`[...${scope}.querySelectorAll('[role="option"]')].map((el) => ({
            value: (el.textContent ?? '').trim(),
            disabled:
                el.getAttribute('aria-disabled') === 'true' ||
                el.hasAttribute('data-disabled'),
        }))`);
    }


    const pickedMin = await pickDay('#datetime-field', '10');

    report.check(
        'B6 — the minimum date is selectable in the calendar',
        pickedMin === true,
        pickedMin ? 'day 10 clicked' : 'day 10 not found',
    );

    const onMin = await page.evaluate(STATE);

    json.onMinDate = onMin;

    report.check(
        'B6 — moving to the minimum date keeps a time that is still legal',
        onMin.datetime === '2026-09-10 10:00',
        `state.datetime = ${JSON.stringify(onMin.datetime)}`,
    );

    /*
     * B6 / B7 — the bound, read off fields already sitting on it
     *
     * Driving a calendar popover to get onto the boundary day would make
     * these checks about popover timing rather than about the bound. Three
     * fields start where the question is, so each one is a single select to
     * open and read.
     */
    const minHours = await options('#datetime-at-min [aria-label="Hour"]');

    json.hoursOnMinDate = minHours;

    const blockedHours = minHours
        .filter((option) => option.disabled)
        .map((option) => option.value);

    report.check(
        'B6 — hours before the bound are unselectable on the minimum date',
        minHours.length === 24 &&
            blockedHours.join(',') === '00,01,02,03,04,05,06,07,08' &&
            minHours.find((option) => option.value === '09')?.disabled === false,
        `${minHours.length} options; disabled: ${blockedHours.join(',') || 'none'}`,
    );

    // At the bound hour the minutes are bounded too — this is what makes
    // 09:29 unreachable rather than merely discouraged.
    const minutesAtNine = await options(
        '#datetime-at-min-hour [aria-label="Minute"]',
    );

    json.minutesAtBoundHour = minutesAtNine;

    const blockedMinutes = minutesAtNine
        .filter((option) => option.disabled)
        .map((option) => option.value);

    report.check(
        'B6 — at the bound hour, minutes before the bound are unselectable',
        minutesAtNine.length === 60 &&
            blockedMinutes.length === 30 &&
            blockedMinutes[0] === '00' &&
            blockedMinutes[29] === '29' &&
            minutesAtNine.find((option) => option.value === '30')?.disabled ===
                false,
        `${minutesAtNine.length} options; ${blockedMinutes.length} blocked ` +
            `(${blockedMinutes[0] ?? '-'}..${blockedMinutes[blockedMinutes.length - 1] ?? '-'})`,
    );

    const maxHours = await options('#datetime-at-max [aria-label="Hour"]');

    json.hoursOnMaxDate = maxHours;

    const blockedLate = maxHours
        .filter((option) => option.disabled)
        .map((option) => option.value);

    report.check(
        'B7 — hours after the bound are unselectable on the maximum date',
        maxHours.length === 24 &&
            blockedLate.join(',') === '18,19,20,21,22,23' &&
            maxHours.find((option) => option.value === '17')?.disabled === false,
        `${maxHours.length} options; disabled: ${blockedLate.join(',') || 'none'}`,
    );

    // A last look from a clean document, after every popover and listbox this
    // suite opened: the guarantee is about what the renderers produce, not
    // about what survives one lucky first paint.
    await page.go('temporal');

    const finalSweep = await page.evaluate(COUNT_FORBIDDEN);

    report.check(
        'B1 — still no native temporal input after every interaction',
        finalSweep.total === 0,
        `${finalSweep.total} forbidden controls on a reloaded page`,
    );

    const errors = await page.evaluate('window.__errors ?? []');
    const warnings = await page.evaluate('window.__vueWarnings ?? []');

    report.check(
        'no Vue error or warning while rendering',
        errors.length === 0 && warnings.length === 0,
        `${errors.length} errors, ${warnings.length} warnings`,
    );

    /*
     * B10 / B11 — narrow viewports
     */
    for (const width of [320, 375, 768, 1280]) {
        await page.setViewport({ width, height: 800 });

        const layout = await page.evaluate(`(() => {
            const time = document.querySelector('#time-field [role="group"]');
            const datetime = document.querySelector('#datetime-field [role="group"]');
            const parts = (root) =>
                [...root.querySelectorAll('[aria-label]')].every((el) => {
                    const rect = el.getBoundingClientRect();

                    return rect.width > 0 && rect.height > 0;
                });

            return {
                pageOverflow:
                    document.documentElement.scrollWidth >
                    document.documentElement.clientWidth,
                timeVisible: parts(time),
                datetimeVisible: parts(datetime),
                datetimeHeight: Math.round(
                    datetime.getBoundingClientRect().height,
                ),
            };
        })()`);

        json[`viewport${width}`] = layout;

        report.check(
            `B10/B11 — ${width}px: every temporal control is laid out and the page does not scroll sideways`,
            !layout.pageOverflow &&
                layout.timeVisible &&
                layout.datetimeVisible,
            `overflow=${layout.pageOverflow}, time=${layout.timeVisible}, ` +
                `datetime=${layout.datetimeVisible} (h ${layout.datetimeHeight}px)`,
        );
    }
});

if (process.argv.includes('--json')) {
    console.log(JSON.stringify(json, null, 2));
}

report.finish();
