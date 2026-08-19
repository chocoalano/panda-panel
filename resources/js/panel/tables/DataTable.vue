<script setup lang="ts">
import { ArrowDown, ArrowUp, ChevronsUpDown, GripVertical } from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
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
import {
    ALIGNMENT_CLASSES,
    cellUrl,
    useEmptyStateComponent,
} from '@/panel/tables/tableCells';
import { useFrozenColumns } from '@/panel/tables/useFrozenColumns';
import type { FrozenColumn } from '@/panel/tables/useFrozenColumns';
import { useTableGroups } from '@/panel/tables/useTableGroups';
import { useTableSelection } from '@/panel/tables/useTableSelection';
import type { ActionDefinition } from '@/panel/types/action';
import type {
    ColumnDefinition,
    TableDefinition,
    TableRow as TableRowData,
    TableGroupSummaries,
    TableState,
    TableSummaries,
} from '@/panel/types/table';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

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

const {
    tableInstance,
    allSelected,
    toggleAll,
    toggleRow,
    isRowSelected,
    clearSelection,
} = useTableSelection(
    () => props.rows,
    () => props.table.columns,
    (keys) => emit('selectionChange', keys),
);

const definitionsByName = computed(
    () =>
        new Map<string, ColumnDefinition>(
            props.table.columns.map((column) => [column.name, column]),
        ),
);

const emptyStateComponent = useEmptyStateComponent(() => props.table);

const hasSummaries = computed(() => Object.keys(props.summaries).length > 0);

const { breaks: groupBreaks, ends: groupEnds } = useTableGroups(
    () => props.rows,
);

const actionsPosition = computed(() => props.table.recordActions.position);

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

const emitColumnSearch = useDebounceFn(
    (name: string, term: string) => emit('columnSearch', name, term),
    computed(() => props.table.searchDebounce),
);

watch(
    () => props.state.columnSearches,
    (value) => {
        emitColumnSearch.cancel();
        columnTerms.value = { ...value };
    },
);

function onColumnSearch(name: string, term: string): void {
    columnTerms.value = { ...columnTerms.value, [name]: term };

    emitColumnSearch(name, term);
}

function clearColumnSearches(): void {
    emitColumnSearch.cancel();
    columnTerms.value = {};
}

/**
 * Read straight from the state the server applied, in the order it applied.
 *
 * Not from a local model: the server already dropped unknown names and kept
 * every non-toggleable column visible, so deriving it again here would be a
 * second place for the two to disagree.
 */
const orderedColumns = computed(() =>
    props.state.columns.visible
        .map((name) => definitionsByName.value.get(name))
        .filter((column): column is ColumnDefinition => column !== undefined),
);

/**
 * Frozen columns are drawn at the edge they are pinned to.
 *
 * A column pinned to the left renders first even if it was declared fifth,
 * for the reason that makes freezing work at all: a sticky cell is offset by
 * the width of the frozen columns before it, and a frozen column left sitting
 * in the middle would be offset over the top of the ones it was declared
 * after. Moving it is what "pinned" means everywhere else a table offers it,
 * and it is visible — unlike the alternative, which is a column that quietly
 * declines to freeze.
 *
 * Relative order within each group is the order they were declared in.
 */
const visibleColumns = computed(() => [
    ...orderedColumns.value.filter((column) => column.frozen === 'start'),
    ...orderedColumns.value.filter((column) => column.frozen === null),
    ...orderedColumns.value.filter((column) => column.frozen === 'end'),
]);

/*
 * Freezing, and the cells that take part in it.
 *
 * The structural cells are pinned with the columns rather than on their own
 * account — see `TableSchema::hasFrozenStart()`. Their keys are prefixed so
 * they cannot collide with a column actually called `select`.
 */
const REORDER_KEY = '@reorder';
const SELECT_KEY = '@select';
const ACTIONS_KEY = '@actions';

const tableRoot = ref<HTMLElement | null>(null);

