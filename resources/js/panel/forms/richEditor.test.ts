/**
 * @vitest-environment happy-dom
 */
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { RichEditorFieldDefinition } from '@/panel/types/form';

/**
 * The editor's semantics, as opposed to its appearance.
 *
 * What it *looks* like when focused is a browser question and is answered in
 * `tests/browser/ui6.mjs` — a computed box-shadow that is neither transparent
 * nor zero-width. What is asserted here is the half a DOM implementation can
 * see: that the editable region still says what it is, that the field wiring
 * from U01 survived, and that a toolbar toggle reports a state at all.
 */
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
                    editor_bullet_list: '• List',
                    editor_link: 'Link',
                },
            },
        },
        url: '/',
        component: '',
        version: null,
    }),
    Link: { name: 'Link', template: '<a><slot /></a>' },
}));

const { default: RichEditorField } =
    await import('@/panel/forms/fields/RichEditorField.vue');

const mounted: Array<{ unmount: () => void }> = [];

afterEach(() => {
    while (mounted.length > 0) {
        mounted.pop()?.unmount();
    }

    document.body.innerHTML = '';
});

function field(
    overrides: Record<string, unknown> = {},
): RichEditorFieldDefinition {
    return {
        component: 'field',
        name: 'body',
        label: 'Body',
        type: 'rich_editor',
        value: '<p>Content.</p>',
        placeholder: null,
        helperText: 'Formatting applies to the selection.',
        required: false,
        disabled: false,
        inlineLabel: false,
        columnSpan: 'full',
        conditions: { visibleWhen: [], hiddenWhen: [] },
        live: null,
        validation: { required: false },
        toolbar: ['bold', 'italic', 'bulletList', 'link', 'undo'],
        maxLength: null,
        ...overrides,
    } as unknown as RichEditorFieldDefinition;
}

function render(props: Record<string, unknown> = {}) {
    const wrapper = mount(RichEditorField, {
        attachTo: document.body,
        props: { field: field(), modelValue: '<p>Content.</p>', ...props },
    });

    mounted.push(wrapper);

    return wrapper;
}

/*
 * U17-T1 / U17-T2
 */

describe('the editable region', () => {
    it('still says it is a multi-line textbox', () => {
        const wrapper = render();

        const editor = wrapper.get('[contenteditable="true"]');

        expect(editor.attributes('role')).toBe('textbox');
        expect(editor.attributes('aria-multiline')).toBe('true');
    });

    it('keeps the field wiring U01 established', () => {
        const wrapper = render({
            field: field({ helperText: 'Some help.' }),
            error: 'It is required.',
        });

        const editor = wrapper.get('[contenteditable="true"]');
        const described = (editor.attributes('aria-describedby') ?? '').split(
            ' ',
        );

        // Both the helper and the error, and the invalid state — the same
        // contract every other field carries.
        expect(described).toHaveLength(2);
        expect(editor.attributes('aria-invalid')).toBe('true');
        expect(document.getElementById(described[0])?.textContent?.trim()).toBe(
            'Some help.',
        );
        expect(document.getElementById(described[1])?.textContent?.trim()).toBe(
            'It is required.',
        );
        expect(wrapper.get('label').attributes('for')).toBe(
            editor.attributes('id'),
        );
    });

    it('marks the field boundary rather than removing the outline and stopping', () => {
        const wrapper = render();

        // The class list is the part a DOM implementation can see; whether
        // anything is painted is asserted in the browser suite.
        const shell = wrapper.get('[contenteditable="true"]').element
            .parentElement as HTMLElement;

        expect(shell.className).toContain('focus-within:border-ring');
        expect(
            wrapper.get('[contenteditable="true"]').classes().join(' '),
        ).toContain('focus-visible:ring-3');
    });
});

/*
 * U17-T3 / U17-T4
 */

describe('a toolbar toggle', () => {
    it('reports a pressed state', () => {
        const wrapper = render();

        const bold = wrapper.get('[aria-label="bold"]');

        // Present and false, rather than absent: a toggle with no state is a
        // toggle nobody can read.
        expect(bold.attributes('aria-pressed')).toBe('false');
    });

    it('claims no pressed state for a control that is not a toggle', () => {
        const wrapper = render();

        // Undo is an action, not a state. `aria-pressed` on it would say the
        // editor is "currently undone".
        expect(
            wrapper.get('[aria-label="undo"]').attributes('aria-pressed'),
        ).toBeUndefined();
    });

    it('still runs its command', async () => {
        const exec = vi.fn(() => true);

        Object.defineProperty(document, 'execCommand', {
            value: exec,
            configurable: true,
        });

        const wrapper = render();

        await wrapper.get('[aria-label="bold"]').trigger('click');

        expect(exec).toHaveBeenCalledWith('bold', false, undefined);
    });
});
