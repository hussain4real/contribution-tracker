<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { show as showContribution } from '@/actions/App/Http/Controllers/ContributionController';
import StatusBadge from '@/components/contributions/StatusBadge.vue';
import PaymentHistory from '@/components/contributions/PaymentHistory.vue';
import { ChevronDown, ChevronUp } from 'lucide-vue-next';
import { ref } from 'vue';

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

interface Props {
    contribution: Contribution;
}

defineProps<Props>();

const showPayments = ref(false);

function formatCurrency(kobo: number): string {
    const naira = kobo / 100;
    return new Intl.NumberFormat('en-NG', {
        style: 'currency',
        currency: 'NGN',
    }).format(naira);
}

function togglePayments() {
    showPayments.value = !showPayments.value;
}
</script>

<template>
    <div class="p-6">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <Link
                    :href="showContribution(contribution.id).url"
                    class="text-lg font-medium text-neutral-900 hover:text-blue-600 dark:text-neutral-100 dark:hover:text-blue-400"
                >
                    {{ contribution.period_label }}
                </Link>
                <StatusBadge :status="contribution.status" />
            </div>

            <div class="flex items-center gap-6 text-sm">
                <div class="text-right">
                    <p class="text-neutral-600 dark:text-neutral-400">Expected</p>
                    <p class="font-medium text-neutral-900 dark:text-neutral-100">
                        {{ formatCurrency(contribution.expected_amount) }}
                    </p>
                </div>

                <div class="text-right">
                    <p class="text-neutral-600 dark:text-neutral-400">Paid</p>
                    <p class="font-medium text-green-600 dark:text-green-400">
                        {{ formatCurrency(contribution.total_paid) }}
                    </p>
                </div>

                <div v-if="contribution.balance > 0" class="text-right">
                    <p class="text-neutral-600 dark:text-neutral-400">Balance</p>
                    <p class="font-medium" :class="{
                        'text-amber-600 dark:text-amber-400': contribution.status === 'partial',
                        'text-red-600 dark:text-red-400': contribution.status === 'overdue',
                        'text-neutral-600 dark:text-neutral-400': contribution.status === 'unpaid',
                    }">
                        {{ formatCurrency(contribution.balance) }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Payment History Toggle -->
        <div v-if="contribution.payments.length > 0" class="mt-4">
            <button
                @click="togglePayments"
                class="flex items-center gap-2 text-sm text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300"
            >
                <component :is="showPayments ? ChevronUp : ChevronDown" class="h-4 w-4" />
                {{ showPayments ? 'Hide' : 'Show' }} {{ contribution.payments.length }} payment{{ contribution.payments.length !== 1 ? 's' : '' }}
            </button>

            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0 -translate-y-2"
                enter-to-class="opacity-100 translate-y-0"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100 translate-y-0"
                leave-to-class="opacity-0 -translate-y-2"
            >
                <PaymentHistory
                    v-if="showPayments"
                    :payments="contribution.payments"
                    class="mt-4"
                />
            </Transition>
        </div>

        <div v-else class="mt-3 text-sm text-neutral-500 dark:text-neutral-400">
            No payments recorded yet
        </div>

        <!-- Due Date -->
        <p class="mt-2 text-xs text-neutral-500 dark:text-neutral-400">
            Due: {{ contribution.due_date }}
        </p>
    </div>
</template>