const frozenColumns = computed<FrozenColumn[]>(() => {
    const frozen: FrozenColumn[] = [];

    const leading = props.table.frozen.start;

    if (leading && props.table.reorderable) {
        frozen.push({ key: REORDER_KEY, side: 'start' });
    }

    if (leading && props.table.selectable) {
        frozen.push({ key: SELECT_KEY, side: 'start' });
    }

    if (
        leading &&
        hasActionsColumn.value &&
        actionsPosition.value === 'before_columns'
    ) {
        frozen.push({ key: ACTIONS_KEY, side: 'start' });
    }

    for (const column of visibleColumns.value) {
        if (column.frozen !== null) {
            frozen.push({ key: column.name, side: column.frozen });
        }
    }

    if (
        props.table.frozen.actions &&
        hasActionsColumn.value &&
        actionsPosition.value === 'after_columns'
    ) {
        frozen.push({ key: ACTIONS_KEY, side: 'end' });
    }

    return frozen;
});

const { measure, styleFor, isEdge } = useFrozenColumns(
    frozenColumns,
    tableRoot,
);

/**
 * What a frozen cell wears.
 *
 * `bg-inherit` rather than a colour of its own: the row owns the hover and
 * selected background, and a frozen cell painted `bg-background` would be the
 * one cell in the row that never highlights. Opaque it must be — a
 * transparent sticky cell has the scrolling content pass under it.
 *
 * The divider marks where the frozen group ends, so the seam is something the
 * eye can find rather than a place where columns appear to teleport.
 */
function frozenClass(key: string): string[] {
    if (!frozenColumns.value.some((column) => column.key === key)) {
        return [];
    }

    return ['bg-inherit', isEdge(key) ? 'panel-table-frozen-edge' : ''].filter(
        (value) => value !== '',
    );
}

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

defineExpose({ tableInstance, clearSelection, clearColumnSearches });

const { hook } = usePanelStyling();
</script>

