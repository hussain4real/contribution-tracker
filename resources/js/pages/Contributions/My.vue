<script setup lang="ts">
import AccountDetails from '@/components/AccountDetails.vue';
import AggregateStats from '@/components/contributions/AggregateStats.vue';
import ContributionCard from '@/components/contributions/ContributionCard.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { AlertCircle, CheckCircle2, TrendingUp, Wallet } from 'lucide-vue-next';

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

interface PersonalStats {
    total_expected: number;
    total_paid: number;
    total_outstanding: number;
    payment_rate: number;
    contribution_count: number;
}

interface FamilyAggregate {
    total_expected: number;
    total_collected: number;
    total_outstanding: number;
    collection_rate: number;
    period_label: string;
}

interface Props {
    contributions?: Contribution[];
    personal_stats?: PersonalStats;
    family_aggregate?: FamilyAggregate;
}

withDefaults(defineProps<Props>(), {
    contributions: () => [],
    personal_stats: () => ({
        total_expected: 0,
        total_paid: 0,
        total_outstanding: 0,
        payment_rate: 0,
        contribution_count: 0,
    }),
    family_aggregate: () => ({
        total_expected: 0,
        total_collected: 0,
        total_outstanding: 0,
        collection_rate: 0,
        period_label: '',
    }),
});

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

function formatCurrency(amount: number): string {
    return new Intl.NumberFormat('en-NG', {
        style: 'currency',
        currency: 'NGN',
    }).format(amount);
}
</script>

