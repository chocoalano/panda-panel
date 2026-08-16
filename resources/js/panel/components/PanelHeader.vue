<script setup lang="ts">
import { Moon, Sun } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useAppearance } from '@/composables/useAppearance';
import PanelBreadcrumb from '@/panel/components/PanelBreadcrumb.vue';
import PanelNotifications from '@/panel/components/PanelNotifications.vue';
import PanelRenderHook from '@/panel/components/PanelRenderHook.vue';
import PanelSearch from '@/panel/components/PanelSearch.vue';
import PanelSwitcher from '@/panel/components/PanelSwitcher.vue';
import PanelTenantSwitcher from '@/panel/components/PanelTenantSwitcher.vue';
import { usePanel } from '@/panel/composables/usePanel';
import { usePanelStyling } from '@/panel/composables/usePanelStyling';
import type { PanelBreadcrumbItem } from '@/panel/types/breadcrumb';

withDefaults(
    defineProps<{
        breadcrumbs?: PanelBreadcrumbItem[];
        showSidebarTrigger?: boolean;
    }>(),
    {
        breadcrumbs: () => [],
        showSidebarTrigger: true,
    },
);

const { resolvedAppearance, updateAppearance } = useAppearance();
const { shell } = usePanel();
const { hook } = usePanelStyling();

const isDark = computed(() => resolvedAppearance.value === 'dark');

function toggleAppearance(): void {
    updateAppearance(isDark.value ? 'light' : 'dark');
}
</script>

<template>
    <!--
        Dimensions and composition follow the shadcn dashboard block: a 16-unit
        bar, trigger and breadcrumb separated by a short vertical rule, and the
        same `px-4 lg:px-6` gutter the content below uses, so the header and
        the page share one left edge.
    -->
    <header
        class="flex h-16 shrink-0 items-center gap-2 border-b px-4 transition-[width,height] ease-linear lg:px-6"
        :class="hook('topbar')"
    >
        <template v-if="showSidebarTrigger">
            <SidebarTrigger class="-ml-1" />
            <Separator
                orientation="vertical"
                class="mr-2 data-[orientation=vertical]:h-4"
            />
        </template>

        <PanelRenderHook name="header.start" />

        <PanelBreadcrumb
            v-if="shell.breadcrumbs"
            :breadcrumbs="breadcrumbs"
            class="hidden md:block"
        />

        <div class="ml-auto flex items-center gap-1">
            <PanelRenderHook name="header.end" />

            <!-- Hides itself when no resource in the panel opted in. -->
            <PanelSearch />

            <!-- Hides itself when the user may enter only one panel. -->
            <PanelTenantSwitcher />

            <PanelSwitcher />

            <!-- Hides itself when the panel turned notifications off. -->
            <PanelNotifications />

            <Button
                variant="ghost"
                size="icon-sm"
                :aria-label="
                    isDark ? 'Switch to light mode' : 'Switch to dark mode'
                "
                @click="toggleAppearance"
            >
                <Sun v-if="isDark" />
                <Moon v-else />
            </Button>
        </div>
    </header>
</template>
