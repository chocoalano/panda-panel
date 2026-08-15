<script setup lang="ts">
import { computed } from 'vue';

/**
 * How long an account has existed, as a bar plus its own words.
 *
 * The state is whatever the PHP column's `state()` returned, so it arrives as
 * untyped JSON and is narrowed here rather than asserted — a shape that does
 * not match renders an empty cell instead of throwing inside the table.
 */
const props = defineProps<{ state: unknown }>();

/** A year of membership fills the bar; past that it simply stays full. */
const FULL_AT_DAYS = 365;

const reading = computed(() => {
    const value = props.state;

    if (typeof value !== 'object' || value === null) {
        return null;
    }

    const { days, label } = value as { days?: unknown; label?: unknown };

    if (typeof days !== 'number' || typeof label !== 'string') {
        return null;
    }

    return { days, label };
});

const percent = computed(() =>
    reading.value === null
        ? 0
        : Math.min(100, Math.round((reading.value.days / FULL_AT_DAYS) * 100)),
);
</script>

<template>
    <div v-if="reading" class="flex flex-col gap-1">
        <span class="text-xs whitespace-nowrap text-muted-foreground">
            {{ reading.label }}
        </span>
        <div
            class="h-1.5 w-full overflow-hidden rounded-full bg-muted"
            role="img"
            :aria-label="`Account age: ${reading.label}`"
        >
            <div
                class="h-full rounded-full bg-primary"
                :style="{ width: `${percent}%` }"
            />
        </div>
    </div>
    <span v-else class="text-muted-foreground">—</span>
</template>
