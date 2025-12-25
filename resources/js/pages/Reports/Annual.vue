<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { type BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import { index, annual, monthly } from '@/actions/App/Http/Controllers/ReportController';
import {
    FileBarChart2,
    ChevronLeft,
    ChevronRight,
    TrendingUp,
    DollarSign,
    AlertCircle,
    BarChart3
} from 'lucide-vue-next';
import { ref, computed, watch } from 'vue';

interface MonthData {
    month: number;
    month_name: string;
    short_name: string;
    expected: number;
    collected: number;
    outstanding: number;
    collection_rate: number;
    contribution_count: number;
}

interface TotalData {
    expected: number;
    collected: number;
    outstanding: number;
    collection_rate: number;
}

interface CategoryData {
    label: string;
    expected: number;
    collected: number;
    outstanding: number;
    count: number;
}

interface Props {
    year: number;
    monthly_breakdown: MonthData[];
    total: TotalData;
    by_category: Record<string, CategoryData>;
}

const props = defineProps<Props>();

const selectedYear = ref(props.year);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    {
        title: 'Reports',
        href: index().url,
    },
    {
        title: `${props.year} Annual`,
        href: annual({ year: props.year }).url,
    },
]);

// Format currency from kobo to naira
function formatCurrency(kobo: number): string {
    const naira = kobo / 100;
    return new Intl.NumberFormat('en-NG', {
        style: 'currency',
        currency: 'NGN',
    }).format(naira);
}

// Navigate to a different year
function navigateToYear(year: number) {
    router.get(annual({ year }).url);
}

// Navigate to previous year
function previousYear() {
    navigateToYear(props.year - 1);
}

// Navigate to next year
function nextYear() {
    navigateToYear(props.year + 1);
}

// Watch for year selector changes
watch(selectedYear, (newYear) => {
    if (newYear !== props.year) {
        navigateToYear(newYear);
    }
});

// Calculate the max collected for chart scaling
const maxCollected = computed(() => {
    return Math.max(...props.monthly_breakdown.map(m => m.expected), 1);
});

// Get bar height percentage
function getBarHeight(amount: number): string {
    return `${(amount / maxCollected.value) * 100}%`;
}
</script>

