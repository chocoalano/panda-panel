<script setup lang="ts">
import { X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { useTranslator } from '@/composables/useTranslator';
import { parseTime } from '@/panel/components/temporalValue';

const { t } = useTranslator();

/**
 * One time of day, chosen from the panel's own controls.
 *
 * The control every time in a panel is picked with: a time field and the time
 * half of a datetime field both mount this, so a time looks and behaves the
 * same wherever it is asked for — the same reasoning, and the same shape, as
 * `PanelDatePicker`.
 *
 * It replaces `<input type="time">`, whose rendering, keyboard handling and
 * clear affordance belong to the browser rather than to the panel. Chrome,
 * Firefox and Safari each draw a different control, none of them themeable,
 * and a value carrying seconds is quietly rounded to the minute by some of
 * them. The value crossing the boundary is unchanged: `HH:mm`, or `HH:mm:ss`
 * when the field asks for seconds, or `null` for no time. That is what
 * `TimePicker::typeRules()` already validates with `date_format`, so nothing
 * behind this component had to move.
 *
 * Three selects rather than one: a single list of every minute in a day is
 * 1,440 options, and with seconds it is 86,400. Hours, minutes and seconds are
 * chosen separately, which is also what makes each one reachable by name.
 */
const props = withDefaults(
    defineProps<{
        /** `HH:mm`, `HH:mm:ss`, or null for no time. */
        modelValue: string | null;
        /** Whether the field's contract includes seconds. */
        seconds?: boolean;
        /**
         * The field's own id. Each select derives its id from it, so a
         * repeater entry gets distinct ids without a counter of its own —
         * see `fieldIdentity.ts`, which is what produced this value.
         */
        id?: string;
        disabled?: boolean;
        invalid?: boolean;
        /** Helper and error ids, placed on every select rather than a wrapper. */
        describedBy?: string;
        /** Names the group. A datetime field passes its own label's id. */
        labelledBy?: string;
        ariaLabel?: string;
        clearable?: boolean;
        /**
         * Inclusive bounds, as `HH:mm` or `HH:mm:ss`.
         *
         * Only meaningful once the caller has decided they apply: a datetime
         * field passes a lower bound when the chosen date *is* the minimum
         * date, and nothing when it is later. Options outside the bound are
         * rendered unselectable rather than accepted and corrected.
         */
        minTime?: string | null;
        maxTime?: string | null;
        class?: string;
    }>(),
    {
        seconds: false,
        id: undefined,
        disabled: false,
        invalid: false,
        describedBy: undefined,
        labelledBy: undefined,
        ariaLabel: undefined,
        clearable: true,
        minTime: null,
        maxTime: null,
        class: undefined,
    },
);

const emit = defineEmits<{ 'update:modelValue': [value: string | null] }>();

function pad(value: number): string {
    return String(value).padStart(2, '0');
}

/**
 * The parts as chosen so far.
 *
 * Kept separately from the model value because a time is assembled from two
 * or three decisions and the value in between them is not a time. Emitting
 * `09:` while somebody is still choosing the minute would put a string the
 * server rejects into the form's state; the field stays null until every part
 * it needs has been answered.
 */
const hour = ref<number | null>(null);
const minute = ref<number | null>(null);
const second = ref<number | null>(null);

watch(
    () => props.modelValue,
    (value) => {
        const parts = parseTime(value);

        if (parts === null) {
            // A value that arrives empty clears the draft; one that fails to
            // parse does too, rather than leaving parts of a previous time on
            // screen that no longer describe the field.
            hour.value = null;
            minute.value = null;
            second.value = null;

            return;
        }

        hour.value = parts.hour;
        minute.value = parts.minute;
        second.value = props.seconds ? parts.second : null;
    },
    { immediate: true },
);

const minParts = computed(() => parseTime(props.minTime));
const maxParts = computed(() => parseTime(props.maxTime));

/**
 * Whether one option would put the time outside its bounds.
 *
 * Each part is only bounded once the parts above it are equal to the bound:
 * with a minimum of 09:30, every minute is available at 10, and only minutes
 * from 30 are available at 9. Asking the question this way is what keeps
 * 10:00 selectable while 09:00 is not.
 */
function hourDisabled(value: number): boolean {
    const min = minParts.value;
    const max = maxParts.value;

    return (
        (min !== null && value < min.hour) || (max !== null && value > max.hour)
    );
}

function minuteDisabled(value: number): boolean {
    const min = minParts.value;
    const max = maxParts.value;
    const current = hour.value;

    if (current === null) {
        return false;
    }

    return (
        (min !== null && current === min.hour && value < min.minute) ||
        (max !== null && current === max.hour && value > max.minute)
    );
}

function secondDisabled(value: number): boolean {
    const min = minParts.value;
    const max = maxParts.value;
    const currentHour = hour.value;
    const currentMinute = minute.value;

    if (currentHour === null || currentMinute === null) {
        return false;
    }

    return (
        (min !== null &&
            currentHour === min.hour &&
            currentMinute === min.minute &&
            value < min.second) ||
        (max !== null &&
            currentHour === max.hour &&
            currentMinute === max.minute &&
            value > max.second)
    );
}

const HOURS = Array.from({ length: 24 }, (_, index) => index);
const MINUTES = Array.from({ length: 60 }, (_, index) => index);

/** What the field currently amounts to, or null while it is incomplete. */
function assemble(): string | null {
    if (hour.value === null || minute.value === null) {
        return null;
    }

    if (!props.seconds) {
        return `${pad(hour.value)}:${pad(minute.value)}`;
    }

    if (second.value === null) {
        return null;
    }

    return `${pad(hour.value)}:${pad(minute.value)}:${pad(second.value)}`;
}

function publish(): void {
    const next = assemble();

    if (next !== props.modelValue) {
        emit('update:modelValue', next);
    }
}

/**
 * Choosing a coarser part can invalidate a finer one already chosen — with a
 * minimum of 09:30, a time of 10:00 is valid until the hour is changed to 9.
 * The finer parts are cleared rather than nudged to the nearest legal value:
 * silently moving somebody's 08:00 to 09:30 is a decision the field is not
 * entitled to make, and an empty select says plainly that the answer is
 * needed again.
 */
function onHour(value: string): void {
    hour.value = Number(value);

    if (minute.value !== null && minuteDisabled(minute.value)) {
        minute.value = null;
        second.value = null;
    } else if (second.value !== null && secondDisabled(second.value)) {
        second.value = null;
    }

    publish();
}

function onMinute(value: string): void {
    minute.value = Number(value);

    if (second.value !== null && secondDisabled(second.value)) {
        second.value = null;
    }

    publish();
}

function onSecond(value: string): void {
    second.value = Number(value);
    publish();
}

function onClear(): void {
    hour.value = null;
    minute.value = null;
    second.value = null;
    publish();
}

const hourValue = computed(() =>
    hour.value === null ? undefined : pad(hour.value),
);
const minuteValue = computed(() =>
    minute.value === null ? undefined : pad(minute.value),
);
const secondValue = computed(() =>
    second.value === null ? undefined : pad(second.value),
);

const showClear = computed(
    () =>
        props.clearable &&
        !props.disabled &&
        (hour.value !== null || minute.value !== null || second.value !== null),
);

const groupLabel = computed(() => props.ariaLabel ?? t('forms.time'));

function partId(part: string): string | undefined {
    return props.id === undefined ? undefined : `${props.id}-${part}`;
}
</script>

<template>
    <!--
        One group with three controls rather than three unrelated selects: the
        hour, the minute and the second are parts of a single answer, and a
        reader moving through the form should be told that once rather than
        meeting three fields that happen to sit together.
    -->
    <div
        role="group"
        :aria-label="labelledBy === undefined ? groupLabel : undefined"
        :aria-labelledby="labelledBy"
        :class="cn('flex items-center gap-1', props.class)"
    >
        <Select
            :model-value="hourValue"
            :disabled="disabled"
            @update:model-value="(value) => onHour(String(value))"
        >
            <!--
                `aria-describedby` and `aria-invalid` are on each trigger
                rather than on the group: the trigger is what takes focus, and
                an attribute on a wrapper is an attribute on something nobody
                lands on.
            -->
            <SelectTrigger
                :id="partId('hour')"
                size="sm"
                class="w-[4.5rem]"
                :aria-label="t('forms.hour')"
                :aria-describedby="describedBy"
                :aria-invalid="invalid ? true : undefined"
            >
                <SelectValue placeholder="HH" />
            </SelectTrigger>
            <SelectContent>
                <SelectItem
                    v-for="value in HOURS"
                    :key="value"
                    :value="String(value).padStart(2, '0')"
                    :disabled="hourDisabled(value)"
                >
                    {{ String(value).padStart(2, '0') }}
                </SelectItem>
            </SelectContent>
        </Select>

        <span aria-hidden="true" class="text-muted-foreground">:</span>

        <Select
            :model-value="minuteValue"
            :disabled="disabled"
            @update:model-value="(value) => onMinute(String(value))"
        >
            <SelectTrigger
                :id="partId('minute')"
                size="sm"
                class="w-[4.5rem]"
                :aria-label="t('forms.minute')"
                :aria-describedby="describedBy"
                :aria-invalid="invalid ? true : undefined"
            >
                <SelectValue placeholder="MM" />
            </SelectTrigger>
            <SelectContent>
                <SelectItem
                    v-for="value in MINUTES"
                    :key="value"
                    :value="String(value).padStart(2, '0')"
                    :disabled="minuteDisabled(value)"
                >
                    {{ String(value).padStart(2, '0') }}
                </SelectItem>
            </SelectContent>
        </Select>

        <template v-if="seconds">
            <span aria-hidden="true" class="text-muted-foreground">:</span>

            <Select
                :model-value="secondValue"
                :disabled="disabled"
                @update:model-value="(value) => onSecond(String(value))"
            >
                <SelectTrigger
                    :id="partId('second')"
                    size="sm"
                    class="w-[4.5rem]"
                    :aria-label="t('forms.second')"
                    :aria-describedby="describedBy"
                    :aria-invalid="invalid ? true : undefined"
                >
                    <SelectValue placeholder="SS" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="value in MINUTES"
                        :key="value"
                        :value="String(value).padStart(2, '0')"
                        :disabled="secondDisabled(value)"
                    >
                        {{ String(value).padStart(2, '0') }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </template>

        <button
            v-if="showClear"
            type="button"
            class="rounded-sm p-1 text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            :aria-label="t('forms.clear_time')"
            @click="onClear"
        >
            <X class="size-3.5" />
        </button>
    </div>
</template>
