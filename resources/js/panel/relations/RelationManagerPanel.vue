<script setup lang="ts">
import { computed, ref } from 'vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import ActionButton from '@/panel/actions/ActionButton.vue';
import ActionDialog from '@/panel/actions/ActionDialog.vue';
import { useRelationActions } from '@/panel/composables/useRelationActions';
import { useRelationTable } from '@/panel/composables/useRelationTable';
import RelationFormDialog from '@/panel/relations/RelationFormDialog.vue';
import DataTable from '@/panel/tables/DataTable.vue';
import DataTableBulkActions from '@/panel/tables/DataTableBulkActions.vue';
import DataTableColumnManager from '@/panel/tables/DataTableColumnManager.vue';
import DataTablePagination from '@/panel/tables/DataTablePagination.vue';
import DataTableToolbar from '@/panel/tables/DataTableToolbar.vue';
import type { ActionDefinition } from '@/panel/types/action';
import type { RelationDefinition } from '@/panel/types/relation';

/**
 * One relation manager, rendered from the same table components a resource
 * index uses.
 *
 * It holds no state of its own beyond the current selection and which dialog
 * is open. Sorting, searching, filtering, and paging all write to this
 * relation's own slice of the query string and let the server answer, so
 * several of these on one page never move each other.
 */
const props = defineProps<{
    relation: RelationDefinition;
    /** The resource slug and owner key every write is posted with. */
    resource: string;
    record: string | number;
}>();

const {
    setSearch,
    setSort,
    setPage,
    setPerPage,
    setFilter,
    setFilters,
    setColumnSearch,
    setColumns,
    resetColumns,
    clearFilters,
} = useRelationTable(() => props.relation);

const { pending, processing, runRecord, runBulk, confirm, cancel } =
    useRelationActions(
        () => ({
            resource: props.resource,
            record: props.record,
            relation: props.relation.key,
        }),
        () => props.relation.endpoints,
    );

const selected = ref<Array<string | number>>([]);
const tableRef = ref<InstanceType<typeof DataTable> | null>(null);
const openFormUrl = ref<string | null>(null);

/**
 * A form action opens a dialog; everything else goes to the action endpoint.
 * The decision is the server's — it is the action's declared type — so this
 * only routes it.
 */
function onRunAction(action: ActionDefinition, record: string | number): void {
    if (action.type === 'form' && action.formUrl !== null) {
        openFormUrl.value = action.formUrl;

        return;
    }

    runRecord(action, record);
}

function onRunHeaderAction(action: ActionDefinition): void {
    if (action.formUrl !== null) {
        openFormUrl.value = action.formUrl;
    }
}

function onRunBulk(action: ActionDefinition): void {
    runBulk(action, selected.value);
}

function clearSelection(): void {
    tableRef.value?.clearSelection();
    selected.value = [];
}

const headerActions = computed(() => props.relation.headerActions);
</script>

<template>
    <Card>
        <CardHeader
            class="flex flex-row flex-wrap items-center justify-between gap-2"
        >
            <CardTitle>{{ relation.title }}</CardTitle>

            <div class="flex items-center gap-2">
                <ActionButton
                    v-for="action in headerActions"
                    :key="action.name"
                    :action="action"
                    size="sm"
                    @run="onRunHeaderAction(action)"
                />
            </div>
        </CardHeader>

        <CardContent class="flex flex-col gap-4">
            <DataTableToolbar
                :table="relation.table"
                :state="relation.state"
                @search="setSearch"
                @filter="setFilter"
                @filters="setFilters"
                @clear="clearFilters"
            >
                <template #actions>
                    <DataTableColumnManager
                        :table="relation.table"
                        :visible="relation.state.columns.visible"
                        :order="relation.state.columns.order"
                        @change="setColumns"
                        @reset="resetColumns"
                    />
                </template>
            </DataTableToolbar>

            <DataTableBulkActions
                :actions="relation.table.bulkActions"
                :selected="selected"
                :processing="processing"
                @run="onRunBulk"
                @clear="clearSelection"
            />

            <DataTable
                ref="tableRef"
                :table="relation.table"
                :rows="relation.rows"
                :state="relation.state"
                :summaries="relation.summaries"
                :group-summaries="relation.groupSummaries"
                @sort="setSort"
                @column-search="setColumnSearch"
                @selection-change="(keys) => (selected = keys)"
                @run-action="onRunAction"
            />

            <DataTablePagination
                :pagination="relation.pagination"
                :per-page-options="relation.table.perPageOptions"
                @page="setPage"
                @per-page="setPerPage"
            />
        </CardContent>

        <ActionDialog
            :action="pending?.action ?? null"
            :processing="processing"
            @confirm="confirm"
            @cancel="cancel"
        />

        <RelationFormDialog
            :form-url="openFormUrl"
            @close="openFormUrl = null"
        />
    </Card>
</template>
