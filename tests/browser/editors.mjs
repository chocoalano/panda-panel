#!/usr/bin/env node

/**
 * E1 acceptance — the Markdown and code editors, in a real browser.
 *
 * Three of the panel's form fields are now a third-party editor: the rich
 * editor is Tiptap and has its own suite (`ui6.mjs`, `verify.mjs`), and these
 * two are CodeMirror and Monaco. Both construct the element that takes the
 * keyboard for themselves, after mounting, measuring a layout as they go — so
 * `happy-dom` can only be given a stub, and a stub answers nothing about
 * whether they work.
 *
 * Three questions are left for a browser, and the third is the reason this file
 * exists at all:
 *
 * 1. Do they mount, and does the field's identity land on the element the
 *    keyboard goes to? A label pointing at nothing is the U01 bug again.
 * 2. Does the panel's typography reach the Markdown preview, and the panel's
 *    theme reach both editors when `<html>` goes dark?
 * 3. **Do they fetch anything over the network?** `md-editor-v3` pulls
 *    highlight.js, Prettier, KaTeX and more from unpkg on demand, and
 *    `@guolao/vue-monaco-editor` downloads Monaco itself from a CDN unless it
 *    is handed an instance. Both defaults are off. "Off" is a claim about what
 *    a browser does, and this is the only place a browser is present to
 *    contradict it.
 *
 *     node tests/browser/editors.mjs [--build] [--json]
 */

import { buildFixture, reporter, sleep, withPage } from './chrome.mjs';

const report = reporter('E1 — Markdown and code editors');
const json = {};

/**
 * Monaco's hidden input, whichever of the two it built.
 *
 * Where the browser supports `EditContext` it is a `div.native-edit-context`;
 * everywhere else it is the `textarea.inputarea` Monaco has always used. The
 * field writes its identity onto whichever exists, and a check that knew about
 * only one of them would pass on one engine and fail on another.
 */
const MONACO_INPUT = '.native-edit-context, textarea.inputarea';

/**
 * Everything the page has loaded that did not come from the page's own origin.
 *
 * `performance.getEntriesByType('resource')` rather than CDP's network domain,
 * because the page object here is deliberately small — see `chrome.mjs`, where
 * widening it is called out as the thing that would turn this into a runner.
 * A resource timing entry exists for every fetch a document makes, including
 * the ones a library injects as a `<script>` or a `<link>`, which is exactly
 * the shape both of these libraries use.
 */
const FOREIGN_RESOURCES = `(() => {
    const origin = window.location.origin;

    return performance
        .getEntriesByType('resource')
        .map((entry) => entry.name)
        .filter((name) => !name.startsWith(origin) && !name.startsWith('data:') && !name.startsWith('blob:'));
})()`;

