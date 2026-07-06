<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import { Button } from '@/components/ui/button';
import { useGsapPublicPageAnimations } from '@/composables/useGsapPublicPageAnimations';
import { dashboard, home, login, register } from '@/routes';
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    Check,
    CheckCircle2,
    ClipboardCheck,
    CreditCard,
    FileText,
    Minus,
    ShieldCheck,
    Users,
} from '@lucide/vue';
import { computed, ref, type Component } from 'vue';

interface Plan {
    id: number;
    name: string;
    slug: string;
    price: number;
    formatted_price: string;
    max_members: number | null;
    features: string[];
    is_current: boolean;
    audience: string;
    summary: string;
    is_recommended: boolean;
}

interface Props {
    plans?: Plan[];
    available_features?: Record<string, string>;
    canRegister?: boolean;
}

interface ComparisonRow {
    label: string;
    description: string;
    feature?: string;
    value?: 'member_limit';
    includedSlugs?: string[];
}

interface ComparisonGroup {
    title: string;
    rows: ComparisonRow[];
}

interface PlanCopy {
    fit: string;
    decision: string;
    action: string;
    highlights: string[];
}

interface DecisionStep {
    prompt: string;
    answer: string;
    planSlug: string;
}

interface TrustItem {
    icon: Component;
    title: string;
    description: string;
}

const props = withDefaults(defineProps<Props>(), {
    plans: () => [],
    available_features: () => ({}),
    canRegister: true,
});

const page = usePage();
const pageRoot = ref<HTMLElement | null>(null);
const featureLabels = computed(() => props.available_features);
const isAuthenticated = computed(() => Boolean(page.props.auth?.user));
const recommendedPlan = computed(
    () => props.plans.find((plan) => plan.is_recommended) ?? null,
);
const primaryPlan = computed(
    () => recommendedPlan.value ?? props.plans[0] ?? null,
);
const organizationPlan = computed(
    () =>
        props.plans.find((plan) => plan.slug === 'organization') ??
        props.plans.at(-1) ??
        null,
);

useGsapPublicPageAnimations(pageRoot);

const planCopy: Record<string, PlanCopy> = {
    free: {
        fit: 'Manual records for a very small group.',
        decision:
            'Use it when you need a trusted ledger before payment automation.',
        action: 'Start Free',
        highlights: [
            'Contribution categories',
            'Manual payment recording',
            'Member balance visibility',
        ],
    },
    family: {
        fit: 'The best first paid plan for active family funds.',
        decision:
            'Choose it when members should pay themselves and reminders need to leave the spreadsheet.',
        action: 'Choose Family',
        highlights: [
            'Paystack member self-pay',
            'Email, push, and WhatsApp reminders',
            'Monthly and annual reports',
        ],
    },
    growth: {
        fit: 'More capacity for larger groups and finance reviews.',
        decision:
            'Use it when exports, summaries, and a bigger member list matter every month.',
        action: 'Choose Growth',
        highlights: [
            'CSV exports',
            'AI-assisted report summaries',
            'Up to 75 members',
        ],
    },
    organization: {
        fit: 'For associations and groups that need hands-on onboarding.',
        decision:
            'Choose it when WhatsApp replies, priority support, and onboarding help are part of the job.',
        action: 'Choose Organization',
        highlights: [
            'WhatsApp inbox and replies',
            'Priority support',
            'Assisted onboarding review',
        ],
    },
};

const fallbackPlanCopy: PlanCopy = {
    fit: 'A plan for this FamilyFund workspace.',
    decision: 'Choose it when the capacity and workflow match your group.',
    action: 'Choose plan',
    highlights: ['Contribution records', 'Payment tracking', 'Plan support'],
};

