<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { type BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import { index, monthly } from '@/actions/App/Http/Controllers/ReportController';
import {
    FileBarChart2,
    ChevronLeft,
    ChevronRight,
    Users,
    TrendingUp,
    DollarSign,
    AlertCircle
} from 'lucide-vue-next';
import { ref, computed, watch } from 'vue';

interface MemberData {
    id: number;
    name: string;
    category: string;
    category_label: string;
    expected_amount: number;
    paid_amount: number;
    balance: number;
    status: string;
    status_label: string;
}

interface StatusCounts {
    paid: number;
    partial: number;
    unpaid: number;
    overdue: number;
}

interface Summary {
    total_expected: number;
    total_collected: number;
    total_outstanding: number;
    collection_rate: number;
    member_count: number;
    status_counts: StatusCounts;
}

interface CategoryData {
    label: string;
    expected: number;
    collected: number;
    outstanding: number;
    count: number;
}

interface PaginatedMembers {
    data: MemberData[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: Array<{
        url: string | null;
        label: string;
        active: boolean;
    }>;
}

interface Props {
    year: number;
    month: number;
    month_name: string;
    summary: Summary;
    by_category: Record<string, CategoryData>;
    members: PaginatedMembers;
}

const props = defineProps<Props>();

const selectedYear = ref(props.year);
const selectedMonth = ref(props.month);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    {
        title: 'Reports',
        href: index().url,
    },
    {
        title: `${props.month_name} ${props.year}`,
        href: monthly({ year: props.year, month: props.month }).url,
    },
]);

const monthNames = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December'
];

// Format currency from kobo to naira
function formatCurrency(kobo: number): string {
    const naira = kobo / 100;
    return new Intl.NumberFormat('en-NG', {
        style: 'currency',
        currency: 'NGN',
    }).format(naira);
}

// Navigate to a different month
function navigateToMonth(year: number, month: number) {
    router.get(monthly({ year, month }).url);
}

// Navigate to previous month
function previousMonth() {
    let newMonth = props.month - 1;
    let newYear = props.year;
    if (newMonth < 1) {
        newMonth = 12;
        newYear--;
    }
    navigateToMonth(newYear, newMonth);
}

// Navigate to next month
function nextMonth() {
    let newMonth = props.month + 1;
    let newYear = props.year;
    if (newMonth > 12) {
        newMonth = 1;
        newYear++;
    }
    navigateToMonth(newYear, newMonth);
}

// Watch for year/month selector changes
watch([selectedYear, selectedMonth], ([newYear, newMonth]) => {
    if (newYear !== props.year || newMonth !== props.month) {
        navigateToMonth(newYear, newMonth);
    }
});

// Get status badge color
function getStatusColor(status: string): string {
    switch (status) {
        case 'paid':
            return 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
        case 'partial':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200';
        case 'overdue':
            return 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200';
        default:
            return 'bg-neutral-100 text-neutral-800 dark:bg-neutral-700 dark:text-neutral-200';
    }
}
</script>

