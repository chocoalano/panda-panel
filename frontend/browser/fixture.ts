import type { ActionDefinition } from '@/panel/types/action';
import type {
    ColumnDefinition,
    TableDefinition,
    TableRow,
    TableState,
} from '@/panel/types/table';

/**
 * A table wide enough to have to scroll, pinned at both edges.
 *
 * Deliberately anonymous. Freezing is a feature of every table the package
 * renders, so a fixture named after one application's resource would be a
 * test of that application. What matters here is the shape: two narrow
 * identity columns pinned to the leading edge, several wide ones that scroll,
 * and a pinned actions column at the trailing edge — the arrangement that
 * used to unpin itself on a phone.
 */

export function column(
    name: string,
    label: string,
    overrides: Partial<ColumnDefinition> = {},
): ColumnDefinition {
    return {
        type: 'text',
        name,
        label,
        sortable: false,
        searchable: false,
        individuallySearchable: false,
        visible: true,
        toggleable: true,
        alignment: 'start',
        headerAlignment: 'start',
        placeholder: null,
        headerTooltip: null,
        wrapHeader: false,
        width: null,
        frozen: null,
        wrap: false,
        ...overrides,
    } as ColumnDefinition;
}

const COLUMNS: ColumnDefinition[] = [
    column('number', 'Number', { frozen: 'start', toggleable: false }),
    column('name', 'Name', { frozen: 'start', toggleable: false }),
    column('email', 'Email address'),
    column('department', 'Department'),
    column('location', 'Location'),
    column('joined', 'Joined on'),
    column('status', 'Status'),
    column('note', 'Note'),
];

const ACTION: ActionDefinition = {
    name: 'edit',
    label: 'Edit',
    icon: null,
    variant: 'ghost',
    type: 'callback',
    url: null,
    formUrl: null,
    hasForm: false,
    modal: null,
    modalActions: [],
    confirmation: null,
};

export const table: TableDefinition = {
    description: null,
    callouts: [],
    bulkActions: [],
    headerActions: [],
    toolbarActions: [],
    recordActions: { position: 'after_columns', label: null },
    groups: [],
    defaultGroup: null,
    columns: COLUMNS,
    columnManager: { toggleable: true, resetLabel: null } as never,
    filters: [],
    filterBehaviour: 'live' as never,
    searchable: false,
    searchPlaceholder: '',
    searchDebounce: 300,
    searchOnBlur: false,
    individualSearchColumns: [],
    selectable: false,
    reorderable: false,
    frozen: { start: true, actions: true },
    perPageOptions: [10],
    defaultPerPage: 10,
    defaultSort: null,
    layouts: ['table'],
    cards: null,
    emptyState: {
        heading: 'Nothing here',
        description: null,
        icon: null,
        component: null,
        actions: [],
    },
};

export const rows: TableRow[] = Array.from({ length: 8 }, (_, index) => ({
    key: index + 1,
    group: null,
    cells: {
        number: `EMP-${String(index + 1).padStart(4, '0')}`,
        name: `Record number ${index + 1}`,
        email: `record.${index + 1}@example.test`,
        department: 'Engineering and platform',
        location: 'Somewhere far enough to be wide',
        joined: '12 January 2024',
        status: 'Active and in good standing',
        note: 'A note long enough to make the table scroll sideways',
    },
    cellMeta: {},
    actions: [ACTION],
}));

export const state: TableState = {
    search: null,
    sort: null,
    direction: 'asc',
    perPage: 10,
    filters: {},
    filterIndicators: [],
    columnSearches: {},
    columns: {
        visible: COLUMNS.map((definition) => definition.name),
        order: COLUMNS.map((definition) => definition.name),
    },
    group: null,
    layout: 'table',
};
