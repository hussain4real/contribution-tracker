<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import HeroGsapAnimation from '@/components/home/HeroGsapAnimation.vue';
import { Button } from '@/components/ui/button';
import { useGsapPublicPageAnimations } from '@/composables/useGsapPublicPageAnimations';
import { dashboard, login, pricing, register } from '@/routes';
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    CheckCircle2,
    ChevronDown,
    ClipboardCheck,
    Eye,
    FileClock,
    LockKeyhole,
    ReceiptText,
    ShieldCheck,
} from '@lucide/vue';
import { ref, type Component } from 'vue';

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

interface LandingProof {
    icon: Component;
    title: string;
    description: string;
}

const props = withDefaults(
    defineProps<{
        canRegister: boolean;
        pricingPreviewPlans?: Plan[];
        availableFeatures?: Record<string, string>;
    }>(),
    {
        canRegister: true,
        pricingPreviewPlans: () => [],
        availableFeatures: () => ({}),
    },
);

const heroProofs = [
    {
        title: 'Who paid',
        description: 'Recorded payments and paid-in-full members stay visible.',
    },
    {
        title: 'Who partly paid',
        description: 'Partial payments show the remaining balance immediately.',
    },
    {
        title: 'Who still owes',
        description: 'Due and overdue contributions are clear without shaming.',
    },
];

const trustProofs: LandingProof[] = [
    {
        icon: Eye,
        title: 'Shared visibility',
        description:
            'Members can check their own status while admins and financial secretaries reconcile the full list.',
    },
    {
        icon: ReceiptText,
        title: 'Payment history',
        description:
            'Each contribution keeps expected amount, payments received, balance, recorder, and date.',
    },
    {
        icon: LockKeyhole,
        title: 'Privacy by role',
        description:
            'Sensitive member details stay limited to the people responsible for managing the fund.',
    },
    {
        icon: FileClock,
        title: 'Reports that match records',
        description:
            'Monthly and yearly reports are built from the same contribution records your group reviews.',
    },
];

const workflowDetails: LandingProof[] = [
    {
        icon: ClipboardCheck,
        title: 'Review this month',
        description:
            'Open the current contribution period and see paid, partial, due, and overdue members in one operational view.',
    },
    {
        icon: CheckCircle2,
        title: 'Record with confidence',
        description:
            'Add manual or online payments against the right balance so the list updates from the source record.',
    },
    {
        icon: ShieldCheck,
        title: 'Share only what is needed',
        description:
            'Use role-based access to keep accountability transparent without exposing private details broadly.',
    },
];

const faqs = [
    {
        question: 'Can everyone see who has paid?',
        answer: 'Admins and financial secretaries can review the full contribution list. Members can see the status and history available to their role.',
    },
    {
        question: 'How are partial payments handled?',
        answer: 'Partial payments reduce the outstanding balance immediately, so the remaining amount is clear during review.',
    },
    {
        question: 'Can contribution amounts differ by member?',
        answer: 'Yes. Families can use contribution categories so different members can have different expected amounts.',
    },
    {
        question: 'What makes the records trustworthy?',
        answer: 'Payments, balances, reports, and reminders all work from the same contribution records instead of separate spreadsheets.',
    },
];

const openFaqIndex = ref<number | null>(null);
const pageRoot = ref<HTMLElement | null>(null);
const { animateDisclosureEnter, animateDisclosureLeave } =
    useGsapPublicPageAnimations(pageRoot);

function toggleFaq(index: number): void {
    openFaqIndex.value = openFaqIndex.value === index ? null : index;
}

function memberLimitLabel(plan: Plan): string {
    if (plan.max_members) {
        return `Up to ${plan.max_members} members`;
    }

    return 'Custom member limit';
}

function featureLabel(feature: string): string {
    return props.availableFeatures[feature] || feature;
}
</script>

