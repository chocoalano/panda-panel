<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import InputError from '@/components/InputError.vue';
import type { Props as ManagePasskeysProps } from '@/components/ManagePasskeys.vue';
import ManagePasskeys from '@/components/ManagePasskeys.vue';
import type { Props as ManageTwoFactorProps } from '@/components/ManageTwoFactor.vue';
import ManageTwoFactor from '@/components/ManageTwoFactor.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import PageHeader from '@/panel/components/PageHeader.vue';
import type { PageMetadata } from '@/panel/types/page';
import PanelLayout from '@/panel/layouts/PanelLayout.vue';

defineOptions({ layout: PanelLayout });

type Props = {
    page: PageMetadata;
    passwordRules: string;
    /** The panel's own second factor: a one-time code sent to this address. */
    emailCodeEnabled: boolean;
    emailCodeUrls: { enable: string; disable: string };
} & ManagePasskeysProps &
    ManageTwoFactorProps;

const props = defineProps<Props>();
</script>

<template>
    <Head :title="page.title" />

    <div class="flex max-w-2xl flex-col gap-6">
        <PageHeader :heading="page.heading" :subheading="page.subheading" />

        <Card class="shadow-xs">
            <CardContent>
                <Form
                    v-slot="{ errors, processing }"
                    v-bind="SecurityController.update.form()"
                    :options="{ preserveScroll: true }"
                    reset-on-success
                    :reset-on-error="[
                        'password',
                        'password_confirmation',
                        'current_password',
                    ]"
                    class="space-y-6"
                >
                    <div class="grid gap-2">
                        <Label for="current_password">Current password</Label>
                        <PasswordInput
                            id="current_password"
                            name="current_password"
                            class="block w-full"
                            autocomplete="current-password"
                            placeholder="Current password"
                        />
                        <InputError :message="errors.current_password" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="password">New password</Label>
                        <PasswordInput
                            id="password"
                            name="password"
                            class="block w-full"
                            autocomplete="new-password"
                            placeholder="New password"
                            :passwordrules="props.passwordRules"
                        />
                        <InputError :message="errors.password" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="password_confirmation">
                            Confirm password
                        </Label>
                        <PasswordInput
                            id="password_confirmation"
                            name="password_confirmation"
                            class="block w-full"
                            autocomplete="new-password"
                            placeholder="Confirm password"
                            :passwordrules="props.passwordRules"
                        />
                        <InputError :message="errors.password_confirmation" />
                    </div>

                    <Button
                        :disabled="processing"
                        data-test="update-password-button"
                    >
                        Save
                    </Button>
                </Form>
            </CardContent>
        </Card>

        <ManageTwoFactor
            :can-manage-two-factor="canManageTwoFactor"
            :requires-confirmation="requiresConfirmation"
            :two-factor-enabled="twoFactorEnabled"
        />

        <ManagePasskeys
            :can-manage-passkeys="canManagePasskeys"
            :passkeys="passkeys"
        />

        <!--
            The panel's own channel, for somebody who will not install an
            authenticator app. Weaker than TOTP and much stronger than
            nothing, which is the choice it actually competes with.
        -->
        <Card class="shadow-xs">
            <CardContent class="flex flex-col gap-4">
                <div>
                    <h2 class="text-base font-medium">Email codes</h2>
                    <p class="text-sm text-muted-foreground">
                        {{
                            emailCodeEnabled
                                ? 'A one-time code is sent to your email address each time you sign in on a new session.'
                                : 'Send a one-time code to your email address when signing in.'
                        }}
                    </p>
                </div>

                <Form
                    v-slot="{ processing }"
                    :action="
                        emailCodeEnabled
                            ? emailCodeUrls.disable
                            : emailCodeUrls.enable
                    "
                    method="post"
                >
                    <Button
                        type="submit"
                        :variant="emailCodeEnabled ? 'outline' : 'default'"
                        :disabled="processing"
                    >
                        {{ emailCodeEnabled ? 'Turn off' : 'Turn on' }}
                    </Button>
                </Form>
            </CardContent>
        </Card>
    </div>
</template>
