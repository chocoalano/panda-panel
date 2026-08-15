<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import ActionButton from '@/panel/actions/ActionButton.vue';
import PageHeader from '@/panel/components/PageHeader.vue';
import type { ActionDefinition } from '@/panel/types/action';
import type { PageMetadata } from '@/panel/types/page';

const props = defineProps<{
    page: PageMetadata;
    profile: {
        name: string;
        email: string;
        verified: boolean;
        joined: string | null;
    } | null;
}>();

const headerActions = props.page.headerActions as ActionDefinition[];
</script>

<template>
    <Head :title="page.title" />

    <div class="flex max-w-2xl flex-col gap-6">
        <PageHeader :heading="page.heading" :subheading="page.subheading">
            <template #actions>
                <ActionButton
                    v-for="action in headerActions"
                    :key="action.name"
                    :action="action"
                    size="default"
                />
            </template>
        </PageHeader>

        <dl v-if="profile" class="divide-y rounded-lg border">
            <div class="grid gap-1 px-4 py-3 sm:grid-cols-3 sm:gap-4">
                <dt class="text-sm text-muted-foreground">Name</dt>
                <dd class="text-sm sm:col-span-2">{{ profile.name }}</dd>
            </div>
            <div class="grid gap-1 px-4 py-3 sm:grid-cols-3 sm:gap-4">
                <dt class="text-sm text-muted-foreground">Email</dt>
                <dd class="flex items-center gap-2 text-sm sm:col-span-2">
                    {{ profile.email }}
                    <Badge
                        :variant="profile.verified ? 'secondary' : 'outline'"
                    >
                        {{ profile.verified ? 'Verified' : 'Unverified' }}
                    </Badge>
                </dd>
            </div>
            <div class="grid gap-1 px-4 py-3 sm:grid-cols-3 sm:gap-4">
                <dt class="text-sm text-muted-foreground">Member since</dt>
                <dd class="text-sm sm:col-span-2">
                    {{ profile.joined ?? '—' }}
                </dd>
            </div>
        </dl>
    </div>
</template>