<template>
    <Head :title="`Monthly Report - ${month_name} ${year}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
            <!-- Header with Navigation -->
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-3">
                    <FileBarChart2 class="h-6 w-6 text-neutral-500" />
                    <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">
                        {{ month_name }} {{ year }} Report
                    </h1>
                </div>
                <div class="flex items-center gap-2">
                    <Button variant="outline" size="icon" @click="previousMonth">
                        <ChevronLeft class="h-4 w-4" />
                    </Button>
                    <select
                        v-model="selectedMonth"
                        class="rounded-md border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-600 dark:bg-neutral-800"
                    >
                        <option v-for="(name, idx) in monthNames" :key="idx" :value="idx + 1">
                            {{ name }}
                        </option>
                    </select>
                    <select
                        v-model="selectedYear"
                        class="rounded-md border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-600 dark:bg-neutral-800"
                    >
                        <option v-for="y in [year - 2, year - 1, year, year + 1]" :key="y" :value="y">
                            {{ y }}
                        </option>
                    </select>
                    <Button variant="outline" size="icon" @click="nextMonth">
                        <ChevronRight class="h-4 w-4" />
                    </Button>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-sidebar-border/70 bg-white p-4 dark:border-sidebar-border dark:bg-neutral-900">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-100 dark:bg-blue-900">
                            <DollarSign class="h-5 w-5 text-blue-600 dark:text-blue-400" />
                        </div>
                        <div>
                            <p class="text-sm text-neutral-600 dark:text-neutral-400">Expected</p>
                            <p class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                                {{ formatCurrency(summary.total_expected) }}
                            </p>
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border border-sidebar-border/70 bg-white p-4 dark:border-sidebar-border dark:bg-neutral-900">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-green-100 dark:bg-green-900">
                            <TrendingUp class="h-5 w-5 text-green-600 dark:text-green-400" />
                        </div>
                        <div>
                            <p class="text-sm text-neutral-600 dark:text-neutral-400">Collected</p>
                            <p class="text-xl font-semibold text-green-600 dark:text-green-400">
                                {{ formatCurrency(summary.total_collected) }}
                            </p>
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border border-sidebar-border/70 bg-white p-4 dark:border-sidebar-border dark:bg-neutral-900">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-amber-100 dark:bg-amber-900">
                            <AlertCircle class="h-5 w-5 text-amber-600 dark:text-amber-400" />
                        </div>
                        <div>
                            <p class="text-sm text-neutral-600 dark:text-neutral-400">Outstanding</p>
                            <p class="text-xl font-semibold text-amber-600 dark:text-amber-400">
                                {{ formatCurrency(summary.total_outstanding) }}
                            </p>
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border border-sidebar-border/70 bg-white p-4 dark:border-sidebar-border dark:bg-neutral-900">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-purple-100 dark:bg-purple-900">
                            <Users class="h-5 w-5 text-purple-600 dark:text-purple-400" />
                        </div>
                        <div>
                            <p class="text-sm text-neutral-600 dark:text-neutral-400">Collection Rate</p>
                            <p class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                                {{ summary.collection_rate }}%
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Status Counts & Category Breakdown -->
            <div class="grid gap-6 lg:grid-cols-2">
                <!-- Status Counts -->
                <div class="rounded-xl border border-sidebar-border/70 bg-white p-6 dark:border-sidebar-border dark:bg-neutral-900">
                    <h2 class="mb-4 text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                        Payment Status Breakdown
                    </h2>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="rounded-lg bg-green-50 p-4 dark:bg-green-900/20">
                            <p class="text-sm text-green-600 dark:text-green-400">Paid</p>
                            <p class="text-2xl font-bold text-green-700 dark:text-green-300">
                                {{ summary.status_counts.paid }}
                            </p>
                        </div>
                        <div class="rounded-lg bg-amber-50 p-4 dark:bg-amber-900/20">
                            <p class="text-sm text-amber-600 dark:text-amber-400">Partial</p>
                            <p class="text-2xl font-bold text-amber-700 dark:text-amber-300">
                                {{ summary.status_counts.partial }}
                            </p>
                        </div>
                        <div class="rounded-lg bg-neutral-50 p-4 dark:bg-neutral-800">
                            <p class="text-sm text-neutral-600 dark:text-neutral-400">Unpaid</p>
                            <p class="text-2xl font-bold text-neutral-700 dark:text-neutral-300">
                                {{ summary.status_counts.unpaid }}
                            </p>
                        </div>
                        <div class="rounded-lg bg-red-50 p-4 dark:bg-red-900/20">
                            <p class="text-sm text-red-600 dark:text-red-400">Overdue</p>
                            <p class="text-2xl font-bold text-red-700 dark:text-red-300">
                                {{ summary.status_counts.overdue }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Category Breakdown -->
                <div class="rounded-xl border border-sidebar-border/70 bg-white p-6 dark:border-sidebar-border dark:bg-neutral-900">
                    <h2 class="mb-4 text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                        Breakdown by Category
                    </h2>
                    <div class="space-y-4">
                        <div
                            v-for="(category, key) in by_category"
                            :key="key"
                            class="rounded-lg border border-neutral-200 p-4 dark:border-neutral-700"
                        >
                            <div class="flex items-center justify-between mb-2">
                                <span class="font-medium text-neutral-900 dark:text-neutral-100">
                                    {{ category.label }}
                                </span>
                                <span class="text-sm text-neutral-500">
                                    {{ category.count }} members
                                </span>
                            </div>
                            <div class="grid grid-cols-3 gap-2 text-sm">
                                <div>
                                    <p class="text-neutral-500">Expected</p>
                                    <p class="font-medium text-neutral-900 dark:text-neutral-100">
                                        {{ formatCurrency(category.expected) }}
                                    </p>
                                </div>
                                <div>
                                    <p class="text-neutral-500">Collected</p>
                                    <p class="font-medium text-green-600 dark:text-green-400">
                                        {{ formatCurrency(category.collected) }}
                                    </p>
                                </div>
                                <div>
                                    <p class="text-neutral-500">Outstanding</p>
                                    <p class="font-medium text-amber-600 dark:text-amber-400">
                                        {{ formatCurrency(category.outstanding) }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Members Table -->
            <div class="rounded-xl border border-sidebar-border/70 bg-white dark:border-sidebar-border dark:bg-neutral-900">
                <div class="border-b border-neutral-200 p-4 dark:border-neutral-700">
                    <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                        Member Details
                    </h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-neutral-200 dark:border-neutral-700">
                                <th class="px-6 py-4 font-medium text-neutral-600 dark:text-neutral-400">
                                    Name
                                </th>
                                <th class="px-6 py-4 font-medium text-neutral-600 dark:text-neutral-400">
                                    Category
                                </th>
                                <th class="px-6 py-4 text-right font-medium text-neutral-600 dark:text-neutral-400">
                                    Expected
                                </th>
                                <th class="px-6 py-4 text-right font-medium text-neutral-600 dark:text-neutral-400">
                                    Paid
                                </th>
                                <th class="px-6 py-4 text-right font-medium text-neutral-600 dark:text-neutral-400">
                                    Balance
                                </th>
                                <th class="px-6 py-4 font-medium text-neutral-600 dark:text-neutral-400">
                                    Status
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="member in members.data"
                                :key="member.id"
                                class="border-b border-neutral-100 dark:border-neutral-800"
                            >
                                <td class="px-6 py-4 font-medium text-neutral-900 dark:text-neutral-100">
                                    {{ member.name }}
                                </td>
                                <td class="px-6 py-4 text-neutral-600 dark:text-neutral-400">
                                    {{ member.category_label }}
                                </td>
                                <td class="px-6 py-4 text-right text-neutral-600 dark:text-neutral-400">
                                    {{ formatCurrency(member.expected_amount) }}
                                </td>
                                <td class="px-6 py-4 text-right text-green-600 dark:text-green-400">
                                    {{ formatCurrency(member.paid_amount) }}
                                </td>
                                <td class="px-6 py-4 text-right" :class="member.balance > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-neutral-600 dark:text-neutral-400'">
                                    {{ formatCurrency(member.balance) }}
                                </td>
                                <td class="px-6 py-4">
                                    <span
                                        :class="getStatusColor(member.status)"
                                        class="inline-flex rounded-full px-2 py-1 text-xs font-medium"
                                    >
                                        {{ member.status_label }}
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="members.data.length === 0">
                                <td colspan="6" class="px-6 py-8 text-center text-neutral-500 dark:text-neutral-400">
                                    No member data for this month
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="members.last_page > 1" class="flex items-center justify-between border-t border-neutral-200 px-6 py-4 dark:border-neutral-700">
                    <p class="text-sm text-neutral-600 dark:text-neutral-400">
                        Showing {{ (members.current_page - 1) * members.per_page + 1 }} to
                        {{ Math.min(members.current_page * members.per_page, members.total) }} of
                        {{ members.total }} members
                    </p>
                    <div class="flex gap-2">
                        <template v-for="link in members.links" :key="link.label">
                            <Link
                                v-if="link.url"
                                :href="link.url"
                                class="rounded-md px-3 py-1 text-sm"
                                :class="link.active
                                    ? 'bg-neutral-900 text-white dark:bg-neutral-100 dark:text-neutral-900'
                                    : 'bg-neutral-100 text-neutral-700 hover:bg-neutral-200 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700'"
                            >
                                <span v-html="link.label" />
                            </Link>
                            <span
                                v-else
                                class="rounded-md px-3 py-1 text-sm text-neutral-400 dark:text-neutral-600"
                                v-html="link.label"
                            />
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
