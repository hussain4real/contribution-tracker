<script setup lang="ts">
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';
import * as whatsapp from '@/routes/whatsapp';
import { Form, Head, Link, router, usePage } from '@inertiajs/vue3';

import DeleteUser from '@/components/DeleteUser.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import { CheckCircle2, MessageCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Props {
    mustVerifyEmail?: boolean;
    status?: string;
}

const props = withDefaults(defineProps<Props>(), {
    mustVerifyEmail: false,
});

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Profile settings',
        href: edit().url,
    },
];

const page = usePage();
const user = computed(() => page.props.auth.user);

const isVerified = computed(
    () => !!user.value?.whatsapp_phone && !!user.value?.whatsapp_verified_at,
);

const codeSent = ref(false);
const showCodeSent = computed(
    () => codeSent.value || props.status === 'whatsapp-code-sent',
);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Profile settings" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <HeadingSmall
                    title="Profile information"
                    description="Update your name and email address"
                />

                <Form
                    v-bind="ProfileController.update.form()"
                    class="space-y-6"
                    v-slot="{ errors, processing, recentlySuccessful }"
                >
                    <div class="grid gap-2">
                        <Label for="name">Name</Label>
                        <Input
                            id="name"
                            class="mt-1 block w-full"
                            name="name"
                            :default-value="user.name"
                            required
                            autocomplete="name"
                            placeholder="Full name"
                        />
                        <InputError class="mt-2" :message="errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="email">Email address</Label>
                        <Input
                            id="email"
                            type="email"
                            class="mt-1 block w-full"
                            name="email"
                            :default-value="user.email"
                            required
                            autocomplete="username"
                            placeholder="Email address"
                        />
                        <InputError class="mt-2" :message="errors.email" />
                    </div>

                    <div v-if="mustVerifyEmail && !user.email_verified_at">
                        <p class="-mt-4 text-sm text-muted-foreground">
                            Your email address is unverified.
                            <Link
                                :href="send()"
                                as="button"
                                class="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                            >
                                Click here to resend the verification email.
                            </Link>
                        </p>

                        <div
                            v-if="status === 'verification-link-sent'"
                            class="mt-2 text-sm font-medium text-green-600"
                        >
                            A new verification link has been sent to your email
                            address.
                        </div>
                    </div>

                    <div class="flex items-center gap-4">
                        <Button
                            :disabled="processing"
                            data-test="update-profile-button"
                            >Save</Button
                        >

                        <Transition
                            enter-active-class="transition ease-in-out"
                            enter-from-class="opacity-0"
                            leave-active-class="transition ease-in-out"
                            leave-to-class="opacity-0"
                        >
                            <p
                                v-show="recentlySuccessful"
                                class="text-sm text-neutral-600"
                            >
                                Saved.
                            </p>
                        </Transition>
                    </div>
                </Form>
            </div>

            <Separator class="my-8" />

            <div class="flex flex-col space-y-6">
                <HeadingSmall
                    title="WhatsApp notifications"
                    description="Verify your WhatsApp number to receive contribution reminders on WhatsApp."
                />

                <div
                    v-if="isVerified"
                    class="flex flex-col gap-4 rounded-lg border border-green-200 bg-green-50 p-4 dark:border-green-800 dark:bg-green-900/20"
                >
                    <div class="flex items-start gap-3">
                        <CheckCircle2
                            class="mt-0.5 h-5 w-5 text-green-600 dark:text-green-400"
                        />
                        <div class="flex-1">
                            <div class="flex items-center gap-2">
                                <p class="font-medium">
                                    {{ user.whatsapp_phone }}
                                </p>
                                <Badge variant="default" class="bg-green-600">
                                    Verified
                                </Badge>
                            </div>
                            <p class="mt-1 text-sm text-muted-foreground">
                                You'll receive contribution reminders on this
                                WhatsApp number.
                            </p>
                        </div>
                    </div>

                    <Form
                        v-bind="whatsapp.destroy.form()"
                        :options="{ preserveScroll: true }"
                        v-slot="{ processing }"
                    >
                        <Button
                            type="submit"
                            variant="outline"
                            size="sm"
                            :disabled="processing"
                        >
                            Remove WhatsApp number
                        </Button>
                    </Form>
                </div>

                <template v-else>
                    <Form
                        v-bind="whatsapp.sendCode.form()"
                        :options="{ preserveScroll: true }"
                        v-slot="{ errors, processing }"
                        @success="codeSent = true"
                    >
                        <div class="grid gap-2">
                            <Label for="whatsapp_phone">WhatsApp number</Label>
                            <Input
                                id="whatsapp_phone"
                                name="whatsapp_phone"
                                type="tel"
                                class="mt-1 block w-full"
                                :default-value="user.whatsapp_phone ?? ''"
                                placeholder="+2348012345678"
                                autocomplete="tel"
                            />
                            <p class="text-xs text-muted-foreground">
                                Enter your WhatsApp number with country code
                                (e.g. +234 for Nigeria). Make sure this number
                                is registered on WhatsApp.
                            </p>
                            <InputError
                                class="mt-2"
                                :message="errors.whatsapp_phone"
                            />
                        </div>

                        <div class="mt-4">
                            <Button type="submit" :disabled="processing">
                                <MessageCircle class="h-4 w-4" />
                                Send verification code
                            </Button>
                        </div>
                    </Form>

                    <div
                        v-if="showCodeSent"
                        class="rounded-lg border bg-muted/40 p-4"
                    >
                        <p class="mb-3 text-sm">
                            We sent a 6-digit code to your WhatsApp. Enter it
                            below to verify your number.
                        </p>

                        <Form
                            v-bind="whatsapp.verify.form()"
                            :options="{ preserveScroll: true }"
                            reset-on-success
                            v-slot="{ errors, processing }"
                            @success="
                                () => {
                                    codeSent = false;
                                    router.reload({ only: ['auth'] });
                                }
                            "
                        >
                            <div class="grid gap-2">
                                <Label for="code">Verification code</Label>
                                <Input
                                    id="code"
                                    name="code"
                                    type="text"
                                    inputmode="numeric"
                                    autocomplete="one-time-code"
                                    maxlength="6"
                                    class="mt-1 block w-full"
                                    placeholder="123456"
                                />
                                <InputError
                                    class="mt-2"
                                    :message="errors.code"
                                />
                            </div>

                            <div class="mt-4">
                                <Button type="submit" :disabled="processing">
                                    Verify
                                </Button>
                            </div>
                        </Form>
                    </div>
                </template>
            </div>

            <DeleteUser />
        </SettingsLayout>
    </AppLayout>
</template>
