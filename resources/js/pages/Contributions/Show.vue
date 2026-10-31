<script setup lang="ts">
import ContributionReminderDeliveryRetryController from '@/actions/App/Http/Controllers/ContributionReminderDeliveryRetryController';
import { create as createPayment } from '@/actions/App/Http/Controllers/PaymentController';
import StatusBadge from '@/components/contributions/StatusBadge.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import { useCurrencyFormatter } from '@/lib/currency';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';

interface Payment {
    id: number;
    amount: number;
    paid_at: string;
    notes: string | null;
    recorder: {
        id: number;
        name: string;
    };
    created_at: string;
}

interface Contribution {
    id: number;
    year: number;
    month: number;
    expected_amount: number;
    total_paid: number;
    balance: number;
    status: 'paid' | 'partial' | 'unpaid' | 'overdue';
    period_label: string;
    due_date: string;
    payments: Payment[];
    user: {
        id: number;
        name: string;
        email: string;
        category: string;
    };
}

interface Props {
    contribution: Contribution;
    can_record_payment: boolean;
    reminder_deliveries: {
        id: number;
        type: string;
        channel: string;
        status: string;
        created_at: string | null;
        failure_reason: string | null;
        can_retry: boolean;
    }[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard().url,
    },
    {
        title: 'Contributions',
        href: '#',
    },
    {
        title: props.contribution.period_label,
        href: '#',
    },
];

const { formatCurrency: formatAmount } = useCurrencyFormatter();

function reminderStatus(channel: string, status: string): string {
    if (status === 'queued') return 'Queued';
    if (status === 'accepted')
        return channel === 'mail'
            ? 'Submitted to mailer'
            : channel === 'database'
              ? 'Stored in app'
              : 'Accepted by WhatsApp';
    if (status === 'submitted')
        return 'Submitted to browser notification service';
    if (status === 'delivered') return 'Delivered';
    if (status === 'failed') return 'Delivery failed';
    if (status === 'unconfirmed') return 'Delivery could not be confirmed';
    if (status === 'unavailable')
        return 'Not sent: payment, preferences or contact details changed';
    return 'Unknown';
}

function retryReminder(id: number, status: string): void {
    const message =
        status === 'unconfirmed'
            ? 'The earlier reminder may have arrived. Retry and possibly send another?'
            : 'Retry this reminder?';
    if (confirm(message))
        router.post(
            ContributionReminderDeliveryRetryController({ delivery: id }).url,
            {},
            { preserveScroll: true },
        );
}

