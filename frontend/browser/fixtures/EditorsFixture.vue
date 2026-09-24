<script setup lang="ts">
import CodeEditorField from '@/panel/forms/fields/CodeEditorField.vue';
import MarkdownEditorField from '@/panel/forms/fields/MarkdownEditorField.vue';
import type {
    CodeEditorFieldDefinition,
    MarkdownEditorFieldDefinition,
} from '@/panel/types/form';

/**
 * The two editors a unit test can only stub.
 *
 * `MarkdownEditorField` is CodeMirror and `CodeEditorField` is Monaco, and both
 * of them construct the element that takes the keyboard for themselves, after
 * mounting, measuring a layout as they go. In `happy-dom` the honest thing to
 * do is stub them — which is what `markdownEditor.test.ts` and
 * `codeEditor.test.ts` do, and what leaves exactly three questions open:
 *
 * 1. Do they mount at all, and does the field's identity reach the element the
 *    keyboard lands on?
 * 2. Does the panel's own typography reach the Markdown preview, and the
 *    panel's theme reach both editors?
 * 3. Do they load anything over the network?
 *
 * The third is the one that cannot be asked anywhere else. Both libraries fetch
 * parts of themselves from a CDN by default and both defaults are turned off;
 * "turned off" is a claim about what a browser does, and this is the only place
 * a browser is present to contradict it.
 *
 * Nothing here is styled or shimmed. Both are the published components against
 * the published stylesheet.
 */
const base = {
    component: 'field',
    placeholder: null,
    required: false,
    disabled: false,
    inlineLabel: false,
    columnSpan: 'full',
    conditions: { visibleWhen: [], hiddenWhen: [] },
    live: null,
    validation: { required: false },
} as const;

const markdown = {
    ...base,
    name: 'body',
    label: 'Body',
    type: 'markdown_editor',
    helperText: 'Markdown is stored as written.',
    // A heading, a list, a link and a table: everything `panel-prose` has a
    // rule for, so the preview can be measured against a plain paragraph.
    value: [
        '## A heading',
        '',
        'Some **bold** text and a [link](https://example.test).',
        '',
        '- first',
        '- second',
        '',
        '| a | b |',
        '| --- | --- |',
        '| 1 | 2 |',
    ].join('\n'),
    toolbar: [
        'bold',
        'italic',
        'strike',
        'link',
        'heading',
        'bulletList',
        'orderedList',
        'blockquote',
        'code',
        'preview',
    ],
    maxLength: null,
    rows: 10,
} as unknown as MarkdownEditorFieldDefinition;

const code = {
    ...base,
    name: 'settings',
    label: 'Settings',
    type: 'code_editor',
    helperText: 'Stored exactly as written.',
    // Deliberately several token classes — a string, a key, a number, a
    // bracket — so "is it highlighted" has an answer that is not a guess.
    value: '{\n    "theme": "dark",\n    "retries": 3\n}',
    language: 'json',
    rows: 8,
    maxLength: null,
} as unknown as CodeEditorFieldDefinition;
</script>

<template>
    <div class="flex max-w-3xl flex-col gap-8 bg-background p-6">
        <!-- A control before them, so tabbing *into* an editor is observable. -->
        <button id="before" type="button" class="rounded border px-2 py-1">
            Before
        </button>

        <div id="markdown">
            <MarkdownEditorField
                :field="markdown"
                :model-value="markdown.value"
            />
        </div>

        <div id="code">
            <CodeEditorField :field="code" :model-value="code.value" />
        </div>

        <!-- The same code field while the server has refused it. -->
        <div id="code-invalid">
            <CodeEditorField
                :field="{ ...code, name: 'settings_invalid' }"
                :model-value="code.value"
                error="This is not valid JSON."
            />
        </div>

        <!--
            References, so a check can compare what the editors resolved to
            against what the panel's own tokens resolve to on this page. The
            Markdown preview is themed by mapping the library's custom
            properties onto these tokens, and "the mapping worked" only means
            anything if both sides are measured.
        -->
        <p id="plain">Plain body text.</p>
        <span id="ref-primary" class="text-primary">Primary</span>
        <span id="ref-border" class="border border-border">Border</span>
        <span id="ref-surface" class="bg-background">Surface</span>
    </div>
</template>