<template>
    <Head title="FamilyFund | Contribution Tracking">
        <link rel="preconnect" href="https://rsms.me/" />
        <link rel="stylesheet" href="https://rsms.me/inter/inter.css" />
    </Head>

    <div
        ref="pageRoot"
        class="min-h-screen bg-white text-slate-950 dark:bg-slate-950 dark:text-white"
    >
        <header
            class="fixed top-0 z-50 w-full border-b border-slate-200 bg-white/90 backdrop-blur-md dark:border-slate-800 dark:bg-slate-950/90"
        >
            <div
                class="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8"
            >
                <div class="flex items-center gap-2">
                    <div
                        class="flex size-9 items-center justify-center rounded-lg bg-emerald-700 text-white dark:bg-emerald-500 dark:text-emerald-950"
                    >
                        <AppLogoIcon class-name="size-5" />
                    </div>
                    <span
                        class="text-lg font-bold text-slate-900 dark:text-white"
                        >FamilyFund</span
                    >
                </div>

                <nav class="flex items-center gap-2 sm:gap-3">
                    <Link
                        :href="pricing()"
                        class="hidden rounded-md px-3 py-2 text-sm font-medium text-slate-600 transition-colors hover:text-slate-900 md:inline-flex dark:text-slate-300 dark:hover:text-white"
                    >
                        Pricing
                    </Link>
                    <ThemeToggle />
                    <Link v-if="$page.props.auth.user" :href="dashboard()">
                        <Button variant="default" size="sm"> Dashboard </Button>
                    </Link>
                    <template v-else>
                        <Link :href="login()" class="hidden sm:inline-flex">
                            <Button variant="ghost" size="sm"> Log in </Button>
                        </Link>
                        <Link v-if="props.canRegister" :href="register()">
                            <Button size="sm"> Get started </Button>
                        </Link>
                    </template>
                </nav>
            </div>
        </header>

        <section
            class="border-b border-slate-200 bg-slate-50 pt-28 pb-16 sm:pt-32 sm:pb-20 dark:border-slate-800 dark:bg-slate-950"
        >
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div
                    class="grid items-center gap-12 lg:grid-cols-[0.92fr_1.08fr]"
                >
                    <div class="max-w-2xl">
                        <div
                            class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-200"
                        >
                            <ClipboardCheck
                                class="size-4 text-emerald-700 dark:text-emerald-400"
                            />
                            Monthly contribution clarity
                        </div>

                        <h1
                            class="mt-6 text-4xl leading-tight font-bold tracking-tight text-balance text-slate-950 sm:text-6xl dark:text-white"
                        >
                            See who paid and who still owes.
                        </h1>

                        <p
                            class="mt-5 max-w-xl text-lg leading-8 text-pretty text-slate-700 dark:text-slate-300"
                        >
                            FamilyFund gives families and contribution groups a
                            shared monthly record: expected amounts, received
                            payments, remaining balances, and reports everyone
                            can trust.
                        </p>

                        <div
                            class="mt-9 flex flex-col gap-3 sm:flex-row sm:items-center"
                        >
                            <Link
                                v-if="
                                    !$page.props.auth.user && props.canRegister
                                "
                                :href="register()"
                            >
                                <Button
                                    size="lg"
                                    class="w-full bg-emerald-700 text-white hover:bg-emerald-800 sm:w-auto dark:bg-emerald-500 dark:text-emerald-950 dark:hover:bg-emerald-400"
                                >
                                    Start tracking
                                    <ArrowRight class="size-4" />
                                </Button>
                            </Link>
                            <Link
                                v-else-if="$page.props.auth.user"
                                :href="dashboard()"
                            >
                                <Button
                                    size="lg"
                                    class="w-full bg-emerald-700 text-white hover:bg-emerald-800 sm:w-auto dark:bg-emerald-500 dark:text-emerald-950 dark:hover:bg-emerald-400"
                                >
                                    Go to Dashboard
                                    <ArrowRight class="size-4" />
                                </Button>
                            </Link>
                            <Link
                                v-if="!$page.props.auth.user"
                                :href="pricing()"
                            >
                                <Button
                                    variant="outline"
                                    size="lg"
                                    class="w-full border-slate-300 bg-white sm:w-auto dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200 dark:hover:bg-slate-900"
                                >
                                    View pricing
                                </Button>
                            </Link>
                        </div>

                        <div
                            class="mt-10 max-w-xl divide-y divide-slate-200 border-y border-slate-200 dark:divide-slate-800 dark:border-slate-800"
                        >
                            <div
                                v-for="proof in heroProofs"
                                :key="proof.title"
                                class="py-4"
                            >
                                <p
                                    class="text-sm font-semibold text-slate-950 dark:text-white"
                                >
                                    {{ proof.title }}
                                </p>
                                <p
                                    class="mt-1 text-sm leading-6 text-slate-600 dark:text-slate-400"
                                >
                                    {{ proof.description }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="mx-auto w-full max-w-xl lg:mx-0 lg:max-w-none">
                        <HeroGsapAnimation
                            label="FamilyFund monthly contribution review showing paid, partial, and due members"
                            variant="home"
                        />
                    </div>
                </div>
            </div>
        </section>

        <section id="section-trust" data-gsap-section class="py-16 sm:py-24">
            <div
                class="mx-auto grid max-w-7xl gap-10 px-4 sm:px-6 lg:grid-cols-[0.8fr_1.2fr] lg:px-8"
            >
                <div data-gsap-reveal>
                    <p
                        class="text-sm font-semibold text-emerald-700 dark:text-emerald-400"
                    >
                        Trust before reminders
                    </p>
                    <h2
                        class="mt-3 max-w-xl text-3xl leading-tight font-bold tracking-tight text-balance text-slate-950 sm:text-4xl dark:text-white"
                    >
                        The record should settle the question before the group
                        chat starts.
                    </h2>
                    <p
                        class="mt-5 max-w-xl text-base leading-7 text-pretty text-slate-600 dark:text-slate-400"
                    >
                        FamilyFund keeps contribution status, payment history,
                        and reports tied to one source of truth so
                        accountability feels practical instead of personal.
                    </p>
                </div>

                <div
                    class="divide-y divide-slate-200 border-y border-slate-200 dark:divide-slate-800 dark:border-slate-800"
                >
                    <div
                        v-for="proof in trustProofs"
                        :key="proof.title"
                        class="grid gap-4 py-6 sm:grid-cols-[2.5rem_1fr]"
                        data-gsap-row
                    >
                        <div
                            class="flex size-10 items-center justify-center rounded-lg bg-slate-100 text-slate-800 dark:bg-slate-900 dark:text-slate-200"
                        >
                            <component :is="proof.icon" class="size-5" />
                        </div>
                        <div>
                            <h3
                                class="text-lg font-semibold text-slate-950 dark:text-white"
                            >
                                {{ proof.title }}
                            </h3>
                            <p
                                class="mt-2 max-w-2xl text-sm leading-6 text-slate-600 dark:text-slate-400"
                            >
                                {{ proof.description }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section
            v-if="props.pricingPreviewPlans.length"
            id="section-pricing"
            data-gsap-section
            data-testid="home-pricing-preview-animation"
            class="border-y border-slate-200 bg-slate-50 py-16 dark:border-slate-800 dark:bg-slate-900/40"
        >
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div
                    class="flex flex-col justify-between gap-6 md:flex-row md:items-end"
                    data-gsap-reveal
                >
                    <div class="max-w-2xl">
                        <h2
                            class="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl dark:text-white"
                        >
                            Plans that match your group size
                        </h2>
                        <p
                            class="mt-4 max-w-xl text-base leading-7 text-slate-600 dark:text-slate-400"
                        >
                            Start with the member count and payment workflows
                            your family needs now. Upgrade when reminders,
                            reports, or online payments need more capacity.
                        </p>
                    </div>
                    <Link :href="pricing()">
                        <Button variant="outline" class="w-full sm:w-auto">
                            Compare all plans
                            <ArrowRight class="size-4" />
                        </Button>
                    </Link>
                </div>

                <div class="mt-10 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <div
                        v-for="plan in props.pricingPreviewPlans"
                        :key="plan.id"
                        data-gsap-card
                        data-gsap-hover
                        :data-gsap-highlight="
                            plan.is_recommended ? 'true' : undefined
                        "
                        :class="[
                            'flex min-h-72 flex-col rounded-lg border p-5 transition-colors',
                            plan.is_recommended
                                ? 'border-emerald-500 bg-white dark:bg-slate-950'
                                : 'border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950',
                        ]"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3
                                    class="text-lg font-semibold text-slate-900 dark:text-white"
                                >
                                    {{ plan.name }}
                                </h3>
                                <p
                                    class="mt-1 text-sm text-slate-600 dark:text-slate-400"
                                >
                                    {{ memberLimitLabel(plan) }}
                                </p>
                            </div>
                            <span
                                v-if="plan.is_recommended"
                                class="rounded-full bg-emerald-700 px-2.5 py-1 text-xs font-medium text-white dark:bg-emerald-500 dark:text-emerald-950"
                            >
                                Recommended
                            </span>
                        </div>

                        <div class="mt-5">
                            <span
                                class="text-2xl font-bold text-slate-900 dark:text-white"
                            >
                                {{ plan.formatted_price }}
                            </span>
                            <span
                                v-if="plan.price > 0"
                                class="text-sm text-slate-500 dark:text-slate-400"
                            >
                                /month
                            </span>
                        </div>

                        <p
                            class="mt-4 flex-1 text-sm leading-6 text-slate-600 dark:text-slate-400"
                        >
                            {{ plan.summary }}
                        </p>

                        <ul class="mt-5 space-y-2">
                            <li
                                v-for="feature in plan.features.slice(0, 3)"
                                :key="feature"
                                class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300"
                            >
                                <CheckCircle2
                                    class="size-4 shrink-0 text-emerald-700 dark:text-emerald-400"
                                />
                                {{ featureLabel(feature) }}
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </section>

        <section id="section-workflow" data-gsap-section class="py-16 sm:py-24">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div
                    class="flex flex-col justify-between gap-6 md:flex-row md:items-end"
                    data-gsap-reveal
                >
                    <div class="max-w-2xl">
                        <h2
                            class="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl dark:text-white"
                        >
                            A monthly review without the spreadsheet arguments.
                        </h2>
                        <p
                            class="mt-4 text-base leading-7 text-slate-600 dark:text-slate-400"
                        >
                            The page exists to answer one question quickly: who
                            is settled, who is partly paid, and who needs a
                            follow-up?
                        </p>
                    </div>
                </div>

                <div
                    class="mt-10 overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950"
                >
                    <div
                        class="grid divide-y divide-slate-200 md:grid-cols-3 md:divide-x md:divide-y-0 dark:divide-slate-800"
                    >
                        <div
                            v-for="detail in workflowDetails"
                            :key="detail.title"
                            class="p-6"
                            data-gsap-card
                            data-gsap-hover
                        >
                            <div
                                class="mb-5 flex size-10 items-center justify-center rounded-lg bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300"
                            >
                                <component :is="detail.icon" class="size-5" />
                            </div>
                            <h3
                                class="text-lg font-semibold text-slate-950 dark:text-white"
                            >
                                {{ detail.title }}
                            </h3>
                            <p
                                class="mt-3 text-sm leading-6 text-slate-600 dark:text-slate-400"
                            >
                                {{ detail.description }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section
            id="section-faq"
            data-gsap-section
            data-testid="home-faq-animation"
            class="border-y border-slate-200 bg-slate-50 py-16 sm:py-24 dark:border-slate-800 dark:bg-slate-900/40"
        >
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-2xl text-center" data-gsap-reveal>
                    <h2
                        class="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl dark:text-white"
                    >
                        Questions families ask before trusting the record.
                    </h2>
                    <p
                        class="mt-4 text-base leading-7 text-slate-600 dark:text-slate-400"
                    >
                        Clear enough for members, detailed enough for financial
                        secretaries.
                    </p>
                </div>

                <div
                    class="mx-auto mt-12 max-w-3xl divide-y divide-slate-200 border-y border-slate-200 dark:divide-slate-800 dark:border-slate-800"
                >
                    <div
                        v-for="(faq, index) in faqs"
                        :key="index"
                        class="py-5"
                        data-gsap-row
                    >
                        <button
                            type="button"
                            @click="toggleFaq(index)"
                            class="flex w-full items-center justify-between gap-4 text-left"
                            :aria-expanded="openFaqIndex === index"
                        >
                            <span
                                class="text-base font-semibold text-slate-950 dark:text-white"
                            >
                                {{ faq.question }}
                            </span>
                            <ChevronDown
                                class="size-5 shrink-0 text-slate-500 transition-transform duration-200"
                                :class="
                                    openFaqIndex === index ? 'rotate-180' : ''
                                "
                            />
                        </button>
                        <Transition
                            :css="false"
                            @enter="animateDisclosureEnter"
                            @leave="animateDisclosureLeave"
                        >
                            <div
                                v-if="openFaqIndex === index"
                                class="overflow-hidden"
                            >
                                <p
                                    class="mt-3 text-sm leading-6 text-slate-600 dark:text-slate-400"
                                >
                                    {{ faq.answer }}
                                </p>
                            </div>
                        </Transition>
                    </div>
                </div>
            </div>
        </section>

        <section
            data-gsap-section
            data-testid="home-final-cta-animation"
            class="bg-slate-950 py-16 text-white sm:py-20"
        >
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div
                    class="grid items-center gap-8 md:grid-cols-[1fr_auto]"
                    data-gsap-reveal
                >
                    <div class="max-w-2xl">
                        <p class="text-sm font-semibold text-emerald-300">
                            Ready when the next contribution period opens
                        </p>
                        <h2
                            class="mt-3 text-3xl font-bold tracking-tight text-balance sm:text-4xl"
                        >
                            Replace the reconciliation spreadsheet with one
                            shared record.
                        </h2>
                        <p class="mt-4 text-base leading-7 text-slate-300">
                            Start with a clear monthly list. Keep the proof,
                            permissions, and reports in the same place.
                        </p>
                    </div>
                    <div data-gsap-card data-gsap-hover>
                        <Link
                            v-if="!$page.props.auth.user && props.canRegister"
                            :href="register()"
                        >
                            <Button
                                size="lg"
                                class="w-full bg-white text-emerald-950 hover:bg-emerald-100 sm:w-auto"
                            >
                                Create your account
                                <ArrowRight class="size-4" />
                            </Button>
                        </Link>
                        <Link
                            v-else-if="$page.props.auth.user"
                            :href="dashboard()"
                        >
                            <Button
                                size="lg"
                                class="w-full bg-white text-emerald-950 hover:bg-emerald-100 sm:w-auto"
                            >
                                Go to Dashboard
                                <ArrowRight class="size-4" />
                            </Button>
                        </Link>
                        <Link v-else :href="login()">
                            <Button
                                size="lg"
                                class="w-full bg-white text-emerald-950 hover:bg-emerald-100 sm:w-auto"
                            >
                                Sign in to continue
                                <ArrowRight class="size-4" />
                            </Button>
                        </Link>
                    </div>
                </div>
            </div>
        </section>

        <footer
            class="border-t border-slate-200 bg-white py-10 dark:border-slate-800 dark:bg-slate-950"
        >
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div
                    class="flex flex-col items-center justify-between gap-4 sm:flex-row"
                >
                    <div class="flex items-center gap-2">
                        <div
                            class="flex size-8 items-center justify-center rounded-lg bg-emerald-700 text-white dark:bg-emerald-500 dark:text-emerald-950"
                        >
                            <AppLogoIcon class-name="size-4" />
                        </div>
                        <span
                            class="font-semibold text-slate-900 dark:text-white"
                            >FamilyFund</span
                        >
                    </div>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        © {{ new Date().getFullYear() }} FamilyFund.
                        Contribution records made clear and accountable.
                    </p>
                    <div class="flex items-center gap-4">
                        <Link
                            href="/privacy"
                            class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200"
                        >
                            Privacy
                        </Link>
                        <Link
                            href="/terms"
                            class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200"
                        >
                            Terms
                        </Link>
                    </div>
                </div>
            </div>
        </footer>
    </div>
</template>
