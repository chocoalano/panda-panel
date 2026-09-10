import { defineComponent, h, reactive } from 'vue';
import { dictionaries, translations } from './translations';
import type { PanelLocale } from './translations';
import type { PropType } from 'vue';

/**
 * A stand-in for `@inertiajs/vue3`, for the browser fixture only.
 *
 * The fixture mounts the real `DataTable` in a real browser to measure real
 * layout. What it must not do is stand up an Inertia application to get
 * there: `usePage()` reads a page object a server response created, and a
 * fixture that needed one would be a fixture about Inertia rather than about
 * frozen columns.
 *
 * Aliased in `frontend/browser/vite.config.ts` and nowhere else. The
 * published components import the real package, type-check against the real
 * package, and are compiled against it by every build but this one.
 */

/** The shared props the panel's own accessors read. Empty is a valid panel. */
const page = reactive({
    component: 'browser-fixture',
    url: '/',
    version: null as string | null,
    props: {
        panel: null,
        navigation: [],
        panels: [],
        // The real English strings, copied from `lang/en/frontend.php` at
        // build time by `frontend/browser/translations.ts`. A fixture with no
        // dictionary falls back to a humanised key — "Remove item" for every
        // entry in a repeater — and a browser check that looked for a control
        // by name would be looking for the wrong name.
        translations,
        locale: 'en',
    } as Record<string, unknown>,
});

export function usePage(): typeof page {
    return page;
}

/**
 * Switches the panel's language the way the panel actually switches it.
 *
 * `useTranslator()` reads `translations` and `locale` off the page props, so
 * changing them here is the same event a real Inertia navigation produces —
 * not a test-only pathway around the mechanism. The page object is reactive,
 * so every mounted component re-renders without a reload.
 *
 * Exposed on `window` for the browser checks; nothing in the published
 * package imports it.
 */
export function setPanelLocale(locale: PanelLocale): void {
    page.props.translations = dictionaries[locale];
    page.props.locale = locale;
}

export const Link = defineComponent({
    name: 'InertiaLinkStub',
    props: {
        href: { type: String as PropType<string>, default: '#' },
    },
    setup(props, { slots }) {
        return () => h('a', { href: props.href }, slots.default?.());
    },
});

/** Every method the panel's components call, doing the honest nothing. */
export const router = {
    visit(): void {},
    get(): void {},
    post(): void {},
    put(): void {},
    patch(): void {},
    delete(): void {},
    reload(): void {},
    on(): () => void {
        return () => {};
    },
};
