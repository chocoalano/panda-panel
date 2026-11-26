/**
 * @vitest-environment happy-dom
 */
/* eslint-disable vue/one-component-per-file -- a page and the app that hosts it */
import { createInertiaApp } from '@inertiajs/vue3';
import { flushPromises } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { createApp, defineComponent, h, onMounted } from 'vue';
import type { App, DefineComponent } from 'vue';
import FormRenderer from '@/panel/forms/FormRenderer.vue';
import type { FormDefinition } from '@/panel/types/form';

/**
 * A submit the server refuses, with Inertia itself doing the visiting.
 *
 * Every other form test stands a function in for the router, which is how this
 * went unseen: what a refusal does to the page is Inertia's decision, taken
 * from the options the visit was made with. A visit that says nothing gets a
 * new page component — so the form that was told what was wrong had already
 * been thrown away, along with everything typed into it.
 *
 * Only the network is replaced.
 */
function form(value = ''): FormDefinition {
    return {
        columns: 1,
        schema: [
            {
                component: 'field',
                name: 'name',
                label: 'Name',
                type: 'text',
                inputType: 'text',
                maxLength: null,
                value,
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
        ],
    } as unknown as FormDefinition;
}

let mounts = 0;

const Page = defineComponent({
    props: { form: { type: Object, required: true } },
    setup(props) {
        onMounted(() => mounts++);

        return () =>
            h(FormRenderer, {
                form: props.form as FormDefinition,
                submitUrl: '/records',
            });
    },
});

let app: App | null = null;

afterEach(() => {
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
});

/** Opens the page, answering the next submit with `props`. */
async function open(answer: Record<string, unknown>): Promise<void> {
    mounts = 0;
    document.body.innerHTML = '<div id="app"></div>';

    const page = (props: Record<string, unknown>) => ({
        component: 'Record',
        props,
        url: '/records/create',
        version: '',
    });

    await createInertiaApp({
        page: page({ errors: {}, form: form() }) as never,
        resolve: () => Page as unknown as DefineComponent,
        progress: false,
        http: {
            // Laravel answers with a redirect the browser follows, so what
            // arrives is the page the user is sent back to.
            request: async () => ({
                status: 200,
                headers: { 'x-inertia': 'true' },
                data: JSON.stringify(page(answer)),
            }),
        },
        setup({ el, App: Root, props, plugin }) {
            app = createApp({ render: () => h(Root, props) });
            app.use(plugin);
            app.mount(el as Element);
        },
    });

    await settle();
}

async function settle(): Promise<void> {
    for (let turn = 0; turn < 5; turn++) {
        await new Promise((done) => setTimeout(done, 10));
        await flushPromises();
    }
}

const input = () => document.querySelector('input') as HTMLInputElement;

async function typeAndSubmit(value: string): Promise<void> {
    input().value = value;
    input().dispatchEvent(new Event('input', { bubbles: true }));
    await flushPromises();

    document
        .querySelector('form')
        ?.dispatchEvent(
            new Event('submit', { bubbles: true, cancelable: true }),
        );
    await settle();
}

describe('a submit the server refuses', () => {
    const errors = { name: 'That name is taken.' };

    it('keeps the form and what was typed, and says what was wrong', async () => {
        await open({ errors, form: form() });
        await typeAndSubmit('Ada');

        expect(mounts).toBe(1);
        expect(input().value).toBe('Ada');
        expect(document.body.textContent).toContain('That name is taken.');
        expect(input().getAttribute('aria-invalid')).toBe('true');
    });

    // A create page answers a refusal with its form refilled from the old
    // input, which is not everything: a password is never sent back. What is
    // on screen is newer than anything that response holds.
    it('keeps what was typed when the page comes back filled differently', async () => {
        await open({ errors, form: form('from the server') });
        await typeAndSubmit('Ada');

        expect(input().value).toBe('Ada');
        expect(document.body.textContent).toContain('That name is taken.');
    });
});

describe('a submit the server accepts', () => {
    it('starts the next page afresh, as before', async () => {
        await open({ errors: {}, form: form('Saved') });
        await typeAndSubmit('Ada');

        expect(mounts).toBe(2);
        expect(input().value).toBe('Saved');
    });
});
