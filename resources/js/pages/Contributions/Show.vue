<script setup lang="ts">
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
import type { BreadcrumbItem } from '@/types';
import { create as createPayment } from '@/actions/App/Http/Controllers/PaymentController';
import { dashboard } from '@/routes';
import { Head, Link } from '@inertiajs/vue3';

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

// Helper to format amount in Naira
const formatAmount = (kobo: number): string => {
    return `₦${(kobo / 100).toLocaleString('en-NG', { minimumFractionDigits: 2 })}`;
};

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
            100
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
                                    'bg-green-600': contribution.status === 'paid',
                                    'bg-amber-500': contribution.status === 'partial',
                                    'bg-red-500': contribution.status === 'overdue',
                                    'bg-neutral-400': contribution.status === 'unpaid',
                                }"
                                :style="{ width: `${progressPercentage()}%` }"
                            />
                        </div>
                    </div>

                    <!-- Stats Grid -->
                    <div class="grid grid-cols-3 gap-4 pt-2">
                        <div class="text-center">
                            <div class="text-2xl font-bold">
                                {{ formatAmount(contribution.expected_amount) }}
                            </div>
                            <div class="text-sm text-muted-foreground">
                                Expected
                            </div>
                        </div>
                        <div class="text-center">
                            <div class="text-2xl font-bold text-green-600">
                                {{ formatAmount(contribution.total_paid) }}
                            </div>
                            <div class="text-sm text-muted-foreground">
                                Paid
                            </div>
                        </div>
                        <div class="text-center">
                            <div
                                class="text-2xl font-bold"
                                :class="{
                                    'text-amber-500': contribution.balance > 0,
                                    'text-green-600': contribution.balance === 0,
                                }"
                            >
                                {{ formatAmount(contribution.balance) }}
                            </div>
                            <div class="text-sm text-muted-foreground">
                                Balance
                            </div>
                        </div>
                    </div>

                    <!-- Record Payment Button -->
                    <div
                        v-if="can_record_payment && contribution.balance > 0"
                        class="pt-4"
                    >
                        <Link :href="createPayment(contribution.user.id).url">
                            <Button class="w-full">
                                Record Payment
                            </Button>
                        </Link>
                    </div>
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
                                <TableHead>Recorded By</TableHead>
                                <TableHead>Notes</TableHead>
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
                                <TableCell>
                                    {{ payment.recorder.name }}
                                </TableCell>
                                <TableCell
                                    class="max-w-xs truncate text-muted-foreground"
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
