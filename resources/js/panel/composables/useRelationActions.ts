import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import type { Ref } from 'vue';
import type { ActionDefinition } from '@/panel/types/action';
import type { RelationEndpoints } from '@/panel/types/relation';

export type RelationContext = {
    resource: string;
    record: string | number;
    relation: string;
};

export type PendingRelationAction = {
    action: ActionDefinition;
    related: string | number | null;
    records: Array<string | number>;
};

export type UseRelationActionsReturn = {
    pending: Ref<PendingRelationAction | null>;
    processing: Ref<boolean>;
    runRecord: (action: ActionDefinition, related: string | number) => void;
    runBulk: (
        action: ActionDefinition,
        records: Array<string | number>,
    ) => void;
    confirm: () => void;
    cancel: () => void;
};

/**
 * Runs an action a relation manager's table declared.
 *
 * The request names the resource, the owner record, the relation, and the
 * action. Nothing executable, and nothing that widens what may be reached:
 * the server looks the handler up in the schema of the relation it was given
 * and loads the related record through that relation, so a key belonging to
 * another owner resolves to nothing.
 *
 * An action requiring confirmation is held until the dialog is accepted, so
 * the request is only ever made once the user agreed.
 */
export function useRelationActions(
    context: () => RelationContext,
    endpoints: () => RelationEndpoints,
): UseRelationActionsReturn {
    const pending = ref<PendingRelationAction | null>(null);
    const processing = ref(false);

    function dispatch(request: PendingRelationAction): void {
        const isBulk = request.records.length > 0;
        const { resource, record, relation } = context();

        processing.value = true;

        router.post(
            isBulk ? endpoints().bulk : endpoints().action,
            {
                resource,
                record,
                relation,
                action: request.action.name,
                ...(isBulk
                    ? { records: request.records }
                    : { related: request.related }),
            },
            {
                preserveScroll: true,
                onFinish: () => {
                    processing.value = false;
                    pending.value = null;
                },
            },
        );
    }

    function start(request: PendingRelationAction): void {
        if (request.action.type === 'link' && request.action.url !== null) {
            router.visit(request.action.url);

            return;
        }

        if (request.action.confirmation) {
            pending.value = request;

            return;
        }

        dispatch(request);
    }

    return {
        pending,
        processing,

        runRecord(action, related): void {
            start({ action, related, records: [] });
        },

        runBulk(action, records): void {
            start({ action, related: null, records });
        },

        confirm(): void {
            if (pending.value) {
                dispatch(pending.value);
            }
        },

        cancel(): void {
            pending.value = null;
        },
    };
}
