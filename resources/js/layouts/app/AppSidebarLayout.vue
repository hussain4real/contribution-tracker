<script setup lang="ts">
import { stopImpersonating } from '@/actions/App/Http/Controllers/PlatformAdminController';
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import FlashMessages from '@/components/FlashMessages.vue';
import PwaInstallPrompt from '@/components/PwaInstallPrompt.vue';
import { Button } from '@/components/ui/button';
import type { BreadcrumbItemType } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

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
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar" class="overflow-x-hidden">
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
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <slot />
        </AppContent>
        <FlashMessages />
        <PwaInstallPrompt />
    </AppShell>
</template>
