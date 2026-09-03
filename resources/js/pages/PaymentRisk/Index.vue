<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { useCurrencyFormatter } from '@/lib/currency';
import { index, refresh as refreshPaymentRisk } from '@/routes/payment-risk';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import {
    ChartNoAxesCombined,
    CircleCheck,
    CircleDashed,
    Filter,
    Info,
    RefreshCw,
    ScanSearch,
    ShieldAlert,
    ShieldCheck,
    TriangleAlert,
} from '@lucide/vue';
import { computed, reactive, ref } from 'vue';

type ReadinessStatus = 'ready' | 'unavailable';
type HistoryTier = 'unavailable' | 'pooled' | 'experimental' | 'standard';
type AdvisoryBand = 'routine_review' | 'priority_review';

interface Readiness {
    status: ReadinessStatus;
    message: string;
    can_refresh: boolean;
}

interface Model {
    version: string;
    trained_at: string;
    training_window: string;
    prevalence: number;
    threshold: number;
}

interface Filters {
    period: string;
    band: AdvisoryBand | null;
}

interface Prediction {
    contribution_id: number;
    member_name: string;
    period: string;
    amount: number;
    due_date: string;
    cutoff_at: string;
    probability: number | null;
    advisory_band: AdvisoryBand | null;
    history_tier: HistoryTier;
    history_periods: number;
    factors: string[];
    warning: string | null;
}

interface Summary {
    total: number;
    routine: number;
    priority: number;
    unavailable: number;
}

interface Props {
    readiness: Readiness;
    model: Model | null;
    filters: Filters;
    predictions: Prediction[];
    summary: Summary;
}

