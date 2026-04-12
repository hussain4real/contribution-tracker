<script setup lang="ts">
import {
    subscribe as subscribeAction,
    callback as subscriptionCallback,
} from '@/actions/App/Http/Controllers/SubscriptionController';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

interface Plan {
    id: number;
    name: string;
    slug: string;
    price: number;
    formatted_price: string;
    max_members: number | null;
    features: string[];
    is_current: boolean;
}

interface Props {
    plans?: Plan[];
    current_plan?: {
        id: number;
        name: string;
        slug: string;
        price: number;
    } | null;
    subscription_status?: string;
    current_period_end?: string | null;
    member_count?: number;
    is_admin?: boolean;
    paystack_public_key?: string;
}

const props = withDefaults(defineProps<Props>(), {
    plans: () => [],
    current_plan: null,
    subscription_status: 'free',
    current_period_end: null,
    member_count: 0,
    is_admin: false,
    paystack_public_key: '',
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard().url },
    { title: 'Subscription', href: '#' },
];

const processing = ref(false);
const processingPlanId = ref<number | null>(null);

const featureLabels: Record<string, string> = {
    basic_contributions: 'Monthly contributions',
    manual_payments: 'Manual payment recording',
    online_payments: 'Online payments (Paystack)',
    reports: 'Financial reports',
    exports: 'CSV exports',
    priority_support: 'Priority support',
};

const statusLabel = computed(() => {
    const map: Record<string, string> = {
        free: 'Free Plan',
        active: 'Active',
        cancelled: 'Cancelled',
        past_due: 'Past Due',
    };

    return map[props.subscription_status] || props.subscription_status;
});

const statusVariant = computed(() => {
    const map: Record<string, string> = {
        free: 'secondary',
        active: 'default',
        cancelled: 'destructive',
        past_due: 'destructive',
    };

    return (map[props.subscription_status] || 'secondary') as
        | 'default'
        | 'secondary'
        | 'destructive';
});

const subscribeToPlan = async (planId: number) => {
    if (!props.is_admin) {
        toast.error('Only family admins can manage subscriptions.');
        return;
    }

    processing.value = true;
    processingPlanId.value = planId;

    try {
        const response = await fetch(subscribeAction.url(), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN':
                    (
                        document.querySelector(
                            'meta[name="csrf-token"]',
                        ) as HTMLMetaElement
                    )?.content || '',
                Accept: 'application/json',
            },
            body: JSON.stringify({ plan_id: planId }),
        });

        const data = await response.json();

        if (!response.ok) {
            toast.error(data.message || 'Failed to initialize subscription.');
            processing.value = false;
            processingPlanId.value = null;
            return;
        }

        const { default: PaystackPop } = await import('@paystack/inline-js');
        const popup = new PaystackPop();
        popup.resumeTransaction(data.access_code, {
            onSuccess: () => {
                router.visit(
                    subscriptionCallback.url({
                        query: { reference: data.reference },
                    }),
                );
            },
            onCancel: () => {
                processing.value = false;
                processingPlanId.value = null;
                toast.error('Subscription was cancelled.');
            },
            onClose: () => {
                if (processing.value) {
                    processing.value = false;
                    processingPlanId.value = null;
                    router.visit(
                        subscriptionCallback.url({
                            query: { reference: data.reference },
                        }),
                    );
                }
            },
        });
    } catch {
        toast.error('An error occurred. Please try again.');
        processing.value = false;
        processingPlanId.value = null;
    }
};
</script>

<template>
    <Head title="Subscription" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-4xl space-y-6 p-4">
            <div class="flex items-center justify-between">
                <HeadingSmall
                    title="Subscription Plans"
                    description="Choose the plan that works best for your family."
                />
                <Badge :variant="statusVariant">
                    {{ statusLabel }}
                </Badge>
            </div>

            <!-- Current Plan Info -->
            <div
                v-if="current_plan && subscription_status === 'active'"
                class="rounded-lg border bg-muted/30 p-4"
            >
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-muted-foreground">
                            Current plan
                        </p>
                        <p class="font-semibold">
                            {{ current_plan.name }}
                        </p>
                    </div>
                    <div class="text-right">
                        <p
                            v-if="current_period_end"
                            class="text-sm text-muted-foreground"
                        >
                            Renews on {{ current_period_end }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ member_count }} member{{
                                member_count !== 1 ? 's' : ''
                            }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- Plans Grid -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Card
                    v-for="plan in plans"
                    :key="plan.id"
                    :class="[
                        'relative flex flex-col',
                        plan.is_current
                            ? 'border-primary ring-2 ring-primary/20'
                            : '',
                    ]"
                >
                    <div
                        v-if="plan.is_current"
                        class="absolute -top-3 left-1/2 -translate-x-1/2"
                    >
                        <Badge variant="default"> Current </Badge>
                    </div>

                    <CardHeader class="pb-2">
                        <CardTitle class="text-lg">
                            {{ plan.name }}
                        </CardTitle>
                        <CardDescription>
                            <span class="text-2xl font-bold text-foreground">
                                {{ plan.formatted_price }}
                            </span>
                            <span v-if="plan.price > 0" class="text-sm">
                                /month
                            </span>
                        </CardDescription>
                    </CardHeader>

                    <CardContent class="flex-1">
                        <p class="mb-3 text-sm text-muted-foreground">
                            {{
                                plan.max_members
                                    ? `Up to ${plan.max_members} members`
                                    : 'Unlimited members'
                            }}
                        </p>
                        <ul class="space-y-2">
                            <li
                                v-for="feature in plan.features"
                                :key="feature"
                                class="flex items-center gap-2 text-sm"
                            >
                                <svg
                                    class="size-4 shrink-0 text-green-600 dark:text-green-400"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                    stroke-width="2"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M5 13l4 4L19 7"
                                    />
                                </svg>
                                {{ featureLabels[feature] || feature }}
                            </li>
                        </ul>
                    </CardContent>

                    <CardFooter>
                        <Button
                            v-if="plan.is_current && plan.price > 0 && is_admin"
                            variant="outline"
                            class="w-full"
                            @click="$inertia.post('/subscription/cancel')"
                        >
                            Cancel Plan
                        </Button>
                        <Button
                            v-else-if="
                                !plan.is_current && plan.price > 0 && is_admin
                            "
                            class="w-full"
                            :disabled="
                                processing && processingPlanId === plan.id
                            "
                            @click="subscribeToPlan(plan.id)"
                        >
                            {{
                                processing && processingPlanId === plan.id
                                    ? 'Processing...'
                                    : plan.is_current
                                      ? 'Current Plan'
                                      : 'Subscribe'
                            }}
                        </Button>
                        <div
                            v-else-if="plan.price === 0"
                            class="w-full text-center text-sm text-muted-foreground"
                        >
                            {{
                                plan.is_current
                                    ? 'Your current plan'
                                    : 'Default plan'
                            }}
                        </div>
                        <div
                            v-else
                            class="w-full text-center text-sm text-muted-foreground"
                        >
                            Ask your admin to upgrade
                        </div>
                    </CardFooter>
                </Card>
            </div>

            <!-- Non-admin notice -->
            <div
                v-if="!is_admin"
                class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950"
            >
                <p class="text-sm text-amber-800 dark:text-amber-200">
                    Only the family admin can manage subscription plans.
                </p>
            </div>
        </div>
    </AppLayout>
</template>
