/**
 * @vitest-environment happy-dom
 */
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type {
    FieldDefinition,
    FormComponentDefinition,
} from '@/panel/types/form';

/**
 * What a searchable Select says when the list cannot be fetched.
 *
 * It said nothing. `run()` returned without touching the options when the
 * request failed, and the comment explained why — an empty list would read as
 * "nothing matches", which is a different and wrong answer. That reasoning is
 * right and the outcome was still wrong: a search for "bob" that the server
 * refused left Alice and Alina on screen, settled and unlabelled, and somebody
 * could pick one believing it matched what they typed.
 */
const { fetchOptions } = vi.hoisted(() => ({
    fetchOptions: vi.fn<(...args: unknown[]) => Promise<unknown>>(),
}));

vi.mock('@/panel/forms/optionsEndpoint', async (original) => ({
    ...(await original<Record<string, unknown>>()),
    fetchOptions: (...args: unknown[]) => fetchOptions(...args),
}));

vi.mock('@inertiajs/vue3', () => ({
    router: {
        visit: vi.fn(),
        post: vi.fn(),
        reload: vi.fn(),
        on: () => () => {},
    },
    usePage: () => ({
        props: {
            translations: {
                forms: {
                    select_placeholder: 'Select...',
                    select_empty: 'Nothing to choose from.',
                    select_no_matches: 'Nothing matches that search.',
                    select_failed: 'The list could not be loaded.',
                    select_stale:
                        'Showing earlier results — the update failed.',
                    search_field: 'Search :field',
                    retry: 'Try again',
                },
            },
        },
        url: '/',
        component: '',
        version: null,
    }),
    Link: { name: 'Link', template: '<a><slot /></a>' },
}));

const { default: FormRenderer } =
    await import('@/panel/forms/FormRenderer.vue');

const mounted: Array<{ unmount: () => void }> = [];

beforeEach(() => {
    fetchOptions.mockReset();
});

afterEach(() => {
    while (mounted.length > 0) {
        mounted.pop()?.unmount();
    }

    document.body.innerHTML = '';
});

function select(overrides: Record<string, unknown> = {}): FieldDefinition {
    return {
        component: 'field',
        name: 'owner',
        label: 'Owner',
        type: 'select',
        value: null,
        placeholder: null,
        helperText: null,
        required: false,
        disabled: false,
        inlineLabel: false,
        columnSpan: 'full',
        conditions: { visibleWhen: [], hiddenWhen: [] },
        live: null,
        validation: { required: false },
        multiple: true,
        searchable: true,
        dependentOptions: false,
        options: [
            { value: 'alice', label: 'Alice' },
            { value: 'alina', label: 'Alina' },
        ],
        ...overrides,
    } as unknown as FieldDefinition;
}

function render(field: FieldDefinition, props: Record<string, unknown> = {}) {
    const wrapper = mount(FormRenderer, {
        attachTo: document.body,
        props: {
            form: {
                schema: [field as unknown as FormComponentDefinition],
                columns: 1,
            },
            submitUrl: '/panel/things',
            optionsUrl: '/panel/options',
            ...props,
        },
    });

    mounted.push(wrapper);

    return wrapper;
}

async function type(wrapper: ReturnType<typeof render>, term: string) {
    await wrapper.get('input[type="search"]').setValue(term);
    await flushPromises();
    await new Promise((resolve) => setTimeout(resolve, 300));
    await flushPromises();
}

function labels(wrapper: ReturnType<typeof render>): string[] {
    return wrapper
        .findAll('label')
        .map((label) => label.text().trim())
        .filter((text) => text !== 'Owner');
}

/*
 * T26 / T27 / T28 / T29 / T30 / T31
 */

