<script setup lang="ts">
import StatusBadge from '@/components/contributions/StatusBadge.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { store } from '@/actions/App/Http/Controllers/PaymentController';
import { dashboard } from '@/routes';
import { Form, Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Member {
    id: number;
    name: string;
    email: string;
    category: string;
}

interface AvailableMonth {
    year: number;
    month: number;
    label: string;
}

interface PendingContribution {
    id: number;
    year: number;
    month: number;
    expected_amount: number;
    total_paid: number;
    balance: number;
    status: 'paid' | 'partial' | 'unpaid' | 'overdue';
    period_label: string;
}

interface Category {
    value: string;
    label: string;
}

interface Props {
    member: Member;
    available_months: AvailableMonth[];
    pending_contributions: PendingContribution[];
    category_amount: number;
    formatted_amount: string;
    categories: Category[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard().url,
    },
    {
        title: 'Record Payment',
        href: '#',
    },
];

// Form values
const amount = ref<string>('');
const selectedMonth = ref<string>('');
const paidAt = ref<string>(new Date().toISOString().split('T')[0]);
const notes = ref<string>('');

// Helper to format amount in Naira
const formatAmount = (kobo: number): string => {
    return `₦${(kobo / 100).toLocaleString('en-NG', { minimumFractionDigits: 2 })}`;
};

// Quick amount buttons
const quickAmounts = computed(() => [
    { label: '1 Month', value: props.category_amount },
    { label: '2 Months', value: props.category_amount * 2 },
    { label: '3 Months', value: props.category_amount * 3 },
    { label: '6 Months', value: props.category_amount * 6 },
]);

const setQuickAmount = (value: number) => {
    amount.value = value.toString();
};

// Parse selected month
const targetYear = computed(() => {
    if (!selectedMonth.value) return null;
    const [year] = selectedMonth.value.split('-');
    return parseInt(year);
});

const targetMonth = computed(() => {
    if (!selectedMonth.value) return null;
    const [, month] = selectedMonth.value.split('-');
    return parseInt(month);
});
</script>

<template>
    <Head :title="`Record Payment - ${member.name}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-2xl space-y-6 p-4">
            <HeadingSmall
                :title="`Record Payment for ${member.name}`"
                :description="`${member.category} category - ${formatted_amount}/month`"
            />

            <!-- Pending Contributions Summary -->
            <div
                v-if="pending_contributions.length > 0"
                class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950"
            >
                <h3
                    class="mb-2 text-sm font-medium text-amber-800 dark:text-amber-200"
                >
                    Pending Contributions
                </h3>
                <div class="space-y-2">
                    <div
                        v-for="contribution in pending_contributions"
                        :key="contribution.id"
                        class="flex items-center justify-between text-sm"
                    >
                        <span class="text-amber-700 dark:text-amber-300">
                            {{ contribution.period_label }}
                        </span>
                        <div class="flex items-center gap-2">
                            <StatusBadge :status="contribution.status" />
                            <span
                                class="font-medium text-amber-900 dark:text-amber-100"
                            >
                                {{ formatAmount(contribution.balance) }}
                                remaining
                            </span>
                        </div>
                    </div>
                </div>
                <p class="mt-3 text-xs text-amber-600 dark:text-amber-400">
                    Payments will automatically be applied to the oldest
                    incomplete month first.
                </p>
            </div>

            <!-- Payment Form -->
            <Form
                v-bind="store.form()"
                class="space-y-6"
                v-slot="{ errors, processing, recentlySuccessful }"
            >
                <input type="hidden" name="member_id" :value="member.id" />
                <input
                    v-if="targetYear"
                    type="hidden"
                    name="target_year"
                    :value="targetYear"
                />
                <input
                    v-if="targetMonth"
                    type="hidden"
                    name="target_month"
                    :value="targetMonth"
                />

                <!-- Amount Field -->
                <div class="grid gap-2">
                    <Label for="amount">Amount (₦)</Label>
                    <Input
                        id="amount"
                        type="number"
                        name="amount"
                        v-model="amount"
                        placeholder="Enter amount in kobo"
                        required
                        min="1"
                    />
                    <InputError :message="errors.amount" />
                    <p class="text-xs text-muted-foreground">
                        Amount is in kobo. ₦1 = 100 kobo. For example,
                        {{ category_amount }} kobo = {{ formatted_amount }}
                    </p>
                </div>

                <!-- Quick Amount Buttons -->
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-for="quick in quickAmounts"
                        :key="quick.value"
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="setQuickAmount(quick.value)"
                        :class="{
                            'ring-2 ring-primary':
                                amount === quick.value.toString(),
                        }"
                    >
                        {{ quick.label }} ({{ formatAmount(quick.value) }})
                    </Button>
                </div>

                <!-- Target Month Field -->
                <div class="grid gap-2">
                    <Label for="target_month">Target Month (Optional)</Label>
                    <Select v-model="selectedMonth">
                        <SelectTrigger>
                            <SelectValue
                                placeholder="Select target month (or leave empty for current)"
                            />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="">Current Month</SelectItem>
                            <SelectItem
                                v-for="month in available_months"
                                :key="`${month.year}-${month.month}`"
                                :value="`${month.year}-${month.month}`"
                            >
                                {{ month.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="errors.target_month" />
                    <p class="text-xs text-muted-foreground">
                        Leave empty to pay for current month, or select a future
                        month (up to 6 months ahead).
                    </p>
                </div>

                <!-- Payment Date Field -->
                <div class="grid gap-2">
                    <Label for="paid_at">Payment Date</Label>
                    <Input
                        id="paid_at"
                        type="date"
                        name="paid_at"
                        v-model="paidAt"
                        required
                    />
                    <InputError :message="errors.paid_at" />
                </div>

                <!-- Notes Field -->
                <div class="grid gap-2">
                    <Label for="notes">Notes (Optional)</Label>
                    <Input
                        id="notes"
                        type="text"
                        name="notes"
                        v-model="notes"
                        placeholder="e.g., Cash payment, Bank transfer ref: ABC123"
                        maxlength="500"
                    />
                    <InputError :message="errors.notes" />
                </div>

                <!-- Submit Button -->
                <div class="flex items-center gap-4">
                    <Button type="submit" :disabled="processing">
                        {{ processing ? 'Recording...' : 'Record Payment' }}
                    </Button>

                    <Transition
                        enter-active-class="transition ease-in-out"
                        enter-from-class="opacity-0"
                        leave-active-class="transition ease-in-out"
                        leave-to-class="opacity-0"
                    >
                        <p
                            v-show="recentlySuccessful"
                            class="text-sm text-green-600"
                        >
                            Payment recorded successfully.
                        </p>
                    </Transition>
                </div>
            </Form>
        </div>
    </AppLayout>
</template>
