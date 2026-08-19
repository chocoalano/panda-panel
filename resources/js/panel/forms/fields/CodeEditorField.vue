<script setup lang="ts">
import { computed } from 'vue';
import { Textarea } from '@/components/ui/textarea';
import FieldWrapper from '@/panel/forms/fields/FieldWrapper.vue';
import type { CodeEditorFieldDefinition } from '@/panel/types/form';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

/**
 * A language's name, translated only where it has one.
 *
 * `JSON`, `HTML`, `PHP` are names rather than words: they read the same in
 * every language, so `LANGUAGE_LABELS` holds them literally and only the
 * entries that are actually English — "Plain text" — hold a translation key.
 * A key is recognised by its dot; anything else is passed through.
 */
function languageLabel(language: string): string {
    const label = LANGUAGE_LABELS[language] ?? language;

    return label.includes('.') ? t(label) : label;
}

const props = defineProps<{
    field: CodeEditorFieldDefinition;
    modelValue: unknown;
    error?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

/**
 * A monospace editor that keeps a tab a tab.
 *
 * Syntax highlighting would mean a highlighter dependency, which is a
 * decision this application does not make on its own. What the field does
 * give is everything that matters for editing code in a form: a fixed-width
 * face, a line gutter, no spellcheck or autocorrect mangling identifiers, and
 * Tab inserting indentation rather than leaving the field.
 */
const LANGUAGE_LABELS: Record<string, string> = {
    plain: 'forms.plain_text',
    json: 'JSON',
    html: 'HTML',
    css: 'CSS',
    javascript: 'JavaScript',
    php: 'PHP',
    sql: 'SQL',
    yaml: 'YAML',
    markdown: 'Markdown',
};

const text = computed(() =>
    typeof props.modelValue === 'string' ? props.modelValue : '',
);

const lineCount = computed(() => Math.max(text.value.split('\n').length, 1));

/**
 * Tab indents instead of moving focus.
 *
 * A code field where Tab leaves is unusable; one where Tab never leaves traps
 * keyboard users. Escape then Tab is the way out, which is the convention
 * every code editor on the web already uses.
 */
function onTab(event: KeyboardEvent): void {
    const target = event.target;

    if (!(target instanceof HTMLTextAreaElement) || props.field.disabled) {
        return;
    }

    event.preventDefault();

    const start = target.selectionStart;
    const end = target.selectionEnd;

    emit(
        'update:modelValue',
        `${text.value.slice(0, start)}    ${text.value.slice(end)}`,
    );

    requestAnimationFrame(() => {
        target.focus();
        target.setSelectionRange(start + 4, start + 4);
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
                class="flex items-center justify-between border-b border-input bg-muted/40 px-2 py-1 text-xs text-muted-foreground"
            >
                <span>{{ languageLabel(field.language) }}</span>
                <span>{{ lineCount }} lines</span>
            </div>

            <Textarea
                :id="field.name"
                class="rounded-none border-0 font-mono text-sm shadow-none focus-visible:ring-0"
                spellcheck="false"
                autocapitalize="off"
                autocomplete="off"
                autocorrect="off"
                :model-value="text"
                :rows="field.rows"
                :placeholder="field.placeholder ?? undefined"
                :disabled="field.disabled"
                :maxlength="field.maxLength ?? undefined"
                :aria-invalid="error ? true : undefined"
                @keydown.tab="onTab"
                @update:model-value="
                    (value) => emit('update:modelValue', String(value))
                "
            />
        </div>
    </FieldWrapper>
</template>
