<script setup lang="ts">
import { index as membersIndex } from '@/actions/App/Http/Controllers/MemberController';
import PaymentBatchReversalController from '@/actions/App/Http/Controllers/PaymentBatchReversalController';
import {
    create as createPayment,
    index,
} from '@/actions/App/Http/Controllers/PaymentController';
import PaymentReceiptController from '@/actions/App/Http/Controllers/PaymentReceiptController';
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
import {
    ChevronRight,
    CreditCard,
    Download,
    RotateCcw,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';

interface Member {
    id: number;
    name: string;
    email: string;
    category: string | null;
    category_label: string | null;
    monthly_amount: number;
}

interface Props {
    members?: Member[];
    receipts?: ReceiptItem[];
}

interface ReceiptItem {
    id: number;
    receipt_number: number;
    member_name: string;
    total_amount: number;
    paid_at: string;
    method: string;
    source: string;
    reference: string | null;
    recorded_by: string | null;
    allocations_count: number;
    is_reversed: boolean;
    reversal_reason: string | null;
    can_reverse: boolean;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Payments',
        href: index().url,
    },
];

const searchQuery = ref('');
const reversalTarget = ref<ReceiptItem | null>(null);
const reversalReason = ref('');
const reversalError = ref('');
const reversing = ref(false);

const filteredMembers = computed(() => {
    if (!props.members) return [];
    if (!searchQuery.value) return props.members;

    const query = searchQuery.value.toLowerCase();
    return props.members.filter(
        (member) =>
            member.name.toLowerCase().includes(query) ||
            member.email.toLowerCase().includes(query),
    );
});

const { formatCurrency } = useCurrencyFormatter();

function reverseReceipt(): void {
    if (!reversalTarget.value || reversalReason.value.trim().length < 5) {
        reversalError.value = 'Enter a reason of at least 5 characters.';
        return;
    }

    reversing.value = true;
    router.post(
        PaymentBatchReversalController({
            payment_batch: reversalTarget.value.id,
        }).url,
        { reason: reversalReason.value },
        {
            preserveScroll: true,
            onSuccess: () => (reversalTarget.value = null),
            onError: (errors) => {
                reversalError.value =
                    errors.reason ?? 'Unable to reverse this receipt.';
            },
            onFinish: () => (reversing.value = false),
        },
    );
}
</script>

