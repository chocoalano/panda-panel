<script setup lang="ts">
import { Calendar as CalendarIcon, X } from '@lucide/vue';
import {
    CalendarDate,
    DateFormatter,
    getLocalTimeZone,
    parseDate,
} from '@internationalized/date';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { useTranslator } from '@/composables/useTranslator';

const { t, locale } = useTranslator();

/**
 * One date, chosen from a calendar.
 *
 * The control every date in a panel is picked with: a resource form's date
 * field and a table's date-range filter both mount this, so a date looks and
 * behaves the same wherever it is asked for.
 *
 * It replaces `<input type="date">`, whose rendering, keyboard handling, and
 * clear affordance are the browser's rather than the panel's — Chrome, Firefox
 * and Safari each draw a different control, none of them themeable, and none
 * of them matching the rest of the panel. The value crossing the boundary is
 * unchanged: an ISO `YYYY-MM-DD` string, or `null` for no date. That is what
 * the server already validates and what `DateFilter::sanitize()` already
 * parses, so nothing behind this component had to move.
 */
const props = withDefaults(
    defineProps<{
        /** ISO `YYYY-MM-DD`, or null for no date. */
        modelValue: string | null;
        id?: string;
        disabled?: boolean;
        /** ISO bounds. A day outside them is rendered unselectable. */
        min?: string | null;
        max?: string | null;
        /** Shown on the trigger while no date is set. */
        placeholder?: string;
        invalid?: boolean;
        ariaLabel?: string;
        /** Set false where clearing is the parent's job. */
        clearable?: boolean;
        class?: string;
    }>(),
    {
        id: undefined,
        disabled: false,
        min: null,
        max: null,
        placeholder: undefined,
        invalid: false,
        ariaLabel: undefined,
        clearable: true,
        class: undefined,
    },
);

const emit = defineEmits<{ 'update:modelValue': [value: string | null] }>();

const open = ref(false);

/**
 * A date that reached here from a query string or a server payload is a string
 * somebody could have typed, so parsing is guarded: `parseDate` throws on
 * anything that is not a calendar date, and a filter carrying `?from=lol` must
 * render an empty control rather than break the bar it sits in.
 */
function toCalendarDate(value: string | null | undefined): CalendarDate | null {
    if (typeof value !== 'string' || value === '') {
        return null;
    }

    try {
        return parseDate(value.slice(0, 10));
    } catch {
        return null;
    }
}

const selected = computed(() => toCalendarDate(props.modelValue) ?? undefined);

const minValue = computed(() => toCalendarDate(props.min) ?? undefined);
const maxValue = computed(() => toCalendarDate(props.max) ?? undefined);

/**
 * Medium rather than numeric: `01/02` is January the second on one side of the
 * Atlantic and the first of February on the other, and the trigger is read at
 * a glance. The same reasoning as the filter chip's own format.
 */
// Rebuilt when the locale changes rather than constructed once: a date
// written "Jan 5, 2026" is not how it reads anywhere the panel is not
// English, and the locale is a prop the server resolved.
const formatter = computed(
    () => new DateFormatter(locale.value, { dateStyle: 'medium' }),
);

const label = computed(() => {
    const date = selected.value;

    return date === undefined
        ? (props.placeholder ?? t('forms.pick_a_date'))
        : formatter.value.format(date.toDate(getLocalTimeZone()));
});

const showClear = computed(
    () => props.clearable && !props.disabled && selected.value !== undefined,
);

function onSelect(value: unknown): void {
    if (value === undefined || value === null) {
        emit('update:modelValue', null);

        return;
    }

    // `CalendarDate.toString()` is already `YYYY-MM-DD`, and deliberately not
    // routed through a `Date`: converting to one applies a timezone, and a
    // date picked as the 1st can arrive at the server as the 31st.
    emit('update:modelValue', String(value));
    open.value = false;
}

function onClear(): void {
    emit('update:modelValue', null);
    open.value = false;
}
</script>

<template>
    <div :class="cn('relative', props.class)">
        <Popover v-model:open="open">
            <PopoverTrigger as-child>
                <Button
                    :id="id"
                    type="button"
                    variant="outline"
                    :disabled="disabled"
                    :aria-label="ariaLabel"
                    :aria-invalid="invalid ? true : undefined"
                    :class="
                        cn(
                            'h-8 w-full justify-start text-left font-normal',
                            showClear && 'pr-8',
                            selected === undefined && 'text-muted-foreground',
                        )
                    "
                >
                    <CalendarIcon class="size-4 shrink-0 opacity-60" />
                    <span class="truncate">{{ label }}</span>
                </Button>
            </PopoverTrigger>
            <PopoverContent class="w-auto p-0" align="start">
                <Calendar
                    :locale="locale"
                    :model-value="selected"
                    :min-value="minValue"
                    :max-value="maxValue"
                    initial-focus
                    @update:model-value="onSelect"
                />
            </PopoverContent>
        </Popover>

        <!--
            Outside the trigger button rather than inside it: a button nested in
            a button is invalid HTML, and the browser drops the inner one — so
            the clear would render and never fire.
        -->
        <button
            v-if="showClear"
            type="button"
            class="absolute top-1/2 right-2 -translate-y-1/2 rounded-sm text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            :aria-label="`Clear ${ariaLabel ?? 'date'}`"
            @click="onClear"
        >
            <X class="size-3.5" />
        </button>
    </div>
</template>
