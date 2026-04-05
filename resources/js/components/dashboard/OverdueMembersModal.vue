<script setup lang="ts">
import { show as showContribution } from '@/actions/App/Http/Controllers/ContributionController';
import StatusBadge from '@/components/contributions/StatusBadge.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Link } from '@inertiajs/vue3';

export interface OverdueMember {
    id: number;
    name: string;
    category: string;
    month: number;
    year: number;
    expected_amount: number;
    total_paid: number;
    balance: number;
    contribution_id: number;
}

defineProps<{
    members: OverdueMember[];
}>();

const isOpen = defineModel<boolean>('isOpen');

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

function formatCategory(category: string): string {
    return category.charAt(0).toUpperCase() + category.slice(1);
}

const categoryColors: Record<string, string> = {
    employed:
        'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
    unemployed:
        'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
    student: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
};
</script>

<template>
    <Dialog v-model:open="isOpen">
        <DialogContent class="sm:max-w-xl">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <span
                        class="flex h-8 w-8 items-center justify-center rounded-lg bg-red-100 dark:bg-red-900/30"
                    >
                        <svg
                            class="h-4 w-4 text-red-600 dark:text-red-400"
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
                    </span>
                    Overdue Members
                </DialogTitle>
                <DialogDescription>
                    {{ members.length }} overdue
                    {{
                        members.length === 1 ? 'contribution' : 'contributions'
                    }}
                    requiring attention.
                </DialogDescription>
            </DialogHeader>

            <div class="max-h-[60vh] overflow-y-auto">
                <div
                    v-if="members.length === 0"
                    class="py-8 text-center text-neutral-500 dark:text-neutral-400"
                >
                    No overdue contributions. Great job!
                </div>

                <div v-else class="flex flex-col gap-3">
                    <Link
                        v-for="member in members"
                        :key="`${member.id}-${member.month}-${member.year}`"
                        :href="showContribution(member.contribution_id).url"
                        class="group flex items-center justify-between rounded-lg border border-neutral-200 p-4 transition-all duration-200 hover:border-red-200 hover:bg-red-50/50 hover:shadow-sm dark:border-neutral-700 dark:hover:border-red-800 dark:hover:bg-red-900/10"
                    >
                        <div class="flex flex-col gap-1.5">
                            <div class="flex items-center gap-2">
                                <span
                                    class="font-medium text-neutral-900 group-hover:text-red-700 dark:text-neutral-100 dark:group-hover:text-red-400"
                                >
                                    {{ member.name }}
                                </span>
                                <span
                                    v-if="member.category"
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="
                                        categoryColors[member.category] ??
                                        'bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300'
                                    "
                                >
                                    {{ formatCategory(member.category) }}
                                </span>
                            </div>
                            <div
                                class="flex items-center gap-2 text-xs text-neutral-500 dark:text-neutral-400"
                            >
                                <span>{{
                                    formatMonth(member.month, member.year)
                                }}</span>
                                <span>&middot;</span>
                                <StatusBadge status="overdue" />
                            </div>
                        </div>

                        <div class="flex flex-col items-end gap-1">
                            <span
                                class="text-sm font-semibold text-red-600 dark:text-red-400"
                            >
                                {{ formatCurrency(member.balance) }}
                            </span>
                            <span
                                v-if="member.total_paid > 0"
                                class="text-xs text-neutral-500 dark:text-neutral-400"
                            >
                                {{ formatCurrency(member.total_paid) }} paid
                            </span>
                        </div>
                    </Link>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