describe('a searchable select', () => {
    it('says it is working while it asks', async () => {
        let settle: (value: unknown) => void = () => {};

        fetchOptions.mockImplementation(
            () => new Promise((resolve) => (settle = resolve)),
        );

        const wrapper = render(select());

        await type(wrapper, 'bo');

        expect(
            wrapper.get('input[type="search"]').attributes('aria-busy'),
        ).toBe('true');

        settle([{ value: 'bob', label: 'Bob' }]);
        await flushPromises();

        expect(
            wrapper.get('input[type="search"]').attributes('aria-busy'),
        ).toBeUndefined();
    });

    it('separates a search that matched nothing from one that failed', async () => {
        fetchOptions.mockResolvedValue([]);

        const wrapper = render(select());

        await type(wrapper, 'zz');

        expect(wrapper.text()).toContain('Nothing matches that search.');
        expect(wrapper.text()).not.toContain('could not be loaded');
    });

    it("does not present an earlier query's options as this one's", async () => {
        fetchOptions.mockResolvedValueOnce([
            { value: 'alice', label: 'Alice' },
            { value: 'alina', label: 'Alina' },
        ]);

        const wrapper = render(select());

        await type(wrapper, 'ali');

        expect(labels(wrapper)).toEqual(['Alice', 'Alina']);

        fetchOptions.mockResolvedValueOnce(null);

        await type(wrapper, 'bob');

        // Alice and Alina are still readable — blanking the list would read as
        // "nothing matches bob", which is a claim nobody made — but the field
        // now says they are not the answer to this query.
        expect(labels(wrapper)).toEqual(['Alice', 'Alina']);
        expect(wrapper.text()).toContain('Showing earlier results');
    });

    it('offers a retry that asks for the same term', async () => {
        fetchOptions.mockResolvedValueOnce(null);

        const wrapper = render(select());

        await type(wrapper, 'bob');

        expect(wrapper.text()).toContain('The list could not be loaded.');

        fetchOptions.mockResolvedValueOnce([{ value: 'bob', label: 'Bob' }]);

        const retry = wrapper
            .findAll('button')
            .filter((button) => button.text() === 'Try again')[0];

        await retry.trigger('click');
        await flushPromises();

        expect(fetchOptions.mock.calls.at(-1)?.[2]).toBe('bob');
        expect(labels(wrapper)).toContain('Bob');
    });

    it('keeps a chosen value when the list fails to load', async () => {
        fetchOptions.mockResolvedValueOnce(null);

        const wrapper = render(select({ value: ['alice'] }));

        await type(wrapper, 'bob');

        // A network failure must not quietly unpick what the user already
        // chose: the selection and the option list are different things.
        const checked = wrapper
            .findAll('[role="checkbox"]')
            .filter((box) => box.attributes('aria-checked') === 'true');

        expect(checked).toHaveLength(1);
        // And the chosen option stays in the list whatever the search did, or
        // the control would lose its own label.
        expect(labels(wrapper)).toContain('Alice');
    });

    it('retries a dependent search against the current form state', async () => {
        fetchOptions.mockResolvedValueOnce(null);

        const wrapper = render({
            component: 'grid',
            columns: 1,
            schema: [
                {
                    component: 'field',
                    name: 'team',
                    label: 'Team',
                    type: 'text',
                    inputType: 'text',
                    maxLength: null,
                    value: 'blue',
                    placeholder: null,
                    helperText: null,
                    required: false,
                    disabled: false,
                    inlineLabel: false,
                    columnSpan: 'full',
                    conditions: { visibleWhen: [], hiddenWhen: [] },
                    live: null,
                    validation: { required: false },
                },
                select({ dependentOptions: true }),
            ],
        } as unknown as FieldDefinition);

        await type(wrapper, 'bob');

        fetchOptions.mockResolvedValueOnce([{ value: 'bob', label: 'Bob' }]);

        // The parent changed between the failure and the retry. The retry must
        // ask with what the form holds now, not with what it held when the
        // first attempt was made — this is the PP-20 dependent-select contract.
        await wrapper.get('input[type="text"]').setValue('red');
        await flushPromises();

        const retry = wrapper
            .findAll('button')
            .filter((button) => button.text() === 'Try again')[0];

        await retry.trigger('click');
        await flushPromises();

        expect(fetchOptions.mock.calls.at(-1)?.[3]).toMatchObject({
            team: 'red',
        });
    });
});
