<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { index as paymentsIndex } from '@/actions/App/Http/Controllers/PaymentController';

defineProps<{
    totalMembers: number;
    totalExpected: number;
    totalCollected: number;
    totalOutstanding: number;
    overdueCount: number;
    collectionRate: number;
    canRecordPayments: boolean;
}>();

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
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <!-- Total Members -->
        <div class="rounded-xl border border-sidebar-border/70 bg-white p-6 dark:border-sidebar-border dark:bg-neutral-900">
            <div class="flex items-center gap-3">
                <div class="flex h-12 w-12 items-center justify-center rounded-lg bg-blue-100 dark:bg-blue-900/30">
                    <svg class="h-6 w-6 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                    </svg>
                </div>
                <div>
                    <p class="text-sm text-neutral-600 dark:text-neutral-400">Total Members</p>
                    <p class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ totalMembers }}</p>
                </div>
            </div>
        </div>

        <!-- Total Collected -->
        <div class="rounded-xl border border-sidebar-border/70 bg-white p-6 dark:border-sidebar-border dark:bg-neutral-900">
            <div class="flex items-center gap-3">
                <div class="flex h-12 w-12 items-center justify-center rounded-lg bg-green-100 dark:bg-green-900/30">
                    <svg class="h-6 w-6 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div>
                    <p class="text-sm text-neutral-600 dark:text-neutral-400">Total Collected</p>
                    <p class="text-2xl font-semibold text-green-600 dark:text-green-400">{{ formatCurrency(totalCollected) }}</p>
                </div>
            </div>
        </div>

        <!-- Outstanding -->
        <div class="rounded-xl border border-sidebar-border/70 bg-white p-6 dark:border-sidebar-border dark:bg-neutral-900">
            <div class="flex items-center gap-3">
                <div class="flex h-12 w-12 items-center justify-center rounded-lg bg-amber-100 dark:bg-amber-900/30">
                    <svg class="h-6 w-6 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div>
                    <p class="text-sm text-neutral-600 dark:text-neutral-400">Outstanding</p>
                    <p class="text-2xl font-semibold text-amber-600 dark:text-amber-400">{{ formatCurrency(totalOutstanding) }}</p>
                </div>
            </div>
        </div>

        <!-- Overdue Count -->
        <div class="rounded-xl border border-sidebar-border/70 bg-white p-6 dark:border-sidebar-border dark:bg-neutral-900">
            <div class="flex items-center gap-3">
                <div class="flex h-12 w-12 items-center justify-center rounded-lg" :class="overdueCount > 0 ? 'bg-red-100 dark:bg-red-900/30' : 'bg-neutral-100 dark:bg-neutral-800'">
                    <svg class="h-6 w-6" :class="overdueCount > 0 ? 'text-red-600 dark:text-red-400' : 'text-neutral-600 dark:text-neutral-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                </div>
                <div>
                    <p class="text-sm text-neutral-600 dark:text-neutral-400">Overdue Members</p>
                    <p class="text-2xl font-semibold" :class="overdueCount > 0 ? 'text-red-600 dark:text-red-400' : 'text-neutral-900 dark:text-neutral-100'">{{ overdueCount }}</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Collection Rate Bar -->
    <div class="mt-4 rounded-xl border border-sidebar-border/70 bg-white p-6 dark:border-sidebar-border dark:bg-neutral-900">
        <div class="flex items-center justify-between">
            <div>
                <h3 class="text-sm font-medium text-neutral-600 dark:text-neutral-400">Collection Rate</h3>
                <p class="mt-1 text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ collectionRate }}%</p>
            </div>
            <Link
                v-if="canRecordPayments"
                :href="paymentsIndex().url"
                class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-blue-700 dark:bg-blue-500 dark:hover:bg-blue-600"
            >
                Record Payment
            </Link>
        </div>
        <div class="mt-3 h-3 overflow-hidden rounded-full bg-neutral-200 dark:bg-neutral-700">
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
</template>
