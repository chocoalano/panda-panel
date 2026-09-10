<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import FieldWrapper from '@/panel/forms/fields/FieldWrapper.vue';
import {
    fetchOptions,
    useFormValues,
    useOptionsUrl,
} from '@/panel/forms/optionsEndpoint';
import type { SelectFieldDefinition, SelectOption } from '@/panel/types/form';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

const props = defineProps<{
    field: SelectFieldDefinition;
    modelValue: unknown;
    error?: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string | string[]];
}>();

/**
 * A many-to-many select holds a set of keys. Narrowed rather than asserted,
 * because the value arrives from the server as untyped JSON like every other
 * field value.
 */
const selected = computed<string[]>(() =>
    Array.isArray(props.modelValue)
        ? props.modelValue.map((entry) => String(entry))
        : [],
);

const single = computed<string | undefined>(() =>
    typeof props.modelValue === 'string' || typeof props.modelValue === 'number'
        ? String(props.modelValue)
        : undefined,
);

const optionsUrl = useOptionsUrl();
const formValues = useFormValues();

/**
 * What the remote list is currently doing.
 *
 * A failed search used to be invisible: `run()` returned without touching the
 * options, so a query for "bob" that the server refused left Alice and Alina
 * on screen with nothing to say they were the answer to a different question.
 * Somebody could pick one believing it matched what they typed.
 *
 *   idle      no search running; the list is whatever the form arrived with
 *   loading   a request is out
 *   results   the last request answered
 *   empty     the last request answered with nothing
 *   error     the last request failed and there is nothing behind it
 *   stale     the last request failed and older results are still up
 */
type SearchState = 'idle' | 'loading' | 'results' | 'empty' | 'error' | 'stale';

const search = ref('');
const state = ref<SearchState>('idle');
const searching = computed(() => state.value === 'loading');
const failed = computed(
    () => state.value === 'error' || state.value === 'stale',
);

/**
 * The options the server sent with the form, replaced by a search result once
 * there is one.
 *
 * A relation-backed select renders one bounded page of a table that may have
 * thousands of rows, so the list it arrives with is a starting point rather
 * than the whole truth. Searching is only offered when the field says it is
 * searchable *and* the form provided somewhere to ask.
 */
const searched = ref<SelectOption[] | null>(null);

const canSearch = computed(
    () => props.field.searchable && optionsUrl() !== null,
);

const options = computed<SelectOption[]>(() => {
    const found = searched.value;

    if (found === null) {
        return props.field.options;
    }

    // The selected options are kept in the list whatever the search returned,
    // or choosing one and then typing would blank the control's own label.
    const chosen = props.field.options.filter(
        (option) =>
            !found.some((entry) => entry.value === option.value) &&
            (selected.value.includes(option.value) ||
                option.value === single.value),
    );

    return [...chosen, ...found];
});

const selectedLabels = computed(() =>
    options.value
        .filter((option) => selected.value.includes(option.value))
        .map((option) => option.label),
);

let timer: ReturnType<typeof setTimeout> | null = null;

/**
 * Debounced, because this is a keystroke and the answer is a query. An empty
 * term clears the result rather than asking for an unfiltered page the field
 * already has.
 */
watch(search, (term) => {
    if (timer !== null) {
        clearTimeout(timer);
    }

    if (!canSearch.value) {
        return;
    }

    if (term.trim() === '') {
        searched.value = null;
        state.value = 'idle';

        return;
    }

    state.value = 'loading';

    timer = setTimeout(() => {
        void run(term);
    }, 250);
});

async function run(term: string): Promise<void> {
    const url = optionsUrl();

    if (url === null) {
        return;
    }

    // Only a dependent select sends the form's values. Every other search is
    // a plain GET, which is what the overwhelming majority of them are.
    const found = await fetchOptions(
        url,
        props.field.name,
        term,
        props.field.dependentOptions ? formValues() : undefined,
    );

    // A search that raced ahead of a later keystroke is stale; the later one
    // is already on its way and this answer is about a term nobody typed.
    if (search.value !== term) {
        return;
    }

    // Null means the request failed. The list is still left as it was — an
    // empty list would read as "nothing matches", which is a different and
    // wrong answer — but now the field says so, rather than letting a
    // previous query's results pass for this one's.
    if (found === null) {
        state.value = searched.value === null ? 'error' : 'stale';

        return;
    }

    searched.value = found;
    state.value = found.length > 0 ? 'results' : 'empty';
}

/**
 * Asks again for exactly what was asked before.
 *
 * The term is read from the ref rather than captured, and `run()` reads the
 * form's values at call time, so a dependent select retries against the
 * parent's *current* value — the same state the first attempt would have used
 * had it been made now.
 */
