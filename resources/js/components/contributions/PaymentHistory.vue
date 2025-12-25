<script setup lang="ts">
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

interface Props {
    payments: Payment[];
}

defineProps<Props>();

function formatCurrency(kobo: number): string {
    const naira = kobo / 100;
    return new Intl.NumberFormat('en-NG', {
        style: 'currency',
        currency: 'NGN',
    }).format(naira);
}

function formatDate(date: string): string {
    return new Date(date).toLocaleDateString('en-NG', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}
</script>

<template>
    <div class="rounded-lg border border-sidebar-border/70 bg-neutral-50 p-4 dark:border-sidebar-border dark:bg-neutral-800/50">
        <h4 class="mb-3 text-sm font-medium text-neutral-700 dark:text-neutral-300">
            Payment History
        </h4>

        <div class="space-y-3">
            <div
                v-for="payment in payments"
                :key="payment.id"
                class="flex items-start justify-between rounded-md bg-white p-3 shadow-sm dark:bg-neutral-800"
            >
                <div>
                    <p class="font-medium text-neutral-900 dark:text-neutral-100">
                        {{ formatCurrency(payment.amount) }}
                    </p>
                    <p class="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">
                        Paid on {{ formatDate(payment.paid_at) }}
                    </p>
                    <p v-if="payment.notes" class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">
                        {{ payment.notes }}
                    </p>
                </div>

                <div class="text-right text-xs text-neutral-500 dark:text-neutral-400">
                    <p>Recorded by</p>
                    <p class="font-medium text-neutral-700 dark:text-neutral-300">
                        {{ payment.recorder.name }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
