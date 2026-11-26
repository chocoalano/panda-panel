/**
 * @vitest-environment happy-dom
 */
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

/**
 * The integrations page saves without `FormRenderer`, so it has the same two
 * things to get right on its own: keep the draft when the server refuses it,
 * and say why. It did neither.
 */
const visit = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    router: {
        visit: (...args: unknown[]) => visit(...args),
        post: vi.fn(),
        on: () => () => {},
    },
    usePage: () => ({ props: {}, url: '/', component: '', version: null }),
    Head: { name: 'Head', render: () => null },
    Link: { name: 'Link', template: '<a><slot /></a>' },
}));

// The shell is not under test, and it pulls in the broadcasting client.
vi.mock('@/panel/layouts/PanelLayout.vue', () => ({
    default: { name: 'PanelLayout', render: () => null },
}));

const { default: Integrations } =
    await import('@/pages/panel/resources/Integrations.vue');

const mounted: Array<{ unmount: () => void }> = [];

afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
    document.body.innerHTML = '';
    visit.mockClear();
});

type Options = {
    preserveState?: unknown;
    onError?: (errors: Record<string, string>) => void;
    onFinish?: () => void;
};

function render() {
    const wrapper = mount(Integrations, {
        attachTo: document.body,
        props: {
            page: { title: 'Integrations', heading: 'Integrations' } as never,
            resource: {
                slug: 'users',
                label: 'User',
                pluralLabel: 'Users',
                indexUrl: '/users',
            },
            triggers: [{ value: 'after_create', label: 'After create' }],
            integrations: [],
            endpoints: {
                store: '/store',
                update: '/update/__id__',
                rotate: '/rotate/__id__',
                destroy: '/destroy/__id__',
                send: '/send/__id__',
            },
            allowedHosts: ['example.com'],
        },
        global: { stubs: { PanelBreadcrumb: true } },
    });

    mounted.push(wrapper);

    return wrapper;
}

async function save(wrapper: ReturnType<typeof render>): Promise<Options> {
    const button = wrapper
        .findAll('button')
        // No dictionary here, so the key reads as its own words.
        .find((candidate) => candidate.text() === 'Save');

    expect(button, 'no save button').toBeDefined();

    await button!.trigger('click');
    await flushPromises();

    return visit.mock.calls.at(-1)![1] as Options;
}

describe('a refused integration', () => {
    it('is saved so that a refusal keeps the draft', async () => {
        const options = await save(render());

        expect(options.preserveState).toBe('errors');
    });

    it('says what was refused, and stops saying so on the next save', async () => {
        const wrapper = render();
        const options = await save(wrapper);

        options.onError?.({ url: 'The url field is required.' });
        // Inertia always finishes a visit, which is what frees the button.
        options.onFinish?.();
        await flushPromises();

        expect(wrapper.find('div[role="alert"]').text()).toContain(
            'The url field is required.',
        );

        await save(wrapper);

        expect(visit).toHaveBeenCalledTimes(2);
        expect(wrapper.text()).not.toContain('The url field is required.');
    });
});
