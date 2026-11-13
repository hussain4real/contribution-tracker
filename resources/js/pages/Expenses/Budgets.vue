<script setup lang="ts">
import {
    index,
    store,
    storeCategory,
} from '@/actions/App/Http/Controllers/ExpenseBudgetController';
import { index as expenses } from '@/actions/App/Http/Controllers/ExpenseController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { useCurrencyFormatter } from '@/lib/currency';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
interface BudgetRow {
    category_id: number | null;
    category: string;
    budget: number | null;
    actual: number;
    committed: number;
    remaining: number | null;
    actual_url: string;
    committed_url: string;
}
const props = defineProps<{
    month: string;
    rows: BudgetRow[];
    can_manage: boolean;
}>();
const { formatCurrency, currency } = useCurrencyFormatter();
const selectedMonth = ref(props.month);
const budget = useForm({
    month: props.month,
    expense_category_id: '',
    amount: '',
});
const category = useForm({ name: '' });
const totals = computed(() =>
    props.rows.reduce(
        (sum, row) => ({
            actual: sum.actual + row.actual,
            committed: sum.committed + row.committed,
        }),
        { actual: 0, committed: 0 },
    ),
);
function changeMonth() {
    router.get(index().url, { month: selectedMonth.value });
}
function editBudget(row: BudgetRow) {
    budget.expense_category_id = String(row.category_id);
    budget.amount = row.budget === null ? '' : String(row.budget);
    document.getElementById('budget-amount')?.focus();
}
</script>
<template>
    <AppLayout
        :breadcrumbs="[
            { title: 'Expenses', href: expenses().url },
            { title: 'Budgets', href: index().url },
        ]"
    >
        <Head title="Expense budgets" />
        <div class="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-6">
            <header class="space-y-2">
                <h1 class="text-2xl font-semibold">Expense budgets</h1>
                <p class="text-muted-foreground">
                    Plan monthly spending by category. Budgets are advisory and
                    do not change the family balance.
                </p>
            </header>
            <form
                class="flex flex-wrap items-end gap-3"
                @submit.prevent="changeMonth"
            >
                <div class="grid gap-2">
                    <Label for="budget-month">Month</Label
                    ><Input
                        id="budget-month"
                        v-model="selectedMonth"
                        type="month"
                        required
                    />
                </div>
                <Button type="submit" variant="outline">View month</Button>
            </form>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="rounded-xl border p-4">
                    <p>Actual spending</p>
                    <p class="text-2xl font-semibold">
                        {{ formatCurrency(totals.actual) }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        Posted expenses, excluding reversals.
                    </p>
                </div>
                <div class="rounded-xl border p-4">
                    <p>Committed spending</p>
                    <p class="text-2xl font-semibold">
                        {{ formatCurrency(totals.committed) }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        Approved unpaid requests meeting current approval rules.
                    </p>
                </div>
            </div>
            <p class="text-sm text-muted-foreground">
                Amounts use the expense date within {{ month }}. Remaining
                budget subtracts both actual and committed spending.
                Uncategorized expenses have no budget limit.
            </p>
            <div class="grid gap-4 md:grid-cols-2">
                <article
                    v-for="row in rows"
                    :key="row.category_id ?? 'uncategorized'"
                    class="space-y-3 rounded-xl border p-4"
                >
                    <h2 class="text-lg font-semibold">{{ row.category }}</h2>
                    <dl class="grid grid-cols-2 gap-2 text-sm">
                        <dt>Monthly budget</dt>
                        <dd>
                            {{
                                row.budget === null
                                    ? 'Not set'
                                    : formatCurrency(row.budget)
                            }}
                        </dd>
                        <dt>Actual</dt>
                        <dd>
                            <Link
                                :href="row.actual_url"
                                :aria-label="`View ${row.category} actual spending`"
                                class="inline-flex min-h-11 items-center underline"
                                >{{ formatCurrency(row.actual) }}</Link
                            >
                        </dd>
                        <dt>Committed</dt>
                        <dd>
                            <Link
                                :href="row.committed_url"
                                :aria-label="`View ${row.category} committed spending`"
                                class="inline-flex min-h-11 items-center underline"
                                >{{ formatCurrency(row.committed) }}</Link
                            >
                        </dd>
                        <dt>Remaining</dt>
                        <dd>
                            {{
                                row.remaining === null
                                    ? 'No limit set'
                                    : formatCurrency(row.remaining)
                            }}
                        </dd>
                    </dl>
                    <p
                        v-if="row.remaining !== null && row.remaining < 0"
                        role="status"
                        class="font-medium text-destructive"
                    >
                        Over budget by {{ formatCurrency(-row.remaining) }}.
                        Advisory only.
                    </p>
                    <Button
                        v-if="can_manage && row.category_id !== null"
                        variant="outline"
                        @click="editBudget(row)"
                        >Set {{ row.category }} budget</Button
                    >
                </article>
            </div>
            <div v-if="can_manage" class="grid gap-6 md:grid-cols-2">
                <form
                    class="space-y-4 rounded-xl border p-4"
                    @submit.prevent="
                        budget.post(store().url, { preserveScroll: true })
                    "
                >
                    <h2 class="text-lg font-semibold">Set monthly budget</h2>
                    <p class="text-sm text-muted-foreground">
                        For {{ month }} only. Saving replaces this category’s
                        existing limit. Zero means no planned spending.
                    </p>
                    <div class="grid gap-2">
                        <Label for="budget-category">Category</Label
                        ><select
                            id="budget-category"
                            v-model="budget.expense_category_id"
                            required
                            class="min-h-11 w-full rounded-md border bg-background px-3"
                        >
                            <option value="" disabled>Select category</option>
                            <option
                                v-for="row in rows.filter(
                                    (item) => item.category_id !== null,
                                )"
                                :key="row.category_id!"
                                :value="String(row.category_id)"
                            >
                                {{ row.category }}
                            </option></select
                        ><InputError
                            :message="budget.errors.expense_category_id"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="budget-amount"
                            >Budget amount ({{ currency }})</Label
                        ><Input
                            id="budget-amount"
                            v-model="budget.amount"
                            type="number"
                            min="0"
                            max="999999999"
                            step="1"
                            required
                        /><InputError :message="budget.errors.amount" />
                    </div>
                    <InputError :message="budget.errors.month" />
                    <Button type="submit" :disabled="budget.processing">{{
                        budget.processing ? 'Saving…' : 'Save budget'
                    }}</Button>
                    <p v-if="budget.recentlySuccessful" role="status">
                        Monthly budget saved.
                    </p>
                </form>
                <form
                    class="space-y-4 rounded-xl border p-4"
                    @submit.prevent="
                        category.post(storeCategory().url, {
                            preserveScroll: true,
                            onSuccess: () => category.reset(),
                        })
                    "
                >
                    <h2 class="text-lg font-semibold">Add expense category</h2>
                    <div class="grid gap-2">
                        <Label for="category-name">Category name</Label
                        ><Input
                            id="category-name"
                            v-model="category.name"
                            maxlength="80"
                            required
                            placeholder="e.g. Welfare"
                        /><InputError :message="category.errors.name" />
                    </div>
                    <Button
                        type="submit"
                        variant="outline"
                        :disabled="category.processing"
                        >Add category</Button
                    >
                </form>
            </div>
        </div>
    </AppLayout>
</template>
