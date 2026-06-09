<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { dashboard, home, login, register } from '@/routes';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, Check, Minus, Users } from 'lucide-vue-next';
import { computed } from 'vue';

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

const props = withDefaults(defineProps<Props>(), {
    plans: () => [],
    available_features: () => ({}),
    canRegister: true,
});

const featureLabels = computed(() => props.available_features);

const comparisonRows: ComparisonRow[] = [
    {
        label: 'Member cap',
        description: 'Maximum members covered by the self-serve plan.',
        value: 'member_limit',
    },
    {
        label: 'Contribution categories and obligations',
        description: 'Set categories and generate member obligations.',
        feature: 'basic_contributions',
    },
    {
        label: 'Manual payment recording',
        description: 'Record cash, transfer, and other offline payments.',
        feature: 'manual_payments',
    },
    {
        label: 'Paystack member self-pay',
        description: 'Let members pay their own obligations online.',
        feature: 'online_payments',
    },
    {
        label: 'Notification center',
        description: 'In-app notification feed for reminders and updates.',
        includedSlugs: ['free', 'family', 'growth', 'organization'],
    },
    {
        label: 'Email reminders',
        description: 'Send contribution reminders through email.',
        feature: 'email_reminders',
    },
    {
        label: 'Browser push reminders',
        description: 'Send reminders to members who enable browser alerts.',
        feature: 'web_push_reminders',
    },
    {
        label: 'Monthly and annual reports',
        description: 'Generate structured family fund reports.',
        feature: 'reports',
    },
    {
        label: 'CSV exports',
        description: 'Export plan and contribution data for offline review.',
        feature: 'exports',
    },
    {
        label: 'AI agent and report summaries',
        description: 'Available when the AI feature flag is active.',
        feature: 'ai_assistant',
    },
    {
        label: 'WhatsApp reminders',
        description: 'Send WhatsApp contribution reminders to members.',
        feature: 'whatsapp_reminders',
    },
    {
        label: 'WhatsApp inbox and replies',
        description: 'Manage incoming WhatsApp messages and send replies.',
        feature: 'whatsapp_messaging',
    },
    {
        label: 'Priority support',
        description: 'Faster support and assisted onboarding help.',
        feature: 'priority_support',
    },
];

function memberLimitLabel(plan: Plan): string {
    if (plan.max_members) {
        return `Up to ${plan.max_members} members`;
    }

    return 'Custom member limit';
}