const decisionSteps: DecisionStep[] = [
    {
        prompt: 'Only need a shared ledger?',
        answer: 'Start Free.',
        planSlug: 'free',
    },
    {
        prompt: 'Members should pay online?',
        answer: 'Choose Family.',
        planSlug: 'family',
    },
    {
        prompt: 'Need exports or more capacity?',
        answer: 'Choose Growth.',
        planSlug: 'growth',
    },
    {
        prompt: 'Need WhatsApp inbox or onboarding help?',
        answer: 'Choose Organization.',
        planSlug: 'organization',
    },
];

const comparisonGroups: ComparisonGroup[] = [
    {
        title: 'Capacity and records',
        rows: [
            {
                label: 'Member cap',
                description: 'Maximum members covered by the self-serve plan.',
                value: 'member_limit',
            },
            {
                label: 'Monthly contributions',
                description: 'Set categories and generate member obligations.',
                feature: 'basic_contributions',
            },
            {
                label: 'Manual payment recording',
                description:
                    'Record cash, transfer, and other offline payments.',
                feature: 'manual_payments',
            },
        ],
    },
    {
        title: 'Payments and reminders',
        rows: [
            {
                label: 'Online payments',
                description:
                    'Let members pay their own obligations with Paystack.',
                feature: 'online_payments',
            },
            {
                label: 'Notification center',
                description:
                    'In-app feed for reminders and contribution updates.',
                includedSlugs: ['free', 'family', 'growth', 'organization'],
            },
            {
                label: 'Email reminders',
                description: 'Send contribution reminders through email.',
                feature: 'email_reminders',
            },
            {
                label: 'Browser push reminders',
                description: 'Send browser alerts to members who enable them.',
                feature: 'web_push_reminders',
            },
            {
                label: 'WhatsApp reminders',
                description: 'Send WhatsApp contribution reminders to members.',
                feature: 'whatsapp_reminders',
            },
        ],
    },
    {
        title: 'Reports and support',
        rows: [
            {
                label: 'Financial reports',
                description:
                    'Generate monthly and annual reports from records.',
                feature: 'reports',
            },
            {
                label: 'CSV exports',
                description: 'Export contribution data for offline review.',
                feature: 'exports',
            },
            {
                label: 'AI summaries',
                description:
                    'Use AI assistance when it is enabled for the workspace.',
                feature: 'ai_assistant',
            },
            {
                label: 'WhatsApp inbox',
                description: 'Manage incoming WhatsApp messages and replies.',
                feature: 'whatsapp_messaging',
            },
            {
                label: 'Priority support',
                description: 'Faster support and assisted onboarding help.',
                feature: 'priority_support',
            },
        ],
    },
];

const trustItems: TrustItem[] = [
    {
        icon: CreditCard,
        title: 'Paystack handles online payments',
        description:
            'FamilyFund records the obligation and payment status; Paystack handles the online payment flow.',
    },
    {
        icon: ClipboardCheck,
        title: 'Records stay reviewable',
        description:
            'Admins can reconcile paid, partial, due, and overdue balances from the same contribution records.',
    },
    {
        icon: FileText,
        title: 'Exports do not lock you in',
        description:
            'Paid plans can export contribution data when the group needs an offline review trail.',
    },
    {
        icon: ShieldCheck,
        title: 'Support scales with the workflow',
        description:
            'Organization adds priority support and onboarding review for higher-volume groups.',
    },
];

function planCopyFor(plan: Plan): PlanCopy {
    return planCopy[plan.slug] ?? fallbackPlanCopy;
}

function planForSlug(slug: string): Plan | null {
    return props.plans.find((plan) => plan.slug === slug) ?? null;
}

function memberLimitLabel(plan: Plan): string {
    if (plan.max_members) {
        return `Up to ${plan.max_members} members`;
    }

    return 'Custom member limit';
}

function featureLabel(feature: string): string {
    return featureLabels.value[feature] || feature;
}

function comparisonRowLabel(row: ComparisonRow): string {
    return row.feature ? featureLabel(row.feature) : row.label;
}

