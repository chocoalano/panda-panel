import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import { statSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';
import type { Plugin } from 'vite';

/**
 * The package's own build — a compile check, not a deliverable.
 *
 * Nothing this produces is shipped. The panel's frontend reaches an
 * application through `vendor:publish`, is built by that application's Vite,
 * and is bundled with that application's entrypoints. What this build is for
 * is the question no amount of type-checking answers: *does every one of
 * these 337 files actually resolve and compile together?*
 *
 * So the entry is generated rather than authored — see `frontend/entry.ts`,
 * which globs the whole tree. An entry that named a few components by hand
 * would compile a few components by hand.
 */
const root = dirname(fileURLToPath(import.meta.url));

const SOURCE_ROOTS = ['resources/js', 'frontend/host'] as const;

const EXTENSIONS = ['', '.ts', '.vue', '/index.ts', '/index.vue'] as const;

/**
 * Whether a candidate is a file, rather than a directory that happens to have
 * the name.
 *
 * The empty extension is tried first so that `@/lib/utils.ts` resolves as
 * written — but `@/components/ui/button` is a *directory* with that exact
 * path, and the specifier means its `index.ts`. Answering "yes, that exists"
 * for a directory is how a barrel import ends up being read as a file.
 */
function isFile(path: string): boolean {
    try {
        return statSync(path).isFile();
    } catch {
        return false;
    }
}

/**
 * `@/x` means `resources/js/x`, falling through to `frontend/host/x`.
 *
 * A plain Vite alias cannot express the fall-through — an alias is one
 * mapping — and two aliases would have the second shadowed by the first for
 * every path. This is the same two-step `tsconfig.json` gives TypeScript
 * through an ordered `paths` array, so the bundler and the type-checker
 * always agree about what a module specifier points at.
 */
function hostSeam(): Plugin {
    return {
        name: 'panda-panel:host-seam',
        enforce: 'pre',
        resolveId(source) {
            if (!source.startsWith('@/')) {
                return null;
            }

            const relative = source.slice(2);

            for (const base of SOURCE_ROOTS) {
                for (const extension of EXTENSIONS) {
                    const candidate = resolve(root, base, `${relative}${extension}`);

                    if (isFile(candidate)) {
                        return candidate;
                    }
                }
            }

            return null;
        },
    };
}

export default defineConfig({
    plugins: [hostSeam(), vue(), tailwindcss()],
    build: {
        // Under `build/`, beside the PHP toolchain's own scratch output, and
        // ignored for the same reason: a checkout needs no directories that
        // git cannot track, and `rm -rf build` is a complete clean.
        outDir: 'build/frontend',
        emptyOutDir: true,
        // Nothing consumes this bundle, so a minifier pass is a minute spent
        // making an artefact smaller that is about to be deleted.
        minify: false,
        rollupOptions: {
            input: resolve(root, 'frontend/entry.ts'),
        },
    },
});
