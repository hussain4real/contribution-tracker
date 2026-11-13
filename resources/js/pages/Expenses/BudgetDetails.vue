<script setup lang="ts">
import { index } from '@/actions/App/Http/Controllers/ExpenseBudgetController';
import AppLayout from '@/layouts/AppLayout.vue';
import { useCurrencyFormatter } from '@/lib/currency';
import { Head, Link } from '@inertiajs/vue3';
defineProps<{
    category: string;
    month: string;
    kind: 'actual' | 'committed';
    total: number;
    entries: {
        data: {
            id: number;
            description: string;
            amount: number;
            spent_at: string;
            url: string;
        }[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
}>();
const { formatCurrency } = useCurrencyFormatter();
</script>
<template>
    <AppLayout
        :breadcrumbs="[
            {
                title: 'Expense budgets',
                href: index({}, { query: { month } }).url,
            },
        ]"
    >
        <Head title="Budget spending details" />
        <div class="mx-auto w-full max-w-3xl space-y-5 p-4 md:p-6">
            <h1 class="text-2xl font-semibold">
                {{ category }} ·
                {{
                    kind === 'actual' ? 'Actual spending' : 'Committed spending'
                }}
            </h1>
            <p>{{ month }} · Total {{ formatCurrency(total) }}</p>
            <p class="text-sm text-muted-foreground">
                {{
                    kind === 'actual'
                        ? 'Effective posted expenses. Reversed entries are excluded.'
                        : 'Approved requests awaiting payment and meeting current approval rules. These do not reduce the fund balance.'
                }}
            </p>
            <p v-if="!entries.data.length">
                No spending entries in this category and month.
            </p>
            <article
                v-for="entry in entries.data"
                :key="entry.id"
                class="space-y-2 rounded-xl border p-4"
            >
                <h2 class="font-semibold">{{ entry.description }}</h2>
                <p>{{ entry.spent_at }} · {{ formatCurrency(entry.amount) }}</p>
                <Link
                    :href="entry.url"
                    class="inline-flex min-h-11 items-center underline"
                    >View {{ kind === 'actual' ? 'expense' : 'request' }} #{{
                        entry.id
                    }}</Link
                >
            </article>
            <nav aria-label="Spending pages" class="flex gap-4">
                <Link
                    v-if="entries.prev_page_url"
                    :href="entries.prev_page_url"
                    class="min-h-11 underline"
                    >Previous</Link
                >
                <Link
                    v-if="entries.next_page_url"
                    :href="entries.next_page_url"
                    class="min-h-11 underline"
                    >Next</Link
                >
            </nav>
            <Link
                :href="index({}, { query: { month } }).url"
                class="inline-flex min-h-11 items-center underline"
                >Back to monthly budgets</Link
            >
        </div>
    </AppLayout>
</template>
