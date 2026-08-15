import type { ActionDefinition } from '@/panel/types/action';
import type {
    PaginationMeta,
    TableDefinition,
    TableRow,
    TableState,
    TableGroupSummaries,
    TableSummaries,
} from '@/panel/types/table';

/**
 * Mirrors `PandaPanel\Resources\RelationTable::toArray()`.
 *
 * A relation manager sends the same shape a resource index does — definition,
 * applied state, rows, pagination — so the same table components render it.
 * What it adds is the identity of the relation, the endpoints its writes post
 * to, and the key its table state lives under in the query string.
 */
export interface RelationEndpoints {
    /** Fetches a form, and posts it back. */
    form: string;
    save: string;
    action: string;
    bulk: string;
}

export interface RelationDefinition {
    key: string;
    title: string;
    icon: string | null;
    /**
     * The query-string namespace this table's state lives under, such as
     * `relations.posts`. Several relation tables share one page, so each
     * needs its own page, sort, and search.
     */
    stateKey: string;
    table: TableDefinition;
    state: TableState;
    rows: TableRow[];
    summaries: TableSummaries;
    groupSummaries: TableGroupSummaries;
    pagination: PaginationMeta;
    headerActions: ActionDefinition[];
    endpoints: RelationEndpoints;
}
