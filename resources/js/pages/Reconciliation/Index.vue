<script setup lang="ts">
import ProviderSettlementGroupController from '@/actions/App/Http/Controllers/ProviderSettlementGroupController';
import ReconciliationImportController from '@/actions/App/Http/Controllers/ReconciliationImportController';
import ReconciliationLinkController from '@/actions/App/Http/Controllers/ReconciliationLinkController';
import ReconciliationPeriodController from '@/actions/App/Http/Controllers/ReconciliationPeriodController';
import ReconciliationTransactionStatusController from '@/actions/App/Http/Controllers/ReconciliationTransactionStatusController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { useCurrencyFormatter } from '@/lib/currency';
import { index } from '@/routes/reconciliation';
import type { BreadcrumbItem } from '@/types';
import { Form, Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowDownLeft,
    ArrowUpRight,
    CheckCircle2,
    FileSpreadsheet,
    Landmark,
    Link2,
    RotateCcw,
    Scale,
    Search,
    Split,
    Unlink,
    Upload,
} from '@lucide/vue';
import { computed, reactive, ref } from 'vue';

type Status = 'unmatched' | 'suggested' | 'matched' | 'ignored' | 'disputed';

interface Suggestion {
    type: string;
    id: number;
    label: string;
    amount: number;
    date: string;
}

interface ReconciliationLinkItem extends Suggestion {
    target_id: number;
}

interface TransactionItem {
    id: number;
    date: string;
    direction: 'credit' | 'debit';
    direction_label: string;
    amount: number;
    reference: string | null;
    description: string | null;
    source_account: string | null;
    status: Status;
    status_label: string;
    linked_amount: number;
    remaining_amount: number;
    ignored_reason: string | null;
    disputed_reason: string | null;
    suggestions: Suggestion[];
    links: ReconciliationLinkItem[];
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface TargetItem {
    type: string;
    id: number;
    label: string;
    amount: number;
    remaining: number;
    direction?: 'credit' | 'debit';
}

interface PeriodItem {
    id: number;
    starts_at: string;
    ends_at: string;
    status: 'open' | 'closed' | 'reopened';
    opening_balance: number | null;
    closing_balance: number | null;
    bank_net: number | null;
    ledger_net: number | null;
    variance: number | null;
    reopen_reason: string | null;
}

interface PreviewImport {
    id: number;
    name: string;
    headers: string[];
    rows: Array<Record<string, string | null>>;
    status: string;
    mapping: Record<string, string | null> | null;
}

interface Props {
    filters: {
        status?: string | null;
        direction?: string | null;
        search?: string | null;
    };
    transactions: { data: TransactionItem[]; links: PaginationLink[] };
    summary: Record<Status, number>;
    statuses: Array<{ value: Status; label: string }>;
    preview_import: PreviewImport | null;
    imports: Array<{
        id: number;
        name: string;
        status: string;
        rows: number;
        imported: number;
        duplicates: number;
        created_at: string;
    }>;
    periods: PeriodItem[];
    settlements: Array<{
        id: number;
        reference: string;
        settled_at: string;
        transactions_count: number;
        gross_amount: number;
        fee_amount: number;
        net_amount: number;
        bank_amount: number | null;
        difference: number;
    }>;
    targets: {
        payments: TargetItem[];
        expenses: TargetItem[];
        adjustments: TargetItem[];
        settlements: TargetItem[];
    };
    settlement_bank_credits: Array<{
        id: number;
        date: string;
        reference: string | null;
        description: string | null;
        remaining_amount: number;
    }>;
    paystack_transactions: Array<{
        id: number;
        reference: string;
        amount: number;
        settled_amount: number;
    }>;
    can_reopen: boolean;
}

const props = defineProps<Props>();
const { formatCurrency } = useCurrencyFormatter();
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Reconciliation', href: index().url },
];
const selectedTargets = reactive<Record<number, string>>({});
const selectedAmounts = reactive<Record<number, number>>({});
const linking = reactive<Record<number, boolean>>({});
const filterStatus = ref(props.filters.status ?? '');
const filterDirection = ref(props.filters.direction ?? '');
const filterSearch = ref(props.filters.search ?? '');
const selectedPaystack = ref<number[]>([]);

