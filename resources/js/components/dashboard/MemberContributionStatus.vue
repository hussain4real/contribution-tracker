<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { contributions, payments } from '@/routes';
import StatusBadge from '@/components/contributions/StatusBadge.vue';

interface Member {
    id: number;
    name: string;
    category: string;
    expected_amount: number;
    total_paid: number;
    current_month_status: string;
    current_month_balance: number;
    contribution_id: number;
}

defineProps<{
    member: Member;
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

// Format category label
function formatCategory(category: string): string {
    return category.charAt(0).toUpperCase() + category.slice(1);
}
</script>

<template>
    <tr class="border-b border-neutral-100 transition hover:bg-neutral-50 dark:border-neutral-800 dark:hover:bg-neutral-800/50">
        <td class="px-4 py-3">
            <Link
                :href="contributions.show(member.contribution_id).url"
                class="font-medium text-neutral-900 hover:text-blue-600 dark:text-neutral-100 dark:hover:text-blue-400"
            >
                {{ member.name }}
            </Link>
        </td>
        <td class="px-4 py-3">
            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                :class="{
                    'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400': member.category === 'employed',
                    'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400': member.category === 'unemployed',
                    'bg-cyan-100 text-cyan-800 dark:bg-cyan-900/30 dark:text-cyan-400': member.category === 'student',
                }"
            >
                {{ formatCategory(member.category) }}
            </span>
        </td>
        <td class="px-4 py-3">
            <StatusBadge :status="member.current_month_status" />
        </td>
        <td class="px-4 py-3 text-right">
            <span class="font-medium" :class="{
                'text-neutral-900 dark:text-neutral-100': member.current_month_balance === 0,
                'text-amber-600 dark:text-amber-400': member.current_month_balance > 0 && member.current_month_status !== 'overdue',
                'text-red-600 dark:text-red-400': member.current_month_status === 'overdue',
            }">
                {{ member.current_month_balance > 0 ? formatCurrency(member.current_month_balance) : '-' }}
            </span>
            <Link
                v-if="canRecordPayments && member.current_month_balance > 0"
                :href="payments.create({ member_id: member.id }).url"
                class="ml-2 text-xs text-blue-600 hover:underline dark:text-blue-400"
            >
                Pay
            </Link>
        </td>
    </tr>
</template>
