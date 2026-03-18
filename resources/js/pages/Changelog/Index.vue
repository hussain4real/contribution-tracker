<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { ChevronDown, ExternalLink, Rocket } from 'lucide-vue-next';
import { reactive } from 'vue';

interface Release {
    id: number;
    name: string;
    tag_name: string;
    body: string;
    published_at: string;
    html_url: string;
    prerelease: boolean;
}

const props = withDefaults(defineProps<{ releases?: Release[] }>(), {
    releases: () => [],
});

const expanded: Record<number, boolean> = reactive(
    Object.fromEntries(props.releases.map((r, i) => [r.id, i === 0])),
);

function toggleExpanded(id: number): void {
    expanded[id] = !expanded[id];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: "What's New", href: '/changelog' },
];

function formatDate(dateString: string): string {
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

function timeAgo(dateString: string): string {
    const now = new Date();
    const date = new Date(dateString);
    const diffMs = now.getTime() - date.getTime();
    const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));

    if (diffDays === 0) {
        return 'today';
    }
    if (diffDays === 1) {
        return 'yesterday';
    }
    if (diffDays < 7) {
        return `${diffDays} days ago`;
    }
    if (diffDays < 30) {
        const weeks = Math.floor(diffDays / 7);
        return `${weeks} ${weeks === 1 ? 'week' : 'weeks'} ago`;
    }
    if (diffDays < 365) {
        const months = Math.floor(diffDays / 30);
        return `${months} ${months === 1 ? 'month' : 'months'} ago`;
    }
    const years = Math.floor(diffDays / 365);
    return `${years} ${years === 1 ? 'year' : 'years'} ago`;
}
</script>

<template>
    <Head title="What's New" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div
            class="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 md:p-6 lg:p-8"
        >
            <!-- Page Header -->
            <div>
                <h1
                    class="flex items-center gap-3 text-3xl font-bold tracking-tight text-neutral-900 dark:text-neutral-100"
                >
                    <Rocket
                        class="h-8 w-8 text-green-600 dark:text-green-400"
                    />
                    What's New
                </h1>
                <p class="mt-2 text-lg text-neutral-600 dark:text-neutral-400">
                    Latest updates, improvements, and new features.
                </p>
            </div>

            <!-- Empty State -->
            <div
                v-if="!releases.length"
                class="rounded-xl border border-dashed border-neutral-300 py-16 text-center dark:border-neutral-700"
            >
                <Rocket
                    class="mx-auto h-12 w-12 text-neutral-300 dark:text-neutral-600"
                />
                <p
                    class="mt-4 text-lg font-medium text-neutral-500 dark:text-neutral-400"
                >
                    No releases found
                </p>
                <p class="mt-1 text-sm text-neutral-400 dark:text-neutral-500">
                    Check back later for updates.
                </p>
            </div>

            <!-- Timeline -->
            <div v-else class="relative space-y-8">
                <!-- Timeline Line -->
                <div
                    class="absolute top-2 bottom-0 left-[15px] w-px bg-neutral-200 dark:bg-neutral-800"
                />

                <div
                    v-for="(release, index) in releases"
                    :key="release.id"
                    class="relative flex gap-6"
                >
                    <!-- Timeline Marker -->
                    <div class="relative z-10 mt-1.5 shrink-0">
                        <div
                            class="flex h-8 w-8 items-center justify-center rounded-full ring-4 ring-white dark:ring-neutral-950"
                            :class="
                                index === 0
                                    ? 'bg-green-500'
                                    : 'bg-neutral-200 dark:bg-neutral-700'
                            "
                        >
                            <div
                                class="h-2 w-2 rounded-full"
                                :class="
                                    index === 0
                                        ? 'bg-white'
                                        : 'bg-neutral-400 dark:bg-neutral-500'
                                "
                            />
                        </div>
                    </div>

                    <!-- Release Content -->
                    <div class="min-w-0 flex-1 pb-2">
                        <div
                            class="overflow-hidden rounded-xl border border-sidebar-border/70 bg-white shadow-sm dark:border-sidebar-border dark:bg-neutral-900"
                        >
                            <!-- Release Header -->
                            <button
                                type="button"
                                class="flex w-full cursor-pointer flex-col gap-3 px-6 py-4 text-left transition-colors hover:bg-neutral-50 sm:flex-row sm:items-center sm:justify-between dark:hover:bg-neutral-800/50"
                                :class="
                                    expanded[release.id]
                                        ? 'border-b border-neutral-100 dark:border-neutral-800'
                                        : ''
                                "
                                @click="toggleExpanded(release.id)"
                            >
                                <div class="flex flex-wrap items-center gap-2">
                                    <ChevronDown
                                        class="h-4 w-4 shrink-0 text-neutral-400 transition-transform duration-200"
                                        :class="
                                            expanded[release.id]
                                                ? 'rotate-0'
                                                : '-rotate-90'
                                        "
                                    />
                                    <h2
                                        class="text-lg font-bold text-neutral-900 dark:text-neutral-100"
                                    >
                                        {{ release.name || release.tag_name }}
                                    </h2>
                                    <span
                                        class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                        :class="
                                            release.prerelease
                                                ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300'
                                                : 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300'
                                        "
                                    >
                                        {{ release.tag_name }}
                                    </span>
                                    <span
                                        v-if="release.prerelease"
                                        class="inline-flex items-center rounded-full bg-neutral-100 px-2 py-0.5 text-xs text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400"
                                    >
                                        Pre-release
                                    </span>
                                </div>
                                <div
                                    class="flex items-center gap-3 text-sm text-neutral-500 dark:text-neutral-400"
                                >
                                    <span
                                        :title="
                                            formatDate(release.published_at)
                                        "
                                    >
                                        {{ timeAgo(release.published_at) }}
                                    </span>
                                    <a
                                        :href="release.html_url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="inline-flex items-center gap-1 text-neutral-400 transition-colors hover:text-green-600 dark:hover:text-green-400"
                                        title="View on GitHub"
                                        @click.stop
                                    >
                                        <ExternalLink class="h-4 w-4" />
                                    </a>
                                </div>
                            </button>

                            <!-- Release Body -->
                            <Transition
                                enter-active-class="transition-all duration-200 ease-out"
                                leave-active-class="transition-all duration-150 ease-in"
                                enter-from-class="max-h-0 opacity-0"
                                enter-to-class="max-h-[2000px] opacity-100"
                                leave-from-class="max-h-[2000px] opacity-100"
                                leave-to-class="max-h-0 opacity-0"
                            >
                                <div
                                    v-show="expanded[release.id]"
                                    class="overflow-hidden"
                                >
                                    <div
                                        class="prose prose-sm max-w-none px-6 py-4 prose-neutral dark:prose-invert prose-headings:text-base prose-headings:font-semibold prose-p:my-2 prose-a:text-green-600 hover:prose-a:text-green-500 prose-ul:my-2 prose-li:my-0.5"
                                        v-html="release.body"
                                    />
                                </div>
                            </Transition>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