<template>
    <Head title="My Contribution History" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
            <!-- Page Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1
                        class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100"
                    >
                        My Contribution History
                    </h1>
                    <p
                        class="mt-1 text-sm text-neutral-600 dark:text-neutral-400"
                    >
                        View your payment history and contribution status
                    </p>
                </div>
            </div>

            <AccountDetails />

            <!-- Personal Stats (Primary) -->
            <div
                class="rounded-xl border border-sidebar-border/70 bg-white p-6 dark:border-sidebar-border dark:bg-neutral-900"
            >
                <div class="mb-4 flex items-center justify-between">
                    <h2
                        class="text-lg font-semibold text-neutral-900 dark:text-neutral-100"
                    >
                        My Contribution Summary
                    </h2>
                    <span class="text-sm text-neutral-500 dark:text-neutral-400"
                        >{{ personal_stats.contribution_count }} months</span
                    >
                </div>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-lg bg-blue-50 p-4 dark:bg-blue-900/20">
                        <div class="flex items-center gap-2">
                            <Wallet
                                class="h-4 w-4 text-blue-600 dark:text-blue-400"
                            />
                            <p
                                class="text-sm font-medium text-blue-700 dark:text-blue-300"
                            >
                                Total Expected
                            </p>
                        </div>
                        <p
                            class="mt-2 text-2xl font-bold text-blue-700 dark:text-blue-300"
                        >
                            {{ formatCurrency(personal_stats.total_expected) }}
                        </p>
                    </div>
                    <div
                        class="rounded-lg bg-emerald-50 p-4 dark:bg-emerald-900/20"
                    >
                        <div class="flex items-center gap-2">
                            <TrendingUp
                                class="h-4 w-4 text-emerald-600 dark:text-emerald-400"
                            />
                            <p
                                class="text-sm font-medium text-emerald-700 dark:text-emerald-300"
                            >
                                Total Paid
                            </p>
                        </div>
                        <p
                            class="mt-2 text-2xl font-bold text-emerald-700 dark:text-emerald-300"
                        >
                            {{ formatCurrency(personal_stats.total_paid) }}
                        </p>
                    </div>
                    <div
                        class="rounded-lg p-4"
                        :class="
                            personal_stats.total_outstanding > 0
                                ? 'bg-amber-50 dark:bg-amber-900/20'
                                : 'bg-neutral-50 dark:bg-neutral-800'
                        "
                    >
                        <div class="flex items-center gap-2">
                            <AlertCircle
                                class="h-4 w-4"
                                :class="
                                    personal_stats.total_outstanding > 0
                                        ? 'text-amber-600 dark:text-amber-400'
                                        : 'text-neutral-500'
                                "
                            />
                            <p
                                class="text-sm font-medium"
                                :class="
                                    personal_stats.total_outstanding > 0
                                        ? 'text-amber-700 dark:text-amber-300'
                                        : 'text-neutral-600 dark:text-neutral-400'
                                "
                            >
                                Outstanding
                            </p>
                        </div>
                        <p
                            class="mt-2 text-2xl font-bold"
                            :class="
                                personal_stats.total_outstanding > 0
                                    ? 'text-amber-700 dark:text-amber-300'
                                    : 'text-neutral-600 dark:text-neutral-400'
                            "
                        >
                            {{
                                formatCurrency(personal_stats.total_outstanding)
                            }}
                        </p>
                    </div>
                    <div
                        class="rounded-lg p-4"
                        :class="{
                            'bg-emerald-50 dark:bg-emerald-900/20':
                                personal_stats.payment_rate >= 80,
                            'bg-amber-50 dark:bg-amber-900/20':
                                personal_stats.payment_rate >= 50 &&
                                personal_stats.payment_rate < 80,
                            'bg-red-50 dark:bg-red-900/20':
                                personal_stats.payment_rate < 50,
                        }"
                    >
                        <div class="flex items-center gap-2">
                            <CheckCircle2
                                class="h-4 w-4"
                                :class="{
                                    'text-emerald-600 dark:text-emerald-400':
                                        personal_stats.payment_rate >= 80,
                                    'text-amber-600 dark:text-amber-400':
                                        personal_stats.payment_rate >= 50 &&
                                        personal_stats.payment_rate < 80,
                                    'text-red-600 dark:text-red-400':
                                        personal_stats.payment_rate < 50,
                                }"
                            />
                            <p
                                class="text-sm font-medium"
                                :class="{
                                    'text-emerald-700 dark:text-emerald-300':
                                        personal_stats.payment_rate >= 80,
                                    'text-amber-700 dark:text-amber-300':
                                        personal_stats.payment_rate >= 50 &&
                                        personal_stats.payment_rate < 80,
                                    'text-red-700 dark:text-red-300':
                                        personal_stats.payment_rate < 50,
                                }"
                            >
                                Payment Rate
                            </p>
                        </div>
                        <p
                            class="mt-2 text-2xl font-bold"
                            :class="{
                                'text-emerald-700 dark:text-emerald-300':
                                    personal_stats.payment_rate >= 80,
                                'text-amber-700 dark:text-amber-300':
                                    personal_stats.payment_rate >= 50 &&
                                    personal_stats.payment_rate < 80,
                                'text-red-700 dark:text-red-300':
                                    personal_stats.payment_rate < 50,
                            }"
                        >
                            {{ personal_stats.payment_rate }}%
                        </p>
                    </div>
                </div>

                <!-- Progress Bar -->
                <div class="mt-4">
                    <div
                        class="h-2 overflow-hidden rounded-full bg-neutral-200 dark:bg-neutral-700"
                    >
                        <div
                            class="h-full rounded-full transition-all duration-500"
                            :class="{
                                'bg-emerald-500':
                                    personal_stats.payment_rate >= 80,
                                'bg-amber-500':
                                    personal_stats.payment_rate >= 50 &&
                                    personal_stats.payment_rate < 80,
                                'bg-red-500': personal_stats.payment_rate < 50,
                            }"
                            :style="{
                                width: `${personal_stats.payment_rate}%`,
                            }"
                        />
                    </div>
                </div>
            </div>

            <!-- Family Aggregate Stats (FR-015) - Secondary -->
            <AggregateStats
                :total-expected="family_aggregate.total_expected"
                :total-collected="family_aggregate.total_collected"
                :total-outstanding="family_aggregate.total_outstanding"
                :collection-rate="family_aggregate.collection_rate"
                :period-label="family_aggregate.period_label"
            />

            <!-- Contributions List -->
            <div
                class="rounded-xl border border-sidebar-border/70 bg-white dark:border-sidebar-border dark:bg-neutral-900"
            >
                <div
                    class="border-b border-sidebar-border/70 px-6 py-4 dark:border-sidebar-border"
                >
                    <h2
                        class="text-lg font-medium text-neutral-900 dark:text-neutral-100"
                    >
                        Contribution History
                    </h2>
                </div>

                <div v-if="contributions.length === 0" class="p-6 text-center">
                    <p class="text-neutral-600 dark:text-neutral-400">
                        No contributions found
                    </p>
                </div>

                <div
                    v-else
                    class="divide-y divide-sidebar-border/70 dark:divide-sidebar-border"
                >
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
