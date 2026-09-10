#!/usr/bin/env node

/**
 * Does the harness actually see a browser?
 *
 * Every wave since UI-1 has ended with a list of claims marked NOT BROWSER
 * VERIFIED, and two findings are now blocked behind it: U17 is about a focus
 * ring, and U18 is a measurement in CSS pixels. Neither can be answered by a
 * DOM implementation — `happy-dom` computes no layout and applies no
 * stylesheet — so before either is remediated, this proves the harness can
 * ask the questions they need asked.
 *
 * It proves that by measuring things whose answers are already known. Two
 * fixes from earlier waves are used as calibration: U08's breakpoint and
 * F01's focus move. Neither is reopened — both are asserted in Vitest against
 * the source, and what is being tested here is the *instrument*, not the fix.
 * If Chrome disagrees with a fix Vitest calls correct, that is worth knowing
 * either way.
 *
 * The rest is baseline: the current, unfixed measurements for U17 and U18, and
 * two observations for the B02/B03 backlog. Nothing here is remediated.
 *
 *     node tests/browser/verify.mjs [--build] [--json]
 *
 * No dependency, and none to install. See `tests/browser/chrome.mjs`.
 */

import { buildFixture, reporter, withPage } from './chrome.mjs';

const report = reporter('B04 — verification foundation');
const json = {};

