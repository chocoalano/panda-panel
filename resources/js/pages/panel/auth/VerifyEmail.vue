<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import PanelAuthLayout from '@/panel/layouts/PanelAuthLayout.vue';
import type { PanelDefinition } from '@/panel/types/panel';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

defineProps<{
    panel: PanelDefinition;
    status?: string;
}>();
</script>

<template>
    <PanelAuthLayout
        :panel="panel"
        title="Verify your email"
        description="We sent you a link. Open it to finish signing in."
    >
        <Head :title="`Verify email · ${panel.brandName}`" />

        <div
            v-if="status === 'verification-link-sent'"
            class="mb-4 text-center text-sm font-medium text-emerald-600"
        >
            A new link has been sent to your address.
        </div>

        <Form
            v-slot="{ processing }"
            v-bind="send.form()"
            class="flex flex-col gap-4"
        >
            <Button type="submit" :disabled="processing">
                <Spinner v-if="processing" />
                Resend the link
            </Button>

            <Link
                :href="logout()"
                as="button"
                class="text-center text-sm text-muted-foreground underline underline-offset-4"
            >
                Log out
            </Link>
        </Form>
    </PanelAuthLayout>
</template>
