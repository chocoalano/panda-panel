import { defineComponent, h, reactive } from 'vue';
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
        translations: {},
    } as Record<string, unknown>,
});

export function usePage(): typeof page {
    return page;
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
