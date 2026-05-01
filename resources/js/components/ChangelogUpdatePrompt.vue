<script setup lang="ts">
import ChangelogController from '@/actions/App/Http/Controllers/ChangelogController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { seen as markChangelogSeen } from '@/routes/changelog';
import { Link, router, usePage } from '@inertiajs/vue3';
import { ExternalLink, Rocket, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const page = usePage();
const open = ref(false);
const dismissing = ref(false);

const update = computed(() => page.props.changelogUpdate);
const latest = computed(() => update.value?.latest ?? null);
const showPrompt = computed(
    () =>
        page.component !== 'Changelog/Index' &&
        !!latest.value &&
        update.value?.unseen === true,
);

function formatDate(dateString: string): string {
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

function markSeen(): void {
    dismissing.value = true;

    router.post(
        markChangelogSeen().url,
        {},
        {
            preserveScroll: true,
            only: ['changelogUpdate'],
            onFinish: () => {
                dismissing.value = false;
                open.value = false;
            },
        },
    );
}
</script>

<template>
    <div
        v-if="showPrompt && latest"
        class="flex items-center justify-between gap-3 border-b border-teal-200 bg-teal-50 px-4 py-2 text-sm dark:border-teal-800 dark:bg-teal-950/30"
    >
        <div class="flex min-w-0 items-center gap-2">
            <Rocket
                class="h-4 w-4 shrink-0 text-teal-600 dark:text-teal-300"
                aria-hidden="true"
            />
            <span class="truncate text-teal-950 dark:text-teal-100">
                New update available: {{ latest.name || latest.tag_name }}
            </span>
        </div>

        <div class="flex shrink-0 items-center gap-2">
            <Button size="sm" variant="default" @click="open = true">
                View details
            </Button>
            <Button
                size="icon"
                variant="ghost"
                class="h-7 w-7"
                :disabled="dismissing"
                aria-label="Dismiss update prompt"
                @click="markSeen"
            >
                <X class="h-4 w-4" />
            </Button>
        </div>
    </div>

    <Dialog v-if="latest" v-model:open="open">
        <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <div class="flex items-start gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-teal-100 text-teal-700 dark:bg-teal-950 dark:text-teal-200"
                    >
                        <Rocket class="h-5 w-5" aria-hidden="true" />
                    </div>
                    <div>
                        <DialogTitle>
                            {{ latest.name || latest.tag_name }}
                        </DialogTitle>
                        <DialogDescription>
                            {{ latest.tag_name }} ·
                            {{ formatDate(latest.published_at) }}
                        </DialogDescription>
                    </div>
                </div>
            </DialogHeader>

            <div
                class="prose prose-sm max-w-none prose-neutral dark:prose-invert prose-headings:text-base prose-headings:font-semibold prose-p:my-2 prose-a:text-teal-600 hover:prose-a:text-teal-500 prose-ul:my-2 prose-li:my-0.5"
                v-html="latest.body"
            />

            <DialogFooter class="gap-2">
                <Link :href="ChangelogController().url">
                    <Button variant="outline">
                        <ExternalLink class="h-4 w-4" />
                        Open What's New
                    </Button>
                </Link>
                <Button :disabled="dismissing" @click="markSeen">
                    {{ dismissing ? 'Saving...' : 'Got it' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
