<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/DeleteUser.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import PageHeader from '@/panel/components/PageHeader.vue';
import type { PageMetadata } from '@/panel/types/page';
import { send } from '@/routes/verification';
import PanelLayout from '@/panel/layouts/PanelLayout.vue';

defineOptions({ layout: PanelLayout });

/**
 * The panel's profile page.
 *
 * It posts to the application's own ProfileController, which is the single
 * place a profile is written; only the shell around the form is the panel's.
 */
withDefaults(
    defineProps<{
        page: PageMetadata;
        mustVerifyEmail?: boolean;
        status?: string | null;
    }>(),
    {
        mustVerifyEmail: false,
        status: null,
    },
);

const inertiaPage = usePage();
const user = computed(() => inertiaPage.props.auth.user);
</script>

<template>
    <Head :title="page.title" />

    <div class="flex max-w-2xl flex-col gap-6">
        <PageHeader :heading="page.heading" :subheading="page.subheading" />

        <Card class="shadow-xs">
            <CardContent>
                <Form
                    v-slot="{ errors, processing }"
                    v-bind="ProfileController.update.form()"
                    class="space-y-6"
                >
                    <div class="grid gap-2">
                        <Label for="name">Name</Label>
                        <Input
                            id="name"
                            class="block w-full"
                            name="name"
                            :default-value="user.name"
                            required
                            autocomplete="name"
                            placeholder="Full name"
                        />
                        <InputError :message="errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="email">Email address</Label>
                        <Input
                            id="email"
                            type="email"
                            class="block w-full"
                            name="email"
                            :default-value="user.email"
                            required
                            autocomplete="username"
                            placeholder="Email address"
                        />
                        <InputError :message="errors.email" />
                    </div>

                    <div v-if="mustVerifyEmail && !user.email_verified_at">
                        <p class="text-sm text-muted-foreground">
                            Your email address is unverified.
                            <Link
                                :href="send()"
                                as="button"
                                class="text-foreground underline underline-offset-4"
                            >
                                Click here to re-send the verification email.
                            </Link>
                        </p>

                        <div
                            v-if="status === 'verification-link-sent'"
                            class="mt-2 text-sm font-medium text-emerald-600 dark:text-emerald-400"
                        >
                            A new verification link has been sent to your email
                            address.
                        </div>
                    </div>

                    <Button
                        :disabled="processing"
                        data-test="update-profile-button"
                    >
                        Save
                    </Button>
                </Form>
            </CardContent>
        </Card>

        <DeleteUser />
    </div>
</template>
