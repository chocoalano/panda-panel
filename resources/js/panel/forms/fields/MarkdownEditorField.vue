<script setup lang="ts">
import { computed, ref } from 'vue';
import type { ComponentPublicInstance } from 'vue';
import { Textarea } from '@/components/ui/textarea';
import FieldWrapper from '@/panel/forms/fields/FieldWrapper.vue';
import { renderMarkdown } from '@/panel/forms/markdown';
import type { MarkdownEditorFieldDefinition } from '@/panel/types/form';

const props = defineProps<{
    field: MarkdownEditorFieldDefinition;
    modelValue: unknown;
    error?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

/**
 * Markdown is stored as Markdown. The toolbar only inserts the characters a
 * user would otherwise type, and the preview renders a copy — neither one
 * rewrites what is submitted.
 */
const input = ref<ComponentPublicInstance | null>(null);
const previewing = ref(false);

/**
 * The real textarea behind the styled component. Selection offsets are a
 * property of the element, and the wrapper does not forward them.
 */
function element(): HTMLTextAreaElement | null {
    const node = input.value?.$el;

    return node instanceof HTMLTextAreaElement ? node : null;
}

const text = computed(() =>
    typeof props.modelValue === 'string' ? props.modelValue : '',
);

const preview = computed(() => renderMarkdown(text.value));

/** What each toolbar button wraps or prefixes the selection with. */
const WRAPPERS: Record<
    string,
    { label: string; before: string; after: string }
> = {
    bold: { label: 'B', before: '**', after: '**' },
    italic: { label: 'I', before: '*', after: '*' },
    strike: { label: 'S', before: '~~', after: '~~' },
    code: { label: '</>', before: '`', after: '`' },
    link: { label: 'Link', before: '[', after: '](https://)' },
    heading: { label: 'H', before: '## ', after: '' },
    bulletList: { label: '• List', before: '- ', after: '' },
    orderedList: { label: '1. List', before: '1. ', after: '' },
    blockquote: { label: '❝', before: '> ', after: '' },
};

function apply(button: string): void {
    const wrapper = WRAPPERS[button];
    const target = element();

    if (!wrapper || target === null || props.field.disabled) {
        return;
    }

    const start = target.selectionStart;
    const end = target.selectionEnd;
    const selected = text.value.slice(start, end);

    const next =
        text.value.slice(0, start) +
        wrapper.before +
        selected +
        wrapper.after +
        text.value.slice(end);

    emit('update:modelValue', next);

    // The caret goes back around what was selected, so typing continues where
    // the user was rather than at the end of the inserted syntax.
    requestAnimationFrame(() => {
        target.focus();
        target.setSelectionRange(
            start + wrapper.before.length,
            start + wrapper.before.length + selected.length,
        );
    });
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
        <div
            class="overflow-hidden rounded-md border border-input"
            :class="error ? 'border-destructive' : ''"
        >
            <div
                class="flex flex-wrap items-center gap-0.5 border-b border-input bg-muted/40 p-1"
            >
                <template v-for="button in field.toolbar" :key="button">
                    <button
                        v-if="WRAPPERS[button]"
                        type="button"
                        class="rounded px-2 py-1 text-xs font-medium text-muted-foreground hover:bg-accent hover:text-foreground disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="field.disabled || previewing"
                        :aria-label="button"
                        @click="apply(button)"
                    >
                        {{ WRAPPERS[button].label }}
                    </button>
                </template>

                <button
                    v-if="field.toolbar.includes('preview')"
                    type="button"
                    class="ml-auto rounded px-2 py-1 text-xs font-medium text-muted-foreground hover:bg-accent hover:text-foreground"
                    :aria-pressed="previewing"
                    @click="previewing = !previewing"
                >
                    {{ previewing ? 'Write' : 'Preview' }}
                </button>
            </div>

            <!--
                The preview is rendered by `renderMarkdown`, which escapes
                every character before adding a single tag. See that module
                for why this `v-html` is safe.
            -->
            <div
                v-if="previewing"
                class="prose prose-sm dark:prose-invert max-w-none bg-background p-3 text-sm"
                v-html="preview"
            />

            <Textarea
                v-else
                :id="field.name"
                ref="input"
                class="rounded-none border-0 font-mono text-sm shadow-none focus-visible:ring-0"
                :model-value="text"
                :rows="field.rows"
                :placeholder="field.placeholder ?? undefined"
                :disabled="field.disabled"
                :maxlength="field.maxLength ?? undefined"
                :aria-invalid="error ? true : undefined"
                @update:model-value="
                    (value) => emit('update:modelValue', String(value))
                "
            />
        </div>
    </FieldWrapper>
</template>
