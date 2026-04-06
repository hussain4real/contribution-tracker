<script setup lang="ts">
import { index as paymentsIndex } from '@/actions/App/Http/Controllers/PaymentController';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { MonthlyBreakdown } from '@/types/dashboard';
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps<{
    totalMembers: number;
    totalExpected: number;
    totalCollected: number;
    currentMonthCollected: number;
    totalOutstanding: number;
    overdueCount: number;
    collectionRate: number;
    monthlyBreakdown: MonthlyBreakdown[];
    canRecordPayments: boolean;
}>();

const emit = defineEmits<{
    overdueClick: [];
}>();

const showBreakdownModal = ref(false);

// Format currency in Naira
function formatCurrency(amount: number): string {
    return new Intl.NumberFormat('en-NG', {
        style: 'currency',
        currency: 'NGN',
    }).format(amount);
}

function formatMonth(month: number, year: number): string {
    return new Date(year, month - 1).toLocaleDateString('en-NG', {
        month: 'short',
        year: 'numeric',
    });
}
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <!-- Total Members -->
        <div
            class="rounded-xl border border-sidebar-border/70 bg-white p-6 dark:border-sidebar-border dark:bg-neutral-900"
        >
            <div class="flex items-center gap-3">
                <div
                    class="flex h-12 w-12 items-center justify-center rounded-lg bg-blue-100 dark:bg-blue-900/30"
                >
                    <svg
                        class="h-6 w-6 text-blue-600 dark:text-blue-400"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"
                        />
                    </svg>
                </div>
                <div>
                    <p class="text-sm text-neutral-600 dark:text-neutral-400">
                        Total Members
                    </p>
                    <p
                        class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100"
                    >
                        {{ totalMembers }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Total Collected -->
        <button
            type="button"
            class="cursor-pointer rounded-xl border border-sidebar-border/70 bg-white p-6 text-left transition-all duration-200 hover:border-green-300 hover:shadow-md active:scale-[0.98] dark:border-sidebar-border dark:bg-neutral-900 dark:hover:border-green-700"
            @click="showBreakdownModal = true"
        >
            <div class="flex items-center gap-3">
                <div
                    class="flex h-12 w-12 items-center justify-center rounded-lg bg-green-100 dark:bg-green-900/30"
                >
                    <svg
                        class="h-6 w-6 text-green-600 dark:text-green-400"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                        />
                    </svg>
                </div>
                <div>
                    <p class="text-sm text-neutral-600 dark:text-neutral-400">
                        Total Collected
                    </p>
                    <p
                        class="text-2xl font-semibold text-green-600 dark:text-green-400"
                    >
                        {{ formatCurrency(totalCollected) }}
                    </p>
                    <p
                        class="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400"
                    >
                        This month:
                        {{ formatCurrency(currentMonthCollected) }}
                    </p>
                </div>
            </div>
        </button>

        <!-- Outstanding -->
        <div
            class="rounded-xl border border-sidebar-border/70 bg-white p-6 dark:border-sidebar-border dark:bg-neutral-900"
        >
            <div class="flex items-center gap-3">
                <div
                    class="flex h-12 w-12 items-center justify-center rounded-lg bg-amber-100 dark:bg-amber-900/30"
                >
                    <svg
                        class="h-6 w-6 text-amber-600 dark:text-amber-400"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"
                        />
                    </svg>
                </div>
                <div>
                    <p class="text-sm text-neutral-600 dark:text-neutral-400">
                        Outstanding
                    </p>
                    <p
                        class="text-2xl font-semibold text-amber-600 dark:text-amber-400"
                    >
                        {{ formatCurrency(totalOutstanding) }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Overdue Count -->
        <button
            type="button"
            :disabled="overdueCount === 0"
            class="rounded-xl border border-sidebar-border/70 bg-white p-6 text-left transition-all duration-200 dark:border-sidebar-border dark:bg-neutral-900"
            :class="
                overdueCount > 0
                    ? 'cursor-pointer hover:border-red-300 hover:shadow-md active:scale-[0.98] dark:hover:border-red-700'
                    : 'cursor-default'
            "
            @click="emit('overdueClick')"
        >
            <div class="flex items-center gap-3">
                <div
                    class="flex h-12 w-12 items-center justify-center rounded-lg"
                    :class="
                        overdueCount > 0
                            ? 'bg-red-100 dark:bg-red-900/30'
                            : 'bg-neutral-100 dark:bg-neutral-800'
                    "
                >
                    <svg
                        class="h-6 w-6"
                        :class="
                            overdueCount > 0
                                ? 'text-red-600 dark:text-red-400'
                                : 'text-neutral-600 dark:text-neutral-400'
                        "
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
                        />
                    </svg>
                </div>
                <div>
                    <p class="text-sm text-neutral-600 dark:text-neutral-400">
                        Overdue Members
                    </p>
                    <p
                        class="text-2xl font-semibold"
                        :class="
                            overdueCount > 0
                                ? 'text-red-600 dark:text-red-400'
                                : 'text-neutral-900 dark:text-neutral-100'
                        "
                    >
                        {{ overdueCount }}
                    </p>
                </div>
            </div>
        </button>
    </div>

    <!-- Collection Rate Bar -->
    <div
        class="mt-4 rounded-xl border border-sidebar-border/70 bg-white p-6 dark:border-sidebar-border dark:bg-neutral-900"
    >
        <div class="flex items-center justify-between">
            <div>
                <h3
                    class="text-sm font-medium text-neutral-600 dark:text-neutral-400"
                >
                    Collection Rate
                </h3>
                <p
                    class="mt-1 text-lg font-semibold text-neutral-900 dark:text-neutral-100"
                >
                    {{ collectionRate }}%
                </p>
            </div>
            <Link
                v-if="canRecordPayments"
                :href="paymentsIndex().url"
                class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-blue-700 dark:bg-blue-500 dark:hover:bg-blue-600"
            >
                Record Payment
            </Link>
        </div>
        <div
            class="mt-3 h-3 overflow-hidden rounded-full bg-neutral-200 dark:bg-neutral-700"
        >
            <div
                class="h-full rounded-full transition-all duration-500"
                :class="{
                    'bg-green-500': collectionRate >= 80,
                    'bg-amber-500': collectionRate >= 50 && collectionRate < 80,
                    'bg-red-500': collectionRate < 50,
                }"
                :style="{ width: `${collectionRate}%` }"
            />
        </div>
    </div>

    <!-- Monthly Breakdown Modal -->
    <Dialog v-model:open="showBreakdownModal">
        <DialogContent class="max-h-[80vh] sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Monthly Collection Breakdown</DialogTitle>
                <DialogDescription>
                    All-time total:
                    {{ formatCurrency(totalCollected) }}
                </DialogDescription>
            </DialogHeader>

            <div class="max-h-[60vh] overflow-y-auto">
                <table class="w-full text-left text-sm">
                    <thead class="sticky top-0 bg-white dark:bg-neutral-950">
                        <tr
                            class="border-b border-neutral-200 dark:border-neutral-700"
                        >
                            <th
                                class="px-3 py-2 font-medium text-neutral-600 dark:text-neutral-400"
                            >
                                Month
                            </th>
                            <th
                                class="px-3 py-2 text-right font-medium text-neutral-600 dark:text-neutral-400"
                            >
                                Expected
                            </th>
                            <th
                                class="px-3 py-2 text-right font-medium text-neutral-600 dark:text-neutral-400"
                            >
                                Collected
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in monthlyBreakdown"
                            :key="row.period"
                            class="border-b border-neutral-100 dark:border-neutral-800"
                        >
                            <td
                                class="px-3 py-2 text-neutral-900 dark:text-neutral-100"
                            >
                                {{ formatMonth(row.month, row.year) }}
                            </td>
                            <td
                                class="px-3 py-2 text-right text-neutral-600 dark:text-neutral-400"
                            >
                                {{ formatCurrency(row.expected) }}
                            </td>
                            <td
                                class="px-3 py-2 text-right font-medium"
                                :class="
                                    row.collected >= row.expected
                                        ? 'text-green-600 dark:text-green-400'
                                        : 'text-amber-600 dark:text-amber-400'
                                "
                            >
                                {{ formatCurrency(row.collected) }}
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div
                    v-if="monthlyBreakdown.length === 0"
                    class="py-8 text-center text-sm text-neutral-500 dark:text-neutral-400"
                >
                    No collection data available yet.
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
