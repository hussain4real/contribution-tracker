<script setup lang="ts">
import { index } from '@/actions/App/Http/Controllers/ContributionController';
import { index as reportsIndex } from '@/actions/App/Http/Controllers/ReportController';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import AppLayout from '@/layouts/AppLayout.vue';
import { useCurrencyFormatter } from '@/lib/currency';
import { type BreadcrumbItem } from '@/types';
import { Deferred, Head, Link, router } from '@inertiajs/vue3';
import { ClipboardList, Download, Filter, Search } from '@lucide/vue';
import { reactive } from 'vue';

interface ContributionRow {
    id: number;
    member_name: string;
    period: string;
    due_date: string;
    category_label: string;
    expected_amount: number;
    paid_amount: number;
    outstanding_amount: number;
    status: string;
    status_label: string;
}

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Paginator {
    data: ContributionRow[];
    links: PageLink[];
    from: number | null;
    to: number | null;
    total: number;
}

interface Summary {
    total_expected: number;
    total_collected: number;
    total_outstanding: number;
    collection_rate: number;
    record_count: number;
}

interface Props {
    contributions: Paginator;
    filters: Record<string, string | number | null>;
    summary?: Summary;
    members: Array<{ id: number; name: string }>;
    categories: Array<{ slug: string; name: string }>;
    statuses: Array<{ value: string; label: string }>;
}