function retry(): void {
    state.value = 'loading';

    void run(search.value);
}

/**
 * Throws away the cached search when the server re-sends this field's own
 * options.
 *
 * A dependent select's list is a function of its parent. When the parent
 * changes, the rebuild brings a new list — and a result cached from the old
 * parent would keep being shown on top of it, which is the stale-options bug
 * rather than a stale-value one. Only for a dependent field: for every other
 * select the sent list never changes, so this would never fire anyway.
 */
watch(
    () => props.field.options,
    () => {
        if (props.field.dependentOptions) {
            searched.value = null;
        }
    },
);

onBeforeUnmount(() => {
    if (timer !== null) {
        clearTimeout(timer);
    }
});

function toggle(value: string, checked: boolean): void {
    const next = checked
        ? [...selected.value, value]
        : selected.value.filter((entry) => entry !== value);

    emit('update:modelValue', next);
}
</script>

<template>
    <FieldWrapper
        v-slot="{ controlId, describedBy, invalid, labelledBy }"
        :name="field.name"
        :inline-label="field.inlineLabel"
        :label="field.label"
        :required="field.required"
        :helper-text="field.helperText"
        :error="error"
        :group="field.multiple"
    >
        <div class="flex flex-col gap-2">
            <div v-if="canSearch" class="flex flex-col gap-1.5">
                <div class="relative">
                    <Input
                        v-model="search"
                        type="search"
                        :aria-label="
                            t('forms.search_field', { field: field.label })
                        "
                        :placeholder="
                            t('forms.search_field_placeholder', {
                                field: field.label,
                            })
                        "
                        :disabled="field.disabled"
                        :aria-busy="searching ? 'true' : undefined"
                    />
                    <Spinner
                        v-if="searching"
                        class="absolute top-1/2 right-2 size-4 -translate-y-1/2"
                    />
                </div>

                <!--
                    A refused request is not an empty result, and results from
                    a previous query are not the answer to this one. Both were
                    silent: the list simply stayed as it was.
                -->
                <div
                    v-if="failed"
                    role="status"
                    class="flex flex-wrap items-center gap-2 text-xs text-destructive"
                >
                    <span>
                        {{
                            state === 'stale'
                                ? t('forms.select_stale')
                                : t('forms.select_failed')
                        }}
                    </span>
                    <button
                        type="button"
                        class="underline underline-offset-2"
                        @click="retry"
                    >
                        {{ t('forms.retry') }}
                    </button>
                </div>

                <p
                    v-else-if="state === 'empty'"
                    class="text-xs text-muted-foreground"
                >
                    {{ t('forms.select_no_matches') }}
                </p>
            </div>

            <!--
                A checkbox list rather than a multi-select control: the set is
                usually small, every option stays visible, and it needs no
                keyboard conventions the rest of the panel does not already
                use.
            -->
            <div
                v-if="field.multiple"
                role="group"
                :aria-labelledby="labelledBy"
                :aria-describedby="describedBy"
                class="flex flex-col gap-2"
            >
                <div
                    v-if="selectedLabels.length > 0"
                    class="flex flex-wrap gap-1"
                >
                    <Badge
                        v-for="label in selectedLabels"
                        :key="label"
                        variant="secondary"
                    >
                        {{ label }}
                    </Badge>
                </div>

                <div
                    class="flex max-h-56 flex-col gap-2 overflow-y-auto rounded-md border p-3"
                >
                    <div
                        v-for="option in options"
                        :key="option.value"
                        class="flex items-center gap-2"
                    >
                        <Checkbox
                            :id="`${controlId}-${option.value}`"
                            :model-value="selected.includes(option.value)"
                            :disabled="field.disabled"
                            @update:model-value="
                                (checked) =>
                                    toggle(option.value, checked === true)
                            "
                        />
                        <Label
                            :for="`${controlId}-${option.value}`"
                            class="font-normal"
                        >
                            {{ option.label }}
                        </Label>
                    </div>

                    <p
                        v-if="options.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        {{ t('forms.select_empty') }}
                    </p>
                </div>
            </div>

            <Select
                v-else
                :model-value="single"
                :disabled="field.disabled"
                @update:model-value="
                    (value) => emit('update:modelValue', String(value))
                "
            >
                <SelectTrigger
                    :id="controlId"
                    :aria-describedby="describedBy"
                    :aria-invalid="invalid"
                    class="w-full"
                >
                    <SelectValue
                        :placeholder="
                            field.placeholder ?? t('forms.select_placeholder')
                        "
                    />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in options"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>
    </FieldWrapper>
</template>
