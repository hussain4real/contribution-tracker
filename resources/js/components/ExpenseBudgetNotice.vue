<script setup lang="ts">
import { useCurrencyFormatter } from '@/lib/currency';
import { Link } from '@inertiajs/vue3';
export interface BudgetAdvisory {
    budget: number;
    projected: number;
    over_by: number;
    month: string;
    url: string;
}
defineProps<{ advisory: BudgetAdvisory | null }>();
const { formatCurrency } = useCurrencyFormatter();
</script>
<template>
    <div
        v-if="advisory"
        role="status"
        class="space-y-1 rounded-lg border bg-muted p-3 text-sm"
    >
        <p v-if="advisory.over_by > 0" class="font-semibold text-destructive">
            Over monthly budget by {{ formatCurrency(advisory.over_by) }}
        </p>
        <p>
            {{ advisory.month }} projected spending
            {{ formatCurrency(advisory.projected) }} against
            {{ formatCurrency(advisory.budget) }} budget.
        </p>
        <p>
            Includes actual spending and approved unpaid requests. Advisory
            only; existing approval rules still apply.
        </p>
        <Link
            :href="advisory.url"
            class="inline-flex min-h-11 items-center underline"
            >View monthly budget</Link
        >
    </div>
</template>