const allTargets = computed(() => [
    ...props.targets.payments.map((target) => ({
        ...target,
        direction: 'credit' as const,
    })),
    ...props.targets.expenses.map((target) => ({
        ...target,
        direction: 'debit' as const,
    })),
    ...props.targets.adjustments,
    ...props.targets.settlements.map((target) => ({
        ...target,
        direction: 'credit' as const,
    })),
]);

function targetsFor(transaction: TransactionItem): TargetItem[] {
    return allTargets.value.filter(
        (target) =>
            target.remaining > 0 && target.direction === transaction.direction,
    );
}

function applyFilters(): void {
    router.get(
        index().url,
        {
            status: filterStatus.value || undefined,
            direction: filterDirection.value || undefined,
            search: filterSearch.value || undefined,
        },
        { preserveState: true, replace: true },
    );
}

function linkTarget(
    transaction: TransactionItem,
    suggestion?: Suggestion,
): void {
    const selected = suggestion
        ? `${suggestion.type}:${suggestion.id}`
        : selectedTargets[transaction.id];
    if (!selected) return;
    const [type, id] = selected.split(':');
    const target = allTargets.value.find(
        (candidate) => candidate.type === type && candidate.id === Number(id),
    );
    const defaultAmount = Math.min(
        transaction.remaining_amount,
        target?.remaining ?? transaction.remaining_amount,
    );
    const amount = suggestion
        ? Math.min(transaction.remaining_amount, suggestion.amount)
        : selectedAmounts[transaction.id] || defaultAmount;
    linking[transaction.id] = true;
    router.post(
        ReconciliationLinkController.store({ bank_transaction: transaction.id })
            .url,
        { target_type: type, target_id: Number(id), amount },
        {
            preserveScroll: true,
            onFinish: () => (linking[transaction.id] = false),
        },
    );
}

function unlink(linkId: number): void {
    router.delete(
        ReconciliationLinkController.destroy({ reconciliation_link: linkId })
            .url,
        { preserveScroll: true },
    );
}

function updateStatus(
    transaction: TransactionItem,
    status: 'unmatched' | 'ignored' | 'disputed',
): void {
    const reason =
        status === 'unmatched'
            ? null
            : window.prompt(`Why should this transaction be ${status}?`);
    if (status !== 'unmatched' && !reason) return;
    router.patch(
        ReconciliationTransactionStatusController.update({
            bank_transaction: transaction.id,
        }).url,
        { status, reason },
        { preserveScroll: true },
    );
}

function closePeriod(period: PeriodItem): void {
    if (
        window.confirm(
            'Close this period and freeze its opening, closing, and variance snapshot?',
        )
    ) {
        router.post(
            ReconciliationPeriodController.close({
                reconciliation_period: period.id,
            }).url,
            {},
            { preserveScroll: true },
        );
    }
}

function reopenPeriod(period: PeriodItem): void {
    const reason = window.prompt('Enter the required reopening reason:');
    if (!reason) return;
    router.post(
        ReconciliationPeriodController.reopen({
            reconciliation_period: period.id,
        }).url,
        { reason },
        { preserveScroll: true },
    );
}

function statusClasses(status: Status): string {
    return {
        unmatched:
            'bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-200',
        suggested:
            'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
        matched:
            'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
        ignored:
            'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
        disputed:
            'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',
    }[status];
}

function paginationLabel(label: string): string {
    return label.replace('&laquo;', '«').replace('&raquo;', '»');
}
</script>

