<script setup lang="ts">
import {
    index as paymentsIndex,
    review,
    store,
} from '@/actions/App/Http/Controllers/PaymentController';
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
import { useCurrencyFormatter } from '@/lib/currency';
import { dashboard } from '@/routes';
import type { AppPageProps, BreadcrumbItem } from '@/types';
import type { FormComponentOptions, VisitOptions } from '@inertiajs/core';
import { Form, Head, Link, useHttp, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref, watch } from 'vue';

interface Member {
    id: number;
    name: string;
    email: string;
    category: string | null;
    category_label: string | null;
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
    value: number;
    label: string;
}

interface PaymentMethod {
    value: string;
    label: string;
}

interface Props {
    member: Member;
    pending_contributions: PendingContribution[];
    category_amount: number;
    formatted_amount: string;
    categories: Category[];
    paymentMethods: PaymentMethod[];
    idempotencyKey: string;
    aiDraft: {
        amount: number;
        paid_at: string;
        notes: string | null;
        target_year: number | null;
        target_month: number | null;
    } | null;
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
const amount = ref<string>(props.aiDraft?.amount.toString() ?? '');
const selectedMonth = ref<string>(
    props.aiDraft?.target_year && props.aiDraft.target_month
        ? `${props.aiDraft.target_year}-${String(props.aiDraft.target_month).padStart(2, '0')}`
        : '',
);
const paidAt = ref<string>(
    props.aiDraft?.paid_at ?? new Date().toISOString().split('T')[0],
);
const notes = ref<string>(props.aiDraft?.notes ?? '');
const method = ref<string>(props.paymentMethods[0]?.value ?? 'cash');
const reference = ref<string>('');
const { currency, formatCurrency: formatAmount } = useCurrencyFormatter();

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

interface ReviewResult {
    allocations: {
        year: number;
        month: number;
        period: string;
        amount: number;
    }[];
    outstanding: number;
    advance: number;
    possible_duplicate: boolean;
    token: string;
}

const page = usePage<AppPageProps>();
const requestKey = ref(props.idempotencyKey);
const storageReady = ref(false);
const storageError = ref('');
const paymentUnconfirmed = ref(false);
const paymentResponsePending = ref(false);
let unconfirmedBeforeSubmit = false;
const storageKey =
    page.props.family?.id && page.props.auth.user?.id
        ? `familyfund:payment:${page.props.family.id}:${page.props.auth.user.id}:${props.member.id}${props.aiDraft ? `:${props.idempotencyKey}` : ''}`
        : null;

function saveRequestKey(unconfirmed: boolean): boolean {
    if (!storageKey) return false;
    try {
        sessionStorage.setItem(
            storageKey,
            `${requestKey.value}|${unconfirmed ? '1' : '0'}`,
        );
        storageError.value = '';
        return true;
    } catch {
        storageError.value =
            'Payment review requires session storage to protect against duplicate recording. Please enable it and reload this page.';
        return false;
    }
}

onMounted(() => {
    if (!storageKey) {
        storageError.value =
            'Could not establish this payment session. Reload and try again.';
        return;
    }
    try {
        const saved = sessionStorage.getItem(storageKey);
        if (saved) {
            const [key, state] = saved.split('|');
            if (
                key &&
                /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(
                    key,
                ) &&
                (state === '0' || state === '1')
            ) {
                requestKey.value = key;
                paymentUnconfirmed.value = state === '1';
            }
        }
        storageReady.value = saveRequestKey(paymentUnconfirmed.value);
    } catch {
        storageError.value =
            'Payment review requires session storage to protect against duplicate recording. Please enable it and reload this page.';
    }
});

function beginPayment(): boolean {
    if (!reviewed.value || !storageReady.value || !saveRequestKey(true))
        return false;
    unconfirmedBeforeSubmit = paymentUnconfirmed.value;
    paymentResponsePending.value = true;
    paymentUnconfirmed.value = true;
    return true;
}

function paymentSucceeded(): void {
    paymentResponsePending.value = false;
    paymentUnconfirmed.value = false;
    if (storageKey) {
        try {
            sessionStorage.removeItem(storageKey);
        } catch {
            storageReady.value = false;
        }
    }
}

function paymentRejected(): void {
    paymentResponsePending.value = false;
    reviewed.value = null;
    paymentUnconfirmed.value = unconfirmedBeforeSubmit;
    storageReady.value = saveRequestKey(paymentUnconfirmed.value);
}

function startNewPayment(): void {
    if (props.aiDraft) {
        window.location.assign(paymentsIndex().url);
        return;
    }
    if (
        !window.confirm(
            'Have you checked Payments for the earlier receipt? Starting a new payment can create another receipt.',
        )
    )
        return;
    requestKey.value = crypto.randomUUID();
    paymentUnconfirmed.value = false;
    reviewed.value = null;
    storageReady.value = saveRequestKey(false);
}

const reviewRequest = useHttp<
    {
        member_id: number;
        amount: string;
        paid_at: string;
        method: string;
        reference: string | null;
        notes: string | null;
        target_year: number | null;
        target_month: number | null;
        idempotency_key: string;
    },
    ReviewResult
>({
    member_id: props.member.id,
    amount: '',
    paid_at: '',
    method: '',
    reference: null,
    notes: null,
    target_year: null,
    target_month: null,
    idempotency_key: requestKey.value,
});
const reviewed = ref<ReviewResult | null>(null);
const reviewHeading = ref<HTMLHeadingElement | null>(null);
const reviewError = ref('');
const paymentOptions: FormComponentOptions &
    Pick<VisitOptions, 'onHttpException' | 'onNetworkError'> = {
    onHttpException: (response) => (response.status >= 500 ? false : undefined),
    onNetworkError: () => false,
};
const reviewInputs = () => ({
    member_id: props.member.id,
    amount: amount.value,
    paid_at: paidAt.value,
    method: method.value,
    reference: reference.value.trim() || null,
    notes: notes.value.trim() || null,
    target_year: targetYear.value,
    target_month: targetMonth.value,
    idempotency_key: requestKey.value,
});
watch([amount, paidAt, method, reference, notes, selectedMonth], () => {
    reviewed.value = null;
    reviewError.value = '';
});

async function reviewPayment(): Promise<void> {
    const input = reviewInputs();
    const fingerprint = JSON.stringify(input);
    Object.assign(reviewRequest, input);
    reviewError.value = '';
    try {
        const result = await reviewRequest.post(review.url());
        if (fingerprint === JSON.stringify(reviewInputs())) {
            reviewed.value = result;
            await nextTick();
            reviewHeading.value?.focus();
        }
    } catch {
        reviewError.value =
            Object.values(reviewRequest.errors)
                .flat()
                .find((error) => typeof error === 'string') ??
            'Could not review this payment. Check the details and try again.';
    }
}
</script>

<template>
    <Head :title="`Record Payment - ${member.name}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-2xl space-y-6 p-4">
            <p
                v-if="aiDraft"
                role="status"
                class="rounded-lg border bg-muted p-3 text-sm"
            >
                AI draft — check the member, date and amount, then review the
                allocation before recording. Nothing has been recorded yet.
            </p>
            <HeadingSmall
                :title="`Record Payment for ${member.name}`"
                :description="`${member.category_label ?? member.category ?? 'Contribution'} - ${formatted_amount}/month`"
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
                :action="store()"
                class="space-y-6"
                :options="paymentOptions"
                :on-before="beginPayment"
                @success="paymentSucceeded"
                @error="paymentRejected"
                @finish="
                    if (paymentResponsePending) paymentUnconfirmed = true;
                    paymentResponsePending = false;
                "
                #default="{
                    errors,
                    validate,
                    validating,
                    processing,
                    recentlySuccessful,
                }"
            >
                <input type="hidden" name="member_id" :value="member.id" />
                <input
                    v-if="reviewed"
                    type="hidden"
                    name="review_token"
                    :value="reviewed.token"
                />
                <input
                    type="hidden"
                    name="idempotency_key"
                    :value="requestKey"
                />
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
                    <Label for="amount">Amount ({{ currency }})</Label>
                    <Input
                        id="amount"
                        type="number"
                        name="amount"
                        v-model="amount"
                        :placeholder="`Enter amount in ${currency}`"
                        required
                        min="1"
                        step="1"
                        @change="validate('amount')"
                    />
                    <InputError :message="errors.amount" />
                    <p class="text-xs text-muted-foreground">
                        Monthly contribution: {{ formatted_amount }}
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
                <div v-if="pending_contributions.length > 0" class="grid gap-2">
                    <Label for="target_month">Target Month (Optional)</Label>
                    <Select v-model="selectedMonth">
                        <SelectTrigger>
                            <SelectValue
                                placeholder="Auto-allocate to oldest unpaid month"
                            />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="contribution in pending_contributions"
                                :key="`${contribution.year}-${contribution.month}`"
                                :value="`${contribution.year}-${contribution.month}`"
                            >
                                {{ contribution.period_label }} —
                                {{ formatAmount(contribution.balance) }}
                                remaining
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="errors.target_month" />
                    <p class="text-xs text-muted-foreground">
                        Leave empty to auto-allocate to the oldest unpaid month.
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

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="method">Payment Method</Label>
                        <select
                            id="method"
                            v-model="method"
                            name="method"
                            required
                            class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm"
                        >
                            <option
                                v-for="paymentMethod in paymentMethods"
                                :key="paymentMethod.value"
                                :value="paymentMethod.value"
                            >
                                {{ paymentMethod.label }}
                            </option>
                        </select>
                        <InputError :message="errors.method" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="reference">Reference (Optional)</Label>
                        <Input
                            id="reference"
                            v-model="reference"
                            name="reference"
                            maxlength="255"
                            placeholder="Bank or receipt reference"
                        />
                        <InputError :message="errors.reference" />
                    </div>
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
                        @change="validate('notes')"
                    />

                    <p
                        role="status"
                        class="min-h-4 text-xs text-muted-foreground"
                    >
                        {{ validating ? 'Validating...' : '' }}
                    </p>
                    <InputError :message="errors.notes" />
                </div>

                <!-- Submit Button -->
                <section
                    v-if="reviewed"
                    class="rounded-lg border p-4"
                    aria-labelledby="review-heading"
                >
                    <h2
                        id="review-heading"
                        ref="reviewHeading"
                        tabindex="-1"
                        class="font-semibold"
                    >
                        Review payment
                    </h2>
                    <p class="mt-2 text-sm">
                        {{ member.name }} · {{ formatAmount(Number(amount)) }} ·
                        {{
                            paymentMethods.find((item) => item.value === method)
                                ?.label
                        }}
                        · {{ paidAt }}
                    </p>
                    <ul class="mt-3 space-y-1 text-sm">
                        <li
                            v-for="allocation in reviewed.allocations"
                            :key="`${allocation.year}-${allocation.month}`"
                        >
                            {{ allocation.period }}:
                            {{ formatAmount(allocation.amount) }}
                        </li>
                    </ul>
                    <p
                        v-if="reviewed.advance > 0"
                        class="mt-3 text-sm text-amber-700 dark:text-amber-300"
                    >
                        {{ formatAmount(reviewed.advance) }} exceeds recorded
                        outstanding contributions and will be applied in
                        advance.
                    </p>
                    <p
                        v-if="reviewed.possible_duplicate"
                        class="mt-3 text-sm text-amber-700 dark:text-amber-300"
                    >
                        This reference is already used by another receipt. Check
                        for a duplicate before recording.
                    </p>
                </section>
                <p v-if="reviewError" role="alert" class="text-sm text-red-600">
                    {{ reviewError }}
                </p>
                <InputError :message="errors.review" />
                <p
                    v-if="storageError"
                    role="alert"
                    class="text-sm text-red-600"
                >
                    {{ storageError }}
                </p>
                <p
                    v-if="paymentUnconfirmed"
                    role="alert"
                    class="text-sm text-red-600"
                >
                    We couldn't confirm whether this payment was recorded.
                    <Link :href="paymentsIndex().url" class="underline">
                        Check Payments for the receipt
                    </Link>
                    before recording another payment.
                </p>
                <Button
                    v-if="paymentUnconfirmed"
                    type="button"
                    variant="outline"
                    :disabled="processing"
                    @click="startNewPayment"
                >
                    Start a new payment
                </Button>
                <div class="flex items-center gap-4">
                    <Button
                        v-if="!reviewed"
                        type="button"
                        :disabled="reviewRequest.processing || !storageReady"
                        @click="reviewPayment"
                        >{{
                            reviewRequest.processing
                                ? 'Preparing review...'
                                : 'Review payment'
                        }}</Button
                    >
                    <Button
                        v-else
                        type="submit"
                        :disabled="processing || !storageReady"
                    >
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
