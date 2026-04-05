<script setup lang="ts">
import { generate } from '@/actions/App/Http/Controllers/ContributionController';
import AccountDetails from '@/components/AccountDetails.vue';
import AggregateStats from '@/components/contributions/AggregateStats.vue';
import MemberContributionStatus from '@/components/dashboard/MemberContributionStatus.vue';
import type { OverdueMember } from '@/components/dashboard/OverdueMembersModal.vue';
import OverdueMembersModal from '@/components/dashboard/OverdueMembersModal.vue';
import RecentPayments from '@/components/dashboard/RecentPayments.vue';
import SummaryCards from '@/components/dashboard/SummaryCards.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePoll } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

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
    accrued_balance: number;
    contribution_id: number;
}

interface RecentPayment {
    id: number;
    amount: number;
    paid_at: string;
    member_name: string;
    category: string;
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

type Props = Partial<AdminProps & MemberProps> & {
    fund_balance?: number;
    can_generate_contributions?: boolean;
    has_pending_contributions?: boolean;
    overdue_members?: OverdueMember[];
};

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard().url,
    },
];

// Check if this is an admin/FS view
const isAdminView = computed(() => !!props.summary);

// Generate contributions
const generating = ref(false);
const showOverdueModal = ref(false);

function generateContributions() {
    generating.value = true;
    router.post(
        generate().url,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                generating.value = false;
            },
        },
    );
}

