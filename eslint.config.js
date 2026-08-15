import js from '@eslint/js';
import prettier from 'eslint-config-prettier';
import vue from 'eslint-plugin-vue';
import globals from 'globals';
import ts from 'typescript-eslint';

/**
 * What the linter is for here, given there is also a type-checker.
 *
 * `vue-tsc` already answers every question about types, so nothing below
 * re-states one. What is left is the class of mistake that type-checks
 * perfectly and is still wrong: a `<template>` that misuses `v-if` with
 * `v-for`, a component registered but never rendered, a `defineProps` whose
 * default silently mutates a shared object.
 *
 * `eslint-config-prettier` comes last and turns off every stylistic rule, on
 * purpose: formatting is Prettier's job and a rule that both tools have an
 * opinion about is a rule that fights itself in CI.
 */
export default ts.config(
    {
        ignores: [
            'build/**',
            'node_modules/**',
            'vendor/**',
            'examples/**',
            'bootstrap/**',
        ],
    },

    js.configs.recommended,
    ...ts.configs.recommended,
    ...vue.configs['flat/recommended'],

    {
        files: ['**/*.{ts,vue}'],
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            globals: {
                ...globals.browser,
                ...globals.es2021,
            },
            parserOptions: {
                parser: ts.parser,
                extraFileExtensions: ['.vue'],
            },
        },
        rules: {
            // The panel's components are `PanelSidebar`, `DataTable`,
            // `ActionModal`. Single-word names are the framework's to use, not
            // an application's, and this rule cannot tell the difference.
            'vue/multi-word-component-names': 'off',

            // Deliberate throughout: a prop the server sent is data, and
            // copying every one into local state to satisfy a lint rule is how
            // a form ends up with two ideas of what its value is.
            'vue/no-mutating-props': 'error',

            // An unused argument prefixed `_` is a signature being honoured —
            // a callback that takes three parameters and needs the third.
            '@typescript-eslint/no-unused-vars': [
                'error',
                {
                    argsIgnorePattern: '^_',
                    varsIgnorePattern: '^_',
                    caughtErrorsIgnorePattern: '^_',
                },
            ],

            // Payloads cross from the server as untyped JSON and are narrowed
            // by hand — see any `toPayload()` in this tree. `unknown` is the
            // input to that; `any` would be skipping it.
            '@typescript-eslint/no-explicit-any': 'error',

            // One use, in the Markdown editor's preview, and it is safe by
            // construction rather than by review: `renderMarkdown()` escapes
            // every character *before* adding a single tag, so by the time
            // markup exists there is no author input left that could become
            // it. See `panel/forms/markdown.ts`, which exists to make that
            // ordering the module's whole contract.
            //
            // Off globally rather than disabled at the site: the rule reports
            // on the attribute, and a `<!-- eslint-disable-next-line -->` in a
            // multi-line tag cannot sit on the line before it.
            'vue/no-v-html': 'off',

            // Written for the options API, where an absent prop and a prop
            // explicitly passed `undefined` are indistinguishable. With
            // `defineProps<Props>()` they are not, and `class?: string` with
            // no default is the correct way to say "no class unless given
            // one" — a default of `''` would put an empty attribute on every
            // element instead.
            'vue/require-default-prop': 'off',
        },
    },

    {
        // The stand-ins exist to be minimal. Empty components with no logic
        // are the point, not an oversight.
        files: ['frontend/host/**/*.vue'],
        rules: {
            'vue/no-empty-component-block': 'off',
        },
    },

    {
        // Vendored from shadcn-vue, and deliberately left as upstream wrote
        // it: these are the files an application is most likely to re-pull
        // from shadcn-vue directly, and a house rule applied here would turn
        // every upstream update into a diff about style.
        //
        // Prettier skips this directory for the same reason — see
        // `.prettierignore`. They are still type-checked and still built,
        // which is what actually catches a breakage in them.
        files: ['resources/js/components/ui/**'],
        rules: {
            '@typescript-eslint/no-explicit-any': 'off',
            'vue/require-default-prop': 'off',
            'vue/attributes-order': 'off',
            'vue/attribute-hyphenation': 'off',
        },
    },

    prettier,
);