function featureLabel(feature: string): string {
    return featureLabels.value[feature] || feature;
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
</script>

<template>
    <Head title="Pricing" />

    <div class="min-h-svh bg-background text-foreground">
        <header
            class="sticky top-0 z-40 border-b bg-background/90 backdrop-blur"
        >
            <div
                class="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8"
            >
                <Link :href="home()" class="flex items-center gap-3">
                    <span
                        class="flex size-9 items-center justify-center rounded-lg bg-emerald-600 text-white"
                    >
                        <AppLogoIcon class-name="size-5" />
                    </span>
                    <span class="text-lg font-semibold">FamilyFund</span>
                </Link>

                <nav class="flex items-center gap-2 sm:gap-3">
                    <Link
                        :href="home()"
                        class="hidden rounded-md px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground md:inline-flex"
                    >
                        Home
                    </Link>
                    <ThemeToggle />
                    <Link v-if="$page.props.auth.user" :href="dashboard()">
                        <Button size="sm">
                            Dashboard
                            <ArrowRight class="size-4" />
                        </Button>
                    </Link>
                    <template v-else>
                        <Link :href="login()" class="hidden sm:inline-flex">
                            <Button variant="ghost" size="sm">Log in</Button>
                        </Link>
                        <Link v-if="props.canRegister" :href="register()">
                            <Button size="sm">Get started</Button>
                        </Link>
                    </template>
                </nav>
            </div>
        </header>

        <main>
            <section class="border-b bg-muted/30 py-16 sm:py-20">
                <div class="mx-auto max-w-4xl px-4 text-center sm:px-6">
                    <Badge variant="secondary">Monthly NGN pricing</Badge>
                    <h1
                        class="mt-5 text-4xl font-bold tracking-tight sm:text-5xl"
                    >
                        Plans for families, groups, and organizations
                    </h1>
                    <p
                        class="mx-auto mt-5 max-w-2xl text-base leading-7 text-muted-foreground sm:text-lg"
                    >
                        Start free, then upgrade when your group needs online
                        payments, reports, exports, AI assistance, or WhatsApp
                        workflows.
                    </p>
                    <div
                        class="mt-8 flex flex-col justify-center gap-3 sm:flex-row"
                    >
                        <Link
                            :href="
                                $page.props.auth.user
                                    ? dashboard()
                                    : props.canRegister
                                      ? register()
                                      : login()
                            "
                        >
                            <Button size="lg" class="w-full sm:w-auto">
                                {{
                                    $page.props.auth.user
                                        ? 'Open dashboard'
                                        : 'Create account'
                                }}
                                <ArrowRight class="size-4" />
                            </Button>
                        </Link>
                        <Link :href="home()">
                            <Button
                                variant="outline"
                                size="lg"
                                class="w-full sm:w-auto"
                            >
                                Back to overview
                            </Button>
                        </Link>
                    </div>
                </div>
            </section>

            <section class="py-12 sm:py-16">
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div
                        class="grid gap-4 md:grid-cols-2 xl:grid-cols-4"
                        data-testid="pricing-plan-grid"
                    >
                        <div
                            v-for="plan in props.plans"
                            :key="plan.id"
                            :class="[
                                'relative flex min-h-[540px] flex-col rounded-lg border bg-card p-5 shadow-sm',
                                plan.is_recommended
                                    ? 'border-emerald-500 shadow-emerald-900/10'
                                    : 'border-border',
                            ]"
                        >
                            <div
                                v-if="plan.is_recommended"
                                class="absolute top-4 right-4"
                            >
                                <Badge>Recommended</Badge>
                            </div>

                            <div class="space-y-4">
                                <div>
                                    <h2 class="text-xl font-semibold">
                                        {{ plan.name }}
                                    </h2>
                                    <p
                                        :class="[
                                            'mt-2 min-h-10 text-sm text-muted-foreground',
                                            plan.is_recommended ? 'pr-24' : '',
                                        ]"
                                    >
                                        {{ plan.audience }}
                                    </p>
                                </div>

                                <div>
                                    <span class="text-3xl font-bold">
                                        {{ plan.formatted_price }}
                                    </span>
                                    <span
                                        v-if="plan.price > 0"
                                        class="text-sm text-muted-foreground"
                                    >
                                        /month
                                    </span>
                                </div>

                                <div
                                    class="flex items-center gap-2 text-sm font-medium"
                                >
                                    <Users class="size-4 text-emerald-600" />
                                    {{ memberLimitLabel(plan) }}
                                </div>

                                <p
                                    class="text-sm leading-6 text-muted-foreground"
                                >
                                    {{ plan.summary }}
                                </p>
                            </div>

                            <ul class="mt-6 flex-1 space-y-3">
                                <li
                                    v-for="feature in plan.features"
                                    :key="feature"
                                    class="flex gap-2 text-sm leading-5"
                                >
                                    <Check
                                        class="mt-0.5 size-4 shrink-0 text-emerald-600"
                                    />
                                    <span>{{ featureLabel(feature) }}</span>
                                </li>
                            </ul>

                            <Link
                                class="mt-6"
                                :href="
                                    $page.props.auth.user
                                        ? dashboard()
                                        : props.canRegister
                                          ? register()
                                          : login()
                                "
                            >
                                <Button
                                    class="w-full"
                                    :variant="
                                        plan.is_recommended
                                            ? 'default'
                                            : 'outline'
                                    "
                                >
                                    {{
                                        $page.props.auth.user
                                            ? 'Open dashboard'
                                            : plan.price === 0
                                              ? 'Start free'
                                              : 'Get started'
                                    }}
                                    <ArrowRight class="size-4" />
                                </Button>
                            </Link>
                        </div>
                    </div>

                    <div
                        class="mt-8 rounded-lg border bg-muted/30 p-5 text-sm leading-6 text-muted-foreground"
                    >
                        Subscription prices are final monthly platform prices.
                        Groups above 250 members are handled through a custom
                        onboarding review after confirming support load, payment
                        volume, and onboarding needs.
                    </div>
                </div>
            </section>

            <section class="border-t bg-muted/20 py-12 sm:py-16">
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="max-w-3xl">
                        <Badge variant="secondary">Plans comparison</Badge>
                        <h2 class="mt-4 text-3xl font-bold tracking-tight">
                            Compare every plan feature
                        </h2>
                        <p
                            class="mt-3 text-sm leading-6 text-muted-foreground sm:text-base"
                        >
                            Each notification channel is listed separately, so
                            it is clear which plans include email, browser push,
                            WhatsApp, and the AI agent.
                        </p>
                    </div>

                    <div
                        class="mt-8 overflow-x-auto rounded-lg border bg-background"
                    >
                        <table
                            class="min-w-[920px] table-fixed divide-y text-sm"
                        >
                            <thead class="bg-muted/50">
                                <tr>
                                    <th
                                        scope="col"
                                        class="w-72 px-4 py-4 text-left font-semibold"
                                    >
                                        Feature
                                    </th>
                                    <th
                                        v-for="plan in props.plans"
                                        :key="plan.id"
                                        scope="col"
                                        class="px-4 py-4 text-center font-semibold"
                                    >
                                        <span class="block">{{
                                            plan.name
                                        }}</span>
                                        <span
                                            class="mt-1 block text-xs font-normal text-muted-foreground"
                                        >
                                            {{ plan.formatted_price
                                            }}<template v-if="plan.price > 0"
                                                >/month</template
                                            >
                                        </span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr
                                    v-for="row in comparisonRows"
                                    :key="row.label"
                                    class="align-top"
                                >
                                    <th scope="row" class="px-4 py-4 text-left">
                                        <span
                                            class="block font-medium text-foreground"
                                        >
                                            {{ row.label }}
                                        </span>
                                        <span
                                            class="mt-1 block text-xs leading-5 text-muted-foreground"
                                        >
                                            {{ row.description }}
                                        </span>
                                    </th>
                                    <td
                                        v-for="plan in props.plans"
                                        :key="`${row.label}-${plan.id}`"
                                        class="px-4 py-4 text-center"
                                    >
                                        <span
                                            v-if="row.value === 'member_limit'"
                                            class="font-medium"
                                        >
                                            {{ comparisonCellLabel(plan, row) }}
                                        </span>
                                        <span
                                            v-else-if="
                                                comparisonRowIncluded(plan, row)
                                            "
                                            class="inline-flex items-center justify-center gap-1.5 font-medium text-emerald-700 dark:text-emerald-400"
                                        >
                                            <Check class="size-4" />
                                            {{ comparisonCellLabel(plan, row) }}
                                        </span>
                                        <span
                                            v-else
                                            class="inline-flex items-center justify-center gap-1.5 text-muted-foreground"
                                        >
                                            <Minus class="size-4" />
                                            {{ comparisonCellLabel(plan, row) }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </main>
    </div>
</template>
