<script setup lang="ts">
import { index } from '@/actions/App/Http/Controllers/FamilyActivityController';
import FinancialOverviewCard, {
    type FinancialOverview,
} from '@/components/dashboard/FinancialOverview.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useCurrencyFormatter } from '@/lib/currency';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface Page<T> {
    data: T[];
    prev_page_url: string | null;
    next_page_url: string | null;
}
const props = defineProps<{
    overview: FinancialOverview | null;
    rangeSummary: {
        start_date: string;
        end_date: string;
        received: number;
        expenses: number;
        adjustments: number;
    } | null;
    entries: Page<{
        id: number;
        kind: string;
        date: string;
        amount: number;
        description: string;
    }>;
    events: Page<{
        id: number;
        action: string;
        actor: string;
        reason: string | null;
        date: string;
    }>;
    filters: { type: string; search: string } & (
        | { mode: 'month'; period: string }
        | { mode: 'range'; start_date: string; end_date: string }
    );
}>();
const filters = ref({ ...props.filters });
const loading = ref(false);
const { formatCurrency } = useCurrencyFormatter();
function search() {
    router.get(index().url, filters.value, {
        preserveState: true,
        onStart: () => {
            loading.value = true;
        },
        onFinish: () => {
            loading.value = false;
        },
    });
}
</script>

