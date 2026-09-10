<script setup lang="ts">
import DataTable from '@/panel/tables/DataTable.vue';
import { rows, state, table } from '../fixture';
</script>

<template>
    <DataTable :table="table" :rows="rows" :state="state" bordered />
</template>
