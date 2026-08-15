<script setup lang="ts">
import { Building2, Check } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { usePanel } from '@/panel/composables/usePanel';

/**
 * Moves between the tenants the signed-in user belongs to.
 *
 * A dropdown rather than the sheet the *panel* switcher uses, and the
 * difference is not cosmetic: a panel entry carries a brand, an icon and a
 * path and needs the room, while a tenant is a name. A list of names in a
 * sheet is a lot of chrome around very little.
 *
 * Renders nothing at all unless three things are true — the panel declared
 * tenancy, the user belongs to more than one tenant, and the panel said how
 * to build a tenant's URL. The third is the one that catches people out, and
 * it is deliberate: identification is the application's (a subdomain, a path
 * segment, one tenant per user), so reversing it into a URL is too. A
 * switcher whose entries went nowhere would be worse than no switcher.
 *
 * A plain `<a>` rather than Inertia's `<Link>`, because a tenant usually
 * lives on another host. An Inertia visit across origins is a request the
 * browser refuses; a navigation is what actually moves.
 */
const { tenancy, canSwitchTenants } = usePanel();

const open = ref(false);
</script>

<template>
    <DropdownMenu v-if="canSwitchTenants" v-model:open="open">
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="sm"
                class="gap-2"
                aria-label="Switch tenant"
            >
                <Building2 class="size-4" />
                <span class="max-w-32 truncate">
                    {{ tenancy?.current?.name ?? 'Select' }}
                </span>
            </Button>
        </DropdownMenuTrigger>

        <DropdownMenuContent align="end" class="w-56">
            <DropdownMenuLabel>Switch tenant</DropdownMenuLabel>

            <DropdownMenuSeparator />

            <DropdownMenuItem
                v-for="tenant in tenancy?.available ?? []"
                :key="tenant.key"
                :disabled="tenant.url === null || tenant.current"
                as-child
            >
                <a
                    v-if="tenant.url !== null"
                    :href="tenant.url"
                    class="flex w-full items-center justify-between gap-2"
                    :aria-current="tenant.current ? 'true' : undefined"
                >
                    <span class="truncate">{{ tenant.name }}</span>
                    <Check v-if="tenant.current" class="size-4 shrink-0" />
                </a>

                <span v-else class="flex w-full items-center gap-2 opacity-60">
                    {{ tenant.name }}
                </span>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
