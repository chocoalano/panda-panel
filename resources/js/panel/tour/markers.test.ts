/**
 * @vitest-environment happy-dom
 */
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { TableDefinition, TableState } from '@/panel/types/table';

/**
 * The other half of `TourStep`.
 *
 * `field()`, `column()`, and `action()` serialize a selector in PHP; these
 * assert the components actually draw something it matches. The selectors are
 * written out as `TourTest` expects them, so neither side can move alone.
 */
vi.mock('@inertiajs/vue3', () => ({
    router: { visit: vi.fn(), post: vi.fn(), on: () => () => {} },
    usePage: () => ({ props: { panel: { id: 'admin' } }, url: '/' }),
    Link: { name: 'Link', template: '<a><slot /></a>' },
}));

const { default: FieldWrapper } =
    await import('@/panel/forms/fields/FieldWrapper.vue');
const { default: ActionButton } =
    await import('@/panel/actions/ActionButton.vue');
const { default: DataTable } = await import('@/panel/tables/DataTable.vue');

const mounted: Array<{ unmount: () => void }> = [];

afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
    document.body.innerHTML = '';
});

describe('what a tour step can point at', () => {
    it.each([
        ['stacked', {}],
        ['inline', { inline: true }],
        ['inline-labelled', { inlineLabel: true }],
    ])('marks a field in its %s layout', (_, layout) => {
        mounted.push(
            mount(FieldWrapper, {
                attachTo: document.body,
                props: {
                    name: 'email',
                    label: 'Email',
                    required: false,
                    helperText: null,
                    ...layout,
                },
            }),
        );

        expect(
            document.querySelectorAll('[data-tour="field:email"]'),
        ).toHaveLength(1);
    });

    it.each([
        ['a link', { type: 'link', url: '/users/create' }, 'A'],
        ['a button', { type: 'callback', url: null }, 'BUTTON'],
    ])('marks an action drawn as %s', (_, shape, tag) => {
        mounted.push(
            mount(ActionButton, {
                attachTo: document.body,
                props: {
                    action: {
                        name: 'create',
                        label: 'New user',
                        icon: null,
                        variant: 'default',
                        ...shape,
                    } as never,
                },
            }),
        );

        expect(
            document.querySelector('[data-tour="action:create"]')?.tagName,
        ).toBe(tag);
    });

    it('marks a column header once, on the header that carries its name', () => {
        mounted.push(
            mount(DataTable, {
                attachTo: document.body,
                props: {
                    table: {
                        columns: [
                            {
                                name: 'author.name',
                                label: 'Author',
                                alignment: 'start',
                                headerAlignment: 'start',
                                frozen: null,
                                // A second header row, which must not be
                                // marked as well.
                                individuallySearchable: true,
                            },
                        ],
                        bulkActions: [],
                        recordActions: {
                            position: 'after_columns',
                            label: null,
                        },
                        groups: [],
                        individualSearchColumns: ['author.name'],
                        frozen: { start: false, actions: false },
                        emptyState: {
                            heading: 'Nothing',
                            description: null,
                            icon: null,
                        },
                    } as unknown as TableDefinition,
                    rows: [],
                    state: {
                        sort: null,
                        direction: 'asc',
                        columnSearches: {},
                        columns: {
                            visible: ['author.name'],
                            order: ['author.name'],
                        },
                        group: null,
                    } as unknown as TableState,
                },
            }),
        );

        const marked = document.querySelectorAll(
            '[data-tour="column:author.name"]',
        );

        expect(marked).toHaveLength(1);
        expect(marked[0]?.textContent).toContain('Author');
    });
});
