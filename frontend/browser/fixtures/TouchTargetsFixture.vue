<script setup lang="ts">
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Switch } from '@/components/ui/switch';
import { Button } from '@/components/ui/button';

/**
 * The small controls U18 is about, at their real size.
 *
 * A target's size is a number the layout engine produces from padding, line
 * height, borders and the icon inside it. No amount of reading Tailwind
 * classes gives it: `size-4` inside `p-1` inside a `absolute right-4` is a
 * measurement, not a string.
 *
 * The dialog is open on mount so its close control is laid out and
 * measurable — it is teleported to `body`, which is why the check looks for
 * it there rather than inside this component.
 */
</script>

<template>
    <div class="flex flex-col gap-6 p-6">
        <div class="flex items-center gap-3">
            <Switch id="switch-target" />
            <label for="switch-target" class="text-sm">A setting</label>
        </div>

        <Button
            id="icon-button"
            variant="ghost"
            size="icon-sm"
            aria-label="Icon"
        >
            <svg viewBox="0 0 24 24" class="size-4" aria-hidden="true">
                <circle cx="12" cy="12" r="8" fill="currentColor" />
            </svg>
        </Button>

        <Dialog :open="true">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>A dialog</DialogTitle>
                    <DialogDescription>With a close control.</DialogDescription>
                </DialogHeader>
                <p class="text-sm">Body.</p>
            </DialogContent>
        </Dialog>
    </div>
</template>
