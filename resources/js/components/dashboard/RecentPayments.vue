<script setup lang="ts">
interface Payment {
    id: number;
    amount: number;
    paid_at: string;
    member_name: string;
    recorded_by: string | null;
    month: number;
    year: number;
}

defineProps<{
    payments: Payment[];
}>();

// Format currency from kobo to naira
function formatCurrency(kobo: number): string {
    const naira = kobo / 100;
    return new Intl.NumberFormat('en-NG', {
        style: 'currency',
        currency: 'NGN',
    }).format(naira);
}

// Format date
function formatDate(dateString: string): string {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-NG', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

// Get month name
function getMonthName(month: number): string {
    const months = [
        'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
        'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
    ];
    return months[month - 1] || '';
}
</script>

<template>
    <div class="rounded-xl border border-sidebar-border/70 bg-white p-6 dark:border-sidebar-border dark:bg-neutral-900">
        <h2 class="mb-4 text-lg font-semibold text-neutral-900 dark:text-neutral-100">
            Recent Payments
        </h2>

        <div v-if="payments.length === 0" class="py-8 text-center text-neutral-500 dark:text-neutral-400">
            No payments recorded yet
        </div>

        <div v-else class="space-y-3">
            <div
                v-for="payment in payments"
                :key="payment.id"
                class="flex items-center justify-between rounded-lg border border-neutral-200 p-4 dark:border-neutral-700"
            >
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/30">
                        <svg class="h-5 w-5 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                    </div>
                    <div>
                        <p class="font-medium text-neutral-900 dark:text-neutral-100">{{ payment.member_name }}</p>
                        <p class="text-sm text-neutral-500 dark:text-neutral-400">
                            {{ getMonthName(payment.month) }} {{ payment.year }} • {{ formatDate(payment.paid_at) }}
                        </p>
                    </div>
                </div>
                <div class="text-right">
                    <p class="font-semibold text-green-600 dark:text-green-400">{{ formatCurrency(payment.amount) }}</p>
                    <p v-if="payment.recorded_by" class="text-xs text-neutral-500 dark:text-neutral-400">
                        by {{ payment.recorded_by }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