// Helper to format date
const formatDate = (dateStr: string): string => {
    return new Date(dateStr).toLocaleDateString('en-NG', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
};

// Calculate progress percentage
const progressPercentage = (): number => {
    if (props.contribution.expected_amount === 0) return 0;
    return Math.min(
        100,
        (props.contribution.total_paid / props.contribution.expected_amount) *
            100,
    );
};
</script>

<template>
    <Head :title="`Contribution - ${contribution.period_label}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-4xl space-y-6 p-4">
            <!-- Header -->
            <div class="flex items-start justify-between">
                <div>
                    <HeadingSmall
                        :title="`${contribution.period_label} Contribution`"
                        :description="`${contribution.user.name} - ${contribution.user.category}`"
                    />
                </div>
                <StatusBadge :status="contribution.status" />
            </div>

            <!-- Summary Card -->
            <Card>
                <CardHeader>
                    <CardTitle>Payment Summary</CardTitle>
                    <CardDescription>
                        Due date: {{ formatDate(contribution.due_date) }}
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <!-- Progress Bar -->
                    <div class="space-y-2">
                        <div class="flex justify-between text-sm">
                            <span>Progress</span>
                            <span>
                                {{ formatAmount(contribution.total_paid) }} /
                                {{ formatAmount(contribution.expected_amount) }}
                            </span>
                        </div>
                        <div
                            class="h-2 w-full overflow-hidden rounded-full bg-neutral-200 dark:bg-neutral-700"
                        >
                            <div
                                class="h-full rounded-full transition-all duration-300"
                                :class="{
                                    'bg-green-600':
                                        contribution.status === 'paid',
                                    'bg-amber-500':
                                        contribution.status === 'partial',
                                    'bg-red-500':
                                        contribution.status === 'overdue',
                                    'bg-neutral-400':
                                        contribution.status === 'unpaid',
                                }"
                                :style="{ width: `${progressPercentage()}%` }"
                            />
                        </div>
                    </div>

                    <!-- Stats Grid -->
                    <div class="grid grid-cols-3 gap-2 pt-2 sm:gap-4">
                        <div class="text-center">
                            <div class="text-lg font-bold sm:text-2xl">
                                {{ formatAmount(contribution.expected_amount) }}
                            </div>
                            <div
                                class="text-xs text-muted-foreground sm:text-sm"
                            >
                                Expected
                            </div>
                        </div>
                        <div class="text-center">
                            <div
                                class="text-lg font-bold text-green-600 sm:text-2xl"
                            >
                                {{ formatAmount(contribution.total_paid) }}
                            </div>
                            <div
                                class="text-xs text-muted-foreground sm:text-sm"
                            >
                                Paid
                            </div>
                        </div>
                        <div class="text-center">
                            <div
                                class="text-lg font-bold sm:text-2xl"
                                :class="{
                                    'text-amber-500': contribution.balance > 0,
                                    'text-green-600':
                                        contribution.balance === 0,
                                }"
                            >
                                {{ formatAmount(contribution.balance) }}
                            </div>
                            <div
                                class="text-xs text-muted-foreground sm:text-sm"
                            >
                                Balance
                            </div>
                        </div>
                    </div>

                    <!-- Record Payment Button -->
                    <div
                        v-if="can_record_payment && contribution.balance > 0"
                        class="pt-4"
                    >
                        <Link
                            :href="
                                createPayment({
                                    member: contribution.user.id,
                                }).url
                            "
                        >
                            <Button class="w-full"> Record Payment </Button>
                        </Link>
                    </div>
                </CardContent>
            </Card>

            <Card v-if="can_record_payment">
                <CardHeader
                    ><CardTitle>Reminder delivery</CardTitle
                    ><CardDescription
                        >Recent delivery attempts for this
                        contribution.</CardDescription
                    ></CardHeader
                >
                <CardContent>
                    <p
                        v-if="!reminder_deliveries.length"
                        class="text-sm text-muted-foreground"
                    >
                        No delivery history is available.
                    </p>
                    <ul v-else class="divide-y">
                        <li
                            v-for="delivery in reminder_deliveries"
                            :key="delivery.id"
                            class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm"
                        >
                            <div>
                                <p class="font-medium">
                                    {{
                                        delivery.type === 'follow_up'
                                            ? 'Follow-up'
                                            : 'Reminder'
                                    }}
                                    ·
                                    {{
                                        delivery.channel === 'mail'
                                            ? 'Email'
                                            : delivery.channel === 'whatsapp'
                                              ? 'WhatsApp'
                                              : delivery.channel === 'webpush'
                                                ? 'Browser'
                                                : delivery.channel ===
                                                    'database'
                                                  ? 'In-app'
                                                  : 'Unknown channel'
                                    }}
                                </p>
                                <p>
                                    {{
                                        reminderStatus(
                                            delivery.channel,
                                            delivery.status,
                                        )
                                    }}
                                </p>
                                <p
                                    v-if="delivery.failure_reason"
                                    class="text-muted-foreground"
                                >
                                    {{ delivery.failure_reason }}
                                </p>
                                <time
                                    v-if="delivery.created_at"
                                    :datetime="delivery.created_at"
                                    class="text-muted-foreground"
                                    >{{ formatDate(delivery.created_at) }}</time
                                >
                            </div>
                            <Button
                                v-if="delivery.can_retry"
                                size="sm"
                                variant="outline"
                                @click="
                                    retryReminder(delivery.id, delivery.status)
                                "
                                >Retry</Button
                            >
                        </li>
                    </ul>
                </CardContent>
            </Card>

            <!-- Payment History -->
            <Card>
                <CardHeader>
                    <CardTitle>Payment History</CardTitle>
                    <CardDescription>
                        {{ contribution.payments.length }} payment{{
                            contribution.payments.length !== 1 ? 's' : ''
                        }}
                        recorded
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div
                        v-if="contribution.payments.length === 0"
                        class="py-8 text-center text-muted-foreground"
                    >
                        No payments recorded yet.
                    </div>

                    <Table v-else>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Date</TableHead>
                                <TableHead>Amount</TableHead>
                                <TableHead class="hidden md:table-cell"
                                    >Recorded By</TableHead
                                >
                                <TableHead class="hidden sm:table-cell"
                                    >Notes</TableHead
                                >
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="payment in contribution.payments"
                                :key="payment.id"
                            >
                                <TableCell>
                                    {{ formatDate(payment.paid_at) }}
                                </TableCell>
                                <TableCell class="font-medium">
                                    {{ formatAmount(payment.amount) }}
                                </TableCell>
                                <TableCell class="hidden md:table-cell">
                                    {{ payment.recorder.name }}
                                </TableCell>
                                <TableCell
                                    class="hidden max-w-xs truncate text-muted-foreground sm:table-cell"
                                >
                                    {{ payment.notes || '-' }}
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
