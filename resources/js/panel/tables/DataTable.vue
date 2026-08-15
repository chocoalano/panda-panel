<script setup lang="ts">
import { ArrowDown, ArrowUp, ChevronsUpDown, GripVertical } from '@lucide/vue';
import {
    rowSelectionFeature,
    tableFeatures,
    useTable,
} from '@tanstack/vue-table';
import { useDebounceFn } from '@vueuse/core';
import { computed, defineAsyncComponent, ref, watch } from 'vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import ActionButton from '@/panel/actions/ActionButton.vue';
import ActionGroup from '@/panel/actions/ActionGroup.vue';
import EmptyState from '@/panel/components/EmptyState.vue';
import type { CellEditValue } from '@/panel/composables/useActions';
import { usePanelStyling } from '@/panel/composables/usePanelStyling';
import DataTableCell from '@/panel/tables/DataTableCell.vue';
import { resolveEmptyStateComponent } from '@/panel/tables/registryEmptyStates';
import type { ActionDefinition } from '@/panel/types/action';
import type {
    ColumnAlignment,
    ColumnDefinition,
    TableDefinition,
    TableRow as TableRowData,
    TableGroupSummaries,
    TableState,
    TableSummaries,
} from '@/panel/types/table';

const props = withDefaults(
    defineProps<{
        table: TableDefinition;
        rows: TableRowData[];
        state: TableState;
        /** Figures under the table; empty for one that declares none. */
        summaries?: TableSummaries;
        /** The same, per band, when the table is grouped. */
        groupSummaries?: TableGroupSummaries;
        /**
         * Whether the table draws its own frame.
         *
         * True standalone — a relation table on a record page, a table widget
         * — where it is one object among several and needs an edge of its
         * own. False on a resource index, where the toolbar, the rows and the
         * pagination are joined into a single surface and a second border
         * inside it would draw a box around the middle third of one object.
         */
        bordered?: boolean;
    }>(),
    { summaries: () => ({}), groupSummaries: () => ({}), bordered: true },
);

const emit = defineEmits<{
    columnSearch: [name: string, term: string];
    runTableAction: [action: ActionDefinition];
    editCell: [record: string | number, column: string, value: CellEditValue];
    sort: [column: string];
    selectionChange: [keys: Array<string | number>];
    runAction: [action: ActionDefinition, record: string | number];
    reorder: [keys: Array<string | number>];
}>();

const dragging = ref<string | number | null>(null);

/**
 * Reordering is a drag between two rows, so the resulting order is worked
 * out here and only the key order is sent. What a position means belongs to
 * the server; the client never invents a value for a column it knows nothing
 * about.
 */
function onDrop(targetKey: string | number): void {
    const from = dragging.value;

    dragging.value = null;

    if (from === null || from === targetKey) {
        return;
    }

    const keys = props.rows.map((row) => row.key);
    const fromIndex = keys.indexOf(from);
    const toIndex = keys.indexOf(targetKey);

    if (fromIndex === -1 || toIndex === -1) {
        return;
    }

    keys.splice(toIndex, 0, ...keys.splice(fromIndex, 1));

    emit('reorder', keys);
}

/**
 * TanStack owns the row model and the selection, and nothing else.
 *
 * Sorting, filtering, and pagination are server-side and URL-driven. Column
 * visibility and order joined them once the server started deciding and
 * remembering both: keeping TanStack's copy as well would be a second,
 * conflicting source of truth for the same question.
 *
 * Defined at module scope, as the library recommends, so the feature set is
 * not rebuilt per component instance.
 */
const features = tableFeatures({
    rowSelectionFeature,
});

const columns = computed(() =>
    props.table.columns.map((column) => ({
        id: column.name,
        header: column.label,
        accessorFn: (row: TableRowData) => row.cells[column.name],
    })),
);

const data = computed(() => props.rows);

const tableInstance = useTable({
    features,
    columns,
    data,
    getRowId: (row: TableRowData) => String(row.key),
});

const definitionsByName = computed(
    () =>
        new Map<string, ColumnDefinition>(
            props.table.columns.map((column) => [column.name, column]),
        ),
);

