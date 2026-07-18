<script setup lang="ts">
import {
    create as createExpense,
    index,
} from '@/actions/App/Http/Controllers/ExpenseController';
import ExpenseReversalController from '@/actions/App/Http/Controllers/ExpenseReversalController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { useCurrencyFormatter } from '@/lib/currency';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus, Receipt, RotateCcw } from '@lucide/vue';
import { ref } from 'vue';

interface ExpenseItem {
    id: number;
    amount: number;
    description: string;
    spent_at: string;
    recorded_by: string | null;
    created_at: string;
    is_reversed: boolean;
    reversal_reason: string | null;
    can_reverse: boolean;
}

interface PaginatedExpenses {
    data: ExpenseItem[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
}

interface Props {
    expenses?: PaginatedExpenses;
    can_create?: boolean;
}

defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Expenses',
        href: index().url,
    },
];

const { formatCurrency } = useCurrencyFormatter();

function formatDate(date: string): string {
    return new Date(date).toLocaleDateString('en-NG', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

const reversalTarget = ref<ExpenseItem | null>(null);
const reversalReason = ref('');
const reversalError = ref('');
const reversing = ref(false);

function openReversal(expense: ExpenseItem): void {
    reversalTarget.value = expense;
    reversalReason.value = '';
    reversalError.value = '';
}

function reverseExpense(): void {
    if (!reversalTarget.value || reversalReason.value.trim().length < 5) {
        reversalError.value = 'Enter a reason of at least 5 characters.';
        return;
    }

    reversing.value = true;
    router.post(
        ExpenseReversalController({ expense: reversalTarget.value.id }).url,
        { reason: reversalReason.value },
        {
            preserveScroll: true,
            onSuccess: () => (reversalTarget.value = null),
            onError: (errors) => {
                reversalError.value =
                    errors.reason ?? 'Unable to reverse this expense.';
            },
            onFinish: () => (reversing.value = false),
        },
    );
}
</script>

<template>
    <Head title="Expenses" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
            <!-- Header -->
            <div
                class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="flex items-center gap-3">
                    <Receipt class="h-6 w-6 text-neutral-500" />
                    <h1
                        class="text-xl font-semibold text-neutral-900 sm:text-2xl dark:text-neutral-100"
                    >
                        Expenses
                    </h1>
                </div>
                <Link v-if="can_create" :href="createExpense().url">
                    <Button size="sm">
                        <Plus class="mr-2 h-4 w-4" />
                        Record Expense
                    </Button>
                </Link>
            </div>

            <!-- Expenses Table -->
            <div
                class="rounded-xl border border-sidebar-border/70 bg-white dark:border-sidebar-border dark:bg-neutral-900"
            >
                <div v-if="!expenses?.data?.length" class="p-6 text-center">
                    <p class="text-neutral-600 dark:text-neutral-400">
                        No expenses recorded yet.
                    </p>
                </div>

                <div
                    v-if="expenses?.data?.length"
                    class="divide-y divide-neutral-100 md:hidden dark:divide-neutral-800"
                >
                    <div
                        v-for="expense in expenses.data"
                        :key="expense.id"
                        class="p-4"
                        :class="{ 'opacity-60': expense.is_reversed }"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p
                                    class="font-medium text-neutral-900 dark:text-neutral-100"
                                >
                                    {{ expense.description }}
                                </p>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    {{ formatDate(expense.spent_at) }}
                                    <template v-if="expense.recorded_by">
                                        &middot; {{ expense.recorded_by }}
                                    </template>
                                </p>
                                <p
                                    v-if="expense.is_reversed"
                                    class="mt-1 text-xs font-medium text-amber-600"
                                >
                                    Reversed — {{ expense.reversal_reason }}
                                </p>
                            </div>
                            <p
                                class="shrink-0 font-semibold text-red-600 dark:text-red-400"
                            >
                                -{{ formatCurrency(expense.amount) }}
                            </p>
                        </div>
                        <div
                            v-if="expense.can_reverse"
                            class="mt-3 flex justify-end"
                        >
                            <Button
                                variant="ghost"
                                size="sm"
                                @click="openReversal(expense)"
                                class="text-red-500 hover:text-red-700"
                            >
                                <RotateCcw class="mr-2 h-4 w-4" />
                                Reverse
                            </Button>
                        </div>
                    </div>
                </div>

                <div
                    v-if="expenses?.data?.length"
                    class="hidden overflow-x-auto md:block"
                >
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr
                                class="border-b border-neutral-200 dark:border-neutral-700"
                            >
                                <th
                                    class="px-4 py-3 font-medium text-neutral-600 sm:px-6 dark:text-neutral-400"
                                >
                                    Date
                                </th>
                                <th
                                    class="px-4 py-3 font-medium text-neutral-600 sm:px-6 dark:text-neutral-400"
                                >
                                    Description
                                </th>
                                <th
                                    class="px-4 py-3 text-right font-medium text-neutral-600 sm:px-6 dark:text-neutral-400"
                                >
                                    Amount
                                </th>
                                <th
                                    class="hidden px-6 py-3 font-medium text-neutral-600 md:table-cell dark:text-neutral-400"
                                >
                                    Recorded By
                                </th>
                                <th
                                    class="px-4 py-3 font-medium text-neutral-600 sm:px-6 dark:text-neutral-400"
                                ></th>
                            </tr>
                        </thead>
                        <tbody
                            class="divide-y divide-neutral-100 dark:divide-neutral-800"
                        >
                            <tr
                                v-for="expense in expenses.data"
                                :key="expense.id"
                                class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50"
                                :class="{ 'opacity-60': expense.is_reversed }"
                            >
                                <td
                                    class="px-4 py-4 text-neutral-900 sm:px-6 dark:text-neutral-100"
                                >
                                    {{ formatDate(expense.spent_at) }}
                                </td>
                                <td
                                    class="max-w-[120px] truncate px-4 py-4 text-neutral-700 sm:max-w-xs sm:px-6 dark:text-neutral-300"
                                >
                                    {{ expense.description }}
                                    <span
                                        v-if="expense.is_reversed"
                                        class="ml-2 text-xs font-medium text-amber-600"
                                        >Reversed</span
                                    >
                                </td>
                                <td
                                    class="px-4 py-4 text-right font-medium text-red-600 sm:px-6 dark:text-red-400"
                                >
                                    -{{ formatCurrency(expense.amount) }}
                                </td>
                                <td
                                    class="hidden px-6 py-4 text-neutral-500 md:table-cell dark:text-neutral-400"
                                >
                                    {{ expense.recorded_by ?? 'System' }}
                                </td>
                                <td class="px-4 py-4 text-right sm:px-6">
                                    <Button
                                        v-if="expense.can_reverse"
                                        variant="ghost"
                                        size="sm"
                                        @click="openReversal(expense)"
                                        class="text-red-500 hover:text-red-700"
                                    >
                                        <RotateCcw class="h-4 w-4" />
                                    </Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div
                    v-if="expenses && expenses.last_page > 1"
                    class="flex items-center justify-center gap-2 border-t border-neutral-200 px-6 py-4 dark:border-neutral-700"
                >
                    <template v-for="link in expenses.links" :key="link.label">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            class="rounded-md px-3 py-1 text-sm"
                            :class="
                                link.active
                                    ? 'bg-primary text-primary-foreground'
                                    : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-400 dark:hover:bg-neutral-800'
                            "
                        >
                            <span v-html="link.label" />
                        </Link>
                        <span
                            v-else
                            class="px-3 py-1 text-sm text-neutral-400 dark:text-neutral-600"
                            v-html="link.label"
                        />
                    </template>
                </div>
            </div>

            <Dialog
                :open="reversalTarget !== null"
                @update:open="(open) => !open && (reversalTarget = null)"
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Reverse expense</DialogTitle>
                        <DialogDescription>
                            This keeps the original entry in the audit trail and
                            removes it from the effective family balance.
                        </DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-2">
                        <Label for="expense-reversal-reason">Reason</Label>
                        <textarea
                            id="expense-reversal-reason"
                            v-model="reversalReason"
                            rows="4"
                            maxlength="1000"
                            class="rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                        />
                        <InputError :message="reversalError" />
                    </div>
                    <DialogFooter>
                        <Button variant="outline" @click="reversalTarget = null"
                            >Cancel</Button
                        >
                        <Button
                            variant="destructive"
                            :disabled="reversing"
                            @click="reverseExpense"
                        >
                            {{ reversing ? 'Reversing…' : 'Reverse expense' }}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    </AppLayout>
</template>
