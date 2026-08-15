<script setup lang="ts">
import type { RequestPayload } from '@inertiajs/core';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import EmptyState from '@/panel/components/EmptyState.vue';
import PageHeader from '@/panel/components/PageHeader.vue';
import PanelLayout from '@/panel/layouts/PanelLayout.vue';
import type { PageMetadata } from '@/panel/types/page';

defineOptions({ layout: PanelLayout });

/**
 * The request builder, laid out the way Postman lays one out: the saved
 * requests down the left, and the one being edited on the right with a
 * method-and-URL bar above its tabs.
 *
 * The shape is borrowed because it is the shape people already know. What is
 * different is the trigger — a request here is not sent when you press a
 * button, it is sent when a record is written — so that control sits beside
 * the URL rather than being hidden in a tab.
 */
interface Integration {
    id: number | null;
    name: string;
    trigger: string;
    method: string;
    url: string;
    headers: Record<string, string>;
    query: Record<string, string>;
    bodyType: 'json' | 'form' | 'none';
    body: string | null;
    isActive: boolean;
    lastStatus: number | null;
    lastError: string | null;
    lastAttemptedAt: string | null;
    secret: string | null;
    deliveries: Delivery[];
}

interface Delivery {
    id: number;
    deliveryId: string;
    trigger: string;
    method: string;
    url: string;
    status: number | null;
    durationMs: number | null;
    error: string | null;
    requestBody: string | null;
    responseBody: string | null;
    attemptedAt: string;
}

const props = defineProps<{
    page: PageMetadata;
    resource: {
        slug: string;
        label: string;
        pluralLabel: string;
        indexUrl: string;
    };
    triggers: { value: string; label: string }[];
    integrations: Integration[];
    endpoints: {
        store: string;
        update: string;
        rotate: string;
        destroy: string;
        send: string;
    };
    allowedHosts: string[];
}>();

const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as const;

/*
 * Both of these hold `{{ … }}`, which is the integration template syntax and
 * not Vue's. Kept in script and bound rather than written inline, because a
 * literal `{{` in the template is an interpolation as far as the compiler is
 * concerned however it was meant.
 */
const BODY_PLACEHOLDER =
    'Leave blank to send the whole record. Or write your own shape:\n' +
    '{ "id": "{{ record.id }}", "email": "{{ record.email }}" }';

const TEMPLATE_HINT = '{{ record.field }}';

const SIGNED_STRING = 'timestamp + "." + body';

const TABS = [
    { key: 'params', label: 'Params' },
    { key: 'headers', label: 'Headers' },
    { key: 'body', label: 'Body' },
    { key: 'signing', label: 'Signing' },
    { key: 'history', label: 'History' },
] as const;

type TabKey = (typeof TABS)[number]['key'];

function blank(): Integration {
    return {
        id: null,
        name: 'New request',
        trigger: props.triggers[0]?.value ?? 'after_create',
        method: 'POST',
        url: '',
        headers: { 'Content-Type': 'application/json' },
        query: {},
        bodyType: 'json',
        body: null,
        isActive: true,
        lastStatus: null,
        lastError: null,
        lastAttemptedAt: null,
        secret: null,
        deliveries: [],
    };
}

const selectedId = ref<number | null>(props.integrations[0]?.id ?? null);
const draft = ref<Integration>(
    props.integrations[0] ? { ...props.integrations[0] } : blank(),
);

const processing = ref(false);
const tab = ref<TabKey>('params');
const sendResult = ref<{ status: number | null; error: string | null } | null>(
    null,
);

// The server is the source of truth for the list. Re-selecting after a save
// keeps the pane showing the request that was just written rather than
// snapping back to the first one.
watch(
    () => props.integrations,
    (list) => {
        const current = list.find((item) => item.id === selectedId.value);

        if (current) {
            draft.value = { ...current };
        }
    },
);

function select(integration: Integration): void {
    selectedId.value = integration.id;
    draft.value = { ...integration };
    sendResult.value = null;
}

function startNew(): void {
    selectedId.value = null;
    draft.value = blank();
    sendResult.value = null;
}

const isNew = computed(() => draft.value.id === null);

/**
 * Header and query maps are edited as rows, because a map is not a thing a
 * person types — but they travel as the object the server stores.
 */
type Row = { key: string; value: string };

function toRows(map: Record<string, string>): Row[] {
    return [
        ...Object.entries(map).map(([key, value]) => ({ key, value })),
        { key: '', value: '' },
    ];
}

