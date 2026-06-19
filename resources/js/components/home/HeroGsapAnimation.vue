<script setup lang="ts">
import {
    ArrowRight,
    CheckCircle2,
    CreditCard,
    TrendingUp,
    Users,
    Wallet,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

interface Props {
    label: string;
    variant?: 'home' | 'pricing';
}

const props = withDefaults(defineProps<Props>(), {
    variant: 'home',
});

type AnimationContext = {
    revert: () => void;
};

const root = ref<HTMLElement | null>(null);
const isReducedMotion = ref(false);
const isPricing = computed(() => props.variant === 'pricing');

let motionPreference: MediaQueryList | null = null;
let animationContext: AnimationContext | null = null;

const homeStats = [
    { label: 'Paid this month', value: '92%', tone: 'emerald' },
    { label: 'Members settled', value: '18/21', tone: 'sky' },
    { label: 'Reports ready', value: '4', tone: 'slate' },
];

const paymentRows = [
    { name: 'Amina H.', amount: '₦24,000', status: 'Paid' },
    { name: 'Farouk B.', amount: '₦18,000', status: 'Partial' },
    { name: 'Maryam S.', amount: '₦12,000', status: 'Due' },
];

const planLadder = [
    {
        name: 'Free',
        amount: '₦0',
        height: 'h-24',
        members: '10',
        tone: 'slate',
    },
    {
        name: 'Family',
        amount: '₦4k',
        height: 'h-36',
        members: '50',
        tone: 'emerald',
        recommended: true,
    },
    {
        name: 'Growth',
        amount: '₦9k',
        height: 'h-48',
        members: '150',
        tone: 'sky',
    },
    {
        name: 'Org',
        amount: 'Custom',
        height: 'h-56',
        members: '250+',
        tone: 'teal',
    },
];

const pricingFeatures = [
    'Online payments',
    'Reports',
    'Exports',
    'WhatsApp',
];

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
            gsap.set(revealTargets, { opacity: 0, y: 18 });
        }

        if (popTargets.length) {
            gsap.set(popTargets, { opacity: 0, scale: 0.94 });
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
                opacity: 1,
                y: 0,
                duration: 0.7,
                stagger: 0.08,
            });
        }

        if (popTargets.length) {
            timeline.to(
                popTargets,
                {
                    opacity: 1,
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
        class="relative isolate min-h-[360px] overflow-hidden rounded-lg border border-emerald-200/80 bg-white shadow-2xl shadow-emerald-900/10 sm:min-h-[430px] dark:border-emerald-900/60 dark:bg-slate-950"
        role="img"
        :aria-label="label"
        data-testid="public-gsap-animation"
        :data-motion="isReducedMotion ? 'reduced' : 'animated'"
    >
        <div
            class="absolute inset-0 bg-linear-to-br from-emerald-50 via-white to-sky-50 dark:from-slate-950 dark:via-slate-900 dark:to-emerald-950"
        />
        <div
            class="absolute -top-20 -right-16 size-48 rounded-full bg-emerald-300/25 blur-3xl dark:bg-emerald-500/15"
        />
        <div
            class="absolute -bottom-16 -left-12 size-44 rounded-full bg-sky-300/25 blur-3xl dark:bg-sky-500/10"
        />

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
                    v-for="plan in planLadder"
                    :key="plan.name"
                    class="flex min-w-0 flex-col items-center gap-3"
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
                                {{ plan.members }}
                            </p>
                            <p class="mt-1 text-[10px]">members</p>
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
            class="relative flex min-h-[360px] flex-col gap-4 p-5 sm:min-h-[430px] sm:p-7"
        >
            <div
                class="rounded-lg border border-emerald-200 bg-white/85 p-4 shadow-lg shadow-emerald-900/5 backdrop-blur dark:border-emerald-900/70 dark:bg-slate-900/85"
                data-gsap-reveal
            >
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p
                            class="text-sm font-medium text-slate-500 dark:text-slate-400"
                        >
                            Family fund balance
                        </p>
                        <p
                            class="mt-1 text-3xl font-bold text-slate-950 dark:text-white"
                        >
                            ₦348,000
                        </p>
                    </div>
                    <div
                        class="rounded-full bg-emerald-600 p-3 text-white shadow-lg shadow-emerald-600/25"
                        data-gsap-pulse
                    >
                        <Wallet class="size-5" />
                    </div>
                </div>
                <div
                    class="mt-5 h-3 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800"
                >
                    <div
                        class="h-full w-[92%] rounded-full bg-linear-to-r from-emerald-500 to-teal-500"
                        data-gsap-meter
                    />
                </div>
                <div
                    class="mt-4 grid grid-cols-3 gap-2 text-center text-xs font-medium"
                >
                    <div
                        v-for="stat in homeStats"
                        :key="stat.label"
                        class="rounded-md border border-slate-200 bg-white/75 p-2 dark:border-slate-800 dark:bg-slate-950/70"
                        data-gsap-pop
                    >
                        <p
                            class="text-base font-bold"
                            :class="{
                                'text-emerald-600 dark:text-emerald-400':
                                    stat.tone === 'emerald',
                                'text-sky-600 dark:text-sky-400':
                                    stat.tone === 'sky',
                                'text-slate-700 dark:text-slate-200':
                                    stat.tone === 'slate',
                            }"
                        >
                            {{ stat.value }}
                        </p>
                        <p class="mt-1 text-slate-500 dark:text-slate-400">
                            {{ stat.label }}
                        </p>
                    </div>
                </div>
            </div>

            <div class="grid flex-1 gap-4 sm:grid-cols-[1fr_0.78fr]">
                <div
                    class="rounded-lg border border-slate-200 bg-white/80 p-4 shadow-sm backdrop-blur dark:border-slate-800 dark:bg-slate-900/80"
                    data-gsap-reveal
                >
                    <div class="mb-4 flex items-center justify-between gap-3">
                        <div>
                            <p
                                class="text-sm font-semibold text-slate-900 dark:text-white"
                            >
                                Recent payments
                            </p>
                            <p
                                class="text-xs text-slate-500 dark:text-slate-400"
                            >
                                Clear status for every member
                            </p>
                        </div>
                        <CreditCard class="size-4 text-emerald-500" />
                    </div>
                    <div class="space-y-3">
                        <div
                            v-for="row in paymentRows"
                            :key="row.name"
                            class="flex items-center justify-between gap-3 rounded-md bg-slate-50 p-2 dark:bg-slate-950/70"
                            data-gsap-pop
                        >
                            <div class="min-w-0">
                                <p
                                    class="truncate text-sm font-medium text-slate-900 dark:text-white"
                                >
                                    {{ row.name }}
                                </p>
                                <p
                                    class="text-xs text-slate-500 dark:text-slate-400"
                                >
                                    {{ row.amount }}
                                </p>
                            </div>
                            <span
                                class="rounded-full px-2.5 py-1 text-[11px] font-semibold"
                                :class="{
                                    'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300':
                                        row.status === 'Paid',
                                    'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300':
                                        row.status === 'Partial',
                                    'bg-slate-200 text-slate-700 dark:bg-slate-800 dark:text-slate-300':
                                        row.status === 'Due',
                                }"
                            >
                                {{ row.status }}
                            </span>
                        </div>
                    </div>
                </div>

                <div class="grid gap-4">
                    <div
                        class="rounded-lg border border-slate-200 bg-white/80 p-4 shadow-sm backdrop-blur dark:border-slate-800 dark:bg-slate-900/80"
                        data-gsap-float
                    >
                        <div class="flex items-center gap-3">
                            <div
                                class="rounded-md bg-sky-100 p-2 text-sky-700 dark:bg-sky-950 dark:text-sky-300"
                            >
                                <Users class="size-4" />
                            </div>
                            <div>
                                <p
                                    class="text-sm font-semibold text-slate-900 dark:text-white"
                                >
                                    21 members
                                </p>
                                <p
                                    class="text-xs text-slate-500 dark:text-slate-400"
                                >
                                    4 categories
                                </p>
                            </div>
                        </div>
                    </div>
                    <div
                        class="rounded-lg border border-emerald-200 bg-emerald-50/80 p-4 shadow-sm backdrop-blur dark:border-emerald-900 dark:bg-emerald-950/40"
                        data-gsap-float
                    >
                        <div class="flex items-center gap-3">
                            <div
                                class="rounded-md bg-white p-2 text-emerald-700 dark:bg-slate-950 dark:text-emerald-300"
                            >
                                <TrendingUp class="size-4" />
                            </div>
                            <div>
                                <p
                                    class="text-sm font-semibold text-emerald-900 dark:text-emerald-100"
                                >
                                    Monthly report
                                </p>
                                <p
                                    class="text-xs text-emerald-700 dark:text-emerald-300"
                                >
                                    Ready to share
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
