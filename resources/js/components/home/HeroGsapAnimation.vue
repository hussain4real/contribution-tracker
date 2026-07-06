<script setup lang="ts">
import {
    ArrowRight,
    CheckCircle2,
    ClipboardCheck,
    ReceiptText,
    ShieldCheck,
    TrendingUp,
    Users,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

interface Props {
    label: string;
    variant?: 'home' | 'pricing';
    plans?: PricingPlan[];
}

interface PricingPlan {
    id: number;
    name: string;
    slug: string;
    formatted_price: string;
    max_members: number | null;
    is_recommended: boolean;
}

type PlanTone = 'slate' | 'emerald' | 'sky' | 'teal';

interface PlanDisplayHint {
    height: string;
    tone: PlanTone;
}

interface PlanLadderItem extends PlanDisplayHint {
    key: string;
    slug: string;
    name: string;
    amount: string;
    memberValue: string;
    memberCaption: string;
    recommended: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    variant: 'home',
    plans: () => [],
});

type AnimationContext = {
    revert: () => void;
};

type ContributionStatus = 'Paid' | 'Partial' | 'Still owes';

const root = ref<HTMLElement | null>(null);
const isReducedMotion = ref(false);
const isPricing = computed(() => props.variant === 'pricing');

let motionPreference: MediaQueryList | null = null;
let animationContext: AnimationContext | null = null;

const contributionSummary = [
    { label: 'Paid', value: '17', tone: 'emerald' },
    { label: 'Partial', value: '3', tone: 'amber' },
    { label: 'Still owes', value: '4', tone: 'rose' },
];

const paymentRows = [
    {
        name: 'Amina H.',
        expected: '₦24,000',
        paid: '₦24,000',
        balance: '₦0',
        status: 'Paid' as ContributionStatus,
    },
    {
        name: 'Farouk B.',
        expected: '₦18,000',
        paid: '₦10,000',
        balance: '₦8,000',
        status: 'Partial' as ContributionStatus,
    },
    {
        name: 'Maryam S.',
        expected: '₦12,000',
        paid: '₦0',
        balance: '₦12,000',
        status: 'Still owes' as ContributionStatus,
    },
];

const fallbackPricingPlans: PricingPlan[] = [
    {
        id: 0,
        name: 'Free',
        slug: 'free',
        formatted_price: 'Free',
        max_members: 5,
        is_recommended: false,
    },
    {
        id: 1,
        name: 'Family',
        slug: 'family',
        formatted_price: '₦3,000',
        max_members: 25,
        is_recommended: true,
    },
    {
        id: 2,
        name: 'Growth',
        slug: 'growth',
        formatted_price: '₦7,500',
        max_members: 75,
        is_recommended: false,
    },
    {
        id: 3,
        name: 'Organization',
        slug: 'organization',
        formatted_price: '₦20,000',
        max_members: 250,
        is_recommended: false,
    },
];

const planDisplayHintsBySlug: Record<string, PlanDisplayHint> = {
    free: { height: 'h-24', tone: 'slate' },
    family: { height: 'h-36', tone: 'emerald' },
    growth: { height: 'h-48', tone: 'sky' },
    organization: { height: 'h-56', tone: 'teal' },
};

const fallbackPlanDisplayHints: PlanDisplayHint[] = [
    { height: 'h-24', tone: 'slate' },
    { height: 'h-36', tone: 'emerald' },
    { height: 'h-48', tone: 'sky' },
    { height: 'h-56', tone: 'teal' },
];

const pricingPlanLadder = computed<PlanLadderItem[]>(() => {
    const plans = props.plans.length > 0 ? props.plans : fallbackPricingPlans;

    return plans.map((plan, index) => {
        const hint =
            planDisplayHintsBySlug[plan.slug] ??
            fallbackPlanDisplayHints[index] ??
            fallbackPlanDisplayHints[fallbackPlanDisplayHints.length - 1];

        return {
            ...hint,
            key: `${plan.slug}-${plan.id}`,
            slug: plan.slug,
            name: plan.name,
            amount: plan.formatted_price,
            memberValue:
                plan.max_members === null ? 'Custom' : String(plan.max_members),
            memberCaption: plan.max_members === null ? 'limit' : 'members',
            recommended: plan.is_recommended,
        };
    });
});

const pricingFeatures = ['Online payments', 'Reports', 'Exports', 'WhatsApp'];

