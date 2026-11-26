/**
 * @vitest-environment happy-dom
 */
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { ActionDefinition } from '@/panel/types/action';

/**
 * Which form a dialog shows when two requests for one are in the air.
 *
 * A dialog fetches its form as it opens. Open one, close it, open another, and
 * nothing promises the answers arrive in the order they were asked for — an
 * older one landing last used to replace the newer, so the reader filled in
 * one action's form and submitted it to another's URL.
 */
vi.mock('@inertiajs/vue3', () => ({
    router: {
        post: vi.fn(),
        visit: vi.fn(),
        reload: vi.fn(),
        on: () => () => {},
    },
    usePage: () => ({ props: {}, url: '/', component: '', version: null }),
    Link: { name: 'Link', template: '<a><slot /></a>' },
}));

const { default: ActionModal } =
    await import('@/panel/actions/ActionModal.vue');
const { default: RelationFormDialog } =
    await import('@/panel/relations/RelationFormDialog.vue');

const ACTION: ActionDefinition = {
    name: 'approve',
    label: 'Approve',
    icon: null,
    variant: 'default',
    type: 'callback',
    url: null,
    formUrl: null,
    hasForm: false,
    modal: null,
    modalActions: [],
    confirmation: null,
};

const replies: Array<(value: unknown) => void> = [];
const mounted: Array<{ unmount: () => void }> = [];

beforeEach(() => {
    replies.length = 0;
    vi.stubGlobal(
        'fetch',
        vi.fn(() => new Promise((resolve) => replies.push(resolve))),
    );
});

afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
    document.body.innerHTML = '';
    vi.unstubAllGlobals();
});

function payload(key: string) {
    return {
        ok: true,
        json: async () => ({
            title: `Form ${key}`,
            submitLabel: 'Save',
            form: { columns: 1, schema: [] },
            submitUrl: `/submit-${key}`,
            method: 'post',
            context: {},
        }),
    };
}

const dialogs = [
    [
        'the action dialog',
        () =>
            mount(ActionModal, {
                props: {
                    action: ACTION,
                    processing: false,
                    formUrl: null,
                    context: {},
                },
                global: { stubs: { Spinner: true } },
            }),
    ],
    [
        'the relation dialog',
        () =>
            mount(RelationFormDialog, {
                props: { formUrl: null },
                global: { stubs: { Spinner: true } },
            }),
    ],
] as const;

describe.each(dialogs)('%s', (_, render) => {
    async function open() {
        const wrapper = render();

        mounted.push(wrapper);
        await flushPromises();

        return wrapper;
    }

    function submitUrl(
        wrapper: Awaited<ReturnType<typeof open>>,
    ): string | undefined {
        const renderer = wrapper.findComponent({ name: 'FormRenderer' });

        return renderer.exists()
            ? (renderer.props('submitUrl') as string)
            : undefined;
    }

    it('keeps the newest form when an older answer arrives last', async () => {
        const wrapper = await open();

        await wrapper.setProps({ formUrl: '/form-a' });
        await wrapper.setProps({ formUrl: '/form-b' });

        replies[1]!(payload('b'));
        await flushPromises();
        replies[0]!(payload('a'));
        await flushPromises();

        expect(submitUrl(wrapper)).toBe('/submit-b');
    });

    it('does not show an older form while the newer one is loading', async () => {
        const wrapper = await open();

        await wrapper.setProps({ formUrl: '/form-a' });
        await wrapper.setProps({ formUrl: '/form-b' });

        replies[0]!(payload('a'));
        await flushPromises();

        expect(submitUrl(wrapper)).toBeUndefined();
    });
});

// The dialog stays mounted between actions, so what a late answer leaves
// behind is read by whatever opens next.
describe('the action dialog, once the form is no longer wanted', () => {
    it('does not take its title from an answer that arrives afterwards', async () => {
        const wrapper = mount(ActionModal, {
            props: {
                action: ACTION,
                processing: false,
                formUrl: null,
                context: {},
            },
            global: { stubs: { Spinner: true } },
        });

        mounted.push(wrapper);
        await wrapper.setProps({ formUrl: '/form-a' });
        await wrapper.setProps({ formUrl: null });

        replies[0]!(payload('a'));
        await flushPromises();

        expect(document.body.textContent).toContain('Approve');
        expect(document.body.textContent).not.toContain('Form a');
    });
});