function toMap(rows: Row[]): Record<string, string> {
    const map: Record<string, string> = {};

    for (const row of rows) {
        if (row.key.trim() !== '') {
            map[row.key.trim()] = row.value;
        }
    }

    return map;
}

const headerRows = ref<Row[]>(toRows(draft.value.headers));
const queryRows = ref<Row[]>(toRows(draft.value.query));

watch(draft, (value) => {
    headerRows.value = toRows(value.headers);
    queryRows.value = toRows(value.query);
});

function syncRows(rows: Row[], into: 'headers' | 'query'): void {
    draft.value[into] = toMap(rows);

    // One always-empty row at the end, so there is somewhere to type without
    // an "add" button to find first.
    if (rows.length === 0 || rows[rows.length - 1].key.trim() !== '') {
        rows.push({ key: '', value: '' });
    }
}

function payload(): RequestPayload {
    return {
        name: draft.value.name,
        trigger: draft.value.trigger,
        method: draft.value.method,
        url: draft.value.url,
        headers: draft.value.headers,
        query: draft.value.query,
        body_type: draft.value.bodyType,
        body: draft.value.body,
        is_active: draft.value.isActive,
    };
}

function save(): void {
    processing.value = true;

    const url = isNew.value
        ? props.endpoints.store
        : props.endpoints.update.replace('__id__', String(draft.value.id));

    router.visit(url, {
        method: isNew.value ? 'post' : 'put',
        data: payload(),
        preserveScroll: true,
        onFinish: () => (processing.value = false),
    });
}

function destroy(): void {
    if (draft.value.id === null) {
        startNew();

        return;
    }

    router.visit(
        props.endpoints.destroy.replace('__id__', String(draft.value.id)),
        {
            method: 'delete',
            preserveScroll: true,
            onSuccess: () => startNew(),
        },
    );
}

/**
 * Sends the saved request once, now. Only for a request that exists: there is
 * nothing to send until the server has checked the URL against the allowlist.
 */
async function send(): Promise<void> {
    if (draft.value.id === null) {
        return;
    }

    processing.value = true;
    sendResult.value = null;

    try {
        const response = await fetch(
            props.endpoints.send.replace('__id__', String(draft.value.id)),
            {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            },
        );

        const body = (await response.json()) as {
            status: number | null;
            error: string | null;
        };

        sendResult.value = { status: body.status, error: body.error };
    } catch {
        sendResult.value = {
            status: null,
            error: 'The request could not be made.',
        };
    } finally {
        processing.value = false;
    }
}

function statusTone(
    status: number | null,
): 'default' | 'secondary' | 'destructive' {
    if (status === null) {
        return 'destructive';
    }

    return status >= 200 && status < 300 ? 'default' : 'destructive';
}

function rotate(): void {
    if (draft.value.id === null) {
        return;
    }

    router.visit(
        props.endpoints.rotate.replace('__id__', String(draft.value.id)),
        {
            method: 'post',
            preserveScroll: true,
        },
    );
}

const secretVisible = ref(false);

function triggerLabel(value: string): string {
    return props.triggers.find((item) => item.value === value)?.label ?? value;
}
</script>