function cleanupAnimation(): void {
    animationContext?.revert();
    animationContext = null;
}

async function animateScene(): Promise<void> {
    if (!root.value || isReducedMotion.value) {
        return;
    }

    cleanupAnimation();

    const { gsap } = await import('gsap');

    if (!root.value || isReducedMotion.value) {
        return;
    }

    const animationRoot = root.value;

    animationContext = gsap.context(() => {
        const revealTargets = gsap.utils.toArray('[data-gsap-reveal]');
        const popTargets = gsap.utils.toArray('[data-gsap-pop]');
        const meterTargets = gsap.utils.toArray('[data-gsap-meter]');
        const ladderTargets = gsap.utils.toArray('[data-gsap-ladder]');
        const floatTargets = gsap.utils.toArray('[data-gsap-float]');
        const pulseTargets = gsap.utils.toArray('[data-gsap-pulse]');

        if (revealTargets.length) {
            gsap.set(revealTargets, { y: 18 });
        }

        if (popTargets.length) {
            gsap.set(popTargets, { scale: 0.96 });
        }

        if (meterTargets.length) {
            gsap.set(meterTargets, {
                scaleX: 0,
                transformOrigin: 'left center',
            });
        }

        if (ladderTargets.length) {
            gsap.set(ladderTargets, {
                scaleY: 0.2,
                transformOrigin: 'bottom center',
            });
        }

        const timeline = gsap.timeline({
            defaults: { ease: 'power3.out' },
        });

        if (revealTargets.length) {
            timeline.to(revealTargets, {
                y: 0,
                duration: 0.7,
                stagger: 0.08,
            });
        }

        if (popTargets.length) {
            timeline.to(
                popTargets,
                {
                    scale: 1,
                    duration: 0.5,
                    stagger: 0.06,
                },
                '-=0.35',
            );
        }

        if (meterTargets.length) {
            timeline.to(
                meterTargets,
                {
                    scaleX: 1,
                    duration: 0.8,
                    stagger: 0.08,
                },
                '-=0.35',
            );
        }

        if (ladderTargets.length) {
            timeline.to(
                ladderTargets,
                {
                    scaleY: 1,
                    duration: 0.75,
                    stagger: 0.1,
                },
                '-=0.55',
            );
        }

        if (floatTargets.length) {
            timeline.to(
                floatTargets,
                {
                    y: -8,
                    duration: 2.4,
                    ease: 'sine.inOut',
                    repeat: -1,
                    yoyo: true,
                    stagger: 0.18,
                },
                '-=0.1',
            );
        }

        if (pulseTargets.length) {
            timeline.to(
                pulseTargets,
                {
                    scale: 1.04,
                    duration: 1.5,
                    ease: 'sine.inOut',
                    repeat: -1,
                    yoyo: true,
                },
                '<',
            );
        }
    }, animationRoot);
}

function handleMotionPreferenceChange(event: MediaQueryListEvent): void {
    isReducedMotion.value = event.matches;
    cleanupAnimation();

    if (!event.matches) {
        void animateScene();
    }
}

onMounted(() => {
    motionPreference = window.matchMedia('(prefers-reduced-motion: reduce)');
    isReducedMotion.value = motionPreference.matches;
    motionPreference.addEventListener('change', handleMotionPreferenceChange);

    if (!isReducedMotion.value) {
        void animateScene();
    }
});

onBeforeUnmount(() => {
    cleanupAnimation();
    motionPreference?.removeEventListener(
        'change',
        handleMotionPreferenceChange,
    );
});
</script>

