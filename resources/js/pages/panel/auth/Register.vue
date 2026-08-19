<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import PanelAuthLayout from '@/panel/layouts/PanelAuthLayout.vue';
import type { PanelDefinition } from '@/panel/types/panel';
import { store } from '@/routes/register';
import PanelBlankLayout from '@/panel/layouts/PanelBlankLayout.vue';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

defineOptions({ layout: PanelBlankLayout });

defineProps<{
    panel: PanelDefinition;
    passwordRules: string;
}>();
</script>

<template>
    <PanelAuthLayout
        :panel="panel"
        :title="t('auth.create_an_account')"
        :description="
            t('auth.register_description', { brand: panel.brandName })
        "
    >
        <Head :title="`${t('auth.register')} · ${panel.brandName}`" />

        <Form
            v-slot="{ errors, processing }"
            v-bind="store.form()"
            :reset-on-success="['password', 'password_confirmation']"
            class="flex flex-col gap-6"
        >
            <div class="grid gap-2">
                <Label for="name">{{ t('auth.name') }}</Label>
                <Input
                    id="name"
                    name="name"
                    required
                    autofocus
                    autocomplete="name"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">{{ t('auth.email') }}</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    required
                    autocomplete="email"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="password">{{ t('auth.password') }}</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    autocomplete="new-password"
                />
                <p class="text-xs text-muted-foreground">{{ passwordRules }}</p>
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">
                    {{ t('auth.confirm_password') }}
                </Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    required
                    autocomplete="new-password"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <Button type="submit" class="w-full" :disabled="processing">
                <Spinner v-if="processing" />
                {{ t('auth.create_account') }}
            </Button>

            <p class="text-center text-sm text-muted-foreground">
                {{ t('auth.have_account') }}
                <TextLink :href="`/${panel.path}/login`">
                    {{ t('auth.log_in') }}
                </TextLink>
            </p>
        </Form>
    </PanelAuthLayout>
</template>
