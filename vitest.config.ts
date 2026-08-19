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
 * Components stay out. Their coverage is the request tests, which assert the
 * payload a component is handed, plus `FrontendContractTest`, which asserts
 * the file-level promises a build cannot make.
 */
export default defineConfig({
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
