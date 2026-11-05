<script setup lang="ts">
import { index } from '@/actions/App/Http/Controllers/FamilyActivityController';
import { useCurrencyFormatter } from '@/lib/currency';
import { Link } from '@inertiajs/vue3';

export interface FinancialOverview {
    period: string;
    received: number;
    expenses: number;
    adjustments: number;
    opening_balance: number;
    closing_balance: number;
    outstanding: number;
}

defineProps<{ overview: FinancialOverview }>();
const { formatCurrency } = useCurrencyFormatter();
</script>

<template>
    <section aria-labelledby="financial-overview-title" class="space-y-3">
        <h2 id="financial-overview-title" class="text-lg font-semibold">
            Money movement · {{ overview.period }}
        </h2>
        <div class="grid gap-3 sm:grid-cols-3">
            <Link
                v-for="item in [
                    {
                        title: 'Contributions received',
                        value: overview.received,
                        type: 'payment',
                    },
                    {
                        title: 'Expenses',
                        value: overview.expenses,
                        type: 'expense',
                    },
                    {
                        title: 'Closing balance',
                        value: overview.closing_balance,
                        type: 'all',
                    },
                ]"
                :key="item.type"
                :href="
                    index(
                        {},
                        {
                            query: { period: overview.period, type: item.type },
                        },
                    ).url
                "
                class="rounded-xl border p-4 hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2"
            >
                <p class="text-sm text-muted-foreground">{{ item.title }}</p>
                <p class="mt-1 text-2xl font-semibold">
                    {{ formatCurrency(item.value) }}
                </p>
            </Link>
        </div>
        <p class="text-sm text-muted-foreground">
            Opening balance {{ formatCurrency(overview.opening_balance) }} +
            received {{ formatCurrency(overview.received) }} + adjustments
            {{ formatCurrency(overview.adjustments) }} − expenses
            {{ formatCurrency(overview.expenses) }} = closing balance
            {{ formatCurrency(overview.closing_balance) }}. Uses transaction
            dates and excludes reversed entries.
        </p>
        <p class="text-sm">
            Contributions for {{ overview.period }} still outstanding
            {{ formatCurrency(overview.outstanding) }}.
        </p>
        <Link
            :href="index({}, { query: { period: overview.period } }).url"
            class="inline-flex min-h-11 items-center underline"
        >
            View transactions and activity
        </Link>
    </section>
</template>
