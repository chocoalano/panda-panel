<script setup lang="ts">
import PanelClusterBar from '@/panel/components/PanelClusterBar.vue';
import type { ClusterNavigation } from '@/panel/types/page';

/**
 * A cluster rail beside a page, exactly as `SidebarPanelLayout` arranges it.
 *
 * The calibration target for the harness: U08 is a breakpoint behaviour, so a
 * browser that can see it can see any of them. This does not reopen U08 —
 * the fix is asserted in Vitest against the source, and what is being proved
 * here is that Chrome agrees with what the source says.
 */
const cluster = {
    label: 'Settings',
    position: 'right-bar',
    items: [
        {
            label: 'General',
            href: '/general',
            icon: null,
            activeIcon: null,
            badge: null,
            active: true,
        },
        {
            label: 'Members',
            href: '/members',
            icon: null,
            activeIcon: null,
            badge: null,
            active: false,
        },
        {
            label: 'Billing',
            href: '/billing',
            icon: null,
            activeIcon: null,
            badge: null,
            active: false,
        },
    ],
} as unknown as ClusterNavigation;
</script>

<template>
    <!-- The layout's own wrapper for a right-bar cluster, copied verbatim. -->
    <div class="flex flex-1 flex-col gap-4 lg:flex-row lg:gap-6">
        <div id="content" class="flex min-w-0 flex-1 flex-col gap-4 md:gap-6">
            <p class="text-sm">Page content.</p>
        </div>
        <PanelClusterBar :cluster="cluster" orientation="column" />
    </div>
</template>
