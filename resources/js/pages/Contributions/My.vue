<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import { type BreadcrumbItem } from '@/types';
import { dashboard } from '@/routes';
import AggregateStats from '@/components/contributions/AggregateStats.vue';
import ContributionCard from '@/components/contributions/ContributionCard.vue';

interface Payment {
    id: number;
    amount: number;
    paid_at: string;
    notes: string | null;
    recorder: {
        id: number;
        name: string;
    };
    created_at: string;
}

interface Contribution {
    id: number;
    year: number;
    month: number;
    expected_amount: number;
    total_paid: number;
    balance: number;
    status: 'paid' | 'partial' | 'unpaid' | 'overdue';
    period_label: string;
    due_date: string;
    payments: Payment[];
}

interface FamilyAggregate {
    total_expected: number;
    total_collected: number;
    total_outstanding: number;
    collection_rate: number;
    period_label: string;
}

interface Props {
    contributions: Contribution[];
    family_aggregate: FamilyAggregate;
}

defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard().url,
    },
    {
        title: 'My Contributions',
        href: '#',
    },
];
</script>

<template>
    <Head title="My Contribution History" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
            <!-- Page Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">
                        My Contribution History
                    </h1>
                    <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">
                        View your payment history and contribution status
                    </p>
                </div>
            </div>

            <!-- Family Aggregate Stats (FR-015) -->
            <AggregateStats
                :total-expected="family_aggregate.total_expected"
                :total-collected="family_aggregate.total_collected"
                :total-outstanding="family_aggregate.total_outstanding"
                :collection-rate="family_aggregate.collection_rate"
                :period-label="family_aggregate.period_label"
            />

            <!-- Contributions List -->
            <div class="rounded-xl border border-sidebar-border/70 bg-white dark:border-sidebar-border dark:bg-neutral-900">
                <div class="border-b border-sidebar-border/70 px-6 py-4 dark:border-sidebar-border">
                    <h2 class="text-lg font-medium text-neutral-900 dark:text-neutral-100">
                        Contribution History
                    </h2>
                </div>

                <div v-if="contributions.length === 0" class="p-6 text-center">
                    <p class="text-neutral-600 dark:text-neutral-400">
                        No contributions found
                    </p>
                </div>

                <div v-else class="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                    <ContributionCard
                        v-for="contribution in contributions"
                        :key="contribution.id"
                        :contribution="contribution"
                    />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