<template>
    <Head title="Family financial activity" />
    <AppLayout
        :breadcrumbs="[{ title: 'Financial activity', href: index().url }]"
    >
        <div class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-6">
            <h1 class="text-2xl font-semibold">Family financial activity</h1>
            <form
                class="flex flex-wrap items-end gap-3"
                @submit.prevent="search"
            >
                <label v-if="filters.mode === 'month'" class="grid gap-1"
                    >Month
                    <input
                        v-model="filters.period"
                        type="month"
                        required
                        class="min-h-11 rounded-md border bg-background px-3"
                    />
                </label>
                <template v-else>
                    <label class="grid gap-1"
                        >From<input
                            v-model="filters.start_date"
                            type="date"
                            required
                            class="min-h-11 rounded-md border bg-background px-3"
                    /></label>
                    <label class="grid gap-1"
                        >Through<input
                            v-model="filters.end_date"
                            type="date"
                            :min="filters.start_date"
                            required
                            class="min-h-11 rounded-md border bg-background px-3"
                    /></label>
                    <Link
                        :href="index()"
                        class="inline-flex min-h-11 items-center text-sm underline"
                        >Monthly view</Link
                    >
                </template>
                <label class="grid gap-1"
                    >Entries
                    <select
                        v-model="filters.type"
                        class="min-h-11 rounded-md border bg-background px-3"
                    >
                        <option value="all">All entries</option>
                        <option value="payment">Payments</option>
                        <option value="expense">Expenses</option>
                        <option value="adjustment">Adjustments</option>
                    </select>
                </label>
                <label class="grid gap-1"
                    >Search descriptions or activity
                    <input
                        v-model="filters.search"
                        type="search"
                        maxlength="100"
                        class="min-h-11 rounded-md border bg-background px-3"
                    />
                </label>
                <button
                    class="min-h-11 rounded-md border px-4"
                    :disabled="loading"
                >
                    {{ loading ? 'Loading…' : 'Apply filters' }}
                </button>
            </form>
            <p
                v-if="$page.props.errors.period"
                role="alert"
                class="text-destructive"
            >
                {{ $page.props.errors.period }}
            </p>
            <p
                v-if="$page.props.errors.type"
                role="alert"
                class="text-destructive"
            >
                {{ $page.props.errors.type }}
            </p>
            <p
                v-if="
                    $page.props.errors.start_date || $page.props.errors.end_date
                "
                role="alert"
                class="text-destructive"
            >
                {{
                    $page.props.errors.start_date || $page.props.errors.end_date
                }}
            </p>
            <FinancialOverviewCard v-if="overview" :overview="overview" />
            <section
                v-if="rangeSummary"
                class="space-y-3 rounded-xl border p-5"
                aria-label="Date range totals"
            >
                <h2 class="font-semibold">
                    {{ rangeSummary.start_date }} through
                    {{ rangeSummary.end_date }}
                </h2>
                <p class="text-sm text-muted-foreground">
                    All effective movements in this date range, excluding
                    reversed entries.
                </p>
                <dl class="grid grid-cols-2 gap-4 md:grid-cols-4">
                    <div>
                        <dt class="text-sm text-muted-foreground">Received</dt>
                        <dd class="font-semibold">
                            {{ formatCurrency(rangeSummary.received) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm text-muted-foreground">Expenses</dt>
                        <dd class="font-semibold">
                            {{ formatCurrency(rangeSummary.expenses) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm text-muted-foreground">
                            Adjustments
                        </dt>
                        <dd class="font-semibold">
                            {{ formatCurrency(rangeSummary.adjustments) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm text-muted-foreground">
                            Net movement
                        </dt>
                        <dd class="font-semibold">
                            {{
                                formatCurrency(
                                    rangeSummary.received +
                                        rangeSummary.adjustments -
                                        rangeSummary.expenses,
                                )
                            }}
                        </dd>
                    </div>
                </dl>
            </section>
            <section aria-labelledby="entries-heading" :aria-busy="loading">
                <h2 id="entries-heading" class="mb-3 text-lg font-semibold">
                    Posted transactions
                </h2>
                <p
                    v-if="entries.data.length === 0"
                    class="rounded-md border p-4 text-muted-foreground"
                >
                    No transactions match these filters.
                </p>
                <ul v-else class="divide-y rounded-md border">
                    <li
                        v-for="entry in entries.data"
                        :key="entry.kind + entry.id"
                        class="flex flex-wrap justify-between gap-2 p-4"
                    >
                        <div>
                            <p>{{ entry.description }}</p>
                            <p class="text-sm text-muted-foreground">
                                {{ entry.date }} · {{ entry.kind }} #{{
                                    entry.id
                                }}
                            </p>
                        </div>
                        <p class="font-semibold">
                            {{ formatCurrency(entry.amount) }}
                        </p>
                    </li>
                </ul>
                <nav aria-label="Transaction pages" class="flex gap-4 py-3">
                    <Link
                        v-if="entries.prev_page_url"
                        :href="entries.prev_page_url"
                        class="min-h-11 underline"
                        >Previous transactions</Link
                    >
                    <Link
                        v-if="entries.next_page_url"
                        :href="entries.next_page_url"
                        class="min-h-11 underline"
                        >Next transactions</Link
                    >
                </nav>
            </section>
            <section aria-labelledby="events-heading">
                <h2 id="events-heading" class="mb-3 text-lg font-semibold">
                    Activity history
                </h2>
                <p class="mb-3 text-sm text-muted-foreground">
                    Activity dates show when changes were recorded. Transaction
                    dates determine the monthly totals above.
                </p>
                <p
                    v-if="events.data.length === 0"
                    class="rounded-md border p-4 text-muted-foreground"
                >
                    No recorded activity matches this month and search.
                </p>
                <ul v-else class="divide-y rounded-md border">
                    <li
                        v-for="event in events.data"
                        :key="event.id"
                        class="p-4"
                    >
                        <p>{{ event.action }}</p>
                        <p v-if="event.reason" class="mt-1">
                            {{ event.reason }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ event.actor }} · {{ event.date }}
                        </p>
                    </li>
                </ul>
                <nav aria-label="Activity pages" class="flex gap-4 py-3">
                    <Link
                        v-if="events.prev_page_url"
                        :href="events.prev_page_url"
                        class="min-h-11 underline"
                        >Previous activity</Link
                    >
                    <Link
                        v-if="events.next_page_url"
                        :href="events.next_page_url"
                        class="min-h-11 underline"
                        >Next activity</Link
                    >
                </nav>
            </section>
        </div>
    </AppLayout>
</template>
