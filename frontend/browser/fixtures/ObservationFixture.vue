<script setup lang="ts">
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Card } from '@/components/ui/card';

/**
 * Two backlog items, observed rather than fixed.
 *
 * B02: the editors emit `prose prose-sm dark:prose-invert` and the stylesheet
 * loads no typography plugin, so those classes should compile to nothing —
 * which means an `<h2>` inside them is styled by the browser default and not
 * by the package. A browser is the only place that is visible.
 *
 * B03: the package uses `animate-in` and friends and declares no
 * reduced-motion handling, so an element carrying one should animate
 * identically whether or not the preference is set.
 */
</script>

<template>
    <div class="p-6">
        <div id="prose-sample" class="panel-prose max-w-none">
            <h2 id="prose-heading">A prose heading</h2>
            <p id="prose-paragraph">A prose paragraph.</p>
            <ul>
                <li id="prose-item">A list item.</li>
            </ul>
            <ol>
                <li id="prose-ordered-item">An ordered item.</li>
            </ol>
            <p><a id="prose-link" href="#x">A link.</a></p>
            <p><code id="prose-code">inline()</code></p>
            <pre id="prose-pre"><code>a block</code></pre>
            <blockquote id="prose-quote">A quotation.</blockquote>
        </div>

        <!-- Outside any editor: must be untouched by the editor typography. -->
        <ul id="plain-list">
            <li id="plain-item">A plain list item.</li>
        </ul>
        <p><a id="plain-link" href="#y">A plain link.</a></p>

        <h2 id="plain-heading">A plain heading</h2>

        <!--
            The real primitives, not stand-ins: what matters is whether the
            components the package ships respond to the preference, and a
            hand-made div carrying the same utility classes would answer a
            different question.
        -->
        <div
            id="animated"
            data-slot="fixture-entrance"
            class="data-[state=open]:animate-in data-[state=open]:fade-in-0 mt-6 rounded border p-4"
            data-state="open"
        >
            An animated surface.
        </div>

        <div
            id="sliding"
            data-slot="fixture-slide"
            class="data-[state=open]:animate-in data-[state=open]:slide-in-from-bottom-2 mt-4 rounded border p-4"
            data-state="open"
        >
            A sliding surface.
        </div>

        <!-- A real Card, which is what the stat widgets lift. -->
        <Card
            id="lift"
            class="mt-4 rounded border p-4 transition-all hover:-translate-y-0.5"
        >
            A surface that lifts.
        </Card>

        <!-- A real Dialog: the one that actually animates in a panel. -->
        <Dialog :open="true">
            <DialogContent id="real-dialog">
                <DialogHeader>
                    <DialogTitle>A dialog</DialogTitle>
                    <DialogDescription>Which animates.</DialogDescription>
                </DialogHeader>
            </DialogContent>
        </Dialog>

        <svg
            id="spinner"
            data-slot="spinner"
            role="status"
            aria-label="Loading"
            class="mt-4 size-4 animate-spin"
            viewBox="0 0 24 24"
        >
            <circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" />
        </svg>
    </div>
</template>