function comparisonRowIncluded(plan: Plan, row: ComparisonRow): boolean {
    if (row.value === 'member_limit') {
        return true;
    }

    if (row.feature) {
        return plan.features.includes(row.feature);
    }

    return row.includedSlugs?.includes(plan.slug) ?? false;
}

function comparisonCellLabel(plan: Plan, row: ComparisonRow): string {
    if (row.value === 'member_limit') {
        return memberLimitLabel(plan);
    }

    return comparisonRowIncluded(plan, row) ? 'Included' : 'Not included';
}

function planActionLabel(plan: Plan): string {
    if (plan.is_current) {
        return 'Current plan';
    }

    if (isAuthenticated.value) {
        return `Review ${plan.name}`;
    }

    if (!props.canRegister) {
        return `Log in for ${plan.name}`;
    }

    return planCopyFor(plan).action;
}

function planHref(plan: Plan): ReturnType<typeof register> {
    const options = { query: { plan: plan.slug } };

    if (isAuthenticated.value) {
        return dashboard(undefined, options);
    }

    if (props.canRegister) {
        return register(options);
    }

    return login(options);
}
</script>

<template>
    <Head title="Pricing | FamilyFund" />

    <div
        ref="pageRoot"
        class="min-h-screen bg-white text-slate-950 dark:bg-slate-950 dark:text-white"
    >
        <header
            class="sticky top-0 z-50 border-b border-slate-200 bg-white/90 backdrop-blur-md dark:border-slate-800 dark:bg-slate-950/90"
        >
            <div
                class="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8"
            >
                <Link :href="home()" class="flex items-center gap-2">
                    <span
                        class="flex size-9 items-center justify-center rounded-lg bg-emerald-700 text-white dark:bg-emerald-500 dark:text-emerald-950"
                    >
                        <AppLogoIcon class-name="size-5" />
                    </span>
                    <span class="text-lg font-bold">FamilyFund</span>
                </Link>

                <nav class="flex items-center gap-2 sm:gap-3">
                    <Link
                        :href="home()"
                        class="hidden rounded-md px-3 py-2 text-sm font-medium text-slate-600 transition-colors hover:text-slate-950 md:inline-flex dark:text-slate-300 dark:hover:text-white"
                    >
                        Overview
                    </Link>
                    <ThemeToggle />
                    <Link v-if="isAuthenticated" :href="dashboard()">
                        <Button size="sm">
                            Dashboard
                            <ArrowRight class="size-4" />
                        </Button>
                    </Link>
                    <template v-else>
                        <Link :href="login()" class="hidden sm:inline-flex">
                            <Button variant="ghost" size="sm">Log in</Button>
                        </Link>
                        <Link
                            v-if="props.canRegister"
                            :href="
                                primaryPlan ? planHref(primaryPlan) : register()
                            "
                        >
                            <Button size="sm">Get started</Button>
                        </Link>
                    </template>
                </nav>
            </div>
        </header>

        <main>
            <section
                class="border-b border-slate-200 bg-slate-50 py-14 sm:py-20 dark:border-slate-800 dark:bg-slate-950"
            >
                <div
                    class="mx-auto grid max-w-7xl gap-10 px-4 sm:px-6 lg:grid-cols-[minmax(0,0.95fr)_minmax(320px,0.65fr)] lg:px-8"
                >
                    <div class="max-w-3xl">
                        <p
                            class="text-sm font-semibold text-emerald-700 dark:text-emerald-400"
                        >
                            Monthly pricing in NGN
                        </p>
                        <h1
                            class="mt-4 max-w-4xl text-4xl leading-tight font-bold tracking-tight text-balance text-slate-950 sm:text-5xl dark:text-white"
                        >
                            Choose by how your group collects contributions.
                        </h1>
                        <p
                            class="mt-5 max-w-2xl text-base leading-7 text-pretty text-slate-600 sm:text-lg dark:text-slate-400"
                        >
                            Start with member count. Upgrade when members should
                            pay online, admins need reports, or reminders need
                            more than a group chat.
                        </p>

                        <div
                            class="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center"
                        >
                            <Link
                                v-if="primaryPlan"
                                :href="planHref(primaryPlan)"
                            >
                                <Button
                                    size="lg"
                                    class="w-full bg-emerald-700 text-white hover:bg-emerald-800 sm:w-auto dark:bg-emerald-500 dark:text-emerald-950 dark:hover:bg-emerald-400"
                                >
                                    {{ planActionLabel(primaryPlan) }}
                                    <ArrowRight class="size-4" />
                                </Button>
                            </Link>
                            <a href="#plan-details">
                                <Button
                                    variant="outline"
                                    size="lg"
                                    class="w-full border-slate-300 bg-white sm:w-auto dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200 dark:hover:bg-slate-900"
                                >
                                    Compare details
                                </Button>
                            </a>
                        </div>

                        <dl
                            class="mt-10 grid max-w-2xl gap-0 divide-y divide-slate-200 border-y border-slate-200 text-sm sm:grid-cols-3 sm:divide-x sm:divide-y-0 dark:divide-slate-800 dark:border-slate-800"
                        >
                            <div class="py-4 sm:px-4 sm:first:pl-0">
                                <dt
                                    class="font-semibold text-slate-950 dark:text-white"
                                >
                                    Pick the smallest fit
                                </dt>
                                <dd
                                    class="mt-1 leading-6 text-slate-600 dark:text-slate-400"
                                >
                                    Capacity first, extras second.
                                </dd>
                            </div>
                            <div class="py-4 sm:px-4">
                                <dt
                                    class="font-semibold text-slate-950 dark:text-white"
                                >
                                    Self-pay when ready
                                </dt>
                                <dd
                                    class="mt-1 leading-6 text-slate-600 dark:text-slate-400"
                                >
                                    Family adds Paystack payments.
                                </dd>
                            </div>
                            <div class="py-4 sm:px-4 sm:last:pr-0">
                                <dt
                                    class="font-semibold text-slate-950 dark:text-white"
                                >
                                    Records remain portable
                                </dt>
                                <dd
                                    class="mt-1 leading-6 text-slate-600 dark:text-slate-400"
                                >
                                    Growth unlocks CSV exports.
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <aside
                        class="self-start border-y border-slate-200 py-2 lg:rounded-lg lg:border lg:bg-white lg:p-6 dark:border-slate-800 lg:dark:bg-slate-900/40"
                        data-testid="pricing-decision-guide"
                    >
                        <div class="flex items-center gap-3 px-0 py-4 lg:p-0">
                            <span
                                class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-100"
                            >
                                <Users class="size-5" />
                            </span>
                            <div>
                                <h2
                                    class="text-base font-semibold text-slate-950 dark:text-white"
                                >
                                    The quick answer
                                </h2>
                                <p
                                    class="mt-1 text-sm leading-6 text-slate-600 dark:text-slate-400"
                                >
                                    Match the plan to the workflow you actually
                                    run.
                                </p>
                            </div>
                        </div>

                        <div
                            class="divide-y divide-slate-200 border-t border-slate-200 lg:mt-5 dark:divide-slate-800 dark:border-slate-800"
                        >
                            <div
                                v-for="step in decisionSteps"
                                :key="step.planSlug"
                                class="grid gap-3 py-4 sm:grid-cols-[1fr_auto] sm:items-center"
                            >
                                <div>
                                    <p
                                        class="text-sm font-medium text-slate-950 dark:text-white"
                                    >
                                        {{ step.prompt }}
                                    </p>
                                    <p
                                        class="mt-1 text-sm text-slate-600 dark:text-slate-400"
                                    >
                                        {{ step.answer }}
                                    </p>
                                </div>
                                <Link
                                    v-if="planForSlug(step.planSlug)"
                                    :href="
                                        planHref(planForSlug(step.planSlug)!)
                                    "
                                    class="text-sm font-semibold text-emerald-700 hover:text-emerald-800 dark:text-emerald-400 dark:hover:text-emerald-300"
                                >
                                    Select
                                </Link>
                            </div>
                        </div>
                    </aside>
                </div>
            </section>

            <section
                id="plans"
                class="py-14 sm:py-20"
                data-gsap-section
                data-testid="pricing-plan-card-animation"
            >
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div
                        class="flex flex-col justify-between gap-5 md:flex-row md:items-end"
                        data-gsap-reveal
                    >
                        <div class="max-w-2xl">
                            <h2
                                class="text-3xl font-bold tracking-tight text-balance text-slate-950 sm:text-4xl dark:text-white"
                            >
                                Choose the smallest plan that matches the job.
                            </h2>
                            <p
                                class="mt-4 text-base leading-7 text-pretty text-slate-600 dark:text-slate-400"
                            >
                                The plan list is ordered by the pressure it
                                removes: ledger, self-pay, exports, then
                                onboarding support.
                            </p>
                        </div>
                        <p
                            v-if="recommendedPlan"
                            class="max-w-sm text-sm leading-6 text-slate-600 dark:text-slate-400"
                        >
                            Most active family funds start with
                            <span
                                class="font-semibold text-slate-950 dark:text-white"
                                >{{ recommendedPlan.name }}</span
                            >
                            because it adds online payments and reminders
                            without requiring an organization setup.
                        </p>
                    </div>

                    <div
                        class="mt-10 divide-y divide-slate-200 border-y border-slate-200 dark:divide-slate-800 dark:border-slate-800"
                        data-testid="pricing-plan-grid"
                    >
                        <article
                            v-for="plan in props.plans"
                            :key="plan.id"
                            data-gsap-row
                            data-gsap-hover
                            data-testid="pricing-plan-row"
                            :data-plan-slug="plan.slug"
                            :data-plan-name="plan.name"
                            :data-plan-amount="plan.formatted_price"
                            :data-plan-member-limit="plan.max_members"
                            :data-gsap-highlight="
                                plan.is_recommended ? 'true' : undefined
                            "
                            :class="[
                                'grid gap-6 py-7 transition-colors md:grid-cols-[minmax(0,1.35fr)_minmax(10rem,0.45fr)_minmax(0,1fr)_auto] md:items-center',
                                plan.is_recommended
                                    ? 'bg-emerald-50/70 px-4 sm:px-5 dark:bg-emerald-950/20'
                                    : '',
                            ]"
                        >
                            <div>
                                <div
                                    class="flex flex-wrap items-center gap-2 text-sm"
                                >
                                    <h3
                                        class="text-xl font-semibold text-slate-950 dark:text-white"
                                    >
                                        {{ plan.name }}
                                    </h3>
                                    <span
                                        v-if="plan.is_recommended"
                                        class="rounded-full bg-emerald-700 px-2.5 py-1 text-xs font-semibold text-white dark:bg-emerald-500 dark:text-emerald-950"
                                    >
                                        Recommended
                                    </span>
                                    <span
                                        v-if="plan.is_current"
                                        class="rounded-full bg-slate-900 px-2.5 py-1 text-xs font-semibold text-white dark:bg-white dark:text-slate-950"
                                    >
                                        Current
                                    </span>
                                </div>
                                <p
                                    class="mt-2 max-w-2xl text-sm leading-6 text-slate-600 dark:text-slate-400"
                                >
                                    {{ planCopyFor(plan).fit }}
                                </p>
                                <p
                                    class="mt-2 max-w-2xl text-sm leading-6 text-slate-700 dark:text-slate-300"
                                >
                                    {{ planCopyFor(plan).decision }}
                                </p>
                            </div>

                            <div>
                                <p
                                    class="text-2xl font-bold text-slate-950 dark:text-white"
                                >
                                    {{ plan.formatted_price }}
                                </p>
                                <p
                                    class="mt-1 text-sm text-slate-600 dark:text-slate-400"
                                >
                                    <template v-if="plan.price > 0">
                                        per month
                                    </template>
                                    <template v-else>no monthly fee</template>
                                </p>
                                <p
                                    class="mt-3 flex items-center gap-2 text-sm font-medium text-slate-800 dark:text-slate-200"
                                >
                                    <Users
                                        class="size-4 text-emerald-700 dark:text-emerald-400"
                                    />
                                    {{ memberLimitLabel(plan) }}
                                </p>
                            </div>

                            <ul class="space-y-2">
                                <li
                                    v-for="highlight in planCopyFor(plan)
                                        .highlights"
                                    :key="highlight"
                                    class="flex gap-2 text-sm leading-5 text-slate-700 dark:text-slate-300"
                                >
                                    <CheckCircle2
                                        class="mt-0.5 size-4 shrink-0 text-emerald-700 dark:text-emerald-400"
                                    />
                                    <span>{{ highlight }}</span>
                                </li>
                            </ul>

                            <div class="md:min-w-40">
                                <Button
                                    v-if="plan.is_current"
                                    disabled
                                    class="w-full"
                                >
                                    {{ planActionLabel(plan) }}
                                </Button>
                                <Link v-else :href="planHref(plan)">
                                    <Button
                                        class="w-full"
                                        :variant="
                                            plan.is_recommended
                                                ? 'default'
                                                : 'outline'
                                        "
                                    >
                                        {{ planActionLabel(plan) }}
                                        <ArrowRight class="size-4" />
                                    </Button>
                                </Link>
                            </div>
                        </article>
                    </div>

                    <div
                        v-if="organizationPlan"
                        class="mt-8 flex flex-col gap-4 border-y border-slate-200 py-5 text-sm leading-6 text-slate-600 md:flex-row md:items-center md:justify-between dark:border-slate-800 dark:text-slate-400"
                        data-gsap-reveal
                    >
                        <p class="max-w-3xl">
                            More than 250 members? Start with
                            <span
                                class="font-semibold text-slate-950 dark:text-white"
                                >{{ organizationPlan.name }}</span
                            >. The onboarding review confirms support load,
                            payment volume, and migration needs before the group
                            scales further.
                        </p>
                        <Link :href="planHref(organizationPlan)">
                            <Button variant="outline" class="w-full sm:w-auto">
                                Start onboarding review
                                <ArrowRight class="size-4" />
                            </Button>
                        </Link>
                    </div>
                </div>
            </section>

            <section
                id="plan-details"
                class="border-y border-slate-200 bg-slate-50 py-14 sm:py-20 dark:border-slate-800 dark:bg-slate-900/40"
                data-gsap-section
                data-testid="pricing-comparison-animation"
            >
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="max-w-3xl" data-gsap-reveal>
                        <h2
                            class="text-3xl font-bold tracking-tight text-balance text-slate-950 sm:text-4xl dark:text-white"
                        >
                            Plan details without the spreadsheet scroll.
                        </h2>
                        <p
                            class="mt-4 text-base leading-7 text-pretty text-slate-600 dark:text-slate-400"
                        >
                            On small screens, open one plan at a time. On wider
                            screens, compare the same details side by side.
                        </p>
                    </div>

                    <div class="mt-8 space-y-3 lg:hidden" data-gsap-reveal>
                        <details
                            v-for="plan in props.plans"
                            :key="plan.id"
                            class="group rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950"
                            :open="plan.is_recommended"
                        >
                            <summary
                                class="flex cursor-pointer list-none items-center justify-between gap-4 p-4"
                            >
                                <span>
                                    <span
                                        class="block text-base font-semibold text-slate-950 dark:text-white"
                                    >
                                        {{ plan.name }}
                                    </span>
                                    <span
                                        class="mt-1 block text-sm text-slate-600 dark:text-slate-400"
                                    >
                                        {{ plan.formatted_price
                                        }}<template v-if="plan.price > 0">
                                            per month</template
                                        >
                                    </span>
                                </span>
                                <ArrowRight
                                    class="size-4 text-slate-500 transition-transform group-open:rotate-90"
                                />
                            </summary>

                            <div
                                class="border-t border-slate-200 px-4 py-2 dark:border-slate-800"
                            >
                                <div
                                    v-for="group in comparisonGroups"
                                    :key="`${plan.slug}-${group.title}`"
                                    class="py-4"
                                >
                                    <h3
                                        class="text-sm font-semibold text-slate-950 dark:text-white"
                                    >
                                        {{ group.title }}
                                    </h3>
                                    <dl class="mt-3 space-y-3">
                                        <div
                                            v-for="row in group.rows"
                                            :key="`${plan.slug}-${row.label}`"
                                            class="grid gap-2 text-sm"
                                        >
                                            <dt
                                                class="text-slate-600 dark:text-slate-400"
                                            >
                                                {{ comparisonRowLabel(row) }}
                                            </dt>
                                            <dd
                                                class="flex items-center gap-2 font-medium text-slate-950 dark:text-white"
                                            >
                                                <Check
                                                    v-if="
                                                        comparisonRowIncluded(
                                                            plan,
                                                            row,
                                                        )
                                                    "
                                                    class="size-4 text-emerald-700 dark:text-emerald-400"
                                                />
                                                <Minus
                                                    v-else
                                                    class="size-4 text-slate-400"
                                                />
                                                {{
                                                    comparisonCellLabel(
                                                        plan,
                                                        row,
                                                    )
                                                }}
                                            </dd>
                                        </div>
                                    </dl>
                                </div>
                            </div>
                        </details>
                    </div>

                    <div class="mt-10 hidden lg:block" data-gsap-reveal>
                        <div
                            v-for="group in comparisonGroups"
                            :key="group.title"
                            class="mb-8 overflow-hidden rounded-lg border border-slate-200 bg-white last:mb-0 dark:border-slate-800 dark:bg-slate-950"
                        >
                            <h3
                                class="border-b border-slate-200 px-5 py-4 text-base font-semibold text-slate-950 dark:border-slate-800 dark:text-white"
                            >
                                {{ group.title }}
                            </h3>
                            <table class="w-full table-fixed text-sm">
                                <thead
                                    class="border-b border-slate-200 bg-slate-50/80 dark:border-slate-800 dark:bg-slate-900/60"
                                >
                                    <tr>
                                        <th
                                            scope="col"
                                            class="w-[30%] px-5 py-3 text-left text-xs font-semibold text-slate-600 dark:text-slate-400"
                                        >
                                            Feature
                                        </th>
                                        <th
                                            v-for="plan in props.plans"
                                            :key="`${group.title}-${plan.id}`"
                                            scope="col"
                                            class="px-4 py-3 text-center"
                                        >
                                            <span
                                                class="block font-semibold text-slate-950 dark:text-white"
                                            >
                                                {{ plan.name }}
                                            </span>
                                            <span
                                                class="mt-1 block text-xs font-normal text-slate-500 dark:text-slate-400"
                                            >
                                                {{ plan.formatted_price }}
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody
                                    class="divide-y divide-slate-200 dark:divide-slate-800"
                                >
                                    <tr
                                        v-for="row in group.rows"
                                        :key="`${group.title}-${row.label}`"
                                        data-gsap-row
                                        class="align-top"
                                    >
                                        <th
                                            scope="row"
                                            class="w-[30%] px-5 py-4 text-left"
                                        >
                                            <span
                                                class="block font-semibold text-slate-950 dark:text-white"
                                            >
                                                {{ comparisonRowLabel(row) }}
                                            </span>
                                            <span
                                                class="mt-1 block text-xs leading-5 text-slate-600 dark:text-slate-400"
                                            >
                                                {{ row.description }}
                                            </span>
                                        </th>
                                        <td
                                            v-for="plan in props.plans"
                                            :key="`${group.title}-${row.label}-${plan.id}`"
                                            class="px-4 py-4 text-center"
                                        >
                                            <span
                                                v-if="
                                                    row.value === 'member_limit'
                                                "
                                                class="font-medium text-slate-950 dark:text-white"
                                            >
                                                {{
                                                    comparisonCellLabel(
                                                        plan,
                                                        row,
                                                    )
                                                }}
                                            </span>
                                            <span
                                                v-else-if="
                                                    comparisonRowIncluded(
                                                        plan,
                                                        row,
                                                    )
                                                "
                                                class="inline-flex items-center justify-center gap-1.5 font-medium text-emerald-700 dark:text-emerald-400"
                                            >
                                                <Check class="size-4" />
                                                Included
                                            </span>
                                            <span
                                                v-else
                                                class="inline-flex items-center justify-center gap-1.5 text-slate-500 dark:text-slate-400"
                                            >
                                                <Minus class="size-4" />
                                                Not included
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <section class="py-14 sm:py-20" data-gsap-section>
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div
                        class="grid gap-10 lg:grid-cols-[0.72fr_1.28fr]"
                        data-gsap-reveal
                    >
                        <div class="max-w-xl">
                            <div
                                class="mb-5 flex size-11 items-center justify-center rounded-lg bg-slate-100 text-slate-800 dark:bg-slate-900 dark:text-slate-200"
                            >
                                <ShieldCheck class="size-5" />
                            </div>
                            <h2
                                class="text-3xl font-bold tracking-tight text-balance text-slate-950 sm:text-4xl dark:text-white"
                            >
                                What stays true after you upgrade.
                            </h2>
                            <p
                                class="mt-4 text-base leading-7 text-pretty text-slate-600 dark:text-slate-400"
                            >
                                Paid plans add workflows. They do not change the
                                basic rule: contribution records remain the
                                source of truth.
                            </p>
                        </div>

                        <div
                            class="divide-y divide-slate-200 border-y border-slate-200 dark:divide-slate-800 dark:border-slate-800"
                        >
                            <div
                                v-for="item in trustItems"
                                :key="item.title"
                                class="grid gap-4 py-6 sm:grid-cols-[2.75rem_1fr]"
                                data-gsap-row
                            >
                                <span
                                    class="flex size-10 items-center justify-center rounded-lg bg-slate-100 text-slate-800 dark:bg-slate-900 dark:text-slate-200"
                                >
                                    <component :is="item.icon" class="size-5" />
                                </span>
                                <div>
                                    <h3
                                        class="text-base font-semibold text-slate-950 dark:text-white"
                                    >
                                        {{ item.title }}
                                    </h3>
                                    <p
                                        class="mt-2 max-w-2xl text-sm leading-6 text-slate-600 dark:text-slate-400"
                                    >
                                        {{ item.description }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section
                v-if="primaryPlan"
                class="border-t border-slate-800 bg-slate-950 py-12 text-white"
            >
                <div
                    class="mx-auto flex max-w-7xl flex-col gap-6 px-4 sm:px-6 md:flex-row md:items-center md:justify-between lg:px-8"
                >
                    <div class="max-w-2xl">
                        <p class="text-sm font-semibold text-emerald-300">
                            Ready when the group is
                        </p>
                        <h2 class="mt-2 text-2xl font-bold tracking-tight">
                            Most active family funds can start with
                            {{ primaryPlan.name }}.
                        </h2>
                        <p class="mt-3 text-sm leading-6 text-slate-300">
                            You can change plans as the member list, payment
                            channels, and reporting needs grow.
                        </p>
                    </div>

                    <Link :href="planHref(primaryPlan)">
                        <Button
                            size="lg"
                            class="w-full bg-emerald-500 text-emerald-950 hover:bg-emerald-400 sm:w-auto"
                        >
                            {{ planActionLabel(primaryPlan) }}
                            <ArrowRight class="size-4" />
                        </Button>
                    </Link>
                </div>
            </section>
        </main>
    </div>
</template>
