<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import PanelAuthLayout from '@/panel/layouts/PanelAuthLayout.vue';
import type { PanelDefinition } from '@/panel/types/panel';
import { email } from '@/routes/password';

defineProps<{
    panel: PanelDefinition;
    status?: string;
}>();
</script>

<template>
    <PanelAuthLayout
        :panel="panel"
        title="Forgot password"
        description="Enter your email and we will send you a reset link."
    >
        <Head :title="`Forgot password · ${panel.brandName}`" />

        <div
            v-if="status"
            class="mb-4 text-center text-sm font-medium text-emerald-600"
        >
            {{ status }}
        </div>

        <Form
            v-slot="{ errors, processing }"
            v-bind="email.form()"
            class="flex flex-col gap-6"
        >
            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    required
                    autofocus
                />
                <InputError :message="errors.email" />
            </div>

            <Button type="submit" class="w-full" :disabled="processing">
                <Spinner v-if="processing" />
                Email reset link
            </Button>

            <p class="text-center text-sm text-muted-foreground">
                <TextLink :href="`/${panel.path}/login`"
                    >Back to log in</TextLink
                >
            </p>
        </Form>
    </PanelAuthLayout>
</template>