<template>
    <div
        ref="root"
        class="relative isolate min-h-[360px] overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm sm:min-h-[430px] dark:border-slate-800 dark:bg-slate-950"
        role="img"
        :aria-label="label"
        data-testid="public-gsap-animation"
        :data-motion="isReducedMotion ? 'reduced' : 'animated'"
    >
        <div class="absolute inset-0 bg-slate-50/70 dark:bg-slate-900/35" />

        <div
            v-if="isReducedMotion"
            class="sr-only"
            data-testid="gsap-static-fallback"
        >
            Reduced-motion static FamilyFund hero visual.
        </div>

        <div
            v-if="isPricing"
            class="relative flex min-h-[360px] flex-col justify-between gap-5 p-5 sm:min-h-[430px] sm:p-7"
        >
            <div
                class="flex items-start justify-between gap-4"
                data-gsap-reveal
            >
                <div>
                    <p
                        class="text-sm font-semibold text-emerald-700 dark:text-emerald-300"
                    >
                        Plan guidance
                    </p>
                    <h3
                        class="mt-2 text-2xl font-bold text-slate-950 dark:text-white"
                    >
                        Pick the capacity your group needs.
                    </h3>
                </div>
                <div
                    class="rounded-full bg-emerald-600 p-3 text-white shadow-lg shadow-emerald-600/25"
                    data-gsap-pulse
                >
                    <TrendingUp class="size-5" />
                </div>
            </div>

            <div
                class="grid flex-1 grid-cols-4 items-end gap-3"
                data-gsap-reveal
            >
                <div
                    v-for="plan in pricingPlanLadder"
                    :key="plan.key"
                    class="flex min-w-0 flex-col items-center gap-3"
                    data-testid="pricing-plan-ladder-item"
                    :data-plan-slug="plan.slug"
                    :data-plan-name="plan.name"
                    :data-plan-amount="plan.amount"
                    :data-plan-member-value="plan.memberValue"
                    :data-plan-member-caption="plan.memberCaption"
                >
                    <div
                        class="relative flex w-full max-w-24 flex-col justify-end overflow-hidden rounded-lg border bg-white/85 p-2 shadow-sm dark:bg-slate-900/85"
                        :class="[
                            plan.height,
                            plan.recommended
                                ? 'border-emerald-400 shadow-emerald-900/10 dark:border-emerald-700'
                                : 'border-slate-200 dark:border-slate-800',
                        ]"
                        data-gsap-ladder
                    >
                        <div
                            v-if="plan.recommended"
                            class="absolute top-2 right-2 size-2 rounded-full bg-emerald-500"
                        />
                        <div
                            class="rounded-md p-2 text-center"
                            :class="{
                                'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200':
                                    plan.tone === 'slate',
                                'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300':
                                    plan.tone === 'emerald',
                                'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300':
                                    plan.tone === 'sky',
                                'bg-teal-100 text-teal-700 dark:bg-teal-950 dark:text-teal-300':
                                    plan.tone === 'teal',
                            }"
                        >
                            <p class="text-[11px] font-semibold">
                                {{ plan.memberValue }}
                            </p>
                            <p class="mt-1 text-[10px]">
                                {{ plan.memberCaption }}
                            </p>
                        </div>
                    </div>
                    <div class="text-center">
                        <p
                            class="truncate text-sm font-semibold text-slate-950 dark:text-white"
                        >
                            {{ plan.name }}
                        </p>
                        <p
                            class="mt-1 text-xs text-slate-500 dark:text-slate-400"
                        >
                            {{ plan.amount }}
                        </p>
                    </div>
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-[1fr_auto]" data-gsap-reveal>
                <div
                    class="grid grid-cols-2 gap-2 rounded-lg border border-slate-200 bg-white/80 p-3 shadow-sm backdrop-blur dark:border-slate-800 dark:bg-slate-900/80"
                >
                    <div
                        v-for="feature in pricingFeatures"
                        :key="feature"
                        class="flex items-center gap-2 text-xs font-medium text-slate-700 dark:text-slate-200"
                        data-gsap-pop
                    >
                        <CheckCircle2 class="size-3.5 text-emerald-500" />
                        <span>{{ feature }}</span>
                    </div>
                </div>
                <div
                    class="flex items-center justify-between gap-4 rounded-lg border border-emerald-200 bg-emerald-50/90 p-3 text-sm font-semibold text-emerald-800 shadow-sm dark:border-emerald-900 dark:bg-emerald-950/60 dark:text-emerald-200"
                    data-gsap-float
                >
                    <span>Recommended</span>
                    <ArrowRight class="size-4" />
                </div>
            </div>
        </div>

        <div
            v-else
            class="relative flex min-h-[360px] flex-col gap-4 p-4 sm:min-h-[430px] sm:p-6"
        >
            <div
                class="flex items-start justify-between gap-4 border-b border-slate-200 pb-4 dark:border-slate-800"
                data-gsap-reveal
            >
                <div>
                    <p
                        class="text-sm font-semibold text-emerald-800 dark:text-emerald-300"
                    >
                        July contribution review
                    </p>
                    <h3
                        class="mt-2 text-2xl font-bold tracking-tight text-slate-950 dark:text-white"
                    >
                        Who paid and who still owes
                    </h3>
                </div>
                <div
                    class="flex size-11 items-center justify-center rounded-lg bg-emerald-700 text-white dark:bg-emerald-500 dark:text-emerald-950"
                    data-gsap-pulse
                >
                    <ClipboardCheck class="size-5" />
                </div>
            </div>

            <div
                class="grid grid-cols-3 divide-x divide-slate-200 rounded-lg border border-slate-200 bg-white text-center dark:divide-slate-800 dark:border-slate-800 dark:bg-slate-950"
                data-gsap-reveal
            >
                <div
                    v-for="summary in contributionSummary"
                    :key="summary.label"
                    class="p-3"
                    data-gsap-pop
                >
                    <p
                        class="text-2xl font-bold"
                        :class="{
                            'text-emerald-700 dark:text-emerald-300':
                                summary.tone === 'emerald',
                            'text-amber-700 dark:text-amber-300':
                                summary.tone === 'amber',
                            'text-rose-700 dark:text-rose-300':
                                summary.tone === 'rose',
                        }"
                    >
                        {{ summary.value }}
                    </p>
                    <p
                        class="mt-1 text-xs font-medium text-slate-600 dark:text-slate-400"
                    >
                        {{ summary.label }}
                    </p>
                </div>
            </div>

            <div
                class="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950"
                data-gsap-reveal
            >
                <div
                    class="grid grid-cols-[1.15fr_0.8fr_0.85fr_auto] gap-3 border-b border-slate-200 px-3 py-2 text-[11px] font-semibold text-slate-500 dark:border-slate-800 dark:text-slate-400"
                >
                    <span>Member</span>
                    <span>Paid</span>
                    <span>Balance</span>
                    <span>Status</span>
                </div>
                <div
                    v-for="row in paymentRows"
                    :key="row.name"
                    class="grid grid-cols-[1.15fr_0.8fr_0.85fr_auto] items-center gap-3 px-3 py-3 text-xs"
                    data-gsap-pop
                >
                    <div class="min-w-0">
                        <p
                            class="truncate font-semibold text-slate-950 dark:text-white"
                        >
                            {{ row.name }}
                        </p>
                        <p class="mt-0.5 text-slate-500 dark:text-slate-400">
                            Expected {{ row.expected }}
                        </p>
                    </div>
                    <span
                        class="font-medium text-slate-800 dark:text-slate-200"
                    >
                        {{ row.paid }}
                    </span>
                    <span
                        class="font-medium text-slate-800 dark:text-slate-200"
                    >
                        {{ row.balance }}
                    </span>
                    <span
                        class="rounded-full px-2 py-1 text-[11px] font-semibold"
                        :class="{
                            'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200':
                                row.status === 'Paid',
                            'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200':
                                row.status === 'Partial',
                            'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-200':
                                row.status === 'Still owes',
                        }"
                    >
                        {{ row.status }}
                    </span>
                </div>
            </div>

            <div class="grid gap-3 text-sm sm:grid-cols-3" data-gsap-reveal>
                <div
                    class="rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-950"
                    data-gsap-pop
                >
                    <div class="flex items-center gap-2">
                        <Users
                            class="size-4 text-slate-700 dark:text-slate-300"
                        />
                        <span
                            class="font-semibold text-slate-950 dark:text-white"
                        >
                            24 members
                        </span>
                    </div>
                    <p class="mt-1 text-xs text-slate-600 dark:text-slate-400">
                        4 contribution categories
                    </p>
                </div>
                <div
                    class="rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-950"
                    data-gsap-pop
                >
                    <div class="flex items-center gap-2">
                        <ReceiptText
                            class="size-4 text-slate-700 dark:text-slate-300"
                        />
                        <span
                            class="font-semibold text-slate-950 dark:text-white"
                        >
                            Audit trail
                        </span>
                    </div>
                    <p class="mt-1 text-xs text-slate-600 dark:text-slate-400">
                        Recorded by and date retained
                    </p>
                </div>
                <div
                    class="rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-950"
                    data-gsap-pop
                >
                    <div class="flex items-center gap-2">
                        <ShieldCheck
                            class="size-4 text-slate-700 dark:text-slate-300"
                        />
                        <span
                            class="font-semibold text-slate-950 dark:text-white"
                        >
                            Role access
                        </span>
                    </div>
                    <p class="mt-1 text-xs text-slate-600 dark:text-slate-400">
                        Full view for treasurers
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