<template>
    <Head title="Record Payment" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
            <!-- Header -->
            <div class="flex items-center gap-3">
                <CreditCard class="h-6 w-6 text-neutral-500" />
                <h1
                    class="text-xl font-semibold text-neutral-900 sm:text-2xl dark:text-neutral-100"
                >
                    Record Payment
                </h1>
            </div>

            <!-- Instructions -->
            <div
                class="rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-900/20"
            >
                <p class="text-sm text-blue-800 dark:text-blue-200">
                    Select a member below to record a payment for them.
                </p>
            </div>

            <!-- Search -->
            <div class="relative">
                <input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Search members by name or email..."
                    class="w-full rounded-lg border border-neutral-300 bg-white px-4 py-3 text-sm placeholder:text-neutral-400 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none dark:border-neutral-600 dark:bg-neutral-800 dark:placeholder:text-neutral-500"
                />
            </div>

            <!-- Members List -->
            <div
                class="rounded-xl border border-sidebar-border/70 bg-white dark:border-sidebar-border dark:bg-neutral-900"
            >
                <div
                    class="border-b border-neutral-200 px-6 py-4 dark:border-neutral-700"
                >
                    <div class="flex items-center gap-2">
                        <Users class="h-5 w-5 text-neutral-500" />
                        <h2
                            class="text-lg font-medium text-neutral-900 dark:text-neutral-100"
                        >
                            Select Member
                        </h2>
                    </div>
                </div>

                <div
                    v-if="!members || members.length === 0"
                    class="p-6 text-center"
                >
                    <p class="text-neutral-600 dark:text-neutral-400">
                        No members found.
                        <Link
                            :href="membersIndex().url"
                            class="text-blue-600 hover:underline dark:text-blue-400"
                            >Add members first</Link
                        >.
                    </p>
                </div>

                <div
                    v-else-if="filteredMembers.length === 0"
                    class="p-6 text-center"
                >
                    <p class="text-neutral-600 dark:text-neutral-400">
                        No members match your search.
                    </p>
                </div>

                <div
                    v-else
                    class="divide-y divide-neutral-100 dark:divide-neutral-800"
                >
                    <Link
                        v-for="member in filteredMembers"
                        :key="member.id"
                        :href="createPayment({ member: member.id }).url"
                        class="flex items-center justify-between gap-3 px-4 py-4 transition hover:bg-neutral-50 sm:gap-4 sm:px-6 dark:hover:bg-neutral-800"
                    >
                        <div class="flex min-w-0 items-center gap-3 sm:gap-4">
                            <div
                                class="flex size-10 shrink-0 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-900/30"
                            >
                                <span
                                    class="text-sm font-medium text-blue-600 dark:text-blue-400"
                                >
                                    {{ member.name.charAt(0).toUpperCase() }}
                                </span>
                            </div>
                            <div class="min-w-0">
                                <p
                                    class="truncate font-medium text-neutral-900 dark:text-neutral-100"
                                >
                                    {{ member.name }}
                                </p>
                                <p
                                    class="truncate text-sm text-neutral-500 dark:text-neutral-400"
                                >
                                    {{ member.email }}
                                </p>
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-2 sm:gap-4">
                            <div class="hidden text-right sm:block">
                                <p
                                    class="text-sm text-neutral-500 dark:text-neutral-400"
                                >
                                    {{ member.category_label || 'No category' }}
                                </p>
                                <p
                                    class="font-medium text-neutral-900 dark:text-neutral-100"
                                >
                                    {{
                                        formatCurrency(member.monthly_amount)
                                    }}/month
                                </p>
                            </div>
                            <ChevronRight class="h-5 w-5 text-neutral-400" />
                        </div>
                    </Link>
                </div>
            </div>

            <div
                class="rounded-xl border border-sidebar-border/70 bg-white dark:border-sidebar-border dark:bg-neutral-900"
            >
                <div class="border-b px-6 py-4">
                    <h2 class="text-lg font-medium">Recent receipts</h2>
                    <p class="text-sm text-muted-foreground">
                        Posted receipts are immutable. Use reversal to correct
                        an entry while retaining its audit history.
                    </p>
                </div>
                <div
                    v-if="!receipts?.length"
                    class="p-6 text-center text-sm text-muted-foreground"
                >
                    No receipts recorded yet.
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b text-muted-foreground">
                            <tr>
                                <th class="px-6 py-3">Receipt</th>
                                <th class="px-6 py-3">Member</th>
                                <th class="px-6 py-3">Date / Method</th>
                                <th class="px-6 py-3 text-right">Amount</th>
                                <th class="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="receipt in receipts"
                                :key="receipt.id"
                                :class="{ 'opacity-60': receipt.is_reversed }"
                            >
                                <td class="px-6 py-4 font-medium">
                                    #{{ receipt.receipt_number }}
                                    <span
                                        v-if="receipt.is_reversed"
                                        class="ml-2 text-xs text-amber-600"
                                        >Reversed</span
                                    >
                                </td>
                                <td class="px-6 py-4">
                                    {{ receipt.member_name }}
                                    <p class="text-xs text-muted-foreground">
                                        {{ receipt.allocations_count }}
                                        allocation(s)
                                    </p>
                                </td>
                                <td class="px-6 py-4">
                                    {{ receipt.paid_at }} · {{ receipt.method }}
                                    <p
                                        v-if="receipt.reference"
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ receipt.reference }}
                                    </p>
                                </td>
                                <td class="px-6 py-4 text-right font-semibold">
                                    {{ formatCurrency(receipt.total_amount) }}
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <Button size="sm" variant="ghost" as-child>
                                        <a
                                            :href="
                                                PaymentReceiptController({
                                                    payment_batch: receipt.id,
                                                }).url
                                            "
                                        >
                                            <Download class="mr-2 h-4 w-4" />
                                            Receipt
                                        </a>
                                    </Button>
                                    <Button
                                        v-if="receipt.can_reverse"
                                        size="sm"
                                        variant="ghost"
                                        class="text-red-600"
                                        @click="
                                            reversalTarget = receipt;
                                            reversalReason = '';
                                            reversalError = '';
                                        "
                                    >
                                        <RotateCcw class="mr-2 h-4 w-4" />
                                        Reverse
                                    </Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <Dialog
                :open="reversalTarget !== null"
                @update:open="(open) => !open && (reversalTarget = null)"
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle
                            >Reverse receipt #{{
                                reversalTarget?.receipt_number
                            }}</DialogTitle
                        >
                        <DialogDescription>
                            Every allocation in this receipt will be excluded
                            from balances. The receipt itself is retained.
                        </DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-2">
                        <Label for="receipt-reversal-reason">Reason</Label>
                        <textarea
                            id="receipt-reversal-reason"
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
                            @click="reverseReceipt"
                        >
                            {{ reversing ? 'Reversing…' : 'Reverse receipt' }}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    </AppLayout>
</template>
