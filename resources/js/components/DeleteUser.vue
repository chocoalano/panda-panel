<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { useTemplateRef } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();

const passwordInput = useTemplateRef('passwordInput');
</script>

<template>
    <div class="space-y-6">
        <Heading
            variant="small"
            :title="t('settings.delete_account')"
            :description="t('settings.delete_account_description')"
        />
        <div
            class="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10"
        >
            <div class="relative space-y-0.5 text-red-600 dark:text-red-100">
                <p class="font-medium">
                    {{ t('settings.delete_account_warning_heading') }}
                </p>
                <p class="text-sm">
                    {{ t('settings.delete_account_warning') }}
                </p>
            </div>
            <Dialog>
                <DialogTrigger as-child>
                    <Button
                        variant="destructive"
                        data-test="delete-user-button"
                    >
                        {{ t('settings.delete_account') }}
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <Form
                        v-slot="{ errors, processing, reset, clearErrors }"
                        v-bind="ProfileController.destroy.form()"
                        reset-on-success
                        :options="{
                            preserveScroll: true,
                        }"
                        class="space-y-6"
                        @error="() => passwordInput?.focus()"
                    >
                        <DialogHeader class="space-y-3">
                            <DialogTitle>
                                {{ t('settings.delete_account_confirm') }}
                            </DialogTitle>
                            <DialogDescription>
                                {{ t('settings.delete_account_explanation') }}
                            </DialogDescription>
                        </DialogHeader>

                        <div class="grid gap-2">
                            <Label for="password" class="sr-only">
                                {{ t('settings.password') }}
                            </Label>
                            <PasswordInput
                                id="password"
                                ref="passwordInput"
                                name="password"
                                :placeholder="t('settings.password')"
                            />
                            <InputError :message="errors.password" />
                        </div>

                        <DialogFooter class="gap-2">
                            <DialogClose as-child>
                                <Button
                                    variant="secondary"
                                    @click="
                                        () => {
                                            clearErrors();
                                            reset();
                                        }
                                    "
                                >
                                    {{ t('settings.cancel') }}
                                </Button>
                            </DialogClose>

                            <Button
                                type="submit"
                                variant="destructive"
                                :disabled="processing"
                                data-test="confirm-delete-user-button"
                            >
                                {{ t('settings.delete_account') }}
                            </Button>
                        </DialogFooter>
                    </Form>
                </DialogContent>
            </Dialog>
        </div>
    </div>
</template>