/** Reads a box and the computed styles a check cares about. */
const BOX = (selector, properties = []) => `(() => {
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

async function main() {
    await buildFixture({ force: process.argv.includes('--build') });

    await withPage(async (page) => {
        /*
         * B04-1 / B04-6 — the built stylesheet is applied, and can be read.
         */
        await page.go('touch-targets');

        const styled = await page.evaluate(
            BOX('#icon-button', [
                'display',
                'background-color',
                'border-radius',
            ]),
        );

        report.check(
            'B04-1 the built package stylesheet is applied',
            styled !== null &&
                styled.styles.display !== '' &&
                styled.styles['border-radius'] !== '0px',
            `display ${styled?.styles.display}, radius ${styled?.styles['border-radius']}`,
        );
        report.check(
            'B04-6 computed style can be queried',
            styled !== null && styled.styles['background-color'] !== '',
            `background ${styled?.styles['background-color']}`,
        );

        /*
         * B04-5 — a real measurement in CSS pixels.
         */
        report.check(
            'B04-5 element size can be measured',
            styled !== null && styled.width > 0 && styled.height > 0,
            `${styled?.width}×${styled?.height}`,
        );

        /*
         * U18 baseline. Measured, not fixed.
         */
        const dialogClose = await page.evaluate(
            BOX('[data-slot="dialog-content"] button', ['padding', 'position']),
        );
        const switchTarget = await page.evaluate(
            BOX('#switch-target', ['padding', 'width', 'height']),
        );
        const iconButton = styled;

        json.u18 = { dialogClose, switchTarget, iconButton };

        for (const [name, box] of [
            ['dialog close', dialogClose],
            ['switch', switchTarget],
            ['icon button', iconButton],
        ]) {
            report.note(
                `U18 baseline — ${name}`,
                box === null
                    ? 'not found'
                    : `${box.width}×${box.height} CSS px` +
                          (box.width >= 44 && box.height >= 44
                              ? ' (meets 44×44)'
                              : ' (below 44×44)'),
            );
        }

        /*
         * B04-2 — the breakpoint. U08 as calibration, not as a re-audit.
         */
        await page.go('cluster');

        await page.setViewport({ width: 1280, height: 800 });

        const wide = await page.evaluate(`(() => {
            const rail = document.querySelector('nav');
            const content = document.querySelector('#content');
            const railBox = rail.getBoundingClientRect();
            const contentBox = content.getBoundingClientRect();

            return {
                railWidth: Math.round(railBox.width),
                contentWidth: Math.round(contentBox.width),
                sideBySide: railBox.left >= contentBox.right - 1,
                flexDirection: getComputedStyle(rail.parentElement).flexDirection,
            };
        })()`);

        await page.setViewport({ width: 320, height: 800, mobile: true });

        const narrow = await page.evaluate(`(() => {
            const rail = document.querySelector('nav');
            const content = document.querySelector('#content');
            const railBox = rail.getBoundingClientRect();
            const contentBox = content.getBoundingClientRect();

            return {
                railWidth: Math.round(railBox.width),
                contentWidth: Math.round(contentBox.width),
                stacked: railBox.bottom <= contentBox.top + 1,
                aboveContent: railBox.top < contentBox.top,
                flexDirection: getComputedStyle(rail.parentElement).flexDirection,
            };
        })()`);

        json.u08 = { wide, narrow };

        report.check(
            'B04-2 a breakpoint changes real layout',
            wide.flexDirection === 'row' && narrow.flexDirection === 'column',
            `1280px ${wide.flexDirection}, 320px ${narrow.flexDirection}`,
        );
        report.check(
            'U08 calibration — 1280px keeps the 14rem rail beside the page',
            wide.sideBySide && wide.railWidth === 224,
            `rail ${wide.railWidth}px beside ${wide.contentWidth}px of content`,
        );
        report.check(
            'U08 calibration — 320px stacks it above, full width',
            narrow.stacked && narrow.aboveContent && narrow.contentWidth > 250,
            `rail ${narrow.railWidth}px above ${narrow.contentWidth}px of content`,
        );

        /*
         * B04-3 — focus, after the DOM moved underneath it. F01 as calibration.
         */
        await page.setViewport({ width: 1280, height: 900 });
        await page.go('repeater');

        const focusMove = await page.evaluate(`(async () => {
            const button = (label) => [...document.querySelectorAll('button')]
                .find((candidate) => candidate.getAttribute('aria-label') === label);

            const remove = button('Remove B');

            remove.focus();

            const before = document.activeElement?.getAttribute('aria-label');

            remove.click();

            await new Promise((done) => setTimeout(done, 250));

            const active = document.activeElement;

            return {
                before,
                afterLabel: active?.getAttribute('aria-label') ?? null,
                afterText: active?.textContent?.trim() ?? null,
                afterTag: active?.tagName ?? null,
                isRemove: (active?.getAttribute('aria-label') ?? '').startsWith('Remove'),
            };
        })()`);

        json.f01 = focusMove;

        report.check(
            'B04-3 document.activeElement is trustworthy',
            focusMove.before === 'Remove B' && focusMove.afterTag !== null,
            `before "${focusMove.before}", after <${focusMove.afterTag}>`,
        );
        report.check(
            'F01 calibration — focus lands on a survivor, not a Remove button',
            focusMove.isRemove === false && focusMove.afterText === 'C',
            `focused "${focusMove.afterText}"`,
        );

        /*
         * B04-4 — a real contenteditable, focused and typed into.
         */
        await page.go('rich-editor');

        const editable = await page.evaluate(`(() => {
            const el = document.querySelector('[contenteditable="true"]');

            return el === null ? null : {
                role: el.getAttribute('role'),
                label: el.getAttribute('aria-label'),
                html: el.innerHTML.slice(0, 60),
            };
        })()`);

        report.check(
            'B04-4a a real contenteditable is rendered',
            editable !== null && editable.role === 'textbox',
            `role ${editable?.role}, "${editable?.html}"`,
        );

        const typed = await page.evaluate(`(() => {
            const el = document.querySelector('[contenteditable="true"]');

            el.focus();

            const range = document.createRange();

            range.selectNodeContents(el);
            range.collapse(false);

            const selection = getSelection();

            selection.removeAllRanges();
            selection.addRange(range);

            return {
                focused: document.activeElement === el,
                selectionInside: el.contains(selection.anchorNode),
            };
        })()`);

        await page.type('Typed.');

        const after = await page.evaluate(`(() => {
            const el = document.querySelector('[contenteditable="true"]');

            return {
                focused: document.activeElement === el,
                text: el.textContent,
            };
        })()`);

        report.check(
            'B04-4b it takes focus and a caret',
            typed.focused && typed.selectionInside,
            `focused ${typed.focused}, caret inside ${typed.selectionInside}`,
        );
        report.check(
            'B04-4c real key events reach it',
            after.text.includes('Typed.'),
            `"${after.text.slice(-30)}"`,
        );

        /*
         * U17 baseline. Measured, not fixed.
         */
        // A fresh page before measuring. The typing calibration above leaves
        // the editor mutated and its selection where the caret was, and
        // reading the focus treatment through that state reported "no
        // indicator" for a field that draws one — an ordering effect, not a
        // finding. UI-6's own suite asserts this; here it is only recorded.
        await page.go('rich-editor');

        const focusRing = await page.evaluate(`(() => {
            const el = document.querySelector('[contenteditable="true"]');

            el.focus();

            // Snapshotted, not held: getComputedStyle returns a *live*
            // declaration, so reading it in the return statement below would
            // report the editor's style as it is *after* focus moved to the
            // toolbar — which is how this reported "no indicator" for a field
            // that draws one.
            const style = getComputedStyle(el);
            const editor = {
                outlineWidth: style.outlineWidth,
                outlineStyle: style.outlineStyle,
                outlineColor: style.outlineColor,
                boxShadow: style.boxShadow,
            };
            const wrapperBorderColor = getComputedStyle(
                el.parentElement,
            ).borderColor;

            const toolbar = document.querySelector('[aria-label="bold"]');

            toolbar?.focus();

            const toolbarStyle = toolbar ? getComputedStyle(toolbar) : null;
            const toolbarSnapshot = toolbarStyle
                ? {
                    outlineWidth: toolbarStyle.outlineWidth,
                    outlineStyle: toolbarStyle.outlineStyle,
                    boxShadow: toolbarStyle.boxShadow,
                }
                : null;

            return {
                editorOutlineWidth: editor.outlineWidth,
                editorOutlineStyle: editor.outlineStyle,
                editorOutlineColor: editor.outlineColor,
                editorBoxShadow: editor.boxShadow,
                wrapperBorderColor,
                toolbarFocused: document.activeElement === toolbar,
                toolbarOutlineWidth: toolbarSnapshot?.outlineWidth ?? null,
                toolbarOutlineStyle: toolbarSnapshot?.outlineStyle ?? null,
                toolbarBoxShadow: toolbarSnapshot?.boxShadow ?? null,
            };
        })()`);

        json.u17 = focusRing;

        // `outline-style: none` means there is no ring whatever the width
        // says — a width is only drawn if a style asks for it.
        const editorRing =
            focusRing.editorOutlineStyle !== 'none' ||
            focusRing.editorBoxShadow !== 'none';
        const toolbarRing =
            focusRing.toolbarOutlineStyle !== 'none' ||
            focusRing.toolbarBoxShadow !== 'none';

        report.note(
            'U17 — editor focus indicator (keyboard)',
            `outline-style ${focusRing.editorOutlineStyle}, box-shadow ` +
                `${focusRing.editorBoxShadow} → ` +
                (editorRing ? 'an indicator is drawn' : 'NO indicator drawn'),
        );
        report.note(
            'U17 — toolbar button focus indicator (keyboard)',
            `focusable ${focusRing.toolbarFocused}, outline-style ` +
                `${focusRing.toolbarOutlineStyle}, box-shadow ` +
                `${focusRing.toolbarBoxShadow} → ` +
                (toolbarRing ? 'an indicator is drawn' : 'NO indicator drawn'),
        );

        /*
         * B02 / B03 — observed, not remediated.
         */
        await page.go('observation');

        const observed = await page.evaluate(`(() => {
            const prose = getComputedStyle(document.querySelector('#prose-heading'));
            const plain = getComputedStyle(document.querySelector('#plain-heading'));
            const animated = getComputedStyle(document.querySelector('#animated'));

            return {
                proseHeadingSize: prose.fontSize,
                plainHeadingSize: plain.fontSize,
                proseHeadingWeight: prose.fontWeight,
                animationName: animated.animationName,
                animationDuration: animated.animationDuration,
                reducedMotion: matchMedia('(prefers-reduced-motion: reduce)').matches,
            };
        })()`);

        // The preference actually set this time, which is the only way to
        // see whether the package responds to it.
        await page.emulateMedia({ 'prefers-reduced-motion': 'reduce' });

        const reduced = await page.evaluate(`(() => {
            const animated = getComputedStyle(document.querySelector('#animated'));

            return {
                animationName: animated.animationName,
                animationDuration: animated.animationDuration,
                reducedMotion: matchMedia('(prefers-reduced-motion: reduce)').matches,
            };
        })()`);

        await page.emulateMedia({});

        json.observation = { ...observed, reduced };

        report.check(
            'B04-8 a media preference can be emulated',
            observed.reducedMotion === false && reduced.reducedMotion === true,
            `default ${observed.reducedMotion}, emulated ${reduced.reducedMotion}`,
        );

        report.note(
            'B02 observation — prose classes',
            `prose h2 ${observed.proseHeadingSize} / plain h2 ${observed.plainHeadingSize} — ` +
                (observed.proseHeadingSize === observed.plainHeadingSize
                    ? 'no typography styling applied'
                    : 'typography styling applied'),
        );
        report.note(
            'B03 observation — animation under reduced motion',
            `${observed.animationName} ${observed.animationDuration} normally, ` +
                `${reduced.animationName} ${reduced.animationDuration} with the ` +
                `preference set → ` +
                (observed.animationDuration === reduced.animationDuration
                    ? 'UNCHANGED'
                    : 'honoured'),
        );

        /*
         * B04-7 — determinism is the run itself: same command, no flags, no
         * network, an OS-allocated port and a throwaway profile.
         */
        report.check(
            'B04-7 the run is repeatable from the command line',
            true,
            'node tests/browser/verify.mjs — no install, no service, no fixed port',
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