/**
 * A table that draws its own empty state. Loaded on demand: most tables use
 * the ordinary one, and bundling every custom view would cost them all.
 */
const emptyStateComponent = computed(() => {
    const name = props.table.emptyState.component;

    if (name === null) {
        return null;
    }

    const loader = resolveEmptyStateComponent(name);

    return loader === null ? null : defineAsyncComponent(loader);
});

const hasSummaries = computed(() => Object.keys(props.summaries).length > 0);

/**
 * Where a group heading has to be inserted: the first row of each band.
 *
 * Derived from the rows as they arrived rather than from a grouped structure,
 * because grouping is presentation — the query still returns a page, and a
 * band can be split across two pages exactly as any run of rows can.
 */
const groupBreaks = computed(() => {
    const breaks = new Set<number>();

    let previous: string | null = null;

    props.rows.forEach((row, index) => {
        if (row.group !== null && row.group.key !== previous) {
            breaks.add(index);
            previous = row.group.key;
        }
    });

    return breaks;
});

const actionsPosition = computed(() => props.table.recordActions.position);

/**
 * Where a band ends: the row before the next break, or the last row shown.
 *
 * Figures go under the band they describe rather than above it, so a total
 * reads the way a column of numbers does.
 */
const groupEnds = computed(() => {
    const ends = new Map<number, string>();

    props.rows.forEach((row, index) => {
        const next = props.rows[index + 1];

        if (
            row.group !== null &&
            (next === undefined || next.group?.key !== row.group.key)
        ) {
            ends.set(index, row.group.key);
        }
    });

    return ends;
});

const columnCount = computed(
    () =>
        visibleColumns.value.length +
        (props.table.selectable ? 1 : 0) +
        (props.table.reorderable ? 1 : 0) +
        (hasActionsColumn.value ? 1 : 0),
);

/** The tallest stack of figures under any one column. */
const summaryRowCount = computed(() =>
    Math.max(
        0,
        ...Object.values(props.summaries).map((figures) => figures.length),
    ),
);

const hasColumnSearch = computed(() =>
    props.table.columns.some((column) => column.individuallySearchable),
);

/**
 * Local, for the same reason the toolbar's box is: the server value only
 * arrives after the debounced visit, and binding straight to it would move
 * the caret while typing. Re-synced whenever the server value changes, so
 * back and forward still update the boxes.
 */
const columnTerms = ref<Record<string, string>>({
    ...props.state.columnSearches,
});

watch(
    () => props.state.columnSearches,
    (value) => {
        columnTerms.value = { ...value };
    },
);

const emitColumnSearch = useDebounceFn(
    (name: string, term: string) => emit('columnSearch', name, term),
    computed(() => props.table.searchDebounce),
);

function onColumnSearch(name: string, term: string): void {
    columnTerms.value = { ...columnTerms.value, [name]: term };

    emitColumnSearch(name, term);
}

/**
 * Read straight from the state the server applied, in the order it applied.
 *
 * Not from a local model: the server already dropped unknown names and kept
 * every non-toggleable column visible, so deriving it again here would be a
 * second place for the two to disagree.
 */
const visibleColumns = computed(() =>
    props.state.columns.visible
        .map((name) => definitionsByName.value.get(name))
        .filter((column): column is ColumnDefinition => column !== undefined),
);

const selectedKeys = computed(() =>
    tableInstance.getSelectedRowModel().rows.map((row) => row.original.key),
);

const allSelected = computed(
    () =>
        props.rows.length > 0 &&
        selectedKeys.value.length === props.rows.length,
);

const hasRowActions = computed(() =>
    props.rows.some((row) => row.actions.length > 0),
);

/**
 * A separate actions column exists unless the buttons live in the last cell.
 * `after_cells` is for a table narrow enough that a column of its own would
 * be most of it.
 */
const hasActionsColumn = computed(
    () => hasRowActions.value && actionsPosition.value !== 'after_cells',
);

/**
 * Logical alignment maps to literal classes. `text-${alignment}` would have to
 * be interpolated, and an interpolated class does not exist in the bundle.
 */