const props = defineProps<Props>();
const form = reactive({
    date_from: String(props.filters.date_from ?? ''),
    date_to: String(props.filters.date_to ?? ''),
    member_id: String(props.filters.member_id ?? ''),
    category: String(props.filters.category ?? ''),
    status: String(props.filters.status ?? ''),
    min_outstanding: String(props.filters.min_outstanding ?? ''),
    search: String(props.filters.search ?? ''),
    per_page: String(props.filters.per_page ?? 25),
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Contributions', href: index().url },
];
const { formatCurrency } = useCurrencyFormatter();

function applyFilters(): void {
    router.get(index().url, form, { preserveState: true, replace: true });
}

function resetFilters(): void {
    router.get(index().url);
}

function statusClass(status: string): string {
    return (
        {
            paid: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
            partial:
                'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
            unpaid: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
            overdue:
                'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
        }[status] ?? 'bg-muted text-muted-foreground'
    );
}
</script>

<template>
    <Head title="Contribution Register" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <ClipboardList class="h-6 w-6 text-neutral-500" />
                    <div>
                        <h1
                            class="text-xl font-semibold text-foreground sm:text-2xl"
                        >
                            Contribution register
                        </h1>
                        <p class="text-sm text-muted-foreground">
                            Search, reconcile, and export the family ledger.
                        </p>
                    </div>
                </div>
                <Button variant="outline" as-child>
                    <Link :href="reportsIndex().url"
                        ><Download class="h-4 w-4" /> Reports & exports</Link
                    >
                </Button>
            </div>

            <Deferred data="summary">
                <template #fallback>
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <Skeleton
                            v-for="item in 4"
                            :key="item"
                            class="h-24 rounded-xl"
                        />
                    </div>
                </template>
                <div
                    v-if="summary"
                    class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
                >
                    <div class="rounded-xl border bg-card p-4">
                        <p class="text-xs text-muted-foreground">Expected</p>
                        <p class="text-xl font-semibold">
                            {{ formatCurrency(summary.total_expected) }}
                        </p>
                    </div>
                    <div class="rounded-xl border bg-card p-4">
                        <p class="text-xs text-muted-foreground">Collected</p>
                        <p
                            class="text-xl font-semibold text-emerald-700 dark:text-emerald-300"
                        >
                            {{ formatCurrency(summary.total_collected) }}
                        </p>
                    </div>
                    <div class="rounded-xl border bg-card p-4">
                        <p class="text-xs text-muted-foreground">Outstanding</p>
                        <p
                            class="text-xl font-semibold text-amber-700 dark:text-amber-300"
                        >
                            {{ formatCurrency(summary.total_outstanding) }}
                        </p>
                    </div>
                    <div class="rounded-xl border bg-card p-4">
                        <p class="text-xs text-muted-foreground">
                            Collection rate
                        </p>
                        <p class="text-xl font-semibold">
                            {{ summary.collection_rate }}%
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ summary.record_count }} records
                        </p>
                    </div>
                </div>
            </Deferred>

            <form
                class="rounded-xl border bg-card p-4"
                @submit.prevent="applyFilters"
            >
                <div class="mb-3 flex items-center gap-2 text-sm font-medium">
                    <Filter class="h-4 w-4" /> Register filters
                </div>
                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <label class="grid gap-1 text-xs text-muted-foreground"
                        >From<input
                            v-model="form.date_from"
                            type="date"
                            class="rounded-md border bg-background px-3 py-2 text-sm text-foreground"
                    /></label>
                    <label class="grid gap-1 text-xs text-muted-foreground"
                        >To<input
                            v-model="form.date_to"
                            type="date"
                            class="rounded-md border bg-background px-3 py-2 text-sm text-foreground"
                    /></label>
                    <label class="grid gap-1 text-xs text-muted-foreground"
                        >Member<select
                            v-model="form.member_id"
                            class="rounded-md border bg-background px-3 py-2 text-sm text-foreground"
                        >
                            <option value="">All members</option>
                            <option
                                v-for="member in members"
                                :key="member.id"
                                :value="member.id"
                            >
                                {{ member.name }}
                            </option>
                        </select></label
                    >
                    <label class="grid gap-1 text-xs text-muted-foreground"
                        >Category<select
                            v-model="form.category"
                            class="rounded-md border bg-background px-3 py-2 text-sm text-foreground"
                        >
                            <option value="">All categories</option>
                            <option
                                v-for="category in categories"
                                :key="category.slug"
                                :value="category.slug"
                            >
                                {{ category.name }}
                            </option>
                        </select></label
                    >
                    <label class="grid gap-1 text-xs text-muted-foreground"
                        >Status<select
                            v-model="form.status"
                            class="rounded-md border bg-background px-3 py-2 text-sm text-foreground"
                        >
                            <option value="">All statuses</option>
                            <option
                                v-for="status in statuses"
                                :key="status.value"
                                :value="status.value"
                            >
                                {{ status.label }}
                            </option>
                        </select></label
                    >
                    <label class="grid gap-1 text-xs text-muted-foreground"
                        >Minimum outstanding<input
                            v-model="form.min_outstanding"
                            min="0"
                            type="number"
                            class="rounded-md border bg-background px-3 py-2 text-sm text-foreground"
                    /></label>
                    <label
                        class="grid gap-1 text-xs text-muted-foreground xl:col-span-2"
                        >Search
                        <div class="relative">
                            <Search
                                class="absolute top-2.5 left-3 h-4 w-4 text-muted-foreground"
                            /><input
                                v-model="form.search"
                                type="search"
                                placeholder="Member, email, or category"
                                class="w-full rounded-md border bg-background py-2 pr-3 pl-9 text-sm text-foreground"
                            /></div
                    ></label>
                </div>
                <div
                    class="mt-4 flex flex-wrap items-end justify-between gap-3"
                >
                    <label class="grid gap-1 text-xs text-muted-foreground"
                        >Rows<select
                            v-model="form.per_page"
                            class="rounded-md border bg-background px-3 py-2 text-sm text-foreground"
                        >
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select></label
                    >
                    <div class="flex gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            @click="resetFilters"
                            >Reset</Button
                        ><Button type="submit">Apply filters</Button>
                    </div>
                </div>
            </form>

            <div class="overflow-hidden rounded-xl border bg-card">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[920px] text-sm">
                        <thead
                            class="bg-muted/60 text-left text-xs text-muted-foreground"
                        >
                            <tr>
                                <th class="px-4 py-3">Member</th>
                                <th class="px-4 py-3">Period</th>
                                <th class="px-4 py-3">Category</th>
                                <th class="px-4 py-3 text-right">Expected</th>
                                <th class="px-4 py-3 text-right">Paid</th>
                                <th class="px-4 py-3 text-right">
                                    Outstanding
                                </th>
                                <th class="px-4 py-3">Due</th>
                                <th class="px-4 py-3">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="row in contributions.data"
                                :key="row.id"
                                class="hover:bg-muted/30"
                            >
                                <td class="px-4 py-3 font-medium">
                                    {{ row.member_name }}
                                </td>
                                <td class="px-4 py-3">{{ row.period }}</td>
                                <td class="px-4 py-3">
                                    {{ row.category_label }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    {{ formatCurrency(row.expected_amount) }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    {{ formatCurrency(row.paid_amount) }}
                                </td>
                                <td class="px-4 py-3 text-right font-medium">
                                    {{ formatCurrency(row.outstanding_amount) }}
                                </td>
                                <td class="px-4 py-3">{{ row.due_date }}</td>
                                <td class="px-4 py-3">
                                    <span
                                        class="rounded-full px-2 py-1 text-xs font-medium"
                                        :class="statusClass(row.status)"
                                        >{{ row.status_label }}</span
                                    >
                                </td>
                            </tr>
                            <tr v-if="contributions.data.length === 0">
                                <td
                                    colspan="8"
                                    class="px-4 py-12 text-center text-muted-foreground"
                                >
                                    No contributions match these filters.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3 text-sm text-muted-foreground"
                >
                    <span
                        >Showing {{ contributions.from ?? 0 }}–{{
                            contributions.to ?? 0
                        }}
                        of {{ contributions.total }}</span
                    >
                    <div class="flex flex-wrap gap-1">
                        <Button
                            v-for="link in contributions.links"
                            :key="link.label"
                            as-child
                            size="sm"
                            :variant="link.active ? 'default' : 'outline'"
                            :disabled="!link.url"
                            ><Link
                                v-if="link.url"
                                :href="link.url"
                                preserve-scroll
                                preserve-state
                                ><span v-html="link.label" /></Link
                            ><span v-else v-html="link.label"
                        /></Button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
