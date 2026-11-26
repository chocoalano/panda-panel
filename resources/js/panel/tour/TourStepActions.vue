<script setup lang="ts">
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { useTranslator } from '@/composables/useTranslator';

defineProps<{
    first: boolean;
    last: boolean;
}>();

const emit = defineEmits<{ back: []; next: []; skip: [] }>();

const { t } = useTranslator();

const forward = ref<InstanceType<typeof Button> | null>(null);

/**
 * Focus goes to the way forward. Left to the focus trap it would land on the
 * first button, and Enter would throw the tour away.
 */
defineExpose({
    focus: () => (forward.value?.$el as HTMLElement | undefined)?.focus(),
});
</script>

<template>
    <div class="flex items-center gap-2">
        <Button
            type="button"
            variant="ghost"
            size="sm"
            class="mr-auto"
            @click="emit('skip')"
        >
            {{ t('tour.skip') }}
        </Button>
        <Button
            v-if="!first"
            type="button"
            variant="outline"
            size="sm"
            @click="emit('back')"
        >
            {{ t('tour.back') }}
        </Button>
        <Button ref="forward" type="button" size="sm" @click="emit('next')">
            {{ last ? t('tour.done') : t('tour.next') }}
        </Button>
    </div>
</template>