const ALIGNMENT_CLASSES: Record<ColumnAlignment, string> = {
    start: 'text-start',
    center: 'text-center',
    end: 'text-end',
    justify: 'text-justify',
};

function toggleAll(checked: boolean): void {
    tableInstance.toggleAllRowsSelected(checked);
    emit('selectionChange', selectedKeys.value);
}

function toggleRow(key: string | number, checked: boolean): void {
    tableInstance.getRow(String(key))?.toggleSelected(checked);
    emit('selectionChange', selectedKeys.value);
}

function isRowSelected(key: string | number): boolean {
    return tableInstance.getRow(String(key))?.getIsSelected() ?? false;
}

function clearSelection(): void {
    tableInstance.toggleAllRowsSelected(false);
    emit('selectionChange', []);
}

defineExpose({ tableInstance, clearSelection });

const { hook } = usePanelStyling();
</script>

<template>
    <div :class="[bordered ? 'rounded-lg border' : '', hook('table')]">
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead v-if="table.reorderable" class="w-10">
                        <span class="sr-only">Reorder</span>
                    </TableHead>
                    <TableHead v-if="table.selectable" class="w-10">
                        <Checkbox
                            :model-value="allSelected"
                            aria-label="Select all rows on this page"
                            @update:model-value="
                                (checked) => toggleAll(checked === true)
                            "
                        />
                    </TableHead>
                    <TableHead
                        v-if="
                            hasActionsColumn &&
                            actionsPosition === 'before_columns'
                        "
                        class="w-12"
                    >
                        <span class="sr-only">
                            {{ table.recordActions.label ?? 'Actions' }}
                        </span>
                    </TableHead>
                    <TableHead
                        v-for="column in visibleColumns"
                        :key="column.name"
                        :class="[
                            ALIGNMENT_CLASSES[column.headerAlignment],
                            column.wrapHeader ? '' : 'whitespace-nowrap',
                        ]"
                        :style="
                            column.width ? { width: column.width } : undefined
                        "
                        :title="column.headerTooltip ?? undefined"
                    >
                        <button
                            v-if="column.sortable"
                            type="button"
                            class="inline-flex items-center gap-1 rounded-sm font-medium hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            :aria-label="`Sort by ${column.label}`"
                            @click="emit('sort', column.name)"
                        >
                            {{ column.label }}
                            <ArrowUp
                                v-if="
                                    state.sort === column.name &&
                                    state.direction === 'asc'
                                "
                                class="size-3.5"
                            />
                            <ArrowDown
                                v-else-if="state.sort === column.name"
                                class="size-3.5"
                            />
                            <ChevronsUpDown
                                v-else
                                class="size-3.5 text-muted-foreground/60"
                            />
                        </button>
                        <span v-else>{{ column.label }}</span>
                    </TableHead>
                    <TableHead
                        v-if="
                            hasActionsColumn &&
                            actionsPosition === 'after_columns'
                        "
                        class="w-12"
                    >
                        <span class="sr-only">
                            {{ table.recordActions.label ?? 'Actions' }}
                        </span>
                    </TableHead>
                </TableRow>

                <!--
                    A second header row rather than boxes crammed beside the
                    labels: a per-column search narrows what the table-wide one
                    already found, and reads as a separate act.
                -->
                <TableRow v-if="hasColumnSearch">
                    <TableHead v-if="table.reorderable" class="w-10" />
                    <TableHead v-if="table.selectable" class="w-10" />
                    <TableHead
                        v-for="column in visibleColumns"
                        :key="column.name"
                        class="py-2"
                    >
                        <Input
                            v-if="column.individuallySearchable"
                            :model-value="columnTerms[column.name] ?? ''"
                            class="h-8"
                            type="search"
                            :placeholder="`Search ${column.label.toLowerCase()}`"
                            :aria-label="`Search ${column.label}`"
                            @update:model-value="
                                (value) =>
                                    onColumnSearch(column.name, String(value))
                            "
                        />
                    </TableHead>
                    <TableHead v-if="hasActionsColumn" class="w-12" />
                </TableRow>
            </TableHeader>

            <TableBody>
                <TableRow v-if="rows.length === 0">
                    <TableCell :colspan="columnCount" class="p-0">
                        <component
                            :is="emptyStateComponent"
                            v-if="emptyStateComponent"
                            :empty-state="table.emptyState"
                        />
                        <EmptyState
                            v-else
                            class="border-0"
                            :heading="table.emptyState.heading"
                            :description="table.emptyState.description"
                            :icon="table.emptyState.icon"
                        >
                            <!--
                                An empty table is where an action is most
                                useful and least likely to be found, because
                                every other affordance is about rows.
                            -->
                            <template #actions>
                                <ActionButton
                                    v-for="action in table.emptyState.actions"
                                    :key="action.name"
                                    :action="action"
                                    @run="emit('runTableAction', action)"
                                />
                            </template>
                        </EmptyState>
                    </TableCell>
                </TableRow>

                <template v-for="(row, index) in rows" :key="row.key">
                    <TableRow
                        v-if="groupBreaks.has(index)"
                        class="bg-muted/50 hover:bg-muted/50"
                    >
                        <TableCell :colspan="columnCount" class="py-2">
                            <span class="text-sm font-medium">
                                {{ row.group?.title }}
                            </span>
                            <span
                                v-if="row.group?.description"
                                class="ml-2 text-xs text-muted-foreground"
                            >
                                {{ row.group.description }}
                            </span>
                        </TableCell>
                    </TableRow>

                    <TableRow
                        :data-state="
                            isRowSelected(row.key) ? 'selected' : undefined
                        "
                        :draggable="table.reorderable && dragging === row.key"
                        :class="[
                            hook('table-row'),
                            dragging === row.key ? 'opacity-60' : undefined,
                        ]"
                        @dragover.prevent
                        @drop.prevent="onDrop(row.key)"
                        @dragend="dragging = null"
                    >
                        <TableCell v-if="table.reorderable" class="w-10">
                            <button
                                type="button"
                                class="cursor-grab rounded-md p-1 text-muted-foreground hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none active:cursor-grabbing"
                                :aria-label="`Reorder row ${row.key}`"
                                draggable="true"
                                @dragstart="dragging = row.key"
                            >
                                <GripVertical class="size-4" />
                            </button>
                        </TableCell>
                        <TableCell v-if="table.selectable" class="w-10">
                            <Checkbox
                                :model-value="isRowSelected(row.key)"
                                :aria-label="`Select row ${row.key}`"
                                @update:model-value="
                                    (checked) =>
                                        toggleRow(row.key, checked === true)
                                "
                            />
                        </TableCell>
                        <TableCell
                            v-if="
                                hasActionsColumn &&
                                actionsPosition === 'before_columns'
                            "
                            class="w-12"
                        >
                            <ActionGroup
                                :actions="row.actions"
                                @run="
                                    (action) =>
                                        emit('runAction', action, row.key)
                                "
                            />
                        </TableCell>
                        <TableCell
                            v-for="column in visibleColumns"
                            :key="column.name"
                            :class="ALIGNMENT_CLASSES[column.alignment]"
                            v-bind="row.cellMeta[column.name]?.attributes"
                            :title="row.cellMeta[column.name]?.tooltip"
                        >
                            <!--
                            A linked cell is a real anchor, so it opens in a
                            new tab, copies, and reads as a link. The URL was
                            produced on the server; nothing is resolved here.
                        -->
                            <component
                                :is="
                                    row.cellMeta[column.name]?.url ? 'a' : 'div'
                                "
                                :href="row.cellMeta[column.name]?.url"
                                :class="
                                    row.cellMeta[column.name]?.url
                                        ? 'underline-offset-4 hover:underline'
                                        : undefined
                                "
                            >
                                <!--
                                A cell with its own action is a button. The
                                action was resolved per record, so a cell the
                                user may not act on is plain text instead.
                            -->
                                <button
                                    v-if="row.cellMeta[column.name]?.action"
                                    type="button"
                                    class="rounded-sm text-left underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    @click="
                                        emit(
                                            'runAction',
                                            row.cellMeta[column.name]!.action!,
                                            row.key,
                                        )
                                    "
                                >
                                    <DataTableCell
                                        :column="column"
                                        :value="row.cells[column.name] ?? null"
                                        :record-key="row.key"
                                        @edit="
                                            (name, value) =>
                                                emit(
                                                    'editCell',
                                                    row.key,
                                                    name,
                                                    value,
                                                )
                                        "
                                    />
                                </button>
                                <DataTableCell
                                    v-else
                                    :column="column"
                                    :value="row.cells[column.name] ?? null"
                                    :record-key="row.key"
                                    @edit="
                                        (name, value) =>
                                            emit(
                                                'editCell',
                                                row.key,
                                                name,
                                                value,
                                            )
                                    "
                                />
                            </component>

                            <ActionGroup
                                v-if="
                                    hasRowActions &&
                                    actionsPosition === 'after_cells' &&
                                    column.name ===
                                        visibleColumns[
                                            visibleColumns.length - 1
                                        ]?.name
                                "
                                class="ml-2 inline-flex"
                                :actions="row.actions"
                                @run="
                                    (action) =>
                                        emit('runAction', action, row.key)
                                "
                            />
                        </TableCell>
                        <TableCell
                            v-if="
                                hasActionsColumn &&
                                actionsPosition === 'after_columns'
                            "
                            class="w-12 text-right"
                        >
                            <ActionGroup
                                :actions="row.actions"
                                @run="
                                    (action) =>
                                        emit('runAction', action, row.key)
                                "
                            />
                        </TableCell>
                    </TableRow>
                    <!--
                        A band's own figures, under the band they describe.
                    -->
                    <template v-if="groupEnds.has(index)">
                        <TableRow
                            v-for="(__, figure) in summaryRowCount"
                            :key="`group-${index}-${figure}`"
                            class="bg-muted/30 hover:bg-muted/30"
                        >
                            <TableCell v-if="table.reorderable" class="w-10" />
                            <TableCell v-if="table.selectable" class="w-10" />
                            <TableCell
                                v-for="column in visibleColumns"
                                :key="column.name"
                                :class="ALIGNMENT_CLASSES[column.alignment]"
                            >
                                <template
                                    v-if="
                                        groupSummaries[groupEnds.get(index)!]?.[
                                            column.name
                                        ]?.[figure]
                                    "
                                >
                                    <span class="text-xs text-muted-foreground">
                                        {{
                                            groupSummaries[
                                                groupEnds.get(index)!
                                            ][column.name][figure].label
                                        }}
                                    </span>
                                    <span
                                        class="ml-1.5 font-medium tabular-nums"
                                    >
                                        {{
                                            groupSummaries[
                                                groupEnds.get(index)!
                                            ][column.name][figure].value
                                        }}
                                    </span>
                                </template>
                            </TableCell>
                            <TableCell v-if="hasActionsColumn" class="w-12" />
                        </TableRow>
                    </template>
                </template>
            </TableBody>

            <!--
                A footer row per figure, so two summaries under one column
                line up beneath it rather than being crammed into one cell.
            -->
            <TableFooter v-if="hasSummaries && rows.length > 0">
                <TableRow
                    v-for="(_, index) in summaryRowCount"
                    :key="index"
                    class="bg-transparent"
                >
                    <TableCell v-if="table.reorderable" class="w-10" />
                    <TableCell v-if="table.selectable" class="w-10" />
                    <TableCell
                        v-for="column in visibleColumns"
                        :key="column.name"
                        :class="ALIGNMENT_CLASSES[column.alignment]"
                    >
                        <template v-if="summaries[column.name]?.[index]">
                            <span class="text-xs text-muted-foreground">
                                {{ summaries[column.name][index].label }}
                            </span>
                            <span class="ml-1.5 font-medium tabular-nums">
                                {{ summaries[column.name][index].value }}
                            </span>
                        </template>
                    </TableCell>
                    <TableCell v-if="hasRowActions" class="w-12" />
                </TableRow>
            </TableFooter>
        </Table>
    </div>
</template>
