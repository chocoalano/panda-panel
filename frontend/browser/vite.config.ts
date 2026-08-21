import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';

/**
 * The browser fixture's own build.
 *
 * Separate from the package build, which compiles every file and mounts
 * none. This one produces a page: the real `DataTable`, the real stylesheet,
 * and a stand-in for `@inertiajs/vue3` so that mounting it does not require
 * an Inertia application — see `inertia.ts`.
 */
const root = dirname(fileURLToPath(import.meta.url));
const packageRoot = resolve(root, '../..');

export default defineConfig({
    root,
    base: './',
    plugins: [vue(), tailwindcss()],
    resolve: {
        alias: [
            {
                find: '@inertiajs/vue3',
                replacement: resolve(root, 'inertia.ts'),
            },
            { find: '@', replacement: resolve(packageRoot, 'resources/js') },
        ],
    },
    build: {
        outDir: resolve(packageRoot, 'build/browser'),
        emptyOutDir: true,
        minify: false,
    },
});
