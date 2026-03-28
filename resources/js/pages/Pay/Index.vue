<script setup lang="ts">
import {
    initiate,
    callback as payCallback,
} from '@/actions/App/Http/Controllers/MemberPaymentController';
import StatusBadge from '@/components/contributions/StatusBadge.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface PendingContribution {
    id: number;
    year: number;
    month: number;
    expected_amount: number;
    total_paid: number;
    balance: number;
    status: 'paid' | 'partial' | 'unpaid' | 'overdue';
    period_label: string;
}

interface Props {
    pending_contributions?: PendingContribution[];
    category_amount?: number;
    formatted_amount?: string;
    has_paystack?: boolean;
    paystack_public_key?: string;
}

const props = withDefaults(defineProps<Props>(), {
    pending_contributions: () => [],
    category_amount: 0,
    formatted_amount: '₦0',
    has_paystack: false,
    paystack_public_key: '',
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard().url },
    { title: 'Pay Contributions', href: '#' },
];

const page = usePage();

const selectedIds = ref<number[]>([]);
const processing = ref(false);
const errorMessage = ref('');
const successMessage = ref('');

const formatAmount = (amount: number): string => {
    return `₦${amount.toLocaleString('en-NG', { minimumFractionDigits: 2 })}`;
};

const totalSelected = computed(() => {
    return props.pending_contributions
        .filter((c) => selectedIds.value.includes(c.id))
        .reduce((sum, c) => sum + c.balance, 0);
});

const toggleContribution = (id: number, checked: boolean) => {
    if (checked) {
        selectedIds.value.push(id);
    } else {
        selectedIds.value = selectedIds.value.filter((i) => i !== id);
    }
};

const selectAll = () => {
    if (selectedIds.value.length === props.pending_contributions.length) {
        selectedIds.value = [];
    } else {
        selectedIds.value = props.pending_contributions.map((c) => c.id);
    }
};

const allSelected = computed(
    () =>
        props.pending_contributions.length > 0 &&
        selectedIds.value.length === props.pending_contributions.length,
);

const payWithPaystack = async () => {
    if (selectedIds.value.length === 0) {
        errorMessage.value = 'Please select at least one contribution to pay.';
        return;
    }

    processing.value = true;
    errorMessage.value = '';
    successMessage.value = '';

    try {
        const response = await fetch(initiate.url(), {
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
            body: JSON.stringify({
                contribution_ids: selectedIds.value,
            }),
        });

        const data = await response.json();

        if (!response.ok) {
            errorMessage.value =
                data.message || 'Failed to initialize payment.';
            processing.value = false;
            return;
        }

        // Open Paystack Popup (dynamic import to avoid SSR window error)
        const { default: PaystackPop } = await import('@paystack/inline-js');
        const popup = new PaystackPop();
        popup.resumeTransaction(data.access_code, {
            onSuccess: () => {
                router.visit(
                    payCallback.url({ query: { reference: data.reference } }),
                );
            },
            onCancel: () => {
                processing.value = false;
                errorMessage.value = 'Payment was cancelled.';
            },
            onClose: () => {
                if (processing.value) {
                    processing.value = false;
                    // If still processing when popup closes, check via callback
                    router.visit(
                        payCallback.url({
                            query: { reference: data.reference },
                        }),
                    );
                }
            },
        });
    } catch {
        errorMessage.value = 'An error occurred. Please try again.';
        processing.value = false;
    }
};
</script>

<template>
    <Head title="Pay Contributions" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-2xl space-y-6 p-4">
            <HeadingSmall
                title="Pay Contributions"
                :description="`Monthly contribution: ${formatted_amount}`"
            />

            <!-- Flash Messages -->
            <div
                v-if="page.props.flash?.success || successMessage"
                class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800 dark:border-green-800 dark:bg-green-950 dark:text-green-200"
            >
                {{ page.props.flash?.success || successMessage }}
            </div>

            <div
                v-if="page.props.flash?.error || errorMessage"
                class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200"
            >
                {{ page.props.flash?.error || errorMessage }}
            </div>

            <!-- No Paystack Setup -->
            <div
                v-if="!has_paystack"
                class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950"
            >
                <p class="text-sm text-amber-800 dark:text-amber-200">
                    Online payments are not yet available. Your family admin
                    needs to configure bank details to enable Paystack payments.
                </p>
            </div>

            <!-- No Pending Contributions -->
            <div
                v-else-if="pending_contributions.length === 0"
                class="rounded-lg border p-8 text-center"
            >
                <p class="text-muted-foreground">
                    You have no pending contributions. You're all caught up! 🎉
                </p>
            </div>

            <!-- Contributions List -->
            <div v-else class="space-y-4">
                <div class="flex items-center justify-between">
                    <button
                        type="button"
                        class="text-sm text-primary hover:underline"
                        @click="selectAll"
                    >
                        {{ allSelected ? 'Deselect All' : 'Select All' }}
                    </button>
                    <p
                        v-if="selectedIds.length > 0"
                        class="text-sm font-medium"
                    >
                        Total: {{ formatAmount(totalSelected) }}
                    </p>
                </div>

                <div class="space-y-2">
                    <label
                        v-for="contribution in pending_contributions"
                        :key="contribution.id"
                        class="flex cursor-pointer items-center gap-4 rounded-lg border p-4 transition hover:bg-muted/50"
                        :class="{
                            'border-primary bg-primary/5': selectedIds.includes(
                                contribution.id,
                            ),
                        }"
                    >
                        <Checkbox
                            :model-value="selectedIds.includes(contribution.id)"
                            @update:model-value="
                                (v: boolean | 'indeterminate') =>
                                    toggleContribution(
                                        contribution.id,
                                        v === true,
                                    )
                            "
                        />

                        <div class="flex-1">
                            <div class="flex items-center justify-between">
                                <span class="font-medium">
                                    {{ contribution.period_label }}
                                </span>
                                <StatusBadge :status="contribution.status" />
                            </div>
                            <div
                                class="mt-1 flex items-center justify-between text-sm text-muted-foreground"
                            >
                                <span>
                                    Expected:
                                    {{
                                        formatAmount(
                                            contribution.expected_amount,
                                        )
                                    }}
                                </span>
                                <span class="font-medium text-foreground">
                                    {{ formatAmount(contribution.balance) }}
                                    remaining
                                </span>
                            </div>
                            <div
                                v-if="contribution.total_paid > 0"
                                class="mt-1 text-xs text-muted-foreground"
                            >
                                Paid so far:
                                {{ formatAmount(contribution.total_paid) }}
                            </div>
                        </div>
                    </label>
                </div>

                <!-- Pay Button -->
                <div class="sticky bottom-4 pt-4">
                    <Button
                        class="w-full"
                        size="lg"
                        :disabled="selectedIds.length === 0 || processing"
                        @click="payWithPaystack"
                    >
                        {{
                            processing
                                ? 'Processing...'
                                : selectedIds.length === 0
                                  ? 'Select contributions to pay'
                                  : `Pay ${formatAmount(totalSelected)} with Paystack`
                        }}
                    </Button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
