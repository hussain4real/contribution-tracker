<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { Head, usePoll } from '@inertiajs/vue3';
import SummaryCards from '@/components/dashboard/SummaryCards.vue';
import RecentPayments from '@/components/dashboard/RecentPayments.vue';
import MemberContributionStatus from '@/components/dashboard/MemberContributionStatus.vue';
import AggregateStats from '@/components/contributions/AggregateStats.vue';

// Auto-refresh dashboard every 30 seconds (T052)
usePoll(30000);

// Props types
interface Summary {
    total_members: number;
    total_expected: number;
    total_collected: number;
    total_outstanding: number;
    overdue_count: number;
    collection_rate: number;
}

interface MemberStatus {
    id: number;
    name: string;
    category: string;
    expected_amount: number;
    total_paid: number;
    current_month_status: string;
    current_month_balance: number;
    contribution_id: number;
}

interface RecentPayment {
    id: number;
    amount: number;
    paid_at: string;
    member_name: string;
    recorded_by: string | null;
    month: number;
    year: number;
}

interface FamilyAggregate {
    total_expected: number;
    total_collected: number;
    total_outstanding: number;
    collection_rate: number;
}

interface Personal {
    expected_amount: number;
    total_paid: number;
    current_month_balance: number;
    current_month_status: string;
}

// Admin/FS view
interface AdminProps {
    summary: Summary;
    member_statuses: MemberStatus[];
    recent_payments: RecentPayment[];
    can_record_payments: boolean;
}

// Member view
interface MemberProps {
    family_aggregate: FamilyAggregate;
    personal: Personal;
    can_record_payments: boolean;
}

type Props = Partial<AdminProps & MemberProps>;

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard().url,
    },
];

// Check if this is an admin/FS view
const isAdminView = !!props.summary;

// Format currency from kobo to naira
function formatCurrency(kobo: number): string {
    const naira = kobo / 100;
    return new Intl.NumberFormat('en-NG', {
        style: 'currency',
        currency: 'NGN',
    }).format(naira);
}
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
            <!-- Admin/Financial Secretary View -->
            <template v-if="isAdminView">
                <SummaryCards
                    v-if="summary"
                    :total-members="summary.total_members"
                    :total-expected="summary.total_expected"
                    :total-collected="summary.total_collected"
                    :total-outstanding="summary.total_outstanding"
                    :overdue-count="summary.overdue_count"
                    :collection-rate="summary.collection_rate"
                    :can-record-payments="can_record_payments"
                />

                <div class="grid gap-6 lg:grid-cols-2">
                    <!-- Member Statuses Table -->
                    <div
                        v-if="member_statuses"
                        class="rounded-xl border border-sidebar-border/70 bg-white p-6 dark:border-sidebar-border dark:bg-neutral-900"
                    >
                        <h2 class="mb-4 text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                            Member Contribution Status
                        </h2>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead>
                                    <tr class="border-b border-neutral-200 dark:border-neutral-700">
                                        <th class="px-4 py-3 font-medium text-neutral-600 dark:text-neutral-400">
                                            Member
                                        </th>
                                        <th class="px-4 py-3 font-medium text-neutral-600 dark:text-neutral-400">
                                            Category
                                        </th>
                                        <th class="px-4 py-3 font-medium text-neutral-600 dark:text-neutral-400">
                                            Status
                                        </th>
                                        <th class="px-4 py-3 text-right font-medium text-neutral-600 dark:text-neutral-400">
                                            Balance
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <MemberContributionStatus
                                        v-for="member in member_statuses"
                                        :key="member.id"
                                        :member="member"
                                        :can-record-payments="can_record_payments ?? false"
                                    />
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Recent Payments -->
                    <RecentPayments
                        v-if="recent_payments"
                        :payments="recent_payments"
                    />
                </div>
            </template>

            <!-- Member View -->
            <template v-else>
                <!-- Personal Status -->
                <div
                    v-if="personal"
                    class="rounded-xl border border-sidebar-border/70 bg-white p-6 dark:border-sidebar-border dark:bg-neutral-900"
                >
                    <h2 class="mb-4 text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                        Your Contribution Status
                    </h2>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="rounded-lg bg-neutral-50 p-4 dark:bg-neutral-800">
                            <p class="text-sm text-neutral-600 dark:text-neutral-400">Expected</p>
                            <p class="mt-1 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">
                                {{ formatCurrency(personal.expected_amount) }}
                            </p>
                        </div>
                        <div class="rounded-lg bg-neutral-50 p-4 dark:bg-neutral-800">
                            <p class="text-sm text-neutral-600 dark:text-neutral-400">Paid</p>
                            <p class="mt-1 text-2xl font-semibold text-green-600 dark:text-green-400">
                                {{ formatCurrency(personal.total_paid) }}
                            </p>
                        </div>
                        <div class="rounded-lg bg-neutral-50 p-4 dark:bg-neutral-800">
                            <p class="text-sm text-neutral-600 dark:text-neutral-400">Balance</p>
                            <p class="mt-1 text-2xl font-semibold" :class="personal.current_month_balance > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-green-600 dark:text-green-400'">
                                {{ formatCurrency(personal.current_month_balance) }}
                            </p>
                        </div>
                        <div class="rounded-lg bg-neutral-50 p-4 dark:bg-neutral-800">
                            <p class="text-sm text-neutral-600 dark:text-neutral-400">Status</p>
                            <p class="mt-1 text-lg font-semibold capitalize" :class="{
                                'text-green-600 dark:text-green-400': personal.current_month_status === 'paid',
                                'text-amber-600 dark:text-amber-400': personal.current_month_status === 'partial',
                                'text-neutral-600 dark:text-neutral-400': personal.current_month_status === 'unpaid',
                                'text-red-600 dark:text-red-400': personal.current_month_status === 'overdue',
                            }">
                                {{ personal.current_month_status }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Family Aggregate Stats -->
                <AggregateStats
                    v-if="family_aggregate"
                    :total-expected="family_aggregate.total_expected"
                    :total-collected="family_aggregate.total_collected"
                    :total-outstanding="family_aggregate.total_outstanding"
                    :collection-rate="family_aggregate.collection_rate"
                />
            </template>
        </div>
    </AppLayout>
</template>
