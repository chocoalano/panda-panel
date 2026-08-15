<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { resolveIcon } from '@/panel/icons/registry';
import type { ClusterNavigation } from '@/panel/types/page';

/**
 * The sub-navigation of the cluster a page belongs to.
 *
 * Rendered either as a bar under the header or as a column beside the
 * content — the same links either way, because where a set of pages is
 * listed is a layout decision the panel made once and not something each
 * page has an opinion about.
 *
 * Never rendered empty: the server sends null when the cluster has nothing
 * this user may see.
 */
defineProps<{
    cluster: ClusterNavigation;
    /** `header` lays the links out in a row; `right-bar` stacks them. */
    orientation: 'row' | 'column';
}>();
</script>

<template>
    <nav
        :aria-label="cluster.label"
        :class="
            orientation === 'row'
                ? 'flex flex-wrap items-center gap-1 border-b pb-3'
                : 'flex w-56 shrink-0 flex-col gap-1'
        "
    >
        <p
            v-if="orientation === 'column'"
            class="px-2 pb-1 text-xs font-medium text-muted-foreground"
        >
            {{ cluster.label }}
        </p>

        <Link
            v-for="item in cluster.items"
            :key="item.href"
            :href="item.href"
            class="flex items-center gap-2 rounded-md px-3 py-1.5 text-sm transition-colors"
            :class="
                item.active
                    ? 'bg-accent font-medium text-accent-foreground'
                    : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground'
            "
            :aria-current="item.active ? 'page' : undefined"
        >
            <!-- The active icon is sent with every item, so the swap happens
                 on a client-side navigation without a round trip. -->
            <component
                :is="resolveIcon(item.active ? item.activeIcon : item.icon)"
                v-if="resolveIcon(item.active ? item.activeIcon : item.icon)"
                class="size-4"
            />
            {{ item.label }}
            <span
                v-if="item.badge !== null"
                class="ml-auto rounded-full bg-muted px-1.5 text-xs text-muted-foreground"
            >
                {{ item.badge }}
            </span>
        </Link>
    </nav>
</template>
