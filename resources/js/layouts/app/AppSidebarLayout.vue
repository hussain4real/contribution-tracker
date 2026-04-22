<script setup lang="ts">
import { stopImpersonating } from '@/actions/App/Http/Controllers/PlatformAdminController';
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import NetworkStatusBanner from '@/components/NetworkStatusBanner.vue';
import PwaInstallPrompt from '@/components/PwaInstallPrompt.vue';
import SwUpdateToast from '@/components/SwUpdateToast.vue';
import { Button } from '@/components/ui/button';
import { Toaster } from '@/components/ui/sonner';
import { usePwaCacheWarmer } from '@/composables/usePwaCacheWarmer';
import { edit as editProfile } from '@/routes/profile';
import type { BreadcrumbItemType } from '@/types';
import { Link, router, usePage } from '@inertiajs/vue3';
import { MessageCircle, X } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';

usePwaCacheWarmer();

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const page = usePage();
const isImpersonating = computed(() => page.props.impersonating);
const impersonatedName = computed(
    () => page.props.auth?.user?.name ?? 'a user',
);
const stopping = ref(false);

function stopImpersonation(): void {
    stopping.value = true;
    router.post(
        stopImpersonating().url,
        {},
        {
            onFinish: () => {
                stopping.value = false;
            },
        },
    );
}

const WHATSAPP_PROMPT_DISMISS_KEY = 'whatsapp_prompt_dismissed_at';
const whatsappPromptDismissed = ref(false);

const showWhatsAppPrompt = computed(
    () =>
        !!page.props.auth?.user &&
        !page.props.auth.user.whatsapp_verified_at &&
        !whatsappPromptDismissed.value,
);

onMounted(() => {
    const dismissedAt = localStorage.getItem(WHATSAPP_PROMPT_DISMISS_KEY);
    if (dismissedAt) {
        // Re-show the prompt after 7 days.
        const sevenDays = 7 * 24 * 60 * 60 * 1000;
        if (Date.now() - Number(dismissedAt) < sevenDays) {
            whatsappPromptDismissed.value = true;
        }
    }
});

function dismissWhatsAppPrompt(): void {
    whatsappPromptDismissed.value = true;
    localStorage.setItem(WHATSAPP_PROMPT_DISMISS_KEY, String(Date.now()));
}
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar" class="overflow-x-hidden">
            <NetworkStatusBanner />
            <!-- Impersonation Banner -->
            <div
                v-if="isImpersonating"
                class="flex items-center justify-between bg-amber-500 px-4 py-2 text-sm font-medium text-white dark:bg-amber-600"
            >
                <span
                    >You are currently impersonating
                    <strong>{{ impersonatedName }}</strong
                    >.</span
                >
                <Button
                    variant="secondary"
                    size="sm"
                    :disabled="stopping"
                    @click="stopImpersonation"
                >
                    {{ stopping ? 'Restoring...' : 'Stop Impersonating' }}
                </Button>
            </div>

            <!-- WhatsApp verification prompt -->
            <div
                v-if="showWhatsAppPrompt"
                class="flex items-center justify-between gap-3 border-b border-green-200 bg-green-50 px-4 py-2 text-sm dark:border-green-800 dark:bg-green-900/20"
            >
                <div class="flex min-w-0 items-center gap-2">
                    <MessageCircle
                        class="h-4 w-4 shrink-0 text-green-600 dark:text-green-400"
                    />
                    <span class="truncate text-green-900 dark:text-green-100">
                        Get contribution reminders on WhatsApp.
                    </span>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <Link :href="editProfile().url">
                        <Button size="sm" variant="default">
                            Verify number
                        </Button>
                    </Link>
                    <Button
                        size="icon"
                        variant="ghost"
                        class="h-7 w-7"
                        aria-label="Dismiss"
                        @click="dismissWhatsAppPrompt"
                    >
                        <X class="h-4 w-4" />
                    </Button>
                </div>
            </div>

            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <slot />
        </AppContent>
        <Toaster />
        <PwaInstallPrompt />
        <SwUpdateToast />
    </AppShell>
</template>