async function main() {
    await buildFixture({ force: process.argv.includes('--build') });

    await withPage(async (page) => {
        await page.go('editors');

        // Monaco arrives through a dynamic import and then lays itself out.
        // Everything below reads the settled state, so it is waited for once
        // here rather than in each check.
        await sleep(1200);

        /* ================================================================
         * The Markdown editor
         * ============================================================= */

        const markdown = await page.evaluate(`(() => {
            const root = document.querySelector('#markdown');
            const editor = root.querySelector('.md-editor');
            const content = root.querySelector('.cm-content');
            const label = root.querySelector('label');

            return {
                mounted: editor !== null,
                id: content?.getAttribute('id') ?? null,
                role: content?.getAttribute('role') ?? null,
                multiline: content?.getAttribute('aria-multiline') ?? null,
                name: content?.getAttribute('aria-label') ?? null,
                describedBy: content?.getAttribute('aria-describedby') ?? null,
                labelFor: label?.getAttribute('for') ?? null,
                buttons: root.querySelectorAll('.md-editor-toolbar-item').length,
                height: Math.round(editor?.getBoundingClientRect().height ?? 0),
            };
        })()`);

        json.markdown = markdown;

        report.check(
            'E1-1 the Markdown editor mounts and is given a height',
            markdown.mounted && markdown.height > 120,
            `${markdown.height}px tall`,
        );
        report.check(
            'E1-2 the field’s identity is on the element the keyboard goes to',
            markdown.id !== null &&
                markdown.id === markdown.labelFor &&
                markdown.name === 'Body' &&
                (markdown.describedBy ?? '') !== '',
            `id "${markdown.id}", label for "${markdown.labelFor}", ` +
                `name "${markdown.name}", described "${markdown.describedBy}"`,
        );
        report.check(
            'E1-3 CodeMirror still says what it is',
            markdown.role === 'textbox' && markdown.multiline === 'true',
            `role ${markdown.role}, multiline ${markdown.multiline}`,
        );
        report.check(
            'E1-4 the toolbar the schema asked for was drawn',
            markdown.buttons >= 9,
            `${markdown.buttons} buttons`,
        );

        // The preview is the half a unit test cannot see: it is `markdown-it`'s
        // output, coloured by `md-editor-v3`'s own content theme with every one
        // of that theme's custom properties mapped to a panel token. What is
        // measured is the *result* of that mapping, against elements the page
        // styles from the same tokens directly — so this fails if the library's
        // literal `#fff` and `#2d8cf0` are still in effect.
        const preview = await page.evaluate(`(() => {
            const pane = document.querySelector('#markdown .md-editor-preview');

            if (pane === null) {
                return null;
            }

            const read = (selector, properties) => {
                const el = pane.querySelector(selector);

                if (el === null) {
                    return null;
                }

                const style = getComputedStyle(el);
                const out = {};

                for (const property of properties) {
                    out[property] = style.getPropertyValue(property);
                }

                return out;
            };

            const reference = (id, property) =>
                getComputedStyle(document.querySelector(id)).getPropertyValue(property);

            return {
                heading: read('h2', ['color', 'font-size']),
                link: read('a', ['color', 'text-decoration-line']),
                cell: read('td', ['border-top-color', 'border-top-width']),
                // The pane itself paints nothing; the editor around it is what
                // carries the background property, so that is the surface to
                // compare against.
                background: getComputedStyle(
                    document.querySelector('#markdown .md-editor'),
                ).backgroundColor,
                foreground: reference('#plain', 'color'),
                primary: reference('#ref-primary', 'color'),
                border: reference('#ref-border', 'border-top-color'),
                surface: reference('#ref-surface', 'background-color'),
                plainSize: parseFloat(reference('#plain', 'font-size')),
                renderedTable: pane.querySelector('table') !== null,
            };
        })()`);

        json.preview = preview;

        report.check(
            'E1-5 the preview is rendered Markdown, not the characters typed',
            preview !== null &&
                preview.heading !== null &&
                preview.renderedTable,
            preview === null
                ? 'no preview pane'
                : `h2 ${preview.heading !== null}, table ${preview.renderedTable}`,
        );
        report.check(
            'E1-6 its surface and its text are the panel’s tokens, not the library’s hex',
            preview !== null &&
                preview.background === preview.surface &&
                preview.heading?.color === preview.foreground,
            preview === null
                ? 'nothing to measure'
                : `pane ${preview.background} vs surface ${preview.surface}, ` +
                  `h2 ${preview.heading?.color} vs foreground ${preview.foreground}`,
        );
        report.check(
            'E1-7 a link is the panel’s primary, and underlined as well as coloured',
            preview?.link?.color === preview?.primary &&
                preview?.link?.['text-decoration-line'] === 'underline',
            `link ${preview?.link?.color} vs primary ${preview?.primary}, ` +
                `${preview?.link?.['text-decoration-line']}`,
        );
        report.check(
            'E1-7b a table in the preview is bounded by the panel’s border colour',
            preview?.cell !== null &&
                parseFloat(preview?.cell?.['border-top-width'] ?? '0') > 0 &&
                preview?.cell?.['border-top-color'] === preview?.border,
            `td ${preview?.cell?.['border-top-width']} ${preview?.cell?.['border-top-color']} ` +
                `vs border ${preview?.border}`,
        );

        /* ================================================================
         * The code editor
         * ============================================================= */

        const code = await page.evaluate(`(() => {
            const root = document.querySelector('#code');
            const editor = root.querySelector('.monaco-editor');
            const input = root.querySelector(${JSON.stringify(MONACO_INPUT)});
            const label = root.querySelector('label');

            // Distinct token classes: Monaco colours by \`.mtk<n>\`, so more
            // than one of them in a document means a grammar ran.
            const tokens = new Set();

            for (const span of root.querySelectorAll('.view-line span[class^="mtk"]')) {
                tokens.add(span.className);
            }

            return {
                mounted: editor !== null,
                input: input === null ? null : input.tagName.toLowerCase(),
                id: input?.getAttribute('id') ?? null,
                name: input?.getAttribute('aria-label') ?? null,
                describedBy: input?.getAttribute('aria-describedby') ?? null,
                labelFor: label?.getAttribute('for') ?? null,
                tokens: tokens.size,
                gutter: root.querySelector('.margin-view-overlays') !== null,
                fallback: root.querySelector('textarea[data-slot="textarea"]') !== null,
                height: Math.round(editor?.getBoundingClientRect().height ?? 0),
            };
        })()`);

        json.code = code;

        report.check(
            'E1-8 Monaco mounts, which means it was not fetched from a CDN',
            code.mounted && code.height > 100 && !code.fallback,
            `${code.height}px tall, fallback textarea ${code.fallback}`,
        );
        report.check(
            'E1-9 the label points at Monaco’s own input',
            code.input !== null &&
                code.id !== null &&
                code.id === code.labelFor &&
                code.name === 'Settings' &&
                (code.describedBy ?? '') !== '',
            `<${code.input}> id "${code.id}", label for "${code.labelFor}", ` +
                `name "${code.name}", described "${code.describedBy}"`,
        );
        report.check(
            'E1-10 the grammar ran — this is highlighted, not monospaced',
            code.tokens > 1 && code.gutter,
            `${code.tokens} token classes, gutter ${code.gutter}`,
        );

        const invalid = await page.evaluate(`(() => {
            const input = document.querySelector(
                '#code-invalid ' + ${JSON.stringify(MONACO_INPUT)},
            );

            return {
                ariaInvalid: input?.getAttribute('aria-invalid') ?? null,
                describedBy: input?.getAttribute('aria-describedby') ?? null,
            };
        })()`);

        json.invalid = invalid;

        report.check(
            'E1-11 a refused code field says so, and says what was wrong',
            invalid.ariaInvalid === 'true' &&
                (invalid.describedBy ?? '').split(' ').length === 2,
            `aria-invalid ${invalid.ariaInvalid}, described "${invalid.describedBy}"`,
        );

        const typed = await page.evaluate(`(() => {
            document.querySelector('#code ' + ${JSON.stringify(MONACO_INPUT)}).focus();

            return document.activeElement?.className ?? null;
        })()`);

        await page.type('X');

        const afterTyping = await page.evaluate(
            `document.querySelector('#code .view-lines').textContent`,
        );

        report.check(
            'E1-12 real key events reach it',
            (typed ?? '') !== '' && afterTyping.includes('X'),
            `focused "${typed}", first line now "${afterTyping.slice(0, 24)}"`,
        );

        /* ================================================================
         * The theme, and the network
         * ============================================================= */

        await page.evaluate(
            `document.documentElement.classList.add('dark'); true`,
        );
        await sleep(400);

        const dark = await page.evaluate(`(() => {
            const md = document.querySelector('#markdown .md-editor');
            const monaco = document.querySelector('#code .monaco-editor');

            return {
                markdown: md?.getAttribute('data-theme') ?? null,
                monaco: monaco?.className ?? null,
                background: getComputedStyle(monaco).backgroundColor,
            };
        })()`);

        json.dark = dark;

        report.check(
            'E1-13 both editors follow the panel into the dark',
            dark.markdown === 'dark' && (dark.monaco ?? '').includes('vs-dark'),
            `md-editor ${dark.markdown}, monaco "${(dark.monaco ?? '').slice(0, 40)}"`,
        );

        // The wiring, after the theme changed. `md-editor-v3` rebuilds its
        // CodeMirror view for some option changes, and a rebuilt element comes
        // back with none of the field's identity on it — a label pointing at an
        // id that no longer exists, silently, and only for somebody who changed
        // a theme with the form open.
        const survived = await page.evaluate(`(() => {
            const content = document.querySelector('#markdown .cm-content');
            const input = document.querySelector('#code ' + ${JSON.stringify(MONACO_INPUT)});

            return {
                markdown: content?.getAttribute('id') ?? null,
                code: input?.getAttribute('id') ?? null,
                described: content?.getAttribute('aria-describedby') ?? null,
            };
        })()`);

        json.survived = survived;

        report.check(
            'E1-14 the field’s identity survives the theme changing under it',
            survived.markdown === 'body' &&
                survived.code === 'settings' &&
                (survived.described ?? '') !== '',
            `markdown "${survived.markdown}", code "${survived.code}", ` +
                `described "${survived.described}"`,
        );

        await page.evaluate(
            `document.documentElement.classList.remove('dark'); true`,
        );

        const foreign = await page.evaluate(FOREIGN_RESOURCES);

        json.foreign = foreign;

        report.check(
            'E1-15 nothing was loaded from anywhere but this origin',
            foreign.length === 0,
            foreign.length === 0
                ? 'no third-party request'
                : foreign.slice(0, 3).join(', '),
        );
    });
}

await main();

if (process.argv.includes('--json')) {
    console.log(JSON.stringify(json, null, 2));
}

report.finish();