// Format currency in Naira
function formatCurrency(amount: number): string {
    return new Intl.NumberFormat('en-NG', {
        style: 'currency',
        currency: 'NGN',
    }).format(amount);
}
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
            <!-- Fund Balance (visible to all users) -->
            <div
                class="rounded-xl border border-sidebar-border/70 bg-white p-6 dark:border-sidebar-border dark:bg-neutral-900"
            >
                <p class="text-sm text-neutral-600 dark:text-neutral-400">
                    Family Fund Balance
                </p>
                <p
                    class="mt-1 text-3xl font-bold"
                    :class="
                        (fund_balance ?? 0) >= 0
                            ? 'text-green-600 dark:text-green-400'
                            : 'text-red-600 dark:text-red-400'
                    "
                >
                    {{ formatCurrency(fund_balance ?? 0) }}
                </p>
            </div>

            <!-- Admin/Financial Secretary View -->
            <template v-if="isAdminView">
                <!-- Generate Contributions Banner -->
                <div
                    v-if="
                        can_generate_contributions && has_pending_contributions
                    "
                    class="flex flex-col items-start justify-between gap-4 rounded-xl border border-amber-200 bg-amber-50 p-6 sm:flex-row sm:items-center dark:border-amber-800 dark:bg-amber-900/20"
                >
                    <div>
                        <h3
                            class="font-semibold text-amber-800 dark:text-amber-200"
                        >
                            Members without contributions this month
                        </h3>
                        <p
                            class="mt-1 text-sm text-amber-700 dark:text-amber-300"
                        >
                            Some active members don't have contribution records
                            yet. Generate them to start tracking payments.
                        </p>
                    </div>
                    <button
                        :disabled="generating"
                        class="shrink-0 rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-amber-700 disabled:opacity-50 dark:bg-amber-500 dark:hover:bg-amber-600"
                        @click="generateContributions"
                    >
                        {{
                            generating
                                ? 'Generating...'
                                : 'Generate Contributions'
                        }}
                    </button>
                </div>

                <SummaryCards
                    v-if="summary"
                    :total-members="summary.total_members"
                    :total-expected="summary.total_expected"
                    :total-collected="summary.total_collected"
                    :total-outstanding="summary.total_outstanding"
                    :overdue-count="summary.overdue_count"
                    :collection-rate="summary.collection_rate"
                    :can-record-payments="can_record_payments"
                    @overdue-click="showOverdueModal = true"
                />

                <div class="grid gap-6 lg:grid-cols-2">
                    <!-- Member Statuses Table -->
                    <div
                        v-if="member_statuses"
                        class="rounded-xl border border-sidebar-border/70 bg-white p-4 sm:p-6 dark:border-sidebar-border dark:bg-neutral-900"
                    >
                        <h2
                            class="mb-4 text-lg font-semibold text-neutral-900 dark:text-neutral-100"
                        >
                            Member Contribution Status
                        </h2>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead>
                                    <tr
                                        class="border-b border-neutral-200 dark:border-neutral-700"
                                    >
                                        <th
                                            class="px-3 py-3 font-medium text-neutral-600 sm:px-4 dark:text-neutral-400"
                                        >
                                            Member
                                        </th>
                                        <th
                                            class="hidden px-3 py-3 font-medium text-neutral-600 sm:table-cell sm:px-4 dark:text-neutral-400"
                                        >
                                            Category
                                        </th>
                                        <th
                                            class="px-3 py-3 font-medium text-neutral-600 sm:px-4 dark:text-neutral-400"
                                        >
                                            Status
                                        </th>
                                        <th
                                            class="px-3 py-3 text-right font-medium text-neutral-600 sm:px-4 dark:text-neutral-400"
                                        >
                                            Balance
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <MemberContributionStatus
                                        v-for="member in member_statuses"
                                        :key="member.id"
                                        :member="member"
                                        :can-record-payments="
                                            can_record_payments ?? false
                                        "
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
                <AccountDetails />

                <!-- Personal Status -->
                <div
                    v-if="personal"
                    class="rounded-xl border border-sidebar-border/70 bg-white p-6 dark:border-sidebar-border dark:bg-neutral-900"
                >
                    <h2
                        class="mb-4 text-lg font-semibold text-neutral-900 dark:text-neutral-100"
                    >
                        Your Contribution Status
                    </h2>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div
                            class="rounded-lg bg-neutral-50 p-4 dark:bg-neutral-800"
                        >
                            <p
                                class="text-sm text-neutral-600 dark:text-neutral-400"
                            >
                                Expected
                            </p>
                            <p
                                class="mt-1 text-2xl font-semibold text-neutral-900 dark:text-neutral-100"
                            >
                                {{ formatCurrency(personal.expected_amount) }}
                            </p>
                        </div>
                        <div
                            class="rounded-lg bg-neutral-50 p-4 dark:bg-neutral-800"
                        >
                            <p
                                class="text-sm text-neutral-600 dark:text-neutral-400"
                            >
                                Paid
                            </p>
                            <p
                                class="mt-1 text-2xl font-semibold text-green-600 dark:text-green-400"
                            >
                                {{ formatCurrency(personal.total_paid) }}
                            </p>
                        </div>
                        <div
                            class="rounded-lg bg-neutral-50 p-4 dark:bg-neutral-800"
                        >
                            <p
                                class="text-sm text-neutral-600 dark:text-neutral-400"
                            >
                                Balance
                            </p>
                            <p
                                class="mt-1 text-2xl font-semibold"
                                :class="
                                    personal.current_month_balance > 0
                                        ? 'text-amber-600 dark:text-amber-400'
                                        : 'text-green-600 dark:text-green-400'
                                "
                            >
                                {{
                                    formatCurrency(
                                        personal.current_month_balance,
                                    )
                                }}
                            </p>
                        </div>
                        <div
                            class="rounded-lg bg-neutral-50 p-4 dark:bg-neutral-800"
                        >
                            <p
                                class="text-sm text-neutral-600 dark:text-neutral-400"
                            >
                                Status
                            </p>
                            <p
                                class="mt-1 text-lg font-semibold capitalize"
                                :class="{
                                    'text-green-600 dark:text-green-400':
                                        personal.current_month_status ===
                                        'paid',
                                    'text-amber-600 dark:text-amber-400':
                                        personal.current_month_status ===
                                        'partial',
                                    'text-neutral-600 dark:text-neutral-400':
                                        personal.current_month_status ===
                                        'unpaid',
                                    'text-red-600 dark:text-red-400':
                                        personal.current_month_status ===
                                        'overdue',
                                }"
                            >
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

        <OverdueMembersModal
            v-if="overdue_members"
            v-model:is-open="showOverdueModal"
            :members="overdue_members"
        />
    </AppLayout>
</template>
