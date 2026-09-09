import vue from '@vitejs/plugin-vue';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

/**
 * Unit tests for the frontend's pure logic.
 *
 * Deliberately not a browser test runner — ADR 001 records that decision and
 * it stands. What this covers is the modules that are ordinary functions:
 * how a card face resolves against a column arrangement, how a filter value
 * becomes a query string, where a group of rows breaks. Every one of them
 * decides something the server cannot check and the type system cannot state,
 * and every one was previously covered by reading it.
 *
 * Most components stay out. Their coverage is the request tests, which assert
 * the payload a component is handed, plus `FrontendContractTest`, which
 * asserts the file-level promises a build cannot make.
 *
 * The exception is the action layer. Which branch a relation action takes —
 * open a form, or run immediately — is a decision made entirely in the
 * browser, and the one time it was wrong the action ran with no dialog and no
 * values while every server-side test stayed green. A payload assertion
 * cannot see that: the bug was that the request was never made at all.
 *
 * So those files mount, in `happy-dom`, opted into per file rather than
 * globally — the rest of the suite is pure functions and should not pay for a
 * DOM it never touches. This is not a browser test runner and ADR 001 still
 * stands: nothing here drives a browser, and `tests/browser` remains the only
 * place a layout engine is asked anything.
 */
export default defineConfig({
    // Compiles the `.vue` files the action tests mount. Inert for every test
    // that imports none.
    plugins: [vue()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        include: ['resources/js/**/*.test.ts'],
        environment: 'node',
    },
});
