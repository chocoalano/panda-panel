<script setup lang="ts">
defineProps<{
    laravel: string;
    php: string;
    environment: string;
    debug: boolean;
}>();
</script>

<template>
    <div class="flex h-full flex-col gap-3 rounded-lg border p-4">
        <h3 class="text-sm font-medium">System</h3>
        <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
            <dt class="text-muted-foreground">Laravel</dt>
            <dd class="tabular-nums">{{ laravel }}</dd>
            <dt class="text-muted-foreground">PHP</dt>
            <dd class="tabular-nums">{{ php }}</dd>
            <dt class="text-muted-foreground">Environment</dt>
            <dd>{{ environment }}</dd>
            <dt class="text-muted-foreground">Debug</dt>
            <dd>{{ debug ? 'On' : 'Off' }}</dd>
        </dl>
    </div>
</template>
