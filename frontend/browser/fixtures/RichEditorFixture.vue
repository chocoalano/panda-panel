<script setup lang="ts">
import RichEditorField from '@/panel/forms/fields/RichEditorField.vue';
import type { RichEditorFieldDefinition } from '@/panel/types/form';

/**
 * The real rich editor, its real toolbar, its real `contenteditable`.
 *
 * U17 is about focus: where the ring is, whether the toolbar is reachable,
 * what happens when the caret is in the body. `happy-dom` will report a
 * `contenteditable` element and will not tell you whether a browser puts a
 * caret in it, gives it a visible ring, or applies `:focus-visible` — which
 * is the whole of the finding.
 *
 * The editor underneath is Tiptap now, and the fixture did not have to change
 * for that: it mounts the published component against the published
 * stylesheet, which is the only thing it was ever allowed to do.
 */
const field = {
    component: 'field',
    name: 'body',
    label: 'Body',
    type: 'rich_editor',
    value:
        '<h2>A heading</h2>' +
        '<p>Some <strong>existing</strong> content.</p>' +
        // Last, and with no formatting, so a toggle check starts from off.
        // It carries no id on purpose: the editor parses this against its own
        // schema and an attribute the schema does not declare does not
        // survive — a fixture that relied on one would be asserting against
        // markup the editor had already thrown away.
        '<p>Nothing is applied here.</p>',
    placeholder: null,
    helperText: 'Formatting is applied to the selection.',
    required: false,
    disabled: false,
    inlineLabel: false,
    columnSpan: 'full',
    conditions: { visibleWhen: [], hiddenWhen: [] },
    live: null,
    validation: { required: false },
    toolbar: ['bold', 'italic', 'h2', 'bulletList', 'link'],
    maxLength: null,
} as unknown as RichEditorFieldDefinition;
</script>

<template>
    <div class="max-w-2xl bg-background p-6">
        <!-- A control before it, so tabbing *into* the editor is observable. -->
        <button id="before" type="button" class="mb-4 rounded border px-2 py-1">
            Before
        </button>

        <RichEditorField :field="field" :model-value="field.value" />

        <!-- The same editor while the server has refused it. -->
        <div id="invalid-editor" class="mt-8">
            <RichEditorField
                :field="{ ...field, name: 'body_invalid' }"
                :model-value="field.value"
                error="This field is required."
            />
        </div>

        <button id="after" type="button" class="mt-4 rounded border px-2 py-1">
            After
        </button>
    </div>
</template>
