<script setup lang="ts">
defineProps<{
    totalExpected: number;
    totalCollected: number;
    totalOutstanding: number;
    collectionRate: number;
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
    <div class="rounded-xl border border-sidebar-border/70 bg-white p-6 dark:border-sidebar-border dark:bg-neutral-900">
        <h2 class="mb-4 text-lg font-semibold text-neutral-900 dark:text-neutral-100">
            Family Contribution Overview
        </h2>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-lg bg-neutral-50 p-4 dark:bg-neutral-800">
                <p class="text-sm text-neutral-600 dark:text-neutral-400">Total Expected</p>
                <p class="mt-1 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">
                    {{ formatCurrency(totalExpected) }}
                </p>
            </div>
            <div class="rounded-lg bg-neutral-50 p-4 dark:bg-neutral-800">
                <p class="text-sm text-neutral-600 dark:text-neutral-400">Total Collected</p>
                <p class="mt-1 text-2xl font-semibold text-green-600 dark:text-green-400">
                    {{ formatCurrency(totalCollected) }}
                </p>
            </div>
            <div class="rounded-lg bg-neutral-50 p-4 dark:bg-neutral-800">
                <p class="text-sm text-neutral-600 dark:text-neutral-400">Outstanding</p>
                <p class="mt-1 text-2xl font-semibold text-amber-600 dark:text-amber-400">
                    {{ formatCurrency(totalOutstanding) }}
                </p>
            </div>
            <div class="rounded-lg bg-neutral-50 p-4 dark:bg-neutral-800">
                <p class="text-sm text-neutral-600 dark:text-neutral-400">Collection Rate</p>
                <p class="mt-1 text-2xl font-semibold" :class="{
                    'text-green-600 dark:text-green-400': collectionRate >= 80,
                    'text-amber-600 dark:text-amber-400': collectionRate >= 50 && collectionRate < 80,
                    'text-red-600 dark:text-red-400': collectionRate < 50,
                }">
                    {{ collectionRate }}%
                </p>
            </div>
        </div>

        <!-- Progress Bar -->
        <div class="mt-4">
            <div class="h-2 overflow-hidden rounded-full bg-neutral-200 dark:bg-neutral-700">
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
    </div>
</template>
