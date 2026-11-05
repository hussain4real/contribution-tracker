<script setup lang="ts">
import { index as expenses } from '@/actions/App/Http/Controllers/ExpenseController';
import {
    decide,
    index,
    pay,
    store,
} from '@/actions/App/Http/Controllers/ExpenseRequestController';
import ExpenseDocuments from '@/components/ExpenseDocuments.vue';
import { useFinancialRequestKey } from '@/composables/useFinancialRequestKey';
import AppLayout from '@/layouts/AppLayout.vue';
import { useCurrencyFormatter } from '@/lib/currency';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
interface ExpenseRequest {
    id: number;
    amount: number;
    description: string;
    spent_at: string;
    requester: string;
    status: string;
    required: number;
    approved: number;
    can_decide: boolean;
    can_pay: boolean;
    expense_id: number | null;
    is_reversed: boolean;
    decisions: { by: string; decision: string; reason: string | null }[];
    documents: { id: number; filename: string }[];
}
const props = defineProps<{
    requests: {
        data: ExpenseRequest[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    can_submit: boolean;
    idempotencyKey: string;
    idempotencyScope: string;
}>();
const { formatCurrency } = useCurrencyFormatter();
const key = useFinancialRequestKey(
    props.idempotencyKey,
    props.idempotencyScope,
    'expense request',
);
const form = useForm({
    amount: '',
    description: '',
    spent_at: new Date().toLocaleDateString('en-CA'),
    idempotency_key: '',
});
const reasons = ref<Record<number, string>>({});
const error = ref('');
const processing = ref(false);
function submit() {
    if (!key.begin()) return;
    form.idempotency_key = key.requestKey.value;
    form.post(store().url, {
        ...key.options,
        preserveScroll: true,
        onError: () => key.rejected(),
        onSuccess: () => {
            key.renew();
            form.reset();
        },
    });
}
function decision(request: ExpenseRequest, value: 'approved' | 'rejected') {
    router.post(
        decide({ expense_request: request.id }).url,
        {
            decision: value,
            reason:
                value === 'rejected' ? (reasons.value[request.id] ?? '') : null,
        },
        {
            preserveScroll: true,
            onStart: () => {
                processing.value = true;
                error.value = '';
            },
            onError: (errors) => {
                error.value = Object.values(errors).join(' ');
            },
            onFinish: () => {
                processing.value = false;
            },
        },
    );
}
function recordPayment(request: ExpenseRequest) {
    if (
        !window.confirm(
            'Record ' +
                formatCurrency(request.amount) +
                ' as paid? This will reduce the family balance.',
        )
    )
        return;
    router.post(
        pay({ expense_request: request.id }).url,
        {},
        {
            preserveScroll: true,
            onStart: () => {
                processing.value = true;
                error.value = '';
            },
            onError: (errors) => {
                error.value = Object.values(errors).join(' ');
            },
            onFinish: () => {
                processing.value = false;
            },
        },
    );
}
</script>
<template>
    <Head title="Expense requests" />
    <AppLayout
        :breadcrumbs="[{ title: 'Expense requests', href: index().url }]"
    >
        <div class="mx-auto w-full max-w-4xl space-y-6 p-4 md:p-6">
            <header>
                <h1 class="text-2xl font-semibold">Expense requests</h1>
                <Link
                    :href="expenses().url"
                    class="inline-flex min-h-11 items-center underline"
                    >Posted expenses</Link
                >
            </header>
            <p class="text-muted-foreground">
                Requests and approvals do not change the balance. Record payment
                after approval when the money has been spent.
            </p>
            <form
                v-if="can_submit"
                class="grid gap-3 rounded-xl border p-4"
                @submit.prevent="submit"
            >
                <h2 class="text-lg font-semibold">Submit an expense request</h2>
                <label
                    >Amount<input
                        v-model="form.amount"
                        type="number"
                        min="1"
                        step="1"
                        required
                        class="block min-h-11 w-full rounded-md border bg-background px-3"
                /></label>
                <label
                    >Description<input
                        v-model="form.description"
                        maxlength="1000"
                        required
                        class="block min-h-11 w-full rounded-md border bg-background px-3"
                /></label>
                <label
                    >Expense date<input
                        v-model="form.spent_at"
                        type="date"
                        required
                        class="block min-h-11 rounded-md border bg-background px-3"
                /></label>
                <p
                    v-for="message in form.errors"
                    :key="message"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ message }}
                </p>
                <p v-if="key.storageError.value" role="alert">
                    {{ key.storageError.value }}
                </p>
                <p v-if="key.unconfirmed.value" role="status">
                    A submission may already have succeeded. Check the requests
                    below before retrying.
                </p>
                <button
                    :disabled="form.processing || !key.storageReady.value"
                    class="min-h-11 justify-self-start rounded-md border px-4"
                >
                    {{ form.processing ? 'Submitting…' : 'Submit request' }}
                </button>
                <button
                    v-if="key.unconfirmed.value || form.errors.idempotency_key"
                    type="button"
                    class="min-h-11 rounded-md border px-3"
                    @click="key.startNew()"
                >
                    Start a new request
                </button>
            </form>
            <p v-else class="text-sm text-muted-foreground">
                Submitting new requests requires an active Organization plan.
                Existing requests can still be reviewed.
            </p>
            <p v-if="error" role="alert" class="text-destructive">
                {{ error }}
            </p>
            <p v-if="!requests.data.length">No expense requests yet.</p>
            <article
                v-for="request in requests.data"
                :key="request.id"
                class="space-y-3 rounded-xl border p-4"
            >
                <h2 class="text-lg font-semibold">
                    {{ request.description }} ·
                    {{ formatCurrency(request.amount) }}
                </h2>
                <p>
                    {{ request.requester }} · {{ request.spent_at }} ·
                    {{ request.status
                    }}{{ request.is_reversed ? ' · Payment reversed' : '' }}
                </p>
                <p>
                    {{ request.approved }} of {{ request.required }} independent
                    approvals
                </p>
                <ul>
                    <li v-for="(entry, i) in request.decisions" :key="i">
                        {{ entry.by }} · {{ entry.decision
                        }}{{ entry.reason ? ' · ' + entry.reason : '' }}
                    </li>
                </ul>
                <div v-if="request.can_decide" class="space-y-2">
                    <button
                        class="min-h-11 rounded-md border px-4"
                        :disabled="processing"
                        :aria-label="'Approve expense request ' + request.id"
                        @click="decision(request, 'approved')"
                    >
                        Approve request #{{ request.id }}
                    </button>
                    <label class="block text-sm"
                        >Reason for rejection<input
                            v-model="reasons[request.id]"
                            maxlength="1000"
                            class="block min-h-11 w-full rounded-md border bg-background px-3"
                    /></label>
                    <button
                        class="min-h-11 rounded-md border px-4"
                        :disabled="processing || !reasons[request.id]?.trim()"
                        @click="decision(request, 'rejected')"
                    >
                        Reject request #{{ request.id }}
                    </button>
                </div>
                <button
                    v-if="request.can_pay"
                    class="min-h-11 rounded-md border px-4"
                    :disabled="processing"
                    :aria-label="
                        'Record payment for expense request ' + request.id
                    "
                    @click="recordPayment(request)"
                >
                    Record payment for request #{{ request.id }}
                </button>
                <p v-if="request.expense_id">
                    Posted expense #{{ request.expense_id }}
                </p>
                <ExpenseDocuments
                    :request-id="request.id"
                    :documents="request.documents"
                    :can-upload="true"
                />
            </article>
            <nav class="flex gap-4" aria-label="Expense request pages">
                <Link
                    v-if="requests.prev_page_url"
                    :href="requests.prev_page_url"
                    class="min-h-11 underline"
                    >Previous requests</Link
                >
                <Link
                    v-if="requests.next_page_url"
                    :href="requests.next_page_url"
                    class="min-h-11 underline"
                    >Next requests</Link
                >
            </nav>
        </div>
    </AppLayout>
</template>
