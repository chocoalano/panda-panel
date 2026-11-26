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
 * What a form must not do with what it cannot send.
 *
 * Some controls hold something the form's values do not: a date somebody typed
 * that is not a date, a file still on its way to the server. Both leave the
 * value null, and an optional field accepts null — so submitting then saved
 * "nothing" over what the reader could see on screen, without a word.
 */
const visit = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    router: {
        visit: (...args: unknown[]) => visit(...args),
        post: vi.fn(),
        reload: vi.fn(),
        on: () => () => {},
    },
    usePage: () => ({ props: {}, url: '/', component: '', version: null }),
    Link: { name: 'Link', template: '<a><slot /></a>' },
}));

const { default: FormRenderer } =
    await import('@/panel/forms/FormRenderer.vue');

const mounted: Array<{ unmount: () => void }> = [];

beforeEach(() => {
    visit.mockClear();
});

afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
    document.body.innerHTML = '';
    vi.unstubAllGlobals();
});

function field(overrides: Record<string, unknown> = {}): FieldDefinition {
    return {
        component: 'field',
        name: 'name',
        label: 'Name',
        type: 'text',
        inputType: 'text',
        maxLength: null,
        value: '',
        placeholder: null,
        helperText: null,
        required: false,
        disabled: false,
        inlineLabel: false,
        columnSpan: 'full',
        conditions: { visibleWhen: [], hiddenWhen: [] },
        live: null,
        validation: { required: false },
        ...overrides,
    } as unknown as FieldDefinition;
}

function fileField(): FieldDefinition {
    return field({
        name: 'evidence',
        label: 'Evidence',
        type: 'file_upload',
        value: null,
        multiple: false,
        maxSize: 1024,
        maxFiles: null,
        acceptedTypes: [],
        image: false,
        previewBase: null,
    });
}

function render(
    schema: FormComponentDefinition[],
    props: Record<string, unknown> = {},
) {
    const wrapper = mount(FormRenderer, {
        attachTo: document.body,
        props: {
            form: { columns: 1, schema },
            submitUrl: '/save',
            context: {},
            ...props,
        },
        global: { stubs: { Spinner: true } },
    });

    mounted.push(wrapper);

    return wrapper;
}

type Wrapper = ReturnType<typeof render>;

async function submit(wrapper: Wrapper): Promise<void> {
    await wrapper.get('form').trigger('submit');
    await flushPromises();
}

/**
 * The form's own account of a refusal. A `div`: every field also carries an
 * empty `<p role="alert">` for its message, whether it has one or not.
 */
function summary(wrapper: Wrapper) {
    return wrapper.find('div[role="alert"]');
}

function sent(): Record<string, unknown> {
    expect(visit).toHaveBeenCalledTimes(1);

    return (visit.mock.calls[0]![1] as { data: Record<string, unknown> }).data;
}

describe('a fresh form from the server', () => {
    it('replaces what was typed, as after "create another"', async () => {
        const wrapper = render([field()]);

        await wrapper.get('input').setValue('Already saved');
        await wrapper.setProps({
            form: { columns: 1, schema: [field({ value: '' })] },
        });

        expect((wrapper.get('input').element as HTMLInputElement).value).toBe(
            '',
        );

        await submit(wrapper);

        expect(sent().name).toBe('');
    });

    // The picker keeps its own draft, which a value that did not change would
    // never overwrite.
    it('clears what a control was holding that never became a value', async () => {
        const date = () =>
            field({
                type: 'date',
                name: 'date',
                value: null,
                minDate: null,
                maxDate: null,
            });
        const wrapper = render([date()]);

        await wrapper.get('input').setValue('2026-02-30');
        await wrapper.setProps({ form: { columns: 1, schema: [date()] } });
        await flushPromises();

        expect((wrapper.get('input').element as HTMLInputElement).value).toBe(
            '',
        );

        await submit(wrapper);

        expect(visit).toHaveBeenCalledTimes(1);
    });
});

describe('a control holding something that is not a value', () => {
    const cases = [
        [
            'a date',
            { type: 'date', minDate: null, maxDate: null },
            0,
            '2026-02-30',
            '2026-02-28',
            'Invalid date',
        ],
        [
            'a time',
            { type: 'time', seconds: false, value: null },
            0,
            '25:00',
            '23:00',
            'Invalid time',
        ],
        [
            'the date half of a datetime',
            { type: 'datetime', seconds: false, minDate: null, maxDate: null },
            0,
            '2026-02-30',
            '2026-02-28',
            'Invalid date',
        ],
        [
            'the time half of a datetime',
            { type: 'datetime', seconds: false, minDate: null, maxDate: null },
            1,
            '25:00',
            '23:00',
            'Invalid time',
        ],
    ] as const;

    it.each(cases)(
        'refuses to submit %s that is not one, until it is corrected',
        async (_, shape, input, bad, good, message) => {
            const wrapper = render([
                field({ name: 'when', value: null, ...shape }),
            ]);
            const control = () => wrapper.findAll('input')[input]!;

            await control().setValue(bad);
            expect(control().attributes('aria-invalid')).toBe('true');

            await submit(wrapper);

            expect(visit).not.toHaveBeenCalled();
            expect(summary(wrapper).exists()).toBe(true);
            // No dictionary here, so the key reads as its own words.
            expect(wrapper.text()).toContain(message);

            await control().setValue(good);
            await submit(wrapper);

            expect(visit).toHaveBeenCalledTimes(1);
        },
    );
});

describe('a file still uploading', () => {
    function upload() {
        let finish: (response: unknown) => void = () => {};

        vi.stubGlobal(
            'fetch',
            vi.fn(() => new Promise((resolve) => (finish = resolve))),
        );

        const wrapper = render([fileField()], { uploadUrl: '/upload' });

        async function pick(): Promise<void> {
            const input = wrapper.get('input[type="file"]');

            Object.defineProperty(input.element, 'files', {
                configurable: true,
                value: [new File(['x'], 'a.txt')],
            });
            await input.trigger('change');
        }

        async function answer(response: unknown): Promise<void> {
            finish(response);
            await flushPromises();
        }

        return { wrapper, pick, answer };
    }

    it('holds the submit', async () => {
        const { wrapper, pick } = upload();

        await pick();
        await submit(wrapper);

        expect(visit).not.toHaveBeenCalled();
        expect(summary(wrapper).exists()).toBe(true);
        expect(wrapper.text()).toContain('Upload in progress');
    });

    it('lets it go, with the file, once the upload lands', async () => {
        const { wrapper, pick, answer } = upload();

        await pick();
        await submit(wrapper);
        await answer({
            ok: true,
            json: async () => ({ path: 'evidence/a.txt', name: 'a.txt' }),
        });

        expect(summary(wrapper).exists()).toBe(false);

        await submit(wrapper);

        expect(sent().evidence).toBe('evidence/a.txt');
    });

    it('stops holding it, and stops saying so, when the upload fails', async () => {
        const { wrapper, pick, answer } = upload();

        await pick();
        await submit(wrapper);
        await answer({ ok: false, json: async () => ({}) });

        expect(summary(wrapper).exists()).toBe(false);

        await submit(wrapper);

        expect(visit).toHaveBeenCalledTimes(1);
    });
});
