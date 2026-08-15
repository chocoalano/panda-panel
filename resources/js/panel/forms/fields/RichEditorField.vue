<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import FieldWrapper from '@/panel/forms/fields/FieldWrapper.vue';
import type { RichEditorFieldDefinition } from '@/panel/types/form';

const props = defineProps<{
    field: RichEditorFieldDefinition;
    modelValue: unknown;
    error?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

/**
 * A `contenteditable` region driven by the browser's own editing commands.
 *
 * Deliberately dependency-free. Adding an editor library is a dependency
 * decision, not a rendering one, and this application does not take those
 * without asking — so the field uses what every browser already implements.
 * `execCommand` is marked deprecated and is also still the only editing API
 * shipped everywhere; when a replacement is universal this is the one place
 * that changes.
 *
 * What it produces is never trusted. `RichEditor::sanitize()` strips the HTML
 * to an allowlist on the way in, so the tags below are a *suggestion* to the
 * browser and the server's list is the actual answer.
 */
const editor = ref<HTMLElement | null>(null);

/**
 * The commands a toolbar button maps to, keyed by the names the schema uses.
 * A name not in here draws no button, exactly as an unregistered icon
 * renders nothing.
 */
const COMMANDS: Record<string, { label: string; run: () => void }> = {
    bold: { label: 'B', run: () => exec('bold') },
    italic: { label: 'I', run: () => exec('italic') },
    strike: { label: 'S', run: () => exec('strikeThrough') },
    underline: { label: 'U', run: () => exec('underline') },
    h2: { label: 'H2', run: () => exec('formatBlock', '<h2>') },
    h3: { label: 'H3', run: () => exec('formatBlock', '<h3>') },
    blockquote: { label: '❝', run: () => exec('formatBlock', '<blockquote>') },
    bulletList: { label: '• List', run: () => exec('insertUnorderedList') },
    orderedList: { label: '1. List', run: () => exec('insertOrderedList') },
    link: { label: 'Link', run: () => link() },
    undo: { label: '↶', run: () => exec('undo') },
    redo: { label: '↷', run: () => exec('redo') },
};

function html(): string {
    return typeof props.modelValue === 'string' ? props.modelValue : '';
}

/**
 * Written into the element only when the two have actually diverged.
 *
 * Assigning `innerHTML` while the field has focus moves the caret to the
 * start, so echoing back the value the editor just emitted would make typing
 * impossible.
 */
function sync(): void {
    const element = editor.value;

    if (element !== null && element.innerHTML !== html()) {
        element.innerHTML = html();
    }
}

onMounted(sync);
watch(() => props.modelValue, sync);

function emitContent(): void {
    const content = editor.value?.innerHTML ?? '';

    // An empty region reports `<br>` in some browsers, which is not content
    // and must not count towards a required field.
    emit('update:modelValue', content === '<br>' ? '' : content);
}

function exec(command: string, argument?: string): void {
    if (props.field.disabled) {
        return;
    }

    editor.value?.focus();
    document.execCommand(command, false, argument);
    emitContent();
}

function link(): void {
    const url = window.prompt('Link URL');

    // A blank answer or a cancelled prompt leaves the selection alone rather
    // than wrapping it in a link to nowhere.
    if (url !== null && url.trim() !== '') {
        exec('createLink', url.trim());
    }
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
                v-if="field.toolbar.length > 0"
                class="flex flex-wrap gap-0.5 border-b border-input bg-muted/40 p-1"
            >
                <template v-for="button in field.toolbar" :key="button">
                    <button
                        v-if="COMMANDS[button]"
                        type="button"
                        class="rounded px-2 py-1 text-xs font-medium text-muted-foreground hover:bg-accent hover:text-foreground disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="field.disabled"
                        :aria-label="button"
                        @click="COMMANDS[button].run()"
                    >
                        {{ COMMANDS[button].label }}
                    </button>
                </template>
            </div>

            <div
                :id="field.name"
                ref="editor"
                role="textbox"
                aria-multiline="true"
                :aria-label="field.label"
                :aria-invalid="error ? true : undefined"
                :contenteditable="!field.disabled"
                class="prose prose-sm dark:prose-invert min-h-40 max-w-none bg-background p-3 text-sm outline-none"
                :class="field.disabled ? 'cursor-not-allowed opacity-50' : ''"
                @input="emitContent"
                @blur="emitContent"
            />
        </div>
    </FieldWrapper>
</template>
