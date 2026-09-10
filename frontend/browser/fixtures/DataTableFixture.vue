<script setup lang="ts">
import { computed, ref } from 'vue';
import DataTableIntro from '@/panel/tables/DataTableIntro.vue';
import DataTableToolbar from '@/panel/tables/DataTableToolbar.vue';
import DataTableColumnManager from '@/panel/tables/DataTableColumnManager.vue';
import { column } from '../fixture';
import type { CalloutDefinition } from '@/panel/types/form';
import type {
    ColumnDefinition,
    FilterValue,
    QueryBuilderRule,
    TableDefinition,
    TableState,
} from '@/panel/types/table';

/**
 * A table's controls, laid out by a real engine.
 *
 * The gap this closes is precise. A closed Reka select renders none of its
 * items, so a unit test can prove what "Add condition" *does* and never what
 * it *shows*. That is the one question the whole column-discovery feature
 * turns on, and only a browser can answer it.
 *
 * Everything here is the real published component — the toolbar, the column
 * manager, the query builder and the intro. Nothing is restyled or
 * reimplemented; a fixture that dressed a component up would be measuring
 * itself.
 */

/**
 * Representative columns, including the ones that must *not* become
 * conditions and two that share a label on purpose.
 */
const COLUMNS: ColumnDefinition[] = [
    column('name', 'Name'),
    column('email', 'Email'),
    column('age', 'Age', { type: 'number' } as Partial<ColumnDefinition>),
    column('created_at', 'Created at', {
        type: 'date',
    } as Partial<ColumnDefinition>),
    column('active', 'Active', {
        type: 'boolean',
    } as Partial<ColumnDefinition>),
    // Display-only: draws something from a value rather than showing it.
    column('avatar', 'Avatar', { type: 'image' } as Partial<ColumnDefinition>),
    // Custom: the package cannot know what its value means.
    column('health', 'Health', { type: 'custom' } as Partial<ColumnDefinition>),
    // Two different columns, one label. The key stays distinct.
    column('billing_city', 'City'),
    column('shipping_city', 'City'),
];

/**
 * What the server would have derived from those columns, plus one constraint
 * declared explicitly to prove precedence.
 */
const CONSTRAINTS = [
    {
        name: 'email',
        label: 'Work address',
        input: 'text',
        operators: [
            { value: 'contains', label: 'contains', needsValue: true },
            { value: 'is_blank', label: 'is blank', needsValue: false },
        ],
    },
    {
        name: 'name',
        label: 'Name',
        input: 'text',
        operators: [
            { value: 'contains', label: 'contains', needsValue: true },
            { value: 'equals', label: 'is', needsValue: true },
        ],
    },
    {
        name: 'age',
        label: 'Age',
        input: 'number',
        operators: [
            { value: 'equals', label: 'is', needsValue: true },
            { value: 'greater_than', label: 'is after', needsValue: true },
        ],
    },
    {
        name: 'created_at',
        label: 'Created at',
        input: 'date',
        operators: [
            { value: 'equals', label: 'is', needsValue: true },
            { value: 'is_blank', label: 'is blank', needsValue: false },
        ],
    },
    {
        name: 'active',
        label: 'Active',
        input: 'none',
        operators: [{ value: 'is_true', label: 'is true', needsValue: false }],
    },
    {
        name: 'billing_city',
        label: 'City',
        input: 'text',
        operators: [{ value: 'contains', label: 'contains', needsValue: true }],
    },
    {
        name: 'shipping_city',
        label: 'City',
        input: 'text',
        operators: [{ value: 'contains', label: 'contains', needsValue: true }],
    },
];

const CALLOUTS: CalloutDefinition[] = [
    {
        component: 'callout',
        body: 'This report excludes archived records.',
        heading: null,
        tone: 'info',
        icon: null,
        schema: [],
    } as unknown as CalloutDefinition,
    {
        component: 'callout',
        body: 'Payroll period is locked.',
        heading: 'Locked',
        tone: 'warning',
        icon: null,
        schema: [],
    } as unknown as CalloutDefinition,
];

const visible = ref<string[]>(COLUMNS.map((definition) => definition.name));
const order = ref<string[]>(COLUMNS.map((definition) => definition.name));
const rules = ref<QueryBuilderRule[]>([]);

const table = computed<TableDefinition>(
    () =>
        ({
            description:
                'Every record this panel can reach, refreshed nightly.',
            callouts: CALLOUTS,
            bulkActions: [],
            headerActions: [],
            toolbarActions: [],
            recordActions: { position: 'after_columns', label: null },
            groups: [],
            defaultGroup: null,
            columns: COLUMNS,
            // The shape the server actually sends: `toggleable` is the list
            // of columns that may be hidden, not a boolean.
            columnManager: {
                reorderable: false,
                deferred: false,
                triggerLabel: 'Columns',
                triggerIcon: null,
                resetLabel: 'Reset',
                showReset: true,
                modal: false,
                toggleable: COLUMNS.map((definition) => definition.name),
            },
            filters: [
                {
                    name: 'advanced',
                    label: 'Advanced',
                    type: 'query_builder',
                    maxRules: 5,
                    constraints: CONSTRAINTS,
                },
            ],
            filterBehaviour: {
                deferred: false,
                triggerIcon: null,
                triggerLabel: 'Filters',
                applyLabel: 'Apply',
                resetLabel: 'Reset',
            },
            searchable: false,
            searchPlaceholder: '',
            searchDebounce: 300,
            searchOnBlur: false,
            individualSearchColumns: [],
            selectable: false,
            reorderable: false,
            perPageOptions: [10],
            layouts: [],
            defaultLayout: null,
            tabs: [],
            emptyState: {
                heading: 'Nothing here',
                description: null,
                icon: null,
                component: null,
                actions: [],
            },
        }) as unknown as TableDefinition,
);

const state = computed<TableState>(
    () =>
        ({
            search: '',
            sort: null,
            direction: 'asc',
            page: 1,
            perPage: 10,
            filters: { advanced: rules.value },
            columnSearches: {},
            filterIndicators: [],
            columns: { visible: visible.value, order: order.value },
            group: null,
            layout: null,
            tab: null,
        }) as unknown as TableState,
);

function onFilter(name: string, value: FilterValue | null): void {
    if (name === 'advanced') {
        rules.value = (value ?? []) as QueryBuilderRule[];
    }
}

function onColumns(next: string[], nextOrder: string[]): void {
    visible.value = next;
    order.value = nextOrder;
}

/**
 * What the checks read back.
 *
 * `rules` here is what the *server* would receive. The toolbar only emits
 * rules the server can execute — an incomplete condition is held in its own
 * draft copy and deliberately not sent, which is PP-27. So an empty array
 * beside a visible rule row is not a bug; it is the feature.
 */
const readback = computed(() =>
    JSON.stringify({
        visible: visible.value,
        serverRules: rules.value,
    }),
);
</script>

<template>
    <div class="p-6">
        <DataTableIntro
            :description="table.description"
            :callouts="table.callouts"
        />

        <div class="mt-4">
            <DataTableToolbar :table="table" :state="state" @filter="onFilter">
                <template #actions>
                    <DataTableColumnManager
                        :table="table"
                        :visible="state.columns.visible"
                        :order="state.columns.order"
                        @change="onColumns"
                    />
                </template>
            </DataTableToolbar>
        </div>

        <output id="state" class="hidden">{{ readback }}</output>
    </div>
</template>
