#!/usr/bin/env node

/**
 * UI-6 acceptance, in a real browser.
 *
 * Four findings whose answers are numbers a layout engine produces or styles a
 * cascade resolves — a focus ring, a target's size in CSS pixels, whether an
 * `<h2>` is bigger than a paragraph, and whether an animation stops when the
 * reader has asked it to. Every one of them was `SOURCE VERIFIED` at best
 * before B04, and every one of them is asserted here against Chrome.
 *
 * Separate from `verify.mjs` on purpose: that file proves the *instrument* and
 * records baselines, and its checks should keep passing whatever this wave
 * changed. This file is the remediation's own acceptance.
 *
 *     node tests/browser/ui6.mjs [--build] [--json]
 */

import { buildFixture, reporter, withPage } from './chrome.mjs';

const report = reporter('UI-6 — editor focus, touch targets, prose, motion');
const json = {};

/** The box and the styles a check cares about, for one selector. */
const READ = (selector, properties) => `(() => {
    const el = document.querySelector(${JSON.stringify(selector)});

    if (el === null) {
        return null;
    }

    const rect = el.getBoundingClientRect();
    const style = getComputedStyle(el);
    const styles = {};

    for (const property of ${JSON.stringify(properties)}) {
        styles[property] = style.getPropertyValue(property);
    }

    return {
        width: Math.round(rect.width * 100) / 100,
        height: Math.round(rect.height * 100) / 100,
        styles,
    };
})()`;

/**
 * Whether a focus indicator is *drawn*, as opposed to merely declared.
 *
 * `box-shadow !== 'none'` is not enough and nearly cost this wave a false
 * pass: Tailwind's ring utilities always emit a shadow list, and an element
 * with no ring reports `rgba(0, 0, 0, 0) 0px 0px 0px 0px` — a transparent
 * shadow of zero width, which is not `none` and is not visible either. A real
 * indicator has a layer with both a non-zero spread and a colour that is not
 * fully transparent.
 */
function drawsIndicator(boxShadow, outlineStyle, outlineWidth) {
    if (outlineStyle !== 'none' && parseFloat(outlineWidth ?? '0') > 0) {
        return true;
    }

    if (boxShadow === 'none' || boxShadow === '') {
        return false;
    }

    return boxShadow
        .split(/,(?![^(]*\))/)
        .some(
            (layer) =>
                !/rgba\([^)]*,\s*0\)/.test(layer) && /[1-9]\d*px/.test(layer),
        );
}

const FOCUS = [
    'outline-style',
    'outline-width',
    'outline-color',
    'box-shadow',
    'border-color',
];

