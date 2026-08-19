<script setup lang="ts">
import { Plus, X } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type {
    ConstraintDefinition,
    QueryBuilderFilterDefinition,
    QueryBuilderRule,
} from '@/panel/types/table';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

/**
 * A list of conditions the user composes.
 *
 * Every choice offered here comes from the server's declaration — which
 * columns may be constrained, and which comparisons each one supports. The
 * component never invents a column or an operator; it renders the ones it
 * was given, and the server checks them again on arrival.
 *
 * Rules are ANDed. Nested and/or groups would need a recursive schema on both
 * sides and a UI to match; a flat list answers the question most tables are
 * actually asked.
 */
const props = defineProps<{
    filter: QueryBuilderFilterDefinition;
    rules: QueryBuilderRule[];
}>();

const emit = defineEmits<{ change: [rules: QueryBuilderRule[]] }>();

const constraintsByName = computed(
    () =>
        new Map<string, ConstraintDefinition>(
            props.filter.constraints.map((constraint) => [
                constraint.name,
                constraint,
            ]),
        ),
);

function constraintFor(rule: QueryBuilderRule): ConstraintDefinition | null {
    return constraintsByName.value.get(rule.column) ?? null;
}

function needsValue(rule: QueryBuilderRule): boolean {
    const constraint = constraintFor(rule);

    return (
        constraint?.operators.find(
            (operator) => operator.value === rule.operator,
        )?.needsValue ?? false
    );
}

function inputTypeFor(rule: QueryBuilderRule): string {
    const input = constraintFor(rule)?.input ?? 'text';

    return input === 'none' ? 'text' : input;
}

const canAdd = computed(
    () =>
        props.filter.constraints.length > 0 &&
        props.rules.length < props.filter.maxRules,
);

function addRule(): void {
    const constraint = props.filter.constraints[0];

    if (constraint === undefined) {
        return;
    }

    emit('change', [
        ...props.rules,
        {
            column: constraint.name,
            operator: constraint.operators[0]?.value ?? '',
            value: null,
        },
    ]);
}

function updateRule(index: number, patch: Partial<QueryBuilderRule>): void {
    emit(
        'change',
        props.rules.map((rule, position) =>
            position === index ? { ...rule, ...patch } : rule,
        ),
    );
}

/**
 * Changing the column resets the operator: the comparisons a text column
 * offers are not the ones a boolean does, and keeping the old one would leave
 * a rule the server drops without saying why.
 */
function onColumn(index: number, column: string): void {
    const constraint = constraintsByName.value.get(column);

    updateRule(index, {
        column,
        operator: constraint?.operators[0]?.value ?? '',
        value: null,
    });
}

function removeRule(index: number): void {
    emit(
        'change',
        props.rules.filter((_, position) => position !== index),
    );
}
</script>

<template>
    <div class="flex flex-col gap-2">
        <div
            v-for="(rule, index) in rules"
            :key="index"
            class="flex flex-wrap items-center gap-2"
        >
            <Select
                :model-value="rule.column"
                @update:model-value="(value) => onColumn(index, String(value))"
            >
                <SelectTrigger class="h-8 w-40">
                    <SelectValue :placeholder="t('tables.column')" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="constraint in filter.constraints"
                        :key="constraint.name"
                        :value="constraint.name"
                    >
                        {{ constraint.label }}
                    </SelectItem>
                </SelectContent>
            </Select>

            <Select
                :model-value="rule.operator"
                @update:model-value="
                    (value) =>
                        updateRule(index, {
                            operator: String(value),
                            value: null,
                        })
                "
            >
                <SelectTrigger class="h-8 w-40">
                    <SelectValue :placeholder="t('tables.condition')" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="operator in constraintFor(rule)?.operators ?? []"
                        :key="operator.value"
                        :value="operator.value"
                    >
                        {{ operator.label }}
                    </SelectItem>
                </SelectContent>
            </Select>

            <Input
                v-if="needsValue(rule)"
                class="h-8 w-44"
                :type="inputTypeFor(rule)"
                :aria-label="`Value for ${constraintFor(rule)?.label ?? 'rule'}`"
                :model-value="rule.value ?? ''"
                @update:model-value="
                    (value) => updateRule(index, { value: String(value) })
                "
            />

            <Button
                variant="ghost"
                size="icon-sm"
                :aria-label="`Remove rule ${index + 1}`"
                @click="removeRule(index)"
            >
                <X />
            </Button>
        </div>

        <div>
            <Button v-if="canAdd" variant="outline" size="sm" @click="addRule">
                <Plus />
                {{ t('tables.add_condition') }}
            </Button>
            <p
                v-else-if="rules.length > 0"
                class="text-xs text-muted-foreground"
            >
                Up to {{ filter.maxRules }} conditions.
            </p>
        </div>
    </div>
</template>
