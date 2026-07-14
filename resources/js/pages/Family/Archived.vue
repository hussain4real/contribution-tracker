<script setup lang="ts">
import {
    exportMethod,
    restore,
    show,
} from '@/actions/App/Http/Controllers/FamilyArchiveController';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { ArchiveRestore, Download, ShieldAlert } from '@lucide/vue';

interface Props {
    family: {
        name: string;
        archived_at: string | null;
        purge_after: string | null;
        archive_reason: string | null;
        legal_hold: boolean;
    };
}

defineProps<Props>();
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Archived family', href: show().url },
];

function restoreFamily(): void {
    router.post(restore().url);
}
</script>

<template>
    <Head title="Family archived" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-2xl flex-1 items-center p-6">
            <section
                class="w-full rounded-2xl border bg-card p-6 shadow-sm sm:p-8"
            >
                <div class="flex items-start gap-4">
                    <div
                        class="rounded-xl bg-amber-100 p-3 text-amber-800 dark:bg-amber-950 dark:text-amber-200"
                    >
                        <ShieldAlert class="h-6 w-6" />
                    </div>
                    <div class="grid gap-2">
                        <h1 class="text-2xl font-semibold">
                            {{ family.name }} is archived
                        </h1>
                        <p class="text-muted-foreground">
                            All normal family operations are disabled. You can
                            restore the workspace or export its records during
                            the 30-day retention window.
                        </p>
                    </div>
                </div>
                <dl
                    class="mt-6 grid gap-4 rounded-xl bg-muted/50 p-4 text-sm sm:grid-cols-2"
                >
                    <div>
                        <dt class="text-muted-foreground">Archived</dt>
                        <dd class="font-medium">
                            {{
                                family.archived_at
                                    ? new Date(
                                          family.archived_at,
                                      ).toLocaleString()
                                    : '—'
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Scheduled purge</dt>
                        <dd class="font-medium">
                            {{
                                family.purge_after
                                    ? new Date(
                                          family.purge_after,
                                      ).toLocaleString()
                                    : '—'
                            }}
                        </dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-muted-foreground">Reason</dt>
                        <dd class="font-medium">
                            {{ family.archive_reason ?? 'No reason recorded' }}
                        </dd>
                    </div>
                    <div v-if="family.legal_hold" class="sm:col-span-2">
                        <dt class="text-muted-foreground">Legal hold</dt>
                        <dd
                            class="font-medium text-amber-700 dark:text-amber-300"
                        >
                            Purge is paused by the platform.
                        </dd>
                    </div>
                </dl>
                <div class="mt-6 flex flex-wrap gap-3">
                    <Button @click="restoreFamily"
                        ><ArchiveRestore class="h-4 w-4" /> Restore
                        family</Button
                    ><Button variant="outline" as-child
                        ><a :href="exportMethod().url"
                            ><Download class="h-4 w-4" /> Export archive</a
                        ></Button
                    >
                </div>
            </section>
        </div>
    </AppLayout>
</template>