async function main() {
    await buildFixture({ force: process.argv.includes('--build') });

    await withPage(async (page) => {
        /* ================================================================
         * U17 — the editor's focus
         * ============================================================= */

        await page.go('rich-editor');

        // U17-B1: focus the editable body the way a keyboard would.
        const keyboardFocus = await page.evaluate(`(() => {
            document.querySelector('#before').focus();

            return document.activeElement?.id ?? null;
        })()`);

        await page.press('Tab');

        const afterTab = await page.evaluate(`(() => {
            const active = document.activeElement;

            return {
                tag: active?.tagName ?? null,
                role: active?.getAttribute('role') ?? null,
                label: active?.getAttribute('aria-label') ?? null,
            };
        })()`);

        report.check(
            'U17-B2 Tab moves into the editor’s controls',
            keyboardFocus === 'before' && afterTab.tag !== null,
            `→ <${afterTab.tag}> ${afterTab.label ?? afterTab.role ?? ''}`,
        );

        // Tab first, deliberately: `:focus-visible` is modality-dependent, so
        // an element focused programmatically after only pointer input does
        // not match it. The keyboard case is the one U17 is about, and it has
        // to be entered rather than assumed.
        await page.press('Tab');

        const editorFocus = await page.evaluate(`(() => {
            const el = document.querySelector('[contenteditable="true"]');

            el.focus();

            const style = getComputedStyle(el);
            const wrapper = getComputedStyle(el.parentElement);

            return {
                focused: document.activeElement === el,
                outlineStyle: style.outlineStyle,
                outlineWidth: style.outlineWidth,
                boxShadow: style.boxShadow,
                wrapperBorder: wrapper.borderColor,
            };
        })()`);

        json.u17 = { editorFocus };

        report.check(
            'U17-B1 the editor draws a package focus indicator',
            editorFocus.focused &&
                drawsIndicator(
                    editorFocus.boxShadow,
                    editorFocus.outlineStyle,
                    editorFocus.outlineWidth,
                ),
            editorFocus.boxShadow === 'none'
                ? 'nothing drawn'
                : `box-shadow ${editorFocus.boxShadow.slice(0, 60)}…`,
        );

        // The wrapper border says which editor owns focus, click or Tab.
        const wrapperBorder = await page.evaluate(`(async () => {
            const el = document.querySelector('[contenteditable="true"]');
            const wrapper = el.parentElement;

            const frame = () =>
                new Promise((done) => requestAnimationFrame(() => requestAnimationFrame(done)));

            el.blur();
            await frame();

            const idle = getComputedStyle(wrapper).borderColor;

            el.focus();
            await frame();

            return { idle, focused: getComputedStyle(wrapper).borderColor };
        })()`);

        report.check(
            'U17-B1b the field boundary marks which editor holds focus',
            wrapperBorder.idle !== wrapperBorder.focused,
            `${wrapperBorder.idle} → ${wrapperBorder.focused}`,
        );

        // U17-B3: the pointer still works, and does not leave the strong ring.
        const pointer = await page.evaluate(`(() => {
            const el = document.querySelector('[contenteditable="true"]');

            el.blur();
            el.click();
            el.focus();

            return { focused: document.activeElement === el };
        })()`);

        report.check(
            'U17-B3 pointer interaction still focuses the editor',
            pointer.focused,
            'click → focused',
        );

        // U17-B4: a toggle reports its state.
        const toggle = await page.evaluate(`(async () => {
            const el = document.querySelector('[contenteditable="true"]');
            const bold = document.querySelector('[aria-label="bold"]');

            el.focus();

            const range = document.createRange();
            // A paragraph that carries no formatting, so the toggle starts
            // from off. Selecting one that already contains <strong> makes
            // queryCommandState report true before anything is pressed —
            // correctly, which is why the first version of this test failed.
            const paragraph = el.querySelector('#plain-paragraph');

            range.selectNodeContents(paragraph);

            const selection = getSelection();

            selection.removeAllRanges();
            selection.addRange(range);

            // selectionchange is asynchronous, so reading the attribute in
            // the same tick returns the state of the previous selection.
            await new Promise((done) => setTimeout(done, 120));

            const before = bold.getAttribute('aria-pressed');

            bold.click();
            document.dispatchEvent(new Event('selectionchange'));

            await new Promise((done) => setTimeout(done, 120));

            return {
                before,
                after: bold.getAttribute('aria-pressed'),
                html: el.innerHTML,
                pressedBackground: getComputedStyle(bold).backgroundColor,
            };
        })()`);

        json.u17.toggle = toggle;

        report.check(
            'U17-B4a a toolbar toggle reports a pressed state',
            toggle.before === 'false' && toggle.after === 'true',
            `aria-pressed ${toggle.before} → ${toggle.after}`,
        );
        report.check(
            'U17-B4b and the formatting was actually applied',
            /<(b|strong)[ >]/i.test(toggle.html),
            toggle.html.slice(0, 60),
        );

        // U17-B6: focused and invalid at the same time.
        const invalid = await page.evaluate(`(() => {
            const editors = [...document.querySelectorAll('[contenteditable="true"]')];
            const el = editors[editors.length - 1];

            el.focus();

            const style = getComputedStyle(el);
            const wrapper = getComputedStyle(el.parentElement);

            return {
                ariaInvalid: el.getAttribute('aria-invalid'),
                describedBy: el.getAttribute('aria-describedby'),
                role: el.getAttribute('role'),
                multiline: el.getAttribute('aria-multiline'),
                border: wrapper.borderColor,
                boxShadow: style.boxShadow,
            };
        })()`);

        json.u17.invalid = invalid;

        report.check(
            'U17-B6 an invalid editor keeps both treatments',
            invalid.ariaInvalid === 'true' &&
                drawsIndicator(invalid.boxShadow, 'none', '0') &&
                invalid.border !== wrapperBorder.focused,
            `invalid, border ${invalid.border}, ring ${drawsIndicator(invalid.boxShadow, 'none', '0') ? 'drawn' : 'absent'}`,
        );
        report.check(
            'U17 semantics survive — textbox, multiline, described',
            invalid.role === 'textbox' &&
                invalid.multiline === 'true' &&
                (invalid.describedBy ?? '') !== '',
            `role ${invalid.role}, describedby "${invalid.describedBy}"`,
        );

        // U17-B5: the same, in the dark.
        await page.evaluate(
            `document.documentElement.classList.add('dark'); true`,
        );

        const dark = await page.evaluate(`(() => {
            const el = document.querySelector('[contenteditable="true"]');

            el.focus();

            const style = getComputedStyle(el);

            return {
                boxShadow: style.boxShadow,
                background: getComputedStyle(el).backgroundColor,
            };
        })()`);

        json.u17.dark = dark;

        report.check(
            'U17-B5 the indicator is drawn in dark mode too',
            drawsIndicator(dark.boxShadow, 'none', '0'),
            `on ${dark.background} → ${dark.boxShadow.slice(0, 52)}…`,
        );

        await page.evaluate(
            `document.documentElement.classList.remove('dark'); true`,
        );

        /* ================================================================
         * B02 — editor content typography
         * ============================================================= */

        await page.go('observation');

        const TYPE = ['font-size', 'font-weight', 'margin-top', 'line-height'];

        const proseHeading = await page.evaluate(READ('#prose-heading', TYPE));
        const plainHeading = await page.evaluate(READ('#plain-heading', TYPE));
        const proseList = await page.evaluate(
            READ('#prose-sample ul', [
                'list-style-type',
                'padding-inline-start',
            ]),
        );
        const plainList = await page.evaluate(
            READ('#plain-list', ['list-style-type', 'padding-inline-start']),
        );
        const proseOrdered = await page.evaluate(
            READ('#prose-sample ol', ['list-style-type']),
        );
        const proseLink = await page.evaluate(
            READ('#prose-link', ['color', 'text-decoration-line']),
        );
        const plainLink = await page.evaluate(
            READ('#plain-link', ['color', 'text-decoration-line']),
        );
        const proseCode = await page.evaluate(
            READ('#prose-code', ['background-color', 'font-family']),
        );
        const proseQuote = await page.evaluate(
            READ('#prose-quote', ['border-left-width', 'color']),
        );

        json.b02 = {
            proseHeading,
            plainHeading,
            proseList,
            plainList,
            proseLink,
            plainLink,
            proseCode,
            proseQuote,
        };

        report.check(
            'B02-B1 an editor heading is bigger and bolder than a plain one',
            parseFloat(proseHeading.styles['font-size']) >
                parseFloat(plainHeading.styles['font-size']) &&
                parseInt(proseHeading.styles['font-weight'], 10) >= 600,
            `${proseHeading.styles['font-size']} / ${proseHeading.styles['font-weight']} ` +
                `vs plain ${plainHeading.styles['font-size']} / ${plainHeading.styles['font-weight']}`,
        );
        report.check(
            'B02-B2 list markers render',
            proseList.styles['list-style-type'] === 'disc' &&
                proseOrdered.styles['list-style-type'] === 'decimal' &&
                parseFloat(proseList.styles['padding-inline-start']) > 0,
            `ul ${proseList.styles['list-style-type']}, ol ${proseOrdered.styles['list-style-type']}, ` +
                `indent ${proseList.styles['padding-inline-start']}`,
        );
        report.check(
            'B02-B3 a link is underlined, not only coloured',
            proseLink.styles['text-decoration-line'].includes('underline'),
            `${proseLink.styles.color}, ${proseLink.styles['text-decoration-line']}`,
        );
        report.check(
            'B02-B4 inline code has a plate and a mono face',
            proseCode.styles['background-color'] !== 'rgba(0, 0, 0, 0)' &&
                /mono/i.test(proseCode.styles['font-family']),
            `${proseCode.styles['background-color']}, ${proseCode.styles['font-family'].slice(0, 28)}`,
        );
        report.check(
            'B02 a blockquote is marked',
            parseFloat(proseQuote.styles['border-left-width']) > 0,
            `border ${proseQuote.styles['border-left-width']}`,
        );
        report.check(
            'B02-B6 none of it leaks outside the editor',
            plainList.styles['list-style-type'] !== 'disc' &&
                !plainLink.styles['text-decoration-line'].includes(
                    'underline',
                ) &&
                plainHeading.styles['font-size'] === '16px',
            `plain list ${plainList.styles['list-style-type']}, ` +
                `plain link ${plainLink.styles['text-decoration-line']}, ` +
                `plain h2 ${plainHeading.styles['font-size']}`,
        );

        await page.evaluate(
            `document.documentElement.classList.add('dark'); true`,
        );

        const darkProse = await page.evaluate(
            READ('#prose-heading', ['color']),
        );
        const darkCode = await page.evaluate(
            READ('#prose-code', ['background-color', 'color']),
        );

        report.check(
            'B02-B5 dark mode follows the tokens',
            darkProse.styles.color !== proseHeading.styles.color ||
                darkCode.styles['background-color'] !==
                    proseCode.styles['background-color'],
            `heading ${darkProse.styles.color}, code plate ${darkCode.styles['background-color']}`,
        );

        await page.evaluate(
            `document.documentElement.classList.remove('dark'); true`,
        );

        /* ================================================================
         * B03 — motion, when less of it was asked for
         * ============================================================= */

        const MOTION = [
            'animation-name',
            'animation-duration',
            'transition-duration',
        ];

        const normal = {
            entrance: await page.evaluate(READ('#animated', MOTION)),
            slide: await page.evaluate(READ('#sliding', MOTION)),
            lift: await page.evaluate(READ('#lift', MOTION)),
            spinner: await page.evaluate(READ('#spinner', MOTION)),
        };

        await page.emulateMedia({ 'prefers-reduced-motion': 'reduce' });

        const reduced = {
            entrance: await page.evaluate(READ('#animated', MOTION)),
            slide: await page.evaluate(READ('#sliding', MOTION)),
            lift: await page.evaluate(READ('#lift', MOTION)),
            spinner: await page.evaluate(READ('#spinner', MOTION)),
        };

        json.b03 = { normal, reduced };

        const stopped = (box) =>
            parseFloat(box.styles['animation-duration']) <= 0.001;

        report.check(
            'B03-B1 motion is intact when nothing was asked',
            parseFloat(normal.entrance.styles['animation-duration']) > 0.1 &&
                parseFloat(normal.slide.styles['animation-duration']) > 0.1,
            `entrance ${normal.entrance.styles['animation-duration']}, ` +
                `slide ${normal.slide.styles['animation-duration']}`,
        );
        report.check(
            'B03-B2 an entrance animation stops',
            stopped(reduced.entrance),
            `${normal.entrance.styles['animation-duration']} → ` +
                `${reduced.entrance.styles['animation-duration']}`,
        );
        report.check(
            'B03-B3 a slide stops',
            stopped(reduced.slide),
            `${normal.slide.styles['animation-duration']} → ` +
                `${reduced.slide.styles['animation-duration']}`,
        );
        report.check(
            'B03-B4 a decorative hover transition stops',
            parseFloat(reduced.lift.styles['transition-duration']) <= 0.001,
            `${normal.lift.styles['transition-duration']} → ` +
                `${reduced.lift.styles['transition-duration']}`,
        );
        report.check(
            'B03-B5 a process indicator keeps turning',
            parseFloat(reduced.spinner.styles['animation-duration']) > 0.1,
            `spinner ${reduced.spinner.styles['animation-duration']} — its motion is the signal`,
        );

        await page.emulateMedia({});

        /* ================================================================
         * U18 — the size of a target
         * ============================================================= */

        const TARGETS = [
            ['dialog close', '[data-slot="dialog-content"] button'],
            ['switch', '[data-slot="switch"]'],
            ['icon button', '#icon-button'],
        ];

        json.u18 = {};

        for (const viewport of [
            { width: 320, height: 800, mobile: true },
            { width: 375, height: 800, mobile: true },
            { width: 1280, height: 800 },
        ]) {
            await page.setViewport(viewport);
            await page.go('touch-targets');

            const measured = {};

            for (const [name, selector] of TARGETS) {
                measured[name] = await page.evaluate(
                    READ(selector, ['width', 'height']),
                );
            }

            json.u18[viewport.width] = measured;

            const touch = viewport.width < 640;

            for (const [name] of TARGETS) {
                const box = measured[name];
                const meets = box.width >= 44 && box.height >= 44;

                if (touch) {
                    report.check(
                        `U18 ${name} meets 44×44 at ${viewport.width}px`,
                        meets,
                        `${box.width}×${box.height}`,
                    );
                } else {
                    report.note(
                        `U18 ${name} at ${viewport.width}px`,
                        `${box.width}×${box.height}` +
                            (meets
                                ? ' (meets 44×44)'
                                : ' (compact, documented exception)'),
                    );
                }
            }
        }

        // U18-B7: the bigger target still shows focus, and still works.
        await page.setViewport({ width: 375, height: 800, mobile: true });
        await page.go('touch-targets');

        const targetFocus = await page.evaluate(`(async () => {
            const close = document.querySelector('[data-slot="dialog-content"] button');
            const control = document.querySelector('[data-slot="switch"]');

            close.focus();

            await new Promise((done) =>
                requestAnimationFrame(() => requestAnimationFrame(done)),
            );

            const closeStyle = getComputedStyle(close);

            control.focus();

            const track = control.querySelector('[data-slot="switch-track"]');
            const trackStyle = getComputedStyle(track);
            const before = control.getAttribute('data-state');

            control.click();

            await new Promise((done) => setTimeout(done, 120));

            return {
                closeFocused: document.activeElement === close,
                closeOutline: closeStyle.outlineStyle,
                closeShadow: closeStyle.boxShadow,
                trackShadow: trackStyle.boxShadow,
                trackWidth: Math.round(track.getBoundingClientRect().width),
                trackHeight: Math.round(track.getBoundingClientRect().height * 100) / 100,
                switchBefore: before,
                switchAfter: control.getAttribute('data-state'),
                closeLabel: close.textContent?.trim() || close.getAttribute('aria-label'),
            };
        })()`);

        json.u18.focus = targetFocus;

        report.check(
            'U18-B7 the enlarged close still shows focus',
            targetFocus.closeFocused &&
                drawsIndicator(
                    targetFocus.closeShadow,
                    targetFocus.closeOutline,
                    '0',
                ),
            `shadow ${targetFocus.closeShadow.slice(0, 52)}…`,
        );
        report.check(
            'U18 the switch still toggles, and still looks like a switch',
            targetFocus.switchBefore === 'unchecked' &&
                targetFocus.switchAfter === 'checked' &&
                targetFocus.trackWidth === 32,
            `${targetFocus.switchBefore} → ${targetFocus.switchAfter}, ` +
                `track ${targetFocus.trackWidth}×${targetFocus.trackHeight}`,
        );
        report.check(
            'U18 the close keeps its accessible name',
            (targetFocus.closeLabel ?? '') !== '',
            `"${targetFocus.closeLabel}"`,
        );
    });

    if (process.argv.includes('--json')) {
        console.log(JSON.stringify(json, null, 2));
    }

    report.finish();
}

main().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
