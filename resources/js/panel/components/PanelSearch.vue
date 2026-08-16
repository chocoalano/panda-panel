<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Loader2, Search } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { safeUrl } from '@/lib/utils';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { usePanel } from '@/panel/composables/usePanel';
import { resolveIcon } from '@/panel/icons/registry';
import type { PanelSearchGroup } from '@/panel/types/panel';

/**
 * The panel's command palette.
 *
 * Renders nothing unless the panel says searching is on *and* a resource in
 * it opted in — a palette that can only ever answer nothing is worse than no
 * palette.
 *
 * Results are whole: the server has already authorized the resource,
 * resolved each title and detail, and generated each URL. Nothing here
 * decides what a record is or where it lives.
 */
const { search } = usePanel();

const open = ref(false);
const term = ref('');
const groups = ref<PanelSearchGroup[]>([]);
const searching = ref(false);
const active = ref(0);

const enabled = computed(
    () => search.value.enabled && search.value.url !== null,
);
const hasResults = computed(() => groups.value.length > 0);

/**
 * Results flattened in the order they are drawn, so the arrow keys walk the
 * list the user sees rather than the groups it arrived in.
 */
const flat = computed(() => groups.value.flatMap((group) => group.results));

function isActive(url: string): boolean {
    return flat.value[active.value]?.url === url;
}

function resultUrl(url: string): string {
    return safeUrl(url) ?? '#';
}

/**
 * Arrows wrap, which is what a short list wants: up from the first result
 * reaches the last rather than doing nothing.
 */
function move(delta: number): void {
    const count = flat.value.length;

    if (count === 0) {
        return;
    }

    active.value = (active.value + delta + count) % count;
}

function onListKeydown(event: KeyboardEvent): void {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        move(1);

        return;
    }

    if (event.key === 'ArrowUp') {
        event.preventDefault();
        move(-1);

        return;
    }

    if (event.key === 'Enter') {
        const result = flat.value[active.value];
        const url = safeUrl(result?.url);

        if (url) {
            event.preventDefault();
            open.value = false;
            router.visit(url);
        }
    }
}

let timer: ReturnType<typeof setTimeout> | null = null;
let inFlight: AbortController | null = null;

/**
 * Validated rather than asserted: this crosses the same boundary an Inertia
 * prop does, so a malformed group degrades to nothing instead of throwing
 * inside the dialog.
 */
function toGroups(payload: unknown): PanelSearchGroup[] {
    if (typeof payload !== 'object' || payload === null) {
        return [];
    }

    const raw = (payload as { groups?: unknown }).groups;

    if (!Array.isArray(raw)) {
        return [];
    }

    return raw.filter((group): group is PanelSearchGroup => {
        if (typeof group !== 'object' || group === null) {
            return false;
        }

        const candidate = group as Partial<PanelSearchGroup>;

        return (
            typeof candidate.label === 'string' &&
            Array.isArray(candidate.results)
        );
    });
}

async function run(value: string): Promise<void> {
    const url = search.value.url;

    if (url === null || value.trim().length < 2) {
        groups.value = [];
        searching.value = false;

        return;
    }

    // One request at a time: a slower earlier answer must not overwrite a
    // faster later one.
    inFlight?.abort();
    inFlight = new AbortController();

    searching.value = true;

    try {
        const response = await fetch(`${url}?q=${encodeURIComponent(value)}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: inFlight.signal,
        });

        groups.value = response.ok ? toGroups(await response.json()) : [];

        // New answers, new selection: keeping the old index would highlight
        // whatever happens to sit there now.
        active.value = 0;
    } catch {
        // An aborted request is the normal case while typing, and a failed
        // one has nothing useful to say inside a palette.
        return;
    } finally {
        searching.value = false;
    }
}

watch(term, (value) => {
    if (timer !== null) {
        clearTimeout(timer);
    }

    timer = setTimeout(() => void run(value), search.value.debounce);
});

/**
 * `mod` is the platform's command key, so one binding covers both.
 */
function matches(binding: string, event: KeyboardEvent): boolean {
    const parts = binding.toLowerCase().split('+');
    const key = parts[parts.length - 1];

    if (event.key.toLowerCase() !== key) {
        return false;
    }

    return parts.slice(0, -1).every((modifier) => {
        if (modifier === 'mod') {
            return event.metaKey || event.ctrlKey;
        }

        if (modifier === 'shift') {
            return event.shiftKey;
        }

        if (modifier === 'alt') {
            return event.altKey;
        }

        return false;
    });
}

function onKeydown(event: KeyboardEvent): void {
    if (!enabled.value) {
        return;
    }

    if (search.value.keyBindings.some((binding) => matches(binding, event))) {
        event.preventDefault();
        open.value = true;
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);

    if (timer !== null) {
        clearTimeout(timer);
    }

    inFlight?.abort();
});

// A visit means the user found what they wanted.
router.on('start', () => {
    open.value = false;
});
</script>

<template>
    <Dialog v-if="enabled" v-model:open="open">
        <Button
            variant="ghost"
            size="icon-sm"
            aria-label="Search"
            @click="open = true"
        >
            <Search />
        </Button>

        <DialogContent class="max-w-xl gap-0 p-0">
            <DialogHeader class="border-b px-4 py-3">
                <DialogTitle class="sr-only">Search</DialogTitle>
                <DialogDescription class="sr-only">
                    Search across this panel's resources.
                </DialogDescription>

                <div class="flex items-center gap-2">
                    <Search class="size-4 shrink-0 text-muted-foreground" />
                    <Input
                        v-model="term"
                        autofocus
                        placeholder="Search..."
                        class="border-0 shadow-none focus-visible:ring-0"
                        @keydown="onListKeydown"
                    />
                    <Loader2
                        v-if="searching"
                        class="size-4 shrink-0 animate-spin text-muted-foreground"
                    />
                </div>
            </DialogHeader>

            <div class="max-h-96 overflow-y-auto p-2">
                <p
                    v-if="!hasResults"
                    class="px-2 py-6 text-center text-sm text-muted-foreground"
                >
                    {{
                        term.trim().length < 2
                            ? 'Type at least two characters.'
                            : 'Nothing found.'
                    }}
                </p>

                <div
                    v-for="group in groups"
                    :key="group.resource"
                    class="flex flex-col gap-1 py-1"
                >
                    <p
                        class="flex items-center gap-2 px-2 py-1 text-xs font-medium text-muted-foreground"
                    >
                        <component
                            :is="resolveIcon(group.icon)"
                            v-if="resolveIcon(group.icon)"
                            class="size-3.5"
                        />
                        {{ group.label }}
                    </p>

                    <Link
                        v-for="result in group.results"
                        :key="result.url"
                        :href="resultUrl(result.url)"
                        :data-active="
                            isActive(resultUrl(result.url)) ? 'true' : undefined
                        "
                        :aria-selected="isActive(resultUrl(result.url))"
                        class="flex flex-col gap-0.5 rounded-md px-2 py-2 text-sm transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none data-[active=true]:bg-accent data-[active=true]:text-accent-foreground"
                    >
                        <span class="font-medium">{{ result.title }}</span>
                        <span
                            v-if="Object.keys(result.details).length > 0"
                            class="flex flex-wrap gap-x-3 text-xs text-muted-foreground"
                        >
                            <span
                                v-for="(value, key) in result.details"
                                :key="key"
                            >
                                {{ key }}: {{ value }}
                            </span>
                        </span>
                    </Link>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
