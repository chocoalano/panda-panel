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
import { fetchOptions, useOptionsUrl } from '@/panel/forms/optionsEndpoint';
import type { SelectFieldDefinition, SelectOption } from '@/panel/types/form';

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

const search = ref('');
const searching = ref(false);

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
        searching.value = false;

        return;
    }

    searching.value = true;

    timer = setTimeout(() => {
        void run(term);
    }, 250);
});

async function run(term: string): Promise<void> {
    const url = optionsUrl();

    if (url === null) {
        return;
    }

    const found = await fetchOptions(url, props.field.name, term);

    // A search that raced ahead of a later keystroke is stale; the later one
    // is already on its way and this answer is about a term nobody typed.
    if (search.value !== term) {
        return;
    }

    searching.value = false;

    // Null means the request failed. Leaving the list as it was is the honest
    // answer: an empty list would read as "nothing matches".
    if (found !== null) {
        searched.value = found;
    }
}

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
        :name="field.name"
        :inline-label="field.inlineLabel"
        :label="field.label"
        :required="field.required"
        :helper-text="field.helperText"
        :error="error"
    >
        <div class="flex flex-col gap-2">
            <div v-if="canSearch" class="relative">
                <Input
                    v-model="search"
                    type="search"
                    :placeholder="`Search ${field.label.toLowerCase()}...`"
                    :disabled="field.disabled"
                />
                <Spinner
                    v-if="searching"
                    class="absolute top-1/2 right-2 size-4 -translate-y-1/2"
                />
            </div>

            <!--
                A checkbox list rather than a multi-select control: the set is
                usually small, every option stays visible, and it needs no
                keyboard conventions the rest of the panel does not already
                use.
            -->
            <div v-if="field.multiple" class="flex flex-col gap-2">
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
                            :id="`${field.name}-${option.value}`"
                            :model-value="selected.includes(option.value)"
                            :disabled="field.disabled"
                            @update:model-value="
                                (checked) =>
                                    toggle(option.value, checked === true)
                            "
                        />
                        <Label
                            :for="`${field.name}-${option.value}`"
                            class="font-normal"
                        >
                            {{ option.label }}
                        </Label>
                    </div>

                    <p
                        v-if="options.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        Nothing to choose from.
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
                <SelectTrigger :id="field.name" class="w-full">
                    <SelectValue
                        :placeholder="field.placeholder ?? 'Select...'"
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
