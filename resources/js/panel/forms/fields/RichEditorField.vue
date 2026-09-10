<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import FieldWrapper from '@/panel/forms/fields/FieldWrapper.vue';
import type { RichEditorFieldDefinition } from '@/panel/types/form';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

/**
 * A toolbar button's face.
 *
 * Most are typographic — `B`, `H2`, `❝` — and mean the same thing in every
 * language, so they are written literally. The few that are words hold a
 * translation key instead, recognised by its dot.
 */
function toolbarLabel(label: string): string {
    return label.includes('.') ? t(label) : label;
}

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
const COMMANDS: Record<
    string,
    { label: string; run: () => void; toggles?: string }
> = {
    bold: { label: 'B', run: () => exec('bold'), toggles: 'bold' },
    italic: { label: 'I', run: () => exec('italic'), toggles: 'italic' },
    strike: {
        label: 'S',
        run: () => exec('strikeThrough'),
        toggles: 'strikeThrough',
    },
    underline: {
        label: 'U',
        run: () => exec('underline'),
        toggles: 'underline',
    },
    h2: { label: 'H2', run: () => exec('formatBlock', '<h2>') },
    h3: { label: 'H3', run: () => exec('formatBlock', '<h3>') },
    blockquote: { label: '❝', run: () => exec('formatBlock', '<blockquote>') },
    bulletList: {
        label: 'forms.editor_bullet_list',
        run: () => exec('insertUnorderedList'),
        toggles: 'insertUnorderedList',
    },
    orderedList: {
        label: 'forms.editor_ordered_list',
        run: () => exec('insertOrderedList'),
        toggles: 'insertOrderedList',
    },
    link: { label: 'forms.editor_link', run: () => link() },
    undo: { label: '↶', run: () => exec('undo') },
    redo: { label: '↷', run: () => exec('redo') },
};

/**
 * Which formatting the caret currently sits inside.
 *
 * A toolbar button that toggles something has a state, and the state was
 * carried by nothing at all — not a class, not an attribute. Somebody who
 * cannot see the button had no way to know whether the next keystroke would
 * be bold, and a sighted user had only whatever hover styling happened to be
 * showing.
 *
 * Recomputed on selection change rather than watched, because the caret moves
 * for reasons no Vue reactive value observes — arrow keys, a click inside the
 * text, an undo.
 */
const activeCommands = ref<Set<string>>(new Set());

function refreshCommandState(): void {
    const found = new Set<string>();

    for (const [name, command] of Object.entries(COMMANDS)) {
        if (command.toggles === undefined) {
            continue;
        }

        try {
            if (document.queryCommandState(command.toggles)) {
                found.add(name);
            }
        } catch {
            // Not every browser answers for every command, and one that
            // refuses is not a reason to lose the rest.
        }
    }

    activeCommands.value = found;
}

/** Only the commands that are toggles have a pressed state to report. */
function pressedState(name: string): boolean | undefined {
    return COMMANDS[name]?.toggles === undefined
        ? undefined
        : activeCommands.value.has(name);
}

function onSelectionChange(): void {
    if (editor.value?.contains(document.getSelection()?.anchorNode ?? null)) {
        refreshCommandState();
    }
}

onMounted(() =>
    document.addEventListener('selectionchange', onSelectionChange),
);
onBeforeUnmount(() =>
    document.removeEventListener('selectionchange', onSelectionChange),
);

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
    const url = window.prompt(t('forms.link_url'));

    // A blank answer or a cancelled prompt leaves the selection alone rather
    // than wrapping it in a link to nowhere.
    if (url !== null && url.trim() !== '') {
        exec('createLink', url.trim());
    }
}
</script>

<template>
    <FieldWrapper
        v-slot="{ controlId, describedBy, invalid }"
        :name="field.name"
        :inline-label="field.inlineLabel"
        :label="field.label"
        :required="field.required"
        :helper-text="field.helperText"
        :error="error"
    >
        <!--
            Two indicators, answering two questions.

            The border says *which editor holds focus* and changes on
            `:focus-within`, so it is right whether the caret arrived by click
            or by Tab, and it is restrained — a colour, not a glow. The ring
            below says *this is where the keyboard is*, and is `:focus-visible`
            only, so clicking a toolbar button does not leave the whole field
            lit up.

            An error keeps its own border: a field that is both focused and
            wrong should not stop looking wrong, so the destructive colour
            wins and the ring still draws around it.
        -->
        <div
            class="overflow-hidden rounded-md border border-input transition-colors focus-within:border-ring"
            :class="
                error
                    ? 'border-destructive focus-within:border-destructive'
                    : ''
            "
        >
            <div
                v-if="field.toolbar.length > 0"
                class="flex flex-wrap gap-0.5 border-b border-input bg-muted/40 p-1"
            >
                <template v-for="button in field.toolbar" :key="button">
                    <button
                        v-if="COMMANDS[button]"
                        type="button"
                        class="rounded px-2 py-1 text-xs font-medium text-muted-foreground hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 aria-pressed:bg-accent aria-pressed:text-foreground aria-pressed:ring-1 aria-pressed:ring-border"
                        :disabled="field.disabled"
                        :aria-label="button"
                        :aria-pressed="pressedState(button)"
                        @click="COMMANDS[button].run()"
                    >
                        {{ toolbarLabel(COMMANDS[button].label) }}
                    </button>
                </template>
            </div>

            <div
                :id="controlId"
                ref="editor"
                :aria-describedby="describedBy"
                role="textbox"
                aria-multiline="true"
                :aria-label="field.label"
                :aria-invalid="invalid"
                :contenteditable="!field.disabled"
                class="panel-prose min-h-40 max-w-none bg-background p-3 text-sm outline-none focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:ring-inset"
                :class="field.disabled ? 'cursor-not-allowed opacity-50' : ''"
                @input="emitContent"
                @blur="emitContent"
            />
        </div>
    </FieldWrapper>
</template>