const props = defineProps<Props>();
const { formatCurrency } = useCurrencyFormatter();
const filtering = ref(false);
const refreshing = ref(false);
const filterForm = reactive({
    period: props.filters.period,
    band: props.filters.band ?? '',
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Payment Risk', href: index().url },
];

const readinessStates = {
    unavailable: {
        title: 'Insights unavailable',
        description:
            'There is not enough mature payment history to produce responsible guidance for this period.',
    },
    ready: {
        title: 'Model ready',
        description:
            'The active advisory model passed its integrity, freshness, and held-out activation gates.',
    },
} satisfies Record<ReadinessStatus, { title: string; description: string }>;

const readinessState = computed(() => readinessStates[props.readiness.status]);

const summaryItems = computed(() => [
    {
        label: 'Contributions reviewed',
        value: props.summary.total,
        icon: ChartNoAxesCombined,
        class: 'text-sky-700 dark:text-sky-300',
        iconClass: 'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300',
    },
    {
        label: 'Priority review',
        value: props.summary.priority,
        icon: ShieldAlert,
        class: 'text-rose-700 dark:text-rose-300',
        iconClass:
            'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300',
    },
    {
        label: 'Routine review',
        value: props.summary.routine,
        icon: CircleCheck,
        class: 'text-emerald-700 dark:text-emerald-300',
        iconClass:
            'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
    },
    {
        label: 'Unavailable',
        value: props.summary.unavailable,
        icon: CircleDashed,
        class: 'text-muted-foreground',
        iconClass: 'bg-muted text-muted-foreground',
    },
]);

const percentFormatter = new Intl.NumberFormat('en', {
    style: 'percent',
    minimumFractionDigits: 0,
    maximumFractionDigits: 1,
});

function formatPercentage(value: number | null): string {
    return value === null ? 'Unavailable' : percentFormatter.format(value);
}

function formatDate(value: string): string {
    const normalized = /^\d{4}-\d{2}-\d{2}$/.test(value)
        ? `${value}T00:00:00`
        : value;
    const date = new Date(normalized);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleDateString('en', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

function formatDateTime(value: string): string {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleString('en', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

function formatPeriod(value: string): string {
    if (!/^\d{4}-\d{2}$/.test(value)) {
        return value;
    }

    return new Date(`${value}-01T00:00:00`).toLocaleDateString('en', {
        month: 'long',
        year: 'numeric',
    });
}

function readinessClasses(status: ReadinessStatus): string {
    return {
        unavailable:
            'border-neutral-200 bg-neutral-50 text-neutral-800 dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100',
        ready: 'border-emerald-200 bg-emerald-50 text-emerald-950 dark:border-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-100',
    }[status];
}

function historyTierClasses(status: HistoryTier): string {
    return {
        unavailable:
            'border-neutral-200 bg-neutral-100 text-neutral-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200',
        pooled: 'border-sky-200 bg-sky-100 text-sky-800 dark:border-sky-800 dark:bg-sky-950 dark:text-sky-200',
        experimental:
            'border-amber-200 bg-amber-100 text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200',
        standard:
            'border-emerald-200 bg-emerald-100 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
    }[status];
}

function historyTierLabel(status: HistoryTier): string {
    return {
        unavailable: 'Unavailable',
        pooled: 'Pooled',
        experimental: 'Experimental',
        standard: 'Standard',
    }[status];
}

function advisoryBandClasses(band: AdvisoryBand | null): string {
    if (band === 'priority_review') {
        return 'border-rose-200 bg-rose-100 text-rose-800 dark:border-rose-800 dark:bg-rose-950 dark:text-rose-200';
    }

    if (band === 'routine_review') {
        return 'border-emerald-200 bg-emerald-100 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200';
    }

    return 'border-neutral-200 bg-neutral-100 text-neutral-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200';
}

function advisoryBandLabel(band: AdvisoryBand | null): string {
    return band === 'priority_review'
        ? 'Priority review'
        : band === 'routine_review'
          ? 'Routine review'
          : 'Unavailable';
}

function applyFilters(): void {
    router.get(
        index().url,
        {
            period: filterForm.period || undefined,
            band: filterForm.band || undefined,
        },
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
            onStart: () => {
                filtering.value = true;
            },
            onFinish: () => {
                filtering.value = false;
            },
        },
    );
}

function resetFilters(): void {
    router.get(
        index().url,
        {},
        {
            preserveScroll: true,
            replace: true,
            onStart: () => {
                filtering.value = true;
            },
            onFinish: () => {
                filtering.value = false;
            },
        },
    );
}

function refreshInsights(): void {
    router.post(
        refreshPaymentRisk().url,
        { period: props.filters.period },
        {
            preserveScroll: true,
            onStart: () => {
                refreshing.value = true;
            },
            onFinish: () => {
                refreshing.value = false;
            },
        },
    );
}
</script>

<template>
    <Head title="Payment Risk Insights" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
            <header
                class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
            >
                <div class="flex min-w-0 items-start gap-3">
                    <div
                        class="mt-0.5 rounded-xl bg-sky-100 p-2 text-sky-700 dark:bg-sky-950 dark:text-sky-300"
                    >
                        <ScanSearch class="size-5" aria-hidden="true" />
                    </div>
                    <div class="min-w-0">
                        <p
                            class="text-xs font-semibold tracking-wide text-sky-700 uppercase dark:text-sky-300"
                        >
                            Officer decision support
                        </p>
                        <h1
                            class="text-xl font-semibold text-foreground sm:text-2xl"
                        >
                            Payment Risk Insights
                        </h1>
                        <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                            Prioritize a human review using payment history that
                            was available at the evidence cutoff.
                        </p>
                    </div>
                </div>

                <Button
                    v-if="readiness.can_refresh"
                    type="button"
                    class="w-full sm:w-auto"
                    :disabled="refreshing || filtering"
                    aria-label="Refresh payment risk insights for the selected period"
                    @click="refreshInsights"
                >
                    <RefreshCw
                        class="size-4"
                        :class="{
                            'animate-spin motion-reduce:animate-none':
                                refreshing,
                        }"
                        aria-hidden="true"
                    />
                    {{ refreshing ? 'Refreshing…' : 'Refresh insights' }}
                </Button>
            </header>

            <section
                id="payment-risk-disclaimer"
                class="flex items-start gap-3 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-950 dark:border-sky-900 dark:bg-sky-950/50 dark:text-sky-100"
                aria-labelledby="decision-support-title"
            >
                <Info class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                <div class="min-w-0">
                    <h2 id="decision-support-title" class="font-semibold">
                        Advisory insight, not an automated decision
                    </h2>
                    <p class="mt-1 leading-6">
                        This is not a credit score. It does not send reminders,
                        restrict access, change contribution terms, or automate
                        any action. An authorized officer remains responsible
                        for every review and follow-up.
                    </p>
                </div>
            </section>

            <section
                class="rounded-xl border p-4 sm:p-5"
                :class="readinessClasses(readiness.status)"
                aria-labelledby="readiness-title"
                aria-live="polite"
            >
                <div class="flex items-start gap-3">
                    <TriangleAlert
                        v-if="readiness.status === 'unavailable'"
                        class="mt-0.5 size-5 shrink-0"
                        aria-hidden="true"
                    />
                    <ShieldCheck
                        v-else
                        class="mt-0.5 size-5 shrink-0"
                        aria-hidden="true"
                    />
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 id="readiness-title" class="font-semibold">
                                {{ readinessState.title }}
                            </h2>
                            <span
                                class="rounded-full border border-current/20 px-2 py-0.5 text-xs font-semibold tracking-wide uppercase"
                            >
                                {{ readiness.status }}
                            </span>
                        </div>
                        <p class="mt-1 text-sm leading-6">
                            {{ readiness.message }}
                        </p>
                        <p class="mt-1 text-xs opacity-80">
                            {{ readinessState.description }}
                        </p>
                    </div>
                </div>
            </section>

            <section
                aria-label="Payment risk summary"
                class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
            >
                <article
                    v-for="item in summaryItems"
                    :key="item.label"
                    class="flex min-w-0 items-center gap-3 rounded-xl border bg-card p-4"
                >
                    <div class="rounded-lg p-2" :class="item.iconClass">
                        <component
                            :is="item.icon"
                            class="size-5"
                            aria-hidden="true"
                        />
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs text-muted-foreground">
                            {{ item.label }}
                        </p>
                        <p class="text-2xl font-semibold" :class="item.class">
                            {{ item.value }}
                        </p>
                    </div>
                </article>
            </section>

            <div
                class="grid min-w-0 gap-4 xl:grid-cols-[minmax(0,1.35fr)_minmax(19rem,0.65fr)]"
            >
                <form
                    class="min-w-0 rounded-xl border bg-card p-4 sm:p-5"
                    aria-labelledby="risk-filters-title"
                    :aria-busy="filtering"
                    @submit.prevent="applyFilters"
                >
                    <div class="flex items-center gap-2">
                        <Filter class="size-4" aria-hidden="true" />
                        <h2 id="risk-filters-title" class="font-semibold">
                            Review filters
                        </h2>
                    </div>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Choose the contribution period and advisory review band.
                    </p>

                    <div class="mt-4 grid min-w-0 gap-3 sm:grid-cols-2">
                        <label
                            for="risk-period"
                            class="grid min-w-0 gap-1 text-xs font-medium text-muted-foreground"
                        >
                            Contribution period
                            <input
                                id="risk-period"
                                v-model="filterForm.period"
                                name="period"
                                type="month"
                                required
                                class="h-10 min-w-0 rounded-md border bg-background px-3 text-sm text-foreground transition outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            />
                        </label>
                        <label
                            for="risk-band"
                            class="grid min-w-0 gap-1 text-xs font-medium text-muted-foreground"
                        >
                            Review band
                            <select
                                id="risk-band"
                                v-model="filterForm.band"
                                name="band"
                                class="h-10 min-w-0 rounded-md border bg-background px-3 text-sm text-foreground transition outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            >
                                <option value="">All review bands</option>
                                <option value="priority_review">
                                    Priority review
                                </option>
                                <option value="routine_review">
                                    Routine review
                                </option>
                            </select>
                        </label>
                    </div>

                    <div
                        class="mt-4 grid grid-cols-1 gap-2 sm:flex sm:justify-end"
                    >
                        <Button
                            type="button"
                            variant="ghost"
                            :disabled="filtering"
                            @click="resetFilters"
                        >
                            Reset
                        </Button>
                        <Button type="submit" :disabled="filtering">
                            {{ filtering ? 'Applying…' : 'Apply filters' }}
                        </Button>
                    </div>
                </form>

                <section
                    class="min-w-0 rounded-xl border bg-card p-4 sm:p-5"
                    aria-labelledby="model-metadata-title"
                >
                    <div class="flex items-center gap-2">
                        <ShieldCheck class="size-4" aria-hidden="true" />
                        <h2 id="model-metadata-title" class="font-semibold">
                            Evidence model
                        </h2>
                    </div>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Version and threshold used for this review.
                    </p>

                    <dl v-if="model" class="mt-4 grid gap-3 text-sm">
                        <div
                            class="flex min-w-0 items-start justify-between gap-4"
                        >
                            <dt class="text-muted-foreground">Version</dt>
                            <dd
                                class="min-w-0 text-right font-medium break-words"
                            >
                                {{ model.version }}
                            </dd>
                        </div>
                        <div
                            class="flex min-w-0 items-start justify-between gap-4"
                        >
                            <dt class="text-muted-foreground">Trained</dt>
                            <dd class="text-right font-medium">
                                {{ formatDateTime(model.trained_at) }}
                            </dd>
                        </div>
                        <div
                            class="flex min-w-0 items-start justify-between gap-4"
                        >
                            <dt class="text-muted-foreground">
                                Training window
                            </dt>
                            <dd
                                class="min-w-0 text-right font-medium break-words"
                            >
                                {{ model.training_window }}
                            </dd>
                        </div>
                        <div class="flex items-start justify-between gap-4">
                            <dt class="text-muted-foreground">
                                Observed prevalence
                            </dt>
                            <dd class="font-medium">
                                {{ formatPercentage(model.prevalence) }}
                            </dd>
                        </div>
                        <div class="flex items-start justify-between gap-4">
                            <dt class="text-muted-foreground">
                                Priority threshold
                            </dt>
                            <dd class="font-medium">
                                {{ formatPercentage(model.threshold) }}
                            </dd>
                        </div>
                    </dl>

                    <div
                        v-else
                        class="mt-4 rounded-lg bg-muted/60 p-3 text-sm text-muted-foreground"
                    >
                        No validated model is active for this period. Scores
                        remain unavailable until the readiness gate is met.
                    </div>
                </section>
            </div>

            <section
                class="min-w-0 overflow-hidden rounded-xl border bg-card"
                aria-labelledby="predictions-title"
                aria-describedby="payment-risk-disclaimer"
            >
                <div
                    class="flex flex-col gap-1 border-b px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5"
                >
                    <div>
                        <h2 id="predictions-title" class="font-semibold">
                            Contribution review list
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            {{ formatPeriod(filters.period) }} ·
                            {{ predictions.length }}
                            {{
                                predictions.length === 1 ? 'result' : 'results'
                            }}
                        </p>
                    </div>
                </div>

                <div
                    v-if="predictions.length === 0"
                    class="flex flex-col items-center gap-3 px-4 py-12 text-center"
                >
                    <CircleDashed
                        class="size-9 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <div>
                        <h3 class="font-medium">
                            {{
                                readiness.status === 'unavailable'
                                    ? 'No responsible score is available yet'
                                    : 'No contributions match these filters'
                            }}
                        </h3>
                        <p class="mt-1 max-w-md text-sm text-muted-foreground">
                            {{
                                readiness.status === 'unavailable'
                                    ? readiness.message
                                    : 'Try another period or review band.'
                            }}
                        </p>
                    </div>
                </div>

                <div v-else class="grid gap-3 p-3 lg:hidden">
                    <article
                        v-for="prediction in predictions"
                        :key="`${prediction.contribution_id}-${prediction.period}`"
                        class="min-w-0 rounded-xl border bg-background p-4"
                    >
                        <div
                            class="flex min-w-0 items-start justify-between gap-3"
                        >
                            <div class="min-w-0">
                                <h3 class="font-semibold break-words">
                                    {{ prediction.member_name }}
                                </h3>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    {{ formatPeriod(prediction.period) }}
                                </p>
                            </div>
                            <span
                                class="shrink-0 rounded-full border px-2 py-1 text-xs font-semibold"
                                :class="
                                    advisoryBandClasses(
                                        prediction.advisory_band,
                                    )
                                "
                            >
                                {{
                                    advisoryBandLabel(prediction.advisory_band)
                                }}
                            </span>
                        </div>

                        <dl
                            class="mt-4 grid grid-cols-2 gap-x-3 gap-y-4 text-sm"
                        >
                            <div class="min-w-0">
                                <dt class="text-xs text-muted-foreground">
                                    Amount
                                </dt>
                                <dd class="font-medium break-words">
                                    {{ formatCurrency(prediction.amount) }}
                                </dd>
                            </div>
                            <div class="min-w-0">
                                <dt class="text-xs text-muted-foreground">
                                    Advisory likelihood
                                </dt>
                                <dd class="font-medium">
                                    {{
                                        formatPercentage(prediction.probability)
                                    }}
                                </dd>
                            </div>
                            <div class="min-w-0">
                                <dt class="text-xs text-muted-foreground">
                                    Due date
                                </dt>
                                <dd class="font-medium">
                                    {{ formatDate(prediction.due_date) }}
                                </dd>
                            </div>
                            <div class="min-w-0">
                                <dt class="text-xs text-muted-foreground">
                                    Evidence cutoff
                                </dt>
                                <dd class="font-medium break-words">
                                    {{ formatDateTime(prediction.cutoff_at) }}
                                </dd>
                            </div>
                        </dl>

                        <div class="mt-4 flex flex-wrap items-center gap-2">
                            <span
                                class="rounded-full border px-2 py-1 text-xs font-medium"
                                :class="
                                    historyTierClasses(prediction.history_tier)
                                "
                            >
                                {{ historyTierLabel(prediction.history_tier) }}
                                history
                            </span>
                            <span class="text-xs text-muted-foreground">
                                {{ prediction.history_periods }} prior mature
                                {{
                                    prediction.history_periods === 1
                                        ? 'period'
                                        : 'periods'
                                }}
                            </span>
                        </div>

                        <div class="mt-4">
                            <h4
                                class="text-xs font-medium text-muted-foreground"
                            >
                                Evidence factors
                            </h4>
                            <ul
                                v-if="prediction.factors.length > 0"
                                class="mt-2 grid gap-1.5 text-sm"
                            >
                                <li
                                    v-for="(
                                        factor, factorIndex
                                    ) in prediction.factors"
                                    :key="factorIndex"
                                    class="flex min-w-0 items-start gap-2"
                                >
                                    <span
                                        class="mt-2 size-1.5 shrink-0 rounded-full bg-sky-500"
                                        aria-hidden="true"
                                    />
                                    <span class="min-w-0 break-words">{{
                                        factor
                                    }}</span>
                                </li>
                            </ul>
                            <p
                                v-else
                                class="mt-2 text-sm text-muted-foreground"
                            >
                                No reliable factor explanation is available for
                                this evidence tier.
                            </p>
                        </div>

                        <p
                            v-if="prediction.warning"
                            class="mt-4 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-900 dark:border-amber-900 dark:bg-amber-950/50 dark:text-amber-100"
                        >
                            <TriangleAlert
                                class="mt-0.5 size-3.5 shrink-0"
                                aria-hidden="true"
                            />
                            <span class="min-w-0 break-words">{{
                                prediction.warning
                            }}</span>
                        </p>
                    </article>
                </div>

                <div
                    v-if="predictions.length > 0"
                    class="hidden overflow-x-auto lg:block"
                >
                    <table class="w-full min-w-[980px] text-left text-sm">
                        <caption class="sr-only">
                            Payment risk advisory insights by contribution
                        </caption>
                        <thead
                            class="bg-muted/60 text-xs text-muted-foreground"
                        >
                            <tr>
                                <th scope="col" class="px-4 py-3">Member</th>
                                <th scope="col" class="px-4 py-3">Review</th>
                                <th scope="col" class="px-4 py-3">
                                    Contribution
                                </th>
                                <th scope="col" class="px-4 py-3">Evidence</th>
                                <th scope="col" class="px-4 py-3">Factors</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="prediction in predictions"
                                :key="`${prediction.contribution_id}-${prediction.period}`"
                                class="align-top hover:bg-muted/30"
                            >
                                <td class="px-4 py-4">
                                    <p class="font-medium">
                                        {{ prediction.member_name }}
                                    </p>
                                    <p
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        {{ formatPeriod(prediction.period) }}
                                    </p>
                                </td>
                                <td class="px-4 py-4">
                                    <span
                                        class="inline-flex rounded-full border px-2 py-1 text-xs font-semibold"
                                        :class="
                                            advisoryBandClasses(
                                                prediction.advisory_band,
                                            )
                                        "
                                    >
                                        {{
                                            advisoryBandLabel(
                                                prediction.advisory_band,
                                            )
                                        }}
                                    </span>
                                    <p class="mt-2 font-medium">
                                        {{
                                            formatPercentage(
                                                prediction.probability,
                                            )
                                        }}
                                        advisory likelihood
                                    </p>
                                    <p
                                        v-if="prediction.warning"
                                        class="mt-2 max-w-56 text-xs leading-5 text-amber-700 dark:text-amber-300"
                                    >
                                        {{ prediction.warning }}
                                    </p>
                                </td>
                                <td class="px-4 py-4">
                                    <p class="font-medium">
                                        {{ formatCurrency(prediction.amount) }}
                                    </p>
                                    <p
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        Due
                                        {{ formatDate(prediction.due_date) }}
                                    </p>
                                </td>
                                <td class="px-4 py-4">
                                    <span
                                        class="inline-flex rounded-full border px-2 py-1 text-xs font-medium"
                                        :class="
                                            historyTierClasses(
                                                prediction.history_tier,
                                            )
                                        "
                                    >
                                        {{
                                            historyTierLabel(
                                                prediction.history_tier,
                                            )
                                        }}
                                    </span>
                                    <p
                                        class="mt-2 text-xs text-muted-foreground"
                                    >
                                        {{ prediction.history_periods }} prior
                                        mature
                                        {{
                                            prediction.history_periods === 1
                                                ? 'period'
                                                : 'periods'
                                        }}
                                    </p>
                                    <p
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        Cutoff
                                        {{
                                            formatDateTime(prediction.cutoff_at)
                                        }}
                                    </p>
                                </td>
                                <td class="max-w-sm px-4 py-4">
                                    <ul
                                        v-if="prediction.factors.length > 0"
                                        class="grid gap-1.5"
                                    >
                                        <li
                                            v-for="(
                                                factor, factorIndex
                                            ) in prediction.factors"
                                            :key="factorIndex"
                                            class="flex min-w-0 items-start gap-2"
                                        >
                                            <span
                                                class="mt-2 size-1.5 shrink-0 rounded-full bg-sky-500"
                                                aria-hidden="true"
                                            />
                                            <span class="min-w-0 break-words">{{
                                                factor
                                            }}</span>
                                        </li>
                                    </ul>
                                    <p
                                        v-else
                                        class="text-xs leading-5 text-muted-foreground"
                                    >
                                        No reliable factor explanation is
                                        available for this evidence tier.
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