<template>
    <div
        ref="tableRoot"
        :class="[bordered ? 'rounded-lg border' : '', hook('table')]"
    >
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead
                        v-if="table.reorderable"
                        :ref="measure(REORDER_KEY)"
                        class="w-10"
                        :class="frozenClass(REORDER_KEY)"
                        :style="styleFor(REORDER_KEY, true)"
                    >
                        <span class="sr-only">{{ t('tables.reorder') }}</span>
                    </TableHead>
                    <TableHead
                        v-if="table.selectable"
                        :ref="measure(SELECT_KEY)"
                        class="w-10"
                        :class="frozenClass(SELECT_KEY)"
                        :style="styleFor(SELECT_KEY, true)"
                    >
                        <Checkbox
                            :model-value="allSelected"
                            :aria-label="t('tables.select_all_rows')"
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
                        :ref="measure(ACTIONS_KEY)"
                        class="w-12"
                        :class="frozenClass(ACTIONS_KEY)"
                        :style="styleFor(ACTIONS_KEY, true)"
                    >
                        <span class="sr-only">
                            {{
                                table.recordActions.label ?? t('tables.actions')
                            }}
                        </span>
                    </TableHead>
                    <TableHead
                        v-for="column in visibleColumns"
                        :key="column.name"
                        :ref="measure(column.name)"
                        :class="[
                            ALIGNMENT_CLASSES[column.headerAlignment],
                            column.wrapHeader ? '' : 'whitespace-nowrap',
                            ...frozenClass(column.name),
                        ]"
                        :style="{
                            ...(column.width ? { width: column.width } : {}),
                            ...styleFor(column.name, true),
                        }"
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
                        :ref="measure(ACTIONS_KEY)"
                        class="w-12"
                        :class="frozenClass(ACTIONS_KEY)"
                        :style="styleFor(ACTIONS_KEY, true)"
                    >
                        <span class="sr-only">
                            {{
                                table.recordActions.label ?? t('tables.actions')
                            }}
                        </span>
                    </TableHead>
                </TableRow>

                <!--
                    A second header row rather than boxes crammed beside the
                    labels: a per-column search narrows what the table-wide one
                    already found, and reads as a separate act.
                -->
                <TableRow v-if="hasColumnSearch">
                    <TableHead
                        v-if="table.reorderable"
                        class="w-10"
                        :class="frozenClass(REORDER_KEY)"
                        :style="styleFor(REORDER_KEY, true)"
                    />
                    <TableHead
                        v-if="table.selectable"
                        class="w-10"
                        :class="frozenClass(SELECT_KEY)"
                        :style="styleFor(SELECT_KEY, true)"
                    />
                    <TableHead
                        v-for="column in visibleColumns"
                        :key="column.name"
                        class="py-2"
                        :class="frozenClass(column.name)"
                        :style="styleFor(column.name, true)"
                    >
                        <Input
                            v-if="column.individuallySearchable"
                            :model-value="columnTerms[column.name] ?? ''"
                            class="h-8"
                            type="search"
                            :placeholder="
                                t('tables.search_column', {
                                    column: column.label.toLowerCase(),
                                })
                            "
                            :aria-label="
                                t('tables.search_column', {
                                    column: column.label,
                                })
                            "
                            @update:model-value="
                                (value) =>
                                    onColumnSearch(column.name, String(value))
                            "
                        />
                    </TableHead>
                    <TableHead
                        v-if="hasActionsColumn"
                        class="w-12"
                        :class="frozenClass(ACTIONS_KEY)"
                        :style="styleFor(ACTIONS_KEY, true)"
                    />
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
                        <TableCell
                            v-if="table.reorderable"
                            class="w-10"
                            :class="frozenClass(REORDER_KEY)"
                            :style="styleFor(REORDER_KEY)"
                        >
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
                        <TableCell
                            v-if="table.selectable"
                            class="w-10"
                            :class="frozenClass(SELECT_KEY)"
                            :style="styleFor(SELECT_KEY)"
                        >
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
                            :class="frozenClass(ACTIONS_KEY)"
                            :style="styleFor(ACTIONS_KEY)"
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
                            :class="[
                                ALIGNMENT_CLASSES[column.alignment],
                                ...frozenClass(column.name),
                            ]"
                            :style="styleFor(column.name)"
                            v-bind="row.cellMeta[column.name]?.attributes"
                            :title="row.cellMeta[column.name]?.tooltip"
                        >
                            <!--
                            A linked cell is a real anchor, so it opens in a
                            new tab, copies, and reads as a link. The URL was
                            produced on the server; nothing is resolved here.
                        -->
                            <component
                                :is="cellUrl(row, column) ? 'a' : 'div'"
                                :href="cellUrl(row, column) ?? undefined"
                                :class="
                                    cellUrl(row, column)
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
                            <TableCell
                                v-if="table.reorderable"
                                class="w-10"
                                :class="frozenClass(REORDER_KEY)"
                                :style="styleFor(REORDER_KEY)"
                            />
                            <TableCell
                                v-if="table.selectable"
                                class="w-10"
                                :class="frozenClass(SELECT_KEY)"
                                :style="styleFor(SELECT_KEY)"
                            />
                            <TableCell
                                v-for="column in visibleColumns"
                                :key="column.name"
                                :class="[
                                    ALIGNMENT_CLASSES[column.alignment],
                                    ...frozenClass(column.name),
                                ]"
                                :style="styleFor(column.name)"
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
                    <TableCell
                        v-if="table.reorderable"
                        class="w-10"
                        :class="frozenClass(REORDER_KEY)"
                        :style="styleFor(REORDER_KEY)"
                    />
                    <TableCell
                        v-if="table.selectable"
                        class="w-10"
                        :class="frozenClass(SELECT_KEY)"
                        :style="styleFor(SELECT_KEY)"
                    />
                    <TableCell
                        v-for="column in visibleColumns"
                        :key="column.name"
                        :class="[
                            ALIGNMENT_CLASSES[column.alignment],
                            ...frozenClass(column.name),
                        ]"
                        :style="styleFor(column.name)"
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
