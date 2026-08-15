<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasskeyVerify from '@/components/PasskeyVerify.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import PanelAuthLayout from '@/panel/layouts/PanelAuthLayout.vue';
import type { PanelDefinition } from '@/panel/types/panel';
import { store } from '@/routes/login';

/**
 * A panel's own login page.
 *
 * The form posts to Fortify's endpoint — the same one the application's own
 * login uses. Only the presentation is the panel's: duplicating the login
 * POST per panel would mean duplicating rate limiting, two-factor, passkeys,
 * and session handling, four things that must never disagree between two
 * doors into the same application.
 *
 * Signing in returns the user to where they were going, because the panel's
 * guest middleware kept the intended URL before redirecting them here.
 */
defineProps<{
    panel: PanelDefinition;
    status?: string;
    canResetPassword: boolean;
    canRegister: boolean;
}>();
</script>

<template>
    <PanelAuthLayout
        :panel="panel"
        title="Sign in"
        :description="`Enter your details to continue to ${panel.brandName}.`"
    >
        <Head :title="`Log in · ${panel.brandName}`" />

        <div
            v-if="status"
            class="mb-4 text-center text-sm font-medium text-emerald-600"
        >
            {{ status }}
        </div>

        <PasskeyVerify />

        <Form
            v-slot="{ errors, processing }"
            v-bind="store.form()"
            :reset-on-success="['password']"
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
                    autocomplete="email"
                    placeholder="email@example.com"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <div class="flex items-center justify-between">
                    <Label for="password">Password</Label>
                    <TextLink
                        v-if="canResetPassword"
                        :href="`/${panel.path}/forgot-password`"
                        class="text-sm"
                    >
                        Forgot your password?
                    </TextLink>
                </div>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    autocomplete="current-password"
                    placeholder="Password"
                />
                <InputError :message="errors.password" />
            </div>

            <Label for="remember" class="flex items-center space-x-3">
                <Checkbox id="remember" name="remember" />
                <span>Remember me</span>
            </Label>

            <Button type="submit" class="w-full" :disabled="processing">
                <Spinner v-if="processing" />
                Log in
            </Button>

            <p
                v-if="canRegister"
                class="text-center text-sm text-muted-foreground"
            >
                Don't have an account?
                <TextLink :href="`/${panel.path}/register`">Sign up</TextLink>
            </p>
        </Form>
    </PanelAuthLayout>
</template>