<template>
    <Head :title="page.title" />

    <div class="flex flex-col gap-6">
        <PageHeader :heading="page.heading" :subheading="page.subheading">
            <template #actions>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="startNew"
                >
                    New request
                </Button>
            </template>
        </PageHeader>

        <div
            v-if="allowedHosts.length === 0"
            class="rounded-lg border border-dashed px-4 py-3 text-sm text-muted-foreground"
        >
            No destination is allowed yet. Add a host to
            <code class="font-mono text-xs">integrations.allowed_hosts</code> in
            <code class="font-mono text-xs">config/panda-panel.php</code>; until
            then every URL here is refused when it is saved.
        </div>

        <div class="grid gap-4 lg:grid-cols-[18rem_1fr]">
            <!-- Saved requests -->
            <div class="flex flex-col gap-1 rounded-lg border p-2">
                <EmptyState
                    v-if="integrations.length === 0"
                    heading="No requests yet"
                    description="A request here is sent when a record is written."
                />

                <button
                    v-for="integration in integrations"
                    :key="integration.id ?? 0"
                    type="button"
                    class="flex flex-col gap-1 rounded-md px-3 py-2 text-left hover:bg-muted"
                    :class="{ 'bg-muted': integration.id === selectedId }"
                    @click="select(integration)"
                >
                    <span class="flex items-center gap-2">
                        <span
                            class="font-mono text-[10px] font-semibold tracking-wide text-muted-foreground"
                        >
                            {{ integration.method }}
                        </span>
                        <span class="truncate text-sm font-medium">
                            {{ integration.name }}
                        </span>
                    </span>
                    <span class="flex items-center gap-2">
                        <Badge variant="secondary" class="text-[10px]">
                            {{ triggerLabel(integration.trigger) }}
                        </Badge>
                        <Badge
                            v-if="!integration.isActive"
                            variant="outline"
                            class="text-[10px]"
                        >
                            Off
                        </Badge>
                    </span>
                </button>
            </div>

            <!-- The request being edited -->
            <div class="flex flex-col gap-4 rounded-lg border p-4">
                <div class="flex flex-wrap items-center gap-2">
                    <Input
                        v-model="draft.name"
                        class="h-9 w-48"
                        aria-label="Request name"
                    />

                    <select
                        v-model="draft.trigger"
                        class="h-9 rounded-md border bg-background px-2 text-sm"
                        aria-label="Trigger"
                    >
                        <option
                            v-for="option in triggers"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>

                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="draft.isActive" type="checkbox" />
                        Active
                    </label>
                </div>

                <!-- Method and URL, the bar everybody recognises -->
                <div class="flex flex-wrap gap-2">
                    <select
                        v-model="draft.method"
                        class="h-10 rounded-md border bg-background px-2 font-mono text-sm font-semibold"
                        aria-label="Method"
                    >
                        <option v-for="method in METHODS" :key="method">
                            {{ method }}
                        </option>
                    </select>

                    <Input
                        v-model="draft.url"
                        class="h-10 min-w-0 flex-1 font-mono text-sm"
                        placeholder="https://api.example.com/hooks/record"
                        aria-label="URL"
                    />

                    <Button type="button" :disabled="processing" @click="save">
                        <Spinner v-if="processing" class="size-4" />
                        Save
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="processing || isNew"
                        @click="send"
                    >
                        Send
                    </Button>
                    <Button type="button" variant="ghost" @click="destroy">
                        Delete
                    </Button>
                </div>

                <!-- Tabs -->
                <div class="flex gap-1 border-b">
                    <button
                        v-for="item in TABS"
                        :key="item.key"
                        type="button"
                        class="-mb-px border-b-2 px-3 py-2 text-sm"
                        :class="
                            tab === item.key
                                ? 'border-primary font-medium'
                                : 'border-transparent text-muted-foreground'
                        "
                        @click="tab = item.key"
                    >
                        {{ item.label }}
                    </button>
                </div>

                <div
                    v-if="tab === 'params' || tab === 'headers'"
                    class="flex flex-col gap-2"
                >
                    <div
                        v-for="(row, index) in tab === 'headers'
                            ? headerRows
                            : queryRows"
                        :key="index"
                        class="grid grid-cols-1 gap-2 md:grid-cols-2"
                    >
                        <Input
                            v-model="row.key"
                            class="h-9 font-mono text-xs"
                            :placeholder="
                                tab === 'headers' ? 'Header' : 'Parameter'
                            "
                            @input="
                                syncRows(
                                    tab === 'headers' ? headerRows : queryRows,
                                    tab === 'headers' ? 'headers' : 'query',
                                )
                            "
                        />
                        <Input
                            v-model="row.value"
                            class="h-9 font-mono text-xs"
                            placeholder="Value"
                            @input="
                                syncRows(
                                    tab === 'headers' ? headerRows : queryRows,
                                    tab === 'headers' ? 'headers' : 'query',
                                )
                            "
                        />
                    </div>
                </div>

                <!-- Signing -->
                <div v-else-if="tab === 'signing'" class="flex flex-col gap-3">
                    <p class="max-w-prose text-sm text-muted-foreground">
                        Every request carries
                        <code class="font-mono text-xs">X-Panel-Signature</code
                        >, an HMAC-SHA256 over
                        <code class="font-mono text-xs">{{
                            SIGNED_STRING
                        }}</code>
                        using the secret below, and
                        <code class="font-mono text-xs">X-Panel-Delivery</code>,
                        which is stable across the retries of one delivery so
                        the receiver can deduplicate.
                    </p>

                    <div class="flex flex-wrap items-center gap-2">
                        <Input
                            :model-value="
                                secretVisible
                                    ? (draft.secret ?? '')
                                    : '••••••••••••••••••••••••'
                            "
                            readonly
                            class="h-9 min-w-0 flex-1 font-mono text-xs"
                            aria-label="Signing secret"
                        />
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="secretVisible = !secretVisible"
                        >
                            {{ secretVisible ? 'Hide' : 'Reveal' }}
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            :disabled="isNew"
                            @click="rotate"
                        >
                            Rotate
                        </Button>
                    </div>

                    <p class="text-xs text-muted-foreground">
                        Rotating takes effect on the very next send. Update the
                        receiving system first.
                    </p>
                </div>

                <!-- History -->
                <div v-else-if="tab === 'history'" class="flex flex-col gap-2">
                    <EmptyState
                        v-if="draft.deliveries.length === 0"
                        heading="Nothing sent yet"
                        description="Attempts appear here once this request fires."
                    />

                    <div
                        v-for="delivery in draft.deliveries"
                        :key="delivery.id"
                        class="flex flex-col gap-1 rounded-md border px-3 py-2 text-xs"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <Badge :variant="statusTone(delivery.status)">
                                {{ delivery.status ?? 'failed' }}
                            </Badge>
                            <span
                                class="font-mono text-[10px] text-muted-foreground"
                            >
                                {{ delivery.method }} {{ delivery.url }}
                            </span>
                            <span class="ml-auto text-muted-foreground">
                                {{ delivery.durationMs }}ms ·
                                {{ delivery.attemptedAt }}
                            </span>
                        </div>

                        <p
                            v-if="delivery.error"
                            class="font-mono break-all text-destructive"
                        >
                            {{ delivery.error }}
                        </p>

                        <details
                            v-if="delivery.requestBody || delivery.responseBody"
                        >
                            <summary
                                class="cursor-pointer text-muted-foreground"
                            >
                                Bodies
                            </summary>
                            <pre
                                v-if="delivery.requestBody"
                                class="mt-1 overflow-x-auto rounded bg-muted/60 p-2 font-mono"
                                >{{ delivery.requestBody }}</pre>
                            <pre
                                v-if="delivery.responseBody"
                                class="mt-1 overflow-x-auto rounded bg-muted/60 p-2 font-mono"
                                >{{ delivery.responseBody }}</pre>
                        </details>
                    </div>
                </div>

                <div v-else class="flex flex-col gap-2">
                    <div class="flex items-center gap-3">
                        <Label
                            v-for="type in ['json', 'form', 'none'] as const"
                            :key="type"
                            class="flex items-center gap-1.5 text-sm font-normal"
                        >
                            <input
                                v-model="draft.bodyType"
                                type="radio"
                                :value="type"
                            />
                            {{ type }}
                        </Label>
                    </div>

                    <Textarea
                        v-if="draft.bodyType !== 'none'"
                        rows="10"
                        class="font-mono text-xs"
                        :placeholder="BODY_PLACEHOLDER"
                        :model-value="draft.body ?? ''"
                        @update:model-value="
                            (value) => (draft.body = String(value))
                        "
                    />

                    <p class="text-xs text-muted-foreground">
                        <code class="font-mono">{{ TEMPLATE_HINT }}</code>
                        is substituted from the payload. It is not Blade — paths
                        only, no expressions.
                    </p>
                </div>

                <!-- What the last attempt did -->
                <div
                    v-if="sendResult || draft.lastAttemptedAt"
                    class="flex flex-col gap-1 rounded-md bg-muted/60 px-3 py-2 text-xs"
                >
                    <span class="flex items-center gap-2">
                        <Badge
                            :variant="
                                statusTone(
                                    sendResult
                                        ? sendResult.status
                                        : draft.lastStatus,
                                )
                            "
                        >
                            {{
                                (sendResult
                                    ? sendResult.status
                                    : draft.lastStatus) ?? 'failed'
                            }}
                        </Badge>
                        <span class="text-muted-foreground">
                            {{
                                sendResult ? 'just now' : draft.lastAttemptedAt
                            }}
                        </span>
                    </span>
                    <span
                        v-if="sendResult ? sendResult.error : draft.lastError"
                        class="font-mono break-all text-destructive"
                    >
                        {{ sendResult ? sendResult.error : draft.lastError }}
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>
