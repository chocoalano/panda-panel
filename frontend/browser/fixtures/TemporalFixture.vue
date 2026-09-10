<script setup lang="ts">
import DateField from '@/panel/forms/fields/DateField.vue';
import TimeField from '@/panel/forms/fields/TimeField.vue';
import DateTimeField from '@/panel/forms/fields/DateTimeField.vue';
import DataTableQueryBuilder from '@/panel/tables/DataTableQueryBuilder.vue';
import RepeatedEntry from '@/panel/forms/fields/RepeatedEntry.vue';
import { ref } from 'vue';
import type {
    DateFieldDefinition,
    DateTimeFieldDefinition,
    TimeFieldDefinition,
} from '@/panel/types/form';
import type {
    QueryBuilderFilterDefinition,
    QueryBuilderRule,
} from '@/panel/types/table';

/**
 * Every place the panel asks for a date or a time, laid out by a real engine.
 *
 * The defect these replace is not visible in a unit test: a native
 * `<input type="time">` renders as *something* in happy-dom, and only a
 * browser draws the control the user actually meets. This fixture mounts the
 * real published renderers against the real stylesheet so the checks can ask
 * the questions that need an engine — is a forbidden control in the document,
 * does the calendar open, does the popover follow the theme, does any of it
 * fit at 320px.
 *
 * Nothing here restyles or reimplements anything. A fixture that dressed a
 * component up would be measuring itself.
 */
function base(name: string, label: string) {
    return {
        component: 'field' as const,
        name,
        label,
        required: false,
        disabled: false,
        helperText: 'Helper text for this field.',
        inlineLabel: false,
    };
}

const dateField = {
    ...base('published_on', 'Published on'),
    type: 'date',
    minDate: null,
    maxDate: null,
} as unknown as DateFieldDefinition;

const timeField = {
    ...base('starts_at', 'Starts at'),
    type: 'time',
    seconds: false,
} as unknown as TimeFieldDefinition;

const secondsField = {
    ...base('measured_at', 'Measured at'),
    type: 'time',
    seconds: true,
} as unknown as TimeFieldDefinition;

/** The bound the old control could not enforce: a time on the boundary day. */
const boundedField = {
    ...base('published_at', 'Published at'),
    type: 'datetime',
    seconds: false,
    minDate: '2026-09-10 09:30',
    maxDate: '2026-09-12 17:45',
} as unknown as DateTimeFieldDefinition;

const dateValue = ref<string | null>('2026-09-10');
const timeValue = ref<string | null>('09:05');
const secondsValue = ref<string | null>('09:05:07');
const boundedValue = ref<string | null>('2026-09-11 10:00');

/**
 * The same bounded field, already sitting on each boundary day.
 *
 * The bound only bites when the chosen date *is* the boundary date, and
 * getting there by driving a calendar popover makes the check about popover
 * timing rather than about the bound. These start where the question is.
 */
const atMinValue = ref<string | null>('2026-09-10 10:00');
const atMinHourValue = ref<string | null>('2026-09-10 09:45');
const atMaxValue = ref<string | null>('2026-09-12 10:00');

/**
 * Two entries of the *same* field, through the real repeater scope.
 *
 * Two bare `TimeField`s with one name would collide by construction and prove
 * nothing; a repeater does not render them that way. `RepeatedEntry` provides
 * the DOM scope each entry's ids are derived from, so this asks the question
 * a repeater actually poses.
 */
const entryValues = ref<Array<Record<string, string | null>>>([
    { starts_at: '01:02' },
    { starts_at: '03:04' },
]);

const queryFilter = {
    name: 'advanced',
    label: 'Advanced',
    type: 'query_builder',
    maxRules: 5,
    constraints: [
        {
            name: 'created_at',
            label: 'Created at',
            input: 'date',
            operators: [
                { value: 'equals', label: 'is', needsValue: true },
                { value: 'is_blank', label: 'is blank', needsValue: false },
            ],
        },
    ],
} as unknown as QueryBuilderFilterDefinition;

const queryRules = ref<QueryBuilderRule[]>([
    { column: 'created_at', operator: 'equals', value: null },
]);
</script>

<template>
    <div class="space-y-8 p-6">
        <div id="date-field">
            <DateField
                :field="dateField"
                :model-value="dateValue"
                @update:model-value="(value) => (dateValue = value)"
            />
        </div>

        <div id="time-field">
            <TimeField
                :field="timeField"
                :model-value="timeValue"
                @update:model-value="(value) => (timeValue = value)"
            />
        </div>

        <div id="seconds-field">
            <TimeField
                :field="secondsField"
                :model-value="secondsValue"
                @update:model-value="(value) => (secondsValue = value)"
            />
        </div>

        <div id="datetime-field">
            <DateTimeField
                :field="boundedField"
                :model-value="boundedValue"
                @update:model-value="(value) => (boundedValue = value)"
            />
        </div>

        <!-- Two instances of one field, as a repeater renders them. -->
        <div id="repeated">
            <RepeatedEntry
                v-for="(entry, index) in entryValues"
                :key="index"
                :schema="[timeField]"
                :values="entry"
                :errors="{}"
                :dom-scope="`entry-${index}-`"
                :path-scope="`items.${index}.`"
                @change="
                    (name, value) => (entry[name] = value as string | null)
                "
            />
        </div>

        <div id="datetime-at-min">
            <DateTimeField
                :field="boundedField"
                :model-value="atMinValue"
                @update:model-value="(value) => (atMinValue = value)"
            />
        </div>

        <div id="datetime-at-min-hour">
            <DateTimeField
                :field="boundedField"
                :model-value="atMinHourValue"
                @update:model-value="(value) => (atMinHourValue = value)"
            />
        </div>

        <div id="datetime-at-max">
            <DateTimeField
                :field="boundedField"
                :model-value="atMaxValue"
                @update:model-value="(value) => (atMaxValue = value)"
            />
        </div>

        <div id="query-builder">
            <DataTableQueryBuilder
                :filter="queryFilter"
                :rules="queryRules"
                @change="(rules) => (queryRules = rules)"
            />
        </div>

        <!-- What the checks read back, so a value never has to be inferred. -->
        <output id="state" class="hidden">{{
            JSON.stringify({
                date: dateValue,
                time: timeValue,
                seconds: secondsValue,
                datetime: boundedValue,
                atMin: atMinValue,
                atMinHour: atMinHourValue,
                atMax: atMaxValue,
                entries: entryValues,
                rules: queryRules,
            })
        }}</output>
    </div>
</template>