<template>
    <Head :title="`Annual Report - ${year}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
            <!-- Header with Navigation -->
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-3">
                    <FileBarChart2 class="h-6 w-6 text-neutral-500" />
                    <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">
                        {{ year }} Annual Report
                    </h1>
                </div>
                <div class="flex items-center gap-2">
                    <Button variant="outline" size="icon" @click="previousYear">
                        <ChevronLeft class="h-4 w-4" />
                    </Button>
                    <select
                        v-model="selectedYear"
                        class="rounded-md border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-600 dark:bg-neutral-800"
                    >
                        <option v-for="y in [year - 3, year - 2, year - 1, year, year + 1]" :key="y" :value="y">
                            {{ y }}
                        </option>
                    </select>
                    <Button variant="outline" size="icon" @click="nextYear">
                        <ChevronRight class="h-4 w-4" />
                    </Button>
                </div>
            </div>

            <!-- Annual Summary Cards -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-sidebar-border/70 bg-white p-4 dark:border-sidebar-border dark:bg-neutral-900">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-100 dark:bg-blue-900">
                            <DollarSign class="h-5 w-5 text-blue-600 dark:text-blue-400" />
                        </div>
                        <div>
                            <p class="text-sm text-neutral-600 dark:text-neutral-400">Total Expected</p>
                            <p class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                                {{ formatCurrency(total.expected) }}
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
                            <p class="text-sm text-neutral-600 dark:text-neutral-400">Total Collected</p>
                            <p class="text-xl font-semibold text-green-600 dark:text-green-400">
                                {{ formatCurrency(total.collected) }}
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
                            <p class="text-sm text-neutral-600 dark:text-neutral-400">Total Outstanding</p>
                            <p class="text-xl font-semibold text-amber-600 dark:text-amber-400">
                                {{ formatCurrency(total.outstanding) }}
                            </p>
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border border-sidebar-border/70 bg-white p-4 dark:border-sidebar-border dark:bg-neutral-900">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-purple-100 dark:bg-purple-900">
                            <BarChart3 class="h-5 w-5 text-purple-600 dark:text-purple-400" />
                        </div>
                        <div>
                            <p class="text-sm text-neutral-600 dark:text-neutral-400">Yearly Collection Rate</p>
                            <p class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                                {{ total.collection_rate }}%
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Monthly Chart -->
            <div class="rounded-xl border border-sidebar-border/70 bg-white p-6 dark:border-sidebar-border dark:bg-neutral-900">
                <h2 class="mb-6 text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                    Monthly Collection Trend
                </h2>
                <div class="flex h-64 items-end gap-2">
                    <div
                        v-for="monthData in monthly_breakdown"
                        :key="monthData.month"
                        class="group flex-1 flex flex-col items-center gap-2"
                    >
                        <div class="relative flex h-full w-full items-end justify-center gap-0.5">
                            <!-- Expected bar (background) -->
                            <div
                                class="w-1/2 rounded-t bg-neutral-200 dark:bg-neutral-700"
                                :style="{ height: getBarHeight(monthData.expected) }"
                            />
                            <!-- Collected bar (foreground) -->
                            <div
                                class="w-1/2 rounded-t bg-green-500 dark:bg-green-600"
                                :style="{ height: getBarHeight(monthData.collected) }"
                            />
                            <!-- Tooltip -->
                            <div class="absolute bottom-full left-1/2 mb-2 -translate-x-1/2 hidden group-hover:block z-10">
                                <div class="rounded-lg bg-neutral-900 px-3 py-2 text-xs text-white shadow-lg dark:bg-neutral-100 dark:text-neutral-900 whitespace-nowrap">
                                    <p class="font-medium">{{ monthData.month_name }}</p>
                                    <p>Expected: {{ formatCurrency(monthData.expected) }}</p>
                                    <p>Collected: {{ formatCurrency(monthData.collected) }}</p>
                                    <p>Rate: {{ monthData.collection_rate }}%</p>
                                </div>
                            </div>
                        </div>
                        <router-link
                            :to="monthly({ year, month: monthData.month }).url"
                            class="text-xs text-neutral-600 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-neutral-100"
                        >
                            {{ monthData.short_name }}
                        </router-link>
                    </div>
                </div>
                <div class="mt-4 flex items-center justify-center gap-6 text-sm">
                    <div class="flex items-center gap-2">
                        <div class="h-3 w-3 rounded bg-neutral-200 dark:bg-neutral-700" />
                        <span class="text-neutral-600 dark:text-neutral-400">Expected</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="h-3 w-3 rounded bg-green-500 dark:bg-green-600" />
                        <span class="text-neutral-600 dark:text-neutral-400">Collected</span>
                    </div>
                </div>
            </div>

            <!-- Monthly Breakdown Table & Category -->
            <div class="grid gap-6 lg:grid-cols-3">
                <!-- Monthly Breakdown Table -->
                <div class="lg:col-span-2 rounded-xl border border-sidebar-border/70 bg-white dark:border-sidebar-border dark:bg-neutral-900">
                    <div class="border-b border-neutral-200 p-4 dark:border-neutral-700">
                        <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                            Monthly Breakdown
                        </h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead>
                                <tr class="border-b border-neutral-200 dark:border-neutral-700">
                                    <th class="px-6 py-4 font-medium text-neutral-600 dark:text-neutral-400">
                                        Month
                                    </th>
                                    <th class="px-6 py-4 text-right font-medium text-neutral-600 dark:text-neutral-400">
                                        Expected
                                    </th>
                                    <th class="px-6 py-4 text-right font-medium text-neutral-600 dark:text-neutral-400">
                                        Collected
                                    </th>
                                    <th class="px-6 py-4 text-right font-medium text-neutral-600 dark:text-neutral-400">
                                        Outstanding
                                    </th>
                                    <th class="px-6 py-4 text-right font-medium text-neutral-600 dark:text-neutral-400">
                                        Rate
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="monthData in monthly_breakdown"
                                    :key="monthData.month"
                                    class="border-b border-neutral-100 hover:bg-neutral-50 dark:border-neutral-800 dark:hover:bg-neutral-800/50 cursor-pointer"
                                    @click="router.get(monthly({ year, month: monthData.month }).url)"
                                >
                                    <td class="px-6 py-4 font-medium text-neutral-900 dark:text-neutral-100">
                                        {{ monthData.month_name }}
                                    </td>
                                    <td class="px-6 py-4 text-right text-neutral-600 dark:text-neutral-400">
                                        {{ formatCurrency(monthData.expected) }}
                                    </td>
                                    <td class="px-6 py-4 text-right text-green-600 dark:text-green-400">
                                        {{ formatCurrency(monthData.collected) }}
                                    </td>
                                    <td class="px-6 py-4 text-right text-amber-600 dark:text-amber-400">
                                        {{ formatCurrency(monthData.outstanding) }}
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <span
                                            :class="monthData.collection_rate >= 80
                                                ? 'text-green-600 dark:text-green-400'
                                                : monthData.collection_rate >= 50
                                                    ? 'text-amber-600 dark:text-amber-400'
                                                    : 'text-red-600 dark:text-red-400'"
                                        >
                                            {{ monthData.collection_rate }}%
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="bg-neutral-50 dark:bg-neutral-800/50 font-semibold">
                                    <td class="px-6 py-4 text-neutral-900 dark:text-neutral-100">
                                        Total
                                    </td>
                                    <td class="px-6 py-4 text-right text-neutral-900 dark:text-neutral-100">
                                        {{ formatCurrency(total.expected) }}
                                    </td>
                                    <td class="px-6 py-4 text-right text-green-600 dark:text-green-400">
                                        {{ formatCurrency(total.collected) }}
                                    </td>
                                    <td class="px-6 py-4 text-right text-amber-600 dark:text-amber-400">
                                        {{ formatCurrency(total.outstanding) }}
                                    </td>
                                    <td class="px-6 py-4 text-right text-neutral-900 dark:text-neutral-100">
                                        {{ total.collection_rate }}%
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <!-- Category Breakdown -->
                <div class="rounded-xl border border-sidebar-border/70 bg-white p-6 dark:border-sidebar-border dark:bg-neutral-900">
                    <h2 class="mb-4 text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                        By Category
                    </h2>
                    <div class="space-y-4">
                        <div
                            v-for="(category, key) in by_category"
                            :key="key"
                            class="rounded-lg border border-neutral-200 p-4 dark:border-neutral-700"
                        >
                            <div class="flex items-center justify-between mb-3">
                                <span class="font-medium text-neutral-900 dark:text-neutral-100">
                                    {{ category.label }}
                                </span>
                                <span class="text-sm text-neutral-500">
                                    {{ category.count }} contributions
                                </span>
                            </div>
                            <div class="space-y-2 text-sm">
                                <div class="flex justify-between">
                                    <span class="text-neutral-500">Expected</span>
                                    <span class="font-medium text-neutral-900 dark:text-neutral-100">
                                        {{ formatCurrency(category.expected) }}
                                    </span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-neutral-500">Collected</span>
                                    <span class="font-medium text-green-600 dark:text-green-400">
                                        {{ formatCurrency(category.collected) }}
                                    </span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-neutral-500">Outstanding</span>
                                    <span class="font-medium text-amber-600 dark:text-amber-400">
                                        {{ formatCurrency(category.outstanding) }}
                                    </span>
                                </div>
                                <!-- Progress bar -->
                                <div class="mt-2">
                                    <div class="h-2 w-full rounded-full bg-neutral-200 dark:bg-neutral-700">
                                        <div
                                            class="h-2 rounded-full bg-green-500"
                                            :style="{ width: category.expected > 0 ? `${(category.collected / category.expected) * 100}%` : '0%' }"
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
