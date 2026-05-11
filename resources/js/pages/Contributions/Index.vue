<script setup lang="ts">
import {
    index,
    my,
} from '@/actions/App/Http/Controllers/ContributionController';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ClipboardList } from 'lucide-vue-next';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Contributions',
        href: index().url,
    },
];
</script>

<template>
    <Head title="Contributions" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
            <div class="flex items-center gap-3">
                <ClipboardList class="h-6 w-6 text-neutral-500" />
                <h1
                    class="text-xl font-semibold text-neutral-900 sm:text-2xl dark:text-neutral-100"
                >
                    Contributions
                </h1>
            </div>

            <section class="max-w-2xl">
                <h2 class="text-lg font-semibold text-foreground">
                    Contribution records
                </h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    Review your family contribution history, current balances,
                    and the payment status for each generated contribution.
                </p>

                <div class="mt-6 flex flex-wrap gap-3">
                    <Button as-child>
                        <Link :href="my().url">My Contributions</Link>
                    </Button>
                    <Button variant="outline" as-child>
                        <Link :href="dashboard().url">Dashboard</Link>
                    </Button>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
