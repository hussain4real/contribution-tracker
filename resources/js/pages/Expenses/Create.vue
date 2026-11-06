<script setup lang="ts">
import { index, store } from '@/actions/App/Http/Controllers/ExpenseController';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFinancialRequestKey } from '@/composables/useFinancialRequestKey';
import AppLayout from '@/layouts/AppLayout.vue';
import { useCurrencyFormatter } from '@/lib/currency';
import type { BreadcrumbItem } from '@/types';
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<{
    idempotencyKey: string;
    idempotencyScope: string;
    aiDraft: { amount: number; description: string; spent_at: string } | null;
}>();
const {
    requestKey,
    unconfirmed,
    storageReady,
    storageError,
    options: expenseOptions,
    begin,
    rejected,
    renew,
    startNew,
} = useFinancialRequestKey(
    props.idempotencyKey,
    props.idempotencyScope,
    'expense',
);

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Expenses',
        href: index().url,
    },
    {
        title: 'Record Expense',
        href: '#',
    },
];

const amount = ref<string>(props.aiDraft?.amount.toString() ?? '');
const description = ref<string>(props.aiDraft?.description ?? '');
const spentAt = ref<string>(
    props.aiDraft?.spent_at ?? new Date().toISOString().split('T')[0],
);
const { currency } = useCurrencyFormatter();
</script>

<template>
    <Head title="Record Expense" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-2xl space-y-6 p-4">
            <p
                v-if="aiDraft"
                role="status"
                class="rounded-lg border bg-muted p-3 text-sm"
            >
                AI draft — review these details before recording. Nothing has
                been recorded yet. To record another entry afterward, start from
                the list.
            </p>
            <HeadingSmall
                title="Record Expense"
                description="Record an expense from the family fund."
            />

            <Form
                :action="store()"
                :options="expenseOptions"
                :on-before="begin"
                @error="rejected"
                @success="
                    () => {
                        if (!aiDraft) renew();
                    }
                "
                class="space-y-6"
                #default="{
                    errors,
                    validate,
                    processing,
                    recentlySuccessful,
                    clearErrors,
                }"
            >
                <input
                    type="hidden"
                    name="idempotency_key"
                    :value="requestKey"
                />
                <p v-if="storageError" role="alert">{{ storageError }}</p>
                <p v-if="unconfirmed" role="alert">
                    We could not confirm whether the expense was recorded. Retry
                    with the same details, or check the expense list before
                    starting a new expense.
                </p>
                <div v-if="unconfirmed || errors.idempotency_key" role="alert">
                    <InputError :message="errors.idempotency_key" />
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="processing"
                        @click="if (startNew()) clearErrors('idempotency_key');"
                        >Start a new expense</Button
                    >
                </div>
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
                </div>

                <!-- Description Field -->
                <div class="grid gap-2">
                    <Label for="description">Description</Label>
                    <Input
                        id="description"
                        type="text"
                        name="description"
                        v-model="description"
                        placeholder="e.g., Transport to meeting, Phone calls"
                        required
                        maxlength="1000"
                        @change="validate('description')"
                    />
                    <InputError :message="errors.description" />
                </div>

                <!-- Date Field -->
                <div class="grid gap-2">
                    <Label for="spent_at">Date</Label>
                    <Input
                        id="spent_at"
                        type="date"
                        name="spent_at"
                        v-model="spentAt"
                        required
                    />
                    <InputError :message="errors.spent_at" />
                </div>

                <!-- Submit Button -->
                <div class="flex items-center gap-4">
                    <Button
                        type="submit"
                        :disabled="processing || !storageReady"
                    >
                        {{ processing ? 'Recording...' : 'Record Expense' }}
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
                            Expense recorded successfully.
                        </p>
                    </Transition>
                </div>
            </Form>
        </div>
    </AppLayout>
</template>
