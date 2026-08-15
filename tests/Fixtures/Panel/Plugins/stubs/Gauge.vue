<script setup lang="ts">
/** A stub component, published by `ReportingPlugin`. */
defineProps<{ config: Record<string, unknown> }>();
</script>

<template>
    <div class="panel-plugin-gauge">{{ config }}</div>
</template>