<template>
    <Head title="Reconciliation" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
            <div
                class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <div class="flex items-center gap-3">
                        <Scale class="h-6 w-6 text-emerald-600" />
                        <h1
                            class="text-2xl font-semibold text-neutral-950 dark:text-white"
                        >
                            Bank reconciliation
                        </h1>
                    </div>
                    <p
                        class="mt-1 text-sm text-neutral-600 dark:text-neutral-400"
                    >
                        Match bank money-in and money-out to the immutable
                        family ledger.
                    </p>
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <button
                    v-for="status in statuses"
                    :key="status.value"
                    type="button"
                    class="rounded-xl border border-sidebar-border/70 bg-white p-4 text-left transition hover:border-emerald-400 dark:bg-neutral-900"
                    @click="
                        filterStatus = status.value;
                        applyFilters();
                    "
                >
                    <span
                        class="text-xs font-medium tracking-wide text-neutral-500 uppercase"
                        >{{ status.label }}</span
                    >
                    <strong
                        class="mt-2 block text-2xl text-neutral-950 dark:text-white"
                        >{{ summary[status.value] ?? 0 }}</strong
                    >
                </button>
            </div>

            <section
                class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(22rem,0.7fr)]"
            >
                <div
                    class="rounded-xl border border-sidebar-border/70 bg-white p-5 dark:bg-neutral-900"
                >
                    <div class="flex items-center gap-2">
                        <Upload class="h-5 w-5 text-emerald-600" />
                        <h2 class="font-semibold">Import a bank statement</h2>
                    </div>
                    <p class="mt-1 text-sm text-neutral-500">
                        CSV and TXT files up to 10 MB are stored privately.
                        Uploading the same statement again is harmless.
                    </p>
                    <Form
                        :action="ReconciliationImportController.store()"
                        class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end"
                        #default="{ errors, processing }"
                    >
                        <div class="grid flex-1 gap-2">
                            <Label for="statement">Statement file</Label
                            ><Input
                                id="statement"
                                name="statement"
                                type="file"
                                accept=".csv,.txt,text/csv,text/plain"
                                required
                            />
                            <p
                                v-if="errors.statement"
                                class="text-sm text-red-600"
                            >
                                {{ errors.statement }}
                            </p>
                        </div>
                        <Button type="submit" :disabled="processing"
                            ><Upload class="mr-2 h-4 w-4" />{{
                                processing ? 'Uploading…' : 'Preview columns'
                            }}</Button
                        >
                    </Form>
                </div>
                <div
                    class="rounded-xl border border-sidebar-border/70 bg-white p-5 dark:bg-neutral-900"
                >
                    <div class="flex items-center gap-2">
                        <FileSpreadsheet class="h-5 w-5 text-blue-600" />
                        <h2 class="font-semibold">Recent imports</h2>
                    </div>
                    <div class="mt-3 space-y-2 text-sm">
                        <div
                            v-for="item in imports"
                            :key="item.id"
                            class="flex items-center justify-between gap-3 rounded-lg bg-neutral-50 p-3 dark:bg-neutral-800/60"
                        >
                            <div class="min-w-0">
                                <p class="truncate font-medium">
                                    {{ item.name }}
                                </p>
                                <p class="text-xs text-neutral-500">
                                    {{ item.imported }} imported ·
                                    {{ item.duplicates }} duplicates
                                </p>
                            </div>
                            <span
                                class="rounded-full px-2 py-1 text-xs capitalize"
                                :class="
                                    item.status === 'imported'
                                        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200'
                                        : 'bg-amber-100 text-amber-800'
                                "
                                >{{ item.status }}</span
                            >
                        </div>
                        <p v-if="imports.length === 0" class="text-neutral-500">
                            No statements imported yet.
                        </p>
                    </div>
                </div>
            </section>

            <section
                v-if="preview_import && preview_import.status !== 'imported'"
                class="rounded-xl border-2 border-emerald-300 bg-emerald-50/50 p-5 dark:border-emerald-800 dark:bg-emerald-950/20"
            >
                <h2 class="font-semibold">
                    Map columns for {{ preview_import.name }}
                </h2>
                <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">
                    Map date plus either amount/direction or separate
                    credit/debit columns. Optional fields improve exact
                    matching.
                </p>
                <Form
                    :action="
                        ReconciliationImportController.commit({
                            reconciliation_import: preview_import.id,
                        })
                    "
                    class="mt-4 space-y-4"
                    #default="{ errors, processing }"
                >
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <label
                            v-for="field in [
                                'date',
                                'amount',
                                'direction',
                                'credit',
                                'debit',
                                'reference',
                                'description',
                                'source_account',
                            ]"
                            :key="field"
                            class="grid gap-1 text-sm font-medium capitalize"
                            >{{ field.replace('_', ' ')
                            }}<select
                                :name="`mapping[${field}]`"
                                :required="field === 'date'"
                                class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                            >
                                <option value="">Not mapped</option>
                                <option
                                    v-for="header in preview_import.headers"
                                    :key="header"
                                    :value="header"
                                >
                                    {{ header }}
                                </option></select
                            ><span
                                v-if="errors[`mapping.${field}`]"
                                class="text-xs text-red-600"
                                >{{ errors[`mapping.${field}`] }}</span
                            ></label
                        >
                    </div>
                    <div
                        class="overflow-x-auto rounded-lg border bg-white dark:bg-neutral-950"
                    >
                        <table class="min-w-full text-sm">
                            <thead class="bg-neutral-100 dark:bg-neutral-800">
                                <tr>
                                    <th
                                        v-for="header in preview_import.headers"
                                        :key="header"
                                        class="px-3 py-2 text-left font-medium"
                                    >
                                        {{ header }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(
                                        row, rowIndex
                                    ) in preview_import.rows"
                                    :key="rowIndex"
                                    class="border-t"
                                >
                                    <td
                                        v-for="header in preview_import.headers"
                                        :key="header"
                                        class="px-3 py-2 whitespace-nowrap"
                                    >
                                        {{ row[header] }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <Button type="submit" :disabled="processing"
                        ><CheckCircle2 class="mr-2 h-4 w-4" />{{
                            processing
                                ? 'Importing…'
                                : 'Import and auto-match exact references'
                        }}</Button
                    >
                </Form>
            </section>

            <section
                class="rounded-xl border border-sidebar-border/70 bg-white dark:bg-neutral-900"
            >
                <div
                    class="flex flex-col gap-3 border-b p-4 lg:flex-row lg:items-end"
                >
                    <div class="grid flex-1 gap-1">
                        <Label for="search">Search</Label>
                        <div class="relative">
                            <Search
                                class="absolute top-2.5 left-3 h-4 w-4 text-neutral-400"
                            /><Input
                                id="search"
                                v-model="filterSearch"
                                class="pl-9"
                                placeholder="Reference or description"
                                @keyup.enter="applyFilters"
                            />
                        </div>
                    </div>
                    <div class="grid gap-1">
                        <Label>Status</Label
                        ><select
                            v-model="filterStatus"
                            class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="">All queues</option>
                            <option
                                v-for="status in statuses"
                                :key="status.value"
                                :value="status.value"
                            >
                                {{ status.label }}
                            </option>
                        </select>
                    </div>
                    <div class="grid gap-1">
                        <Label>Direction</Label
                        ><select
                            v-model="filterDirection"
                            class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="">Money in and out</option>
                            <option value="credit">Money in</option>
                            <option value="debit">Money out</option>
                        </select>
                    </div>
                    <Button variant="outline" @click="applyFilters"
                        >Apply filters</Button
                    >
                </div>
                <div class="divide-y">
                    <article
                        v-for="transaction in transactions.data"
                        :key="transaction.id"
                        class="grid gap-4 p-4 xl:grid-cols-[minmax(0,1fr)_minmax(22rem,0.8fr)]"
                    >
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <ArrowDownLeft
                                    v-if="transaction.direction === 'credit'"
                                    class="h-5 w-5 text-emerald-600"
                                /><ArrowUpRight
                                    v-else
                                    class="h-5 w-5 text-red-600"
                                />
                                <strong class="text-lg">{{
                                    formatCurrency(transaction.amount)
                                }}</strong
                                ><span
                                    class="rounded-full px-2 py-1 text-xs"
                                    :class="statusClasses(transaction.status)"
                                    >{{ transaction.status_label }}</span
                                ><span class="text-sm text-neutral-500">{{
                                    transaction.date
                                }}</span>
                            </div>
                            <p class="mt-2 font-medium">
                                {{
                                    transaction.description || 'No description'
                                }}
                            </p>
                            <p class="text-sm text-neutral-500">
                                {{ transaction.reference || 'No reference'
                                }}<span v-if="transaction.source_account">
                                    · {{ transaction.source_account }}</span
                                >
                            </p>
                            <div
                                class="mt-3 h-2 overflow-hidden rounded-full bg-neutral-100 dark:bg-neutral-800"
                            >
                                <div
                                    class="h-full bg-emerald-500"
                                    :style="{
                                        width: `${Math.min(100, (transaction.linked_amount / transaction.amount) * 100)}%`,
                                    }"
                                />
                            </div>
                            <p class="mt-1 text-xs text-neutral-500">
                                {{ formatCurrency(transaction.linked_amount) }}
                                linked ·
                                {{
                                    formatCurrency(transaction.remaining_amount)
                                }}
                                remaining
                            </p>
                            <div
                                v-if="transaction.links.length"
                                class="mt-3 space-y-2"
                            >
                                <div
                                    v-for="link in transaction.links"
                                    :key="link.id"
                                    class="flex items-center justify-between gap-2 rounded-lg bg-neutral-50 p-2 text-sm dark:bg-neutral-800"
                                >
                                    <span
                                        ><Link2 class="mr-1 inline h-4 w-4" />{{
                                            link.label
                                        }}
                                        ·
                                        {{ formatCurrency(link.amount) }}</span
                                    ><button
                                        type="button"
                                        class="text-red-600"
                                        title="Remove link"
                                        @click="unlink(link.id)"
                                    >
                                        <Unlink class="h-4 w-4" />
                                    </button>
                                </div>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <Button
                                    v-if="transaction.status !== 'ignored'"
                                    size="sm"
                                    variant="outline"
                                    @click="
                                        updateStatus(transaction, 'ignored')
                                    "
                                    >Ignore</Button
                                ><Button
                                    v-if="transaction.status !== 'disputed'"
                                    size="sm"
                                    variant="outline"
                                    @click="
                                        updateStatus(transaction, 'disputed')
                                    "
                                    >Dispute</Button
                                ><Button
                                    v-if="
                                        ['ignored', 'disputed'].includes(
                                            transaction.status,
                                        )
                                    "
                                    size="sm"
                                    variant="ghost"
                                    @click="
                                        updateStatus(transaction, 'unmatched')
                                    "
                                    ><RotateCcw class="mr-1 h-4 w-4" />Return to
                                    queue</Button
                                >
                            </div>
                        </div>
                        <div
                            v-if="transaction.remaining_amount > 0"
                            class="rounded-lg border p-3"
                        >
                            <h3
                                class="flex items-center gap-2 text-sm font-semibold"
                            >
                                <Split class="h-4 w-4" />Link or split amount
                            </h3>
                            <div
                                v-if="transaction.suggestions.length"
                                class="mt-3 space-y-2"
                            >
                                <p
                                    class="text-xs font-medium tracking-wide text-amber-700 uppercase dark:text-amber-300"
                                >
                                    Suggestions — never linked automatically
                                </p>
                                <button
                                    v-for="suggestion in transaction.suggestions"
                                    :key="`${suggestion.type}:${suggestion.id}`"
                                    type="button"
                                    class="flex w-full items-center justify-between gap-2 rounded-md border border-amber-200 p-2 text-left text-sm hover:bg-amber-50 dark:border-amber-900 dark:hover:bg-amber-950/30"
                                    @click="linkTarget(transaction, suggestion)"
                                >
                                    <span
                                        >{{ suggestion.label
                                        }}<small
                                            class="block text-neutral-500"
                                            >{{ suggestion.date }}</small
                                        ></span
                                    ><span>{{
                                        formatCurrency(suggestion.amount)
                                    }}</span>
                                </button>
                            </div>
                            <div class="mt-3 grid gap-2">
                                <select
                                    v-model="selectedTargets[transaction.id]"
                                    :data-test="`link-target-${transaction.id}`"
                                    class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                >
                                    <option value="">
                                        Choose a ledger entry
                                    </option>
                                    <option
                                        v-for="target in targetsFor(
                                            transaction,
                                        )"
                                        :key="`${target.type}:${target.id}`"
                                        :value="`${target.type}:${target.id}`"
                                    >
                                        {{ target.label }} —
                                        {{ formatCurrency(target.remaining) }}
                                        remaining
                                    </option></select
                                ><Input
                                    v-model.number="
                                        selectedAmounts[transaction.id]
                                    "
                                    :data-test="`link-amount-${transaction.id}`"
                                    type="number"
                                    min="1"
                                    :max="transaction.remaining_amount"
                                    :placeholder="`Amount up to ${formatCurrency(transaction.remaining_amount)}`"
                                /><Button
                                    :data-test="`link-submit-${transaction.id}`"
                                    size="sm"
                                    :disabled="
                                        !selectedTargets[transaction.id] ||
                                        linking[transaction.id]
                                    "
                                    @click="linkTarget(transaction)"
                                    ><Link2 class="mr-2 h-4 w-4" />Link
                                    amount</Button
                                >
                            </div>
                        </div>
                    </article>
                    <div
                        v-if="transactions.data.length === 0"
                        class="p-10 text-center text-neutral-500"
                    >
                        No transactions in this queue.
                    </div>
                </div>
                <div class="flex flex-wrap gap-2 border-t p-4">
                    <Button
                        v-for="link in transactions.links"
                        :key="link.label"
                        as-child
                        size="sm"
                        :variant="link.active ? 'default' : 'outline'"
                        :disabled="!link.url"
                        ><Link
                            v-if="link.url"
                            :href="link.url"
                            preserve-state
                            preserve-scroll
                            >{{ paginationLabel(link.label) }}</Link
                        ><span v-else>{{
                            paginationLabel(link.label)
                        }}</span></Button
                    >
                </div>
            </section>

            <section class="grid gap-4 xl:grid-cols-2">
                <div
                    class="rounded-xl border border-sidebar-border/70 bg-white p-5 dark:bg-neutral-900"
                >
                    <div class="flex items-center gap-2">
                        <Landmark class="h-5 w-5 text-violet-600" />
                        <h2 class="font-semibold">Reconciliation periods</h2>
                    </div>
                    <Form
                        :action="ReconciliationPeriodController.store()"
                        class="mt-4 grid gap-3 sm:grid-cols-[1fr_1fr_auto]"
                        #default="{ processing }"
                        ><div class="grid gap-1">
                            <Label>Start</Label
                            ><Input name="starts_at" type="date" required />
                        </div>
                        <div class="grid gap-1">
                            <Label>End</Label
                            ><Input name="ends_at" type="date" required />
                        </div>
                        <Button
                            class="self-end"
                            type="submit"
                            :disabled="processing"
                            >Open period</Button
                        ></Form
                    >
                    <div class="mt-4 space-y-3">
                        <div
                            v-for="period in periods"
                            :key="period.id"
                            class="rounded-lg border p-3"
                        >
                            <div
                                class="flex flex-wrap items-center justify-between gap-2"
                            >
                                <div>
                                    <strong
                                        >{{ period.starts_at }} –
                                        {{ period.ends_at }}</strong
                                    ><span
                                        class="ml-2 rounded-full bg-neutral-100 px-2 py-1 text-xs capitalize dark:bg-neutral-800"
                                        >{{ period.status }}</span
                                    >
                                </div>
                                <Button
                                    v-if="
                                        ['open', 'reopened'].includes(
                                            period.status,
                                        )
                                    "
                                    size="sm"
                                    @click="closePeriod(period)"
                                    >Close and snapshot</Button
                                ><Button
                                    v-else-if="
                                        period.status === 'closed' && can_reopen
                                    "
                                    size="sm"
                                    variant="outline"
                                    @click="reopenPeriod(period)"
                                    >Reopen</Button
                                >
                            </div>
                            <dl
                                v-if="period.closing_balance !== null"
                                class="mt-3 grid grid-cols-2 gap-2 text-sm sm:grid-cols-5"
                            >
                                <div>
                                    <dt class="text-neutral-500">Opening</dt>
                                    <dd>
                                        {{
                                            formatCurrency(
                                                period.opening_balance ?? 0,
                                            )
                                        }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-neutral-500">Closing</dt>
                                    <dd>
                                        {{
                                            formatCurrency(
                                                period.closing_balance,
                                            )
                                        }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-neutral-500">Bank net</dt>
                                    <dd>
                                        {{
                                            formatCurrency(period.bank_net ?? 0)
                                        }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-neutral-500">Ledger net</dt>
                                    <dd>
                                        {{
                                            formatCurrency(
                                                period.ledger_net ?? 0,
                                            )
                                        }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-neutral-500">Variance</dt>
                                    <dd
                                        :class="
                                            period.variance === 0
                                                ? 'text-emerald-600'
                                                : 'text-red-600'
                                        "
                                    >
                                        {{
                                            formatCurrency(period.variance ?? 0)
                                        }}
                                    </dd>
                                </div>
                            </dl>
                            <p
                                v-if="period.reopen_reason"
                                class="mt-2 text-xs text-amber-700 dark:text-amber-300"
                            >
                                Reopened: {{ period.reopen_reason }}
                            </p>
                        </div>
                        <p
                            v-if="periods.length === 0"
                            class="text-sm text-neutral-500"
                        >
                            No periods created yet.
                        </p>
                    </div>
                </div>

                <div
                    class="rounded-xl border border-sidebar-border/70 bg-white p-5 dark:bg-neutral-900"
                >
                    <div class="flex items-center gap-2">
                        <Landmark class="h-5 w-5 text-blue-600" />
                        <h2 class="font-semibold">
                            Paystack settlement groups
                        </h2>
                    </div>
                    <p class="mt-1 text-sm text-neutral-500">
                        Aggregate provider transactions and fees against one
                        bank credit; differences stay visible.
                    </p>
                    <Form
                        :action="ProviderSettlementGroupController.store()"
                        class="mt-4 space-y-3"
                        reset-on-success
                        #default="{ processing, errors }"
                        ><div class="grid gap-3 sm:grid-cols-2">
                            <div class="grid gap-1">
                                <Label>Settlement reference</Label
                                ><Input name="reference" required />
                            </div>
                            <div class="grid gap-1">
                                <Label>Settlement date</Label
                                ><Input
                                    name="settled_at"
                                    type="date"
                                    required
                                />
                            </div>
                        </div>
                        <div class="grid gap-1">
                            <Label>Bank credit (optional)</Label
                            ><select
                                name="bank_transaction_id"
                                class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                            >
                                <option value="">
                                    Record difference without linking
                                </option>
                                <option
                                    v-for="transaction in settlement_bank_credits"
                                    :key="transaction.id"
                                    :value="transaction.id"
                                >
                                    {{ transaction.date }} ·
                                    {{
                                        transaction.reference ||
                                        transaction.description
                                    }}
                                    ·
                                    {{
                                        formatCurrency(
                                            transaction.remaining_amount,
                                        )
                                    }}
                                </option>
                            </select>
                        </div>
                        <div
                            class="max-h-48 space-y-2 overflow-y-auto rounded-lg border p-3"
                        >
                            <label
                                v-for="transaction in paystack_transactions"
                                :key="transaction.id"
                                class="flex items-center justify-between gap-3 text-sm"
                                ><span
                                    ><input
                                        v-model="selectedPaystack"
                                        type="checkbox"
                                        name="paystack_transaction_ids[]"
                                        :value="transaction.id"
                                        class="mr-2"
                                    />{{ transaction.reference }}</span
                                ><span>{{
                                    formatCurrency(transaction.settled_amount)
                                }}</span></label
                            >
                            <p
                                v-if="paystack_transactions.length === 0"
                                class="text-sm text-neutral-500"
                            >
                                No ungrouped allocated Paystack transactions.
                            </p>
                        </div>
                        <p
                            v-if="errors.paystack_transaction_ids"
                            class="text-sm text-red-600"
                        >
                            {{ errors.paystack_transaction_ids }}
                        </p>
                        <Button
                            type="submit"
                            :disabled="
                                processing || selectedPaystack.length === 0
                            "
                            >Create settlement group</Button
                        ></Form
                    >
                    <div class="mt-4 space-y-2">
                        <div
                            v-for="settlement in settlements"
                            :key="settlement.id"
                            class="rounded-lg bg-neutral-50 p-3 text-sm dark:bg-neutral-800/60"
                        >
                            <div
                                class="flex items-center justify-between gap-2"
                            >
                                <strong>{{ settlement.reference }}</strong
                                ><span
                                    :class="
                                        settlement.difference === 0
                                            ? 'text-emerald-600'
                                            : 'text-red-600'
                                    "
                                    >Difference
                                    {{
                                        formatCurrency(settlement.difference)
                                    }}</span
                                >
                            </div>
                            <p class="mt-1 text-neutral-500">
                                {{ settlement.transactions_count }} transactions
                                · Gross
                                {{ formatCurrency(settlement.gross_amount) }} ·
                                Fees
                                {{ formatCurrency(settlement.fee_amount) }} ·
                                Net {{ formatCurrency(settlement.net_amount) }}
                            </p>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
