<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    status: number;
}>();

const errorConfig = computed(() => {
    const configs: Record<number, { title: string; description: string; icon: string; accent: string }> = {
        403: {
            title: 'Access Denied',
            description: "You don't have permission to view this page. If you believe this is a mistake, contact your family admin.",
            icon: '🔒',
            accent: 'amber',
        },
        404: {
            title: 'Page Not Found',
            description: "The page you're looking for has moved or doesn't exist. Let's get you back on track.",
            icon: '🔍',
            accent: 'blue',
        },
        500: {
            title: 'Something Went Wrong',
            description: "We hit an unexpected snag on our end. Our team has been notified and we're working on it.",
            icon: '⚡',
            accent: 'red',
        },
        503: {
            title: 'Under Maintenance',
            description: "We're making improvements to serve you better. Please check back in a few minutes.",
            icon: '🔧',
            accent: 'purple',
        },
    };

    return configs[props.status] ?? {
        title: 'Unexpected Error',
        description: 'Something unexpected happened. Please try again.',
        icon: '⚠️',
        accent: 'gray',
    };
});

function goBack(): void {
    if (window.history.length > 1) {
        window.history.back();
    } else {
        router.visit('/');
    }
}
</script>

<template>
    <div>
        <Head :title="`${status} - ${errorConfig.title}`" />

        <div class="relative flex min-h-screen items-center justify-center overflow-hidden bg-linear-to-b from-slate-50 to-white dark:from-slate-950 dark:to-slate-900">
            <!-- Background decoration -->
            <div class="pointer-events-none absolute inset-0 overflow-hidden">
                <div
                    class="absolute -top-40 -right-40 h-150 w-150 rounded-full bg-linear-to-br from-emerald-400/10 to-teal-500/10 blur-3xl dark:from-emerald-400/5 dark:to-teal-500/5"
                />
                <div
                    class="absolute -bottom-40 -left-40 h-125 w-125 rounded-full bg-linear-to-tr from-teal-400/10 to-emerald-500/10 blur-3xl dark:from-teal-400/5 dark:to-emerald-500/5"
                />

                <!-- Subtle grid pattern -->
                <div
                    class="absolute inset-0 opacity-[0.015] dark:opacity-[0.03]"
                    style="background-image: url('data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2260%22 height=%2260%22%3E%3Cpath d=%22M60 0H0v60%22 fill=%22none%22 stroke=%22currentColor%22 stroke-width=%220.5%22/%3E%3C/svg%3E')"
                />
            </div>

            <div class="relative z-10 mx-auto max-w-lg px-6 py-16 text-center">
                <!-- Logo -->
                <Link href="/" class="mb-10 inline-flex items-center gap-2.5 transition-opacity hover:opacity-80">
                    <div class="flex size-9 items-center justify-center rounded-lg bg-linear-to-br from-emerald-500 to-teal-600 shadow-lg shadow-emerald-500/25">
                        <AppLogoIcon class="size-5 text-white" />
                    </div>
                    <span class="text-lg font-semibold text-slate-900 dark:text-white">FamilyFund</span>
                </Link>

                <!-- Error icon + status code -->
                <div class="mb-6 flex flex-col items-center gap-3">
                    <span class="text-5xl" role="img" :aria-label="errorConfig.title">
                        {{ errorConfig.icon }}
                    </span>
                    <span class="inline-flex items-center rounded-full border border-slate-200 bg-white/80 px-3 py-1 font-mono text-sm font-medium text-slate-500 backdrop-blur-sm dark:border-slate-700 dark:bg-slate-800/80 dark:text-slate-400">
                        {{ status }}
                    </span>
                </div>

                <!-- Title -->
                <h1 class="mb-3 text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl dark:text-white">
                    {{ errorConfig.title }}
                </h1>

                <!-- Description -->
                <p class="mx-auto mb-10 max-w-sm text-base leading-relaxed text-slate-500 dark:text-slate-400">
                    {{ errorConfig.description }}
                </p>

                <!-- Actions -->
                <div class="flex flex-col items-center justify-center gap-3 sm:flex-row">
                    <Link href="/">
                        <Button
                            size="lg"
                            class="w-full bg-linear-to-r from-emerald-600 to-teal-600 text-white shadow-lg shadow-emerald-500/25 hover:from-emerald-700 hover:to-teal-700 sm:w-auto"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="mr-2 size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
                                <polyline points="9 22 9 12 15 12 15 22" />
                            </svg>
                            Go Home
                        </Button>
                    </Link>

                    <Button
                        v-if="status !== 503"
                        variant="outline"
                        size="lg"
                        class="w-full sm:w-auto"
                        @click="goBack"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="mr-2 size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m12 19-7-7 7-7" />
                            <path d="M19 12H5" />
                        </svg>
                        Go Back
                    </Button>
                </div>

                <!-- Helpful hint for 404 -->
                <p v-if="status === 404" class="mt-10 text-sm text-slate-400 dark:text-slate-500">
                    Try checking the URL for typos or
                    <Link href="/" class="text-emerald-600 underline underline-offset-2 hover:text-emerald-700 dark:text-emerald-400 dark:hover:text-emerald-300">
                        browse from the homepage
                    </Link>.
                </p>
            </div>
        </div>
    </div>
</template>
