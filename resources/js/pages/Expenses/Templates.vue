<script setup lang="ts">
import { index as expenseRequests } from '@/actions/App/Http/Controllers/ExpenseRequestController';
import {
    index,
    pause,
    resume,
    store,
} from '@/actions/App/Http/Controllers/ExpenseTemplateController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFinancialRequestKey } from '@/composables/useFinancialRequestKey';
import AppLayout from '@/layouts/AppLayout.vue';
import { useCurrencyFormatter } from '@/lib/currency';
import { Head, Link, useForm } from '@inertiajs/vue3';

interface ExpenseTemplate {
    id: number;
    description: string;
    amount: number;
    category: string;
    day_of_month: number;
    next_run_on: string | null;
}

const props = defineProps<{
    templates: {
        data: ExpenseTemplate[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    categories: { id: number; name: string }[];
    idempotencyKey: string;
    idempotencyScope: string;
}>();

const { formatCurrency } = useCurrencyFormatter();
const key = useFinancialRequestKey(
    props.idempotencyKey,
    props.idempotencyScope,
    'expense template',
);
const form = useForm({
    description: '',
    amount: '',
    expense_category_id: '',
    day_of_month: '1',
    idempotency_key: '',
});
const schedule = useForm({});

function saveTemplate() {
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

function changeSchedule(template: ExpenseTemplate) {
    const action = template.next_run_on === null ? resume : pause;
    schedule.post(action({ expense_template: template.id }).url, {
        preserveScroll: true,
    });
}
</script>

<template>
    <AppLayout
        :breadcrumbs="[
            { title: 'Expense requests', href: expenseRequests().url },
            { title: 'Recurring expense templates', href: index().url },
        ]"
    >
        <Head title="Recurring expense templates" />
        <div class="mx-auto w-full max-w-4xl space-y-6 p-4 md:p-6">
            <header>
                <h1 class="text-2xl font-semibold">
                    Recurring expense templates
                </h1>
                <p class="mt-2 text-muted-foreground">
                    Schedule one draft per month for an officer to review in
                    Expense requests.
                </p>
            </header>
            <form
                class="grid gap-4 rounded-xl border p-4"
                @submit.prevent="saveTemplate"
            >
                <h2 class="text-lg font-semibold">Set up a monthly expense</h2>
                <div class="grid gap-2">
                    <Label for="expense-template-description"
                        >Description</Label
                    >
                    <Input
                        id="expense-template-description"
                        v-model="form.description"
                        required
                        maxlength="1000"
                        :aria-invalid="Boolean(form.errors.description)"
                        :aria-describedby="
                            form.errors.description
                                ? 'expense-template-description-error'
                                : undefined
                        "
                    />
                    <InputError
                        id="expense-template-description-error"
                        :message="form.errors.description"
                    />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="expense-template-amount">Amount</Label>
                        <Input
                            id="expense-template-amount"
                            v-model="form.amount"
                            type="number"
                            min="1"
                            step="1"
                            required
                            :aria-invalid="Boolean(form.errors.amount)"
                            :aria-describedby="
                                form.errors.amount
                                    ? 'expense-template-amount-error'
                                    : undefined
                            "
                        />
                        <InputError
                            id="expense-template-amount-error"
                            :message="form.errors.amount"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="expense-template-category"
                            >Expense category</Label
                        >
                        <select
                            id="expense-template-category"
                            v-model="form.expense_category_id"
                            class="min-h-11 w-full rounded-md border bg-background px-3"
                            :aria-invalid="
                                Boolean(form.errors.expense_category_id)
                            "
                            :aria-describedby="
                                form.errors.expense_category_id
                                    ? 'expense-template-category-error'
                                    : undefined
                            "
                        >
                            <option value="">Uncategorized</option>
                            <option
                                v-for="category in categories"
                                :key="category.id"
                                :value="String(category.id)"
                            >
                                {{ category.name }}
                            </option>
                        </select>
                        <InputError
                            id="expense-template-category-error"
                            :message="form.errors.expense_category_id"
                        />
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label for="expense-template-day">Day of the month</Label>
                    <Input
                        id="expense-template-day"
                        v-model="form.day_of_month"
                        type="number"
                        min="1"
                        max="31"
                        step="1"
                        required
                        :aria-invalid="Boolean(form.errors.day_of_month)"
                        :aria-describedby="
                            form.errors.day_of_month
                                ? 'expense-template-day-help expense-template-day-error'
                                : 'expense-template-day-help'
                        "
                    />
                    <p
                        id="expense-template-day-help"
                        class="text-sm text-muted-foreground"
                    >
                        Shorter months use their last day. Dates use UTC.
                    </p>
                    <InputError
                        id="expense-template-day-error"
                        :message="form.errors.day_of_month"
                    />
                </div>
                <p
                    v-if="form.errors.idempotency_key || key.storageError.value"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ form.errors.idempotency_key || key.storageError.value }}
                </p>
                <p v-if="key.unconfirmed.value" role="status" class="text-sm">
                    A save may have succeeded. Check the templates below before
                    creating another.
                </p>
                <div class="flex flex-wrap gap-2">
                    <Button
                        type="submit"
                        :disabled="form.processing || !key.storageReady.value"
                    >
                        {{ form.processing ? 'Saving…' : 'Save template' }}
                    </Button>
                    <Button
                        v-if="
                            key.unconfirmed.value || form.errors.idempotency_key
                        "
                        type="button"
                        variant="outline"
                        @click="key.startNew()"
                    >
                        Start a new template
                    </Button>
                </div>
            </form>

            <section aria-labelledby="expense-template-list" class="space-y-3">
                <h2 id="expense-template-list" class="text-lg font-semibold">
                    Monthly templates
                </h2>
                <p v-if="!templates.data.length" class="text-muted-foreground">
                    No recurring expense templates yet.
                </p>
                <article
                    v-for="template in templates.data"
                    :key="template.id"
                    class="space-y-3 rounded-xl border p-4"
                >
                    <h3 class="text-base font-semibold">
                        {{ template.description }} ·
                        {{ formatCurrency(template.amount) }}
                    </h3>
                    <p class="text-sm text-muted-foreground">
                        {{ template.category }} · Day
                        {{ template.day_of_month }} of each month
                    </p>
                    <p v-if="template.next_run_on">
                        Next draft
                        <time :datetime="template.next_run_on">{{
                            template.next_run_on
                        }}</time>
                    </p>
                    <p v-else>Paused</p>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="schedule.processing"
                        :aria-label="`${template.next_run_on ? 'Pause' : 'Resume'} ${template.description}`"
                        @click="changeSchedule(template)"
                    >
                        {{ template.next_run_on ? 'Pause' : 'Resume' }}
                    </Button>
                </article>
                <nav
                    v-if="templates.prev_page_url || templates.next_page_url"
                    class="flex flex-wrap gap-4"
                    aria-label="Expense template pages"
                >
                    <Link
                        v-if="templates.prev_page_url"
                        :href="templates.prev_page_url"
                        class="min-h-11 underline"
                        >Previous templates</Link
                    >
                    <Link
                        v-if="templates.next_page_url"
                        :href="templates.next_page_url"
                        class="min-h-11 underline"
                        >Next templates</Link
                    >
                </nav>
            </section>
        </div>
    </AppLayout>
</template>
