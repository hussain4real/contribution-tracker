<script setup lang="ts">
import NotificationBell from '@/components/NotificationBell.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import type { BreadcrumbItemType } from '@/types';
import { router } from '@inertiajs/vue3';
import { ChevronLeft } from '@lucide/vue';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItemType[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const title = computed(
    () => props.breadcrumbs.at(-1)?.title ?? props.breadcrumbs[0]?.title ?? '',
);
const canGoBack = computed(() => props.breadcrumbs.length > 1);

function goBack(): void {
    if (window.history.length > 1) {
        window.history.back();
        return;
    }

    router.visit(dashboard());
}
</script>

<template>
    <header
        class="sticky top-0 z-40 border-b bg-background/95 pt-[env(safe-area-inset-top)] backdrop-blur supports-[backdrop-filter]:bg-background/80 md:hidden"
    >
        <div class="flex h-14 items-center gap-2 px-3">
            <Button
                v-if="canGoBack"
                variant="ghost"
                size="icon"
                class="app-tap -ml-1 size-10"
                aria-label="Go back"
                @click="goBack"
            >
                <ChevronLeft class="size-5" />
            </Button>
            <div v-else class="w-1" />

            <h1 class="min-w-0 flex-1 truncate text-base font-semibold">
                {{ title || 'FamilyFunds' }}
            </h1>

            <NotificationBell />
            <ThemeToggle />
        </div>
    </header>
</template>
