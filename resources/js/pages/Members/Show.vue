<script setup lang="ts">
import { send as sendEmailReminderAction } from '@/actions/App/Http/Controllers/ContributionEmailReminderController';
import { send as sendWhatsAppReminderAction } from '@/actions/App/Http/Controllers/ContributionWhatsAppReminderController';
import {
    destroy,
    index,
    restore,
    show,
} from '@/actions/App/Http/Controllers/MemberController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertCircle,
    Archive,
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    Clock,
    Mail,
    MessageCircle,
    Pencil,
    Receipt,
    RotateCcw,
    Send,
    TrendingUp,
    User,
    Wallet,
} from 'lucide-vue-next';
import { ref } from 'vue';
import { toast } from 'vue-sonner';

interface Payment {
    id: number;
    amount: number;
    paid_at: string;
    notes: string | null;
    recorder: {
        name: string;
    };
}

interface Contribution {
    id: number;
    year: number;
    month: number;
    period_label: string;
    expected_amount: number;
    total_paid: number;
    balance: number;
    status: 'paid' | 'partial' | 'unpaid' | 'overdue';
    status_label: string;
    due_date: string;
    payments: Payment[];
}

interface Summary {
    total_expected: number;
    total_paid: number;
    total_outstanding: number;
    contribution_count: number;
}

interface Member {
    id: number;
    name: string;
    email: string;
    role: string;
    role_label: string;
    category: string | null;
    category_label: string | null;
    monthly_amount: number;
    is_archived: boolean;
    archived_at: string | null;
    created_at: string | null;
    whatsapp_verified: boolean;
}

interface Props {
    member: Member;
    contributions: Contribution[];
    summary: Summary;
    canManageMembers: boolean;
    canViewContributions: boolean;
    canSendEmailReminder?: boolean;
    canSendWhatsAppReminder?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    canSendEmailReminder: false,
    canSendWhatsAppReminder: false,
});

const sendingEmailReminderId = ref<number | null>(null);
const sendingWhatsAppReminderId = ref<number | null>(null);

function handleReminderResponse(page: {
    props: { flash?: { success?: string; error?: string } };
}) {
    const flash = page.props.flash;

    if (flash?.success) {
        toast.success(flash.success);
    } else if (flash?.error) {
        toast.error(flash.error);
    }
}

function sendEmailReminder(contributionId: number) {
    if (sendingEmailReminderId.value !== null) {
        return;
    }

    sendingEmailReminderId.value = contributionId;

    router.post(
        sendEmailReminderAction(contributionId).url,
        {},
        {
            preserveScroll: true,
            onSuccess: handleReminderResponse,
            onError: () => {
                toast.error('Failed to send email reminder.');
            },
            onFinish: () => {
                sendingEmailReminderId.value = null;
            },
        },
    );
}

function sendWhatsAppReminder(contributionId: number) {
    if (sendingWhatsAppReminderId.value !== null) {
        return;
    }

    sendingWhatsAppReminderId.value = contributionId;

    router.post(
        sendWhatsAppReminderAction(contributionId).url,
        {},
        {
            preserveScroll: true,
            onSuccess: handleReminderResponse,
            onError: () => {
                toast.error('Failed to send WhatsApp reminder.');
            },
            onFinish: () => {
                sendingWhatsAppReminderId.value = null;
            },
        },
    );
}

const expandedContribution = ref<number | null>(null);

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Members',
        href: index().url,
    },
    {
        title: props.member.name,
        href: show(props.member.id).url,
    },
];

function formatCurrency(amount: number): string {
    return new Intl.NumberFormat('en-NG', {
        style: 'currency',
        currency: 'NGN',
    }).format(amount);
}

function formatDate(dateStr: string): string {
    return new Date(dateStr).toLocaleDateString('en-NG', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

function getStatusColor(status: string) {
    switch (status) {
        case 'paid':
            return 'text-emerald-600 bg-emerald-50 dark:text-emerald-400 dark:bg-emerald-900/30';
        case 'partial':
            return 'text-amber-600 bg-amber-50 dark:text-amber-400 dark:bg-amber-900/30';
        case 'overdue':
            return 'text-red-600 bg-red-50 dark:text-red-400 dark:bg-red-900/30';
        default:
            return 'text-neutral-600 bg-neutral-50 dark:text-neutral-400 dark:bg-neutral-800';
    }
}

function getStatusIcon(status: string) {
    switch (status) {
        case 'paid':
            return CheckCircle2;
        case 'partial':
            return Clock;
        case 'overdue':
            return AlertCircle;
        default:
            return Wallet;
    }
}

function getProgressPercentage(contribution: Contribution): number {
    if (contribution.expected_amount === 0) return 0;
    return Math.min(
        100,
        (contribution.total_paid / contribution.expected_amount) * 100,
    );
}

function canSendEmail(contribution: Contribution): boolean {
    return (
        props.canSendEmailReminder &&
        Boolean(props.member.email) &&
        contribution.balance > 0
    );
}

function canSendWhatsApp(contribution: Contribution): boolean {
    return (
        props.canSendWhatsAppReminder &&
        props.member.whatsapp_verified &&
        contribution.balance > 0
    );
}

function canSendAnyReminder(contribution: Contribution): boolean {
    return canSendEmail(contribution) || canSendWhatsApp(contribution);
}

function hasSingleEmailReminder(contribution: Contribution): boolean {
    return canSendEmail(contribution) && !canSendWhatsApp(contribution);
}

function hasSingleWhatsAppReminder(contribution: Contribution): boolean {
    return !canSendEmail(contribution) && canSendWhatsApp(contribution);
}

function hasMultipleReminderChannels(contribution: Contribution): boolean {
    return canSendEmail(contribution) && canSendWhatsApp(contribution);
}

function isSendingReminder(contributionId: number): boolean {
    return (
        sendingEmailReminderId.value === contributionId ||
        sendingWhatsAppReminderId.value === contributionId
    );
}

function toggleExpand(id: number) {
    expandedContribution.value = expandedContribution.value === id ? null : id;
}

function getRoleBadgeVariant(role: string) {
    switch (role) {
        case 'admin':
            return 'default';
        case 'financial_secretary':
            return 'secondary';
        default:
            return 'outline';
    }
}

function archiveMember() {
    if (confirm(`Are you sure you want to archive ${props.member.name}?`)) {
        router
            .optimistic((pageProps: any) => ({
                member: { ...pageProps.member, is_archived: true },
            }))
            .delete(destroy(props.member.id).url);
    }
}

function restoreMember() {
    if (confirm(`Are you sure you want to restore ${props.member.name}?`)) {
        router
            .optimistic((pageProps: any) => ({
                member: {
                    ...pageProps.member,
                    is_archived: false,
                    archived_at: null,
                },
            }))
            .post(restore(props.member.id).url);
    }
}
</script>

<template>
    <Head :title="member.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
            <div class="mx-auto w-full max-w-2xl">
                <div
                    class="rounded-xl border border-sidebar-border/70 bg-white p-4 sm:p-6 dark:border-sidebar-border dark:bg-neutral-900"
                >
                    <!-- Header -->
                    <div
                        class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
                    >
                        <div class="flex items-center gap-3 sm:gap-4">
                            <div
                                class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-neutral-100 sm:h-16 sm:w-16 dark:bg-neutral-800"
                            >
                                <User
                                    class="h-6 w-6 text-neutral-500 sm:h-8 sm:w-8"
                                />
                            </div>
                            <div class="min-w-0">
                                <h1
                                    class="truncate text-xl font-semibold text-neutral-900 sm:text-2xl dark:text-neutral-100"
                                >
                                    {{ member.name }}
                                </h1>
                                <p
                                    class="truncate text-sm text-neutral-600 sm:text-base dark:text-neutral-400"
                                >
                                    {{ member.email }}
                                </p>
                            </div>
                        </div>
                        <div
                            v-if="canManageMembers"
                            class="flex items-center gap-2"
                        >
                            <template v-if="!member.is_archived">
                                <Link :href="`/members/${member.id}/edit`">
                                    <Button variant="outline" size="sm">
                                        <Pencil class="mr-2 h-4 w-4" />
                                        Edit
                                    </Button>
                                </Link>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="archiveMember"
                                >
                                    <Archive
                                        class="mr-2 h-4 w-4 text-amber-600"
                                    />
                                    Archive
                                </Button>
                            </template>
                            <template v-else>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="restoreMember"
                                >
                                    <RotateCcw
                                        class="mr-2 h-4 w-4 text-green-600"
                                    />
                                    Restore
                                </Button>
                            </template>
                        </div>
                    </div>

                    <!-- Archived Warning -->
                    <div
                        v-if="member.is_archived"
                        class="mt-4 rounded-lg bg-red-50 p-4 dark:bg-red-900/20"
                    >
                        <p
                            class="text-sm font-medium text-red-800 dark:text-red-200"
                        >
                            This member is archived
                        </p>
                        <p class="mt-1 text-sm text-red-600 dark:text-red-400">
                            Archived on {{ member.archived_at }}
                        </p>
                    </div>

                    <!-- Details -->
                    <div class="mt-6 space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <p
                                    class="text-sm text-neutral-500 dark:text-neutral-400"
                                >
                                    Role
                                </p>
                                <div class="mt-1">
                                    <Badge
                                        :variant="
                                            getRoleBadgeVariant(member.role)
                                        "
                                    >
                                        {{ member.role_label }}
                                    </Badge>
                                </div>
                            </div>
                            <div>
                                <p
                                    class="text-sm text-neutral-500 dark:text-neutral-400"
                                >
                                    Category
                                </p>
                                <p
                                    class="mt-1 font-medium text-neutral-900 dark:text-neutral-100"
                                >
                                    {{ member.category_label ?? '—' }}
                                </p>
                            </div>
                            <div>
                                <p
                                    class="text-sm text-neutral-500 dark:text-neutral-400"
                                >
                                    Monthly Amount
                                </p>
                                <p
                                    class="mt-1 text-lg font-semibold text-neutral-900 dark:text-neutral-100"
                                >
                                    {{ formatCurrency(member.monthly_amount) }}
                                </p>
                            </div>
                            <div>
                                <p
                                    class="text-sm text-neutral-500 dark:text-neutral-400"
                                >
                                    Member Since
                                </p>
                                <p
                                    class="mt-1 font-medium text-neutral-900 dark:text-neutral-100"
                                >
                                    {{ member.created_at ?? '—' }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Contribution History Section -->
                    <div
                        v-if="canViewContributions"
                        class="mt-8 border-t border-neutral-200 pt-6 dark:border-neutral-700"
                    >
                        <div class="flex items-center justify-between">
                            <h2
                                class="text-lg font-semibold text-neutral-900 dark:text-neutral-100"
                            >
                                Contribution History
                            </h2>
                            <span
                                class="text-sm text-neutral-500 dark:text-neutral-400"
                            >
                                Last {{ summary.contribution_count }} months
                            </span>
                        </div>

                        <!-- Summary Stats -->
                        <div
                            v-if="contributions.length > 0"
                            class="mt-4 grid grid-cols-3 gap-2 sm:gap-3"
                        >
                            <div
                                class="rounded-lg bg-emerald-50 p-3 dark:bg-emerald-900/20"
                            >
                                <div class="flex items-center gap-2">
                                    <TrendingUp
                                        class="h-4 w-4 text-emerald-600 dark:text-emerald-400"
                                    />
                                    <span
                                        class="text-xs font-medium text-emerald-700 dark:text-emerald-300"
                                        >Total Paid</span
                                    >
                                </div>
                                <p
                                    class="mt-1 text-base font-bold text-emerald-700 sm:text-lg dark:text-emerald-300"
                                >
                                    {{ formatCurrency(summary.total_paid) }}
                                </p>
                            </div>
                            <div
                                class="rounded-lg bg-blue-50 p-3 dark:bg-blue-900/20"
                            >
                                <div class="flex items-center gap-2">
                                    <Wallet
                                        class="h-4 w-4 text-blue-600 dark:text-blue-400"
                                    />
                                    <span
                                        class="text-xs font-medium text-blue-700 dark:text-blue-300"
                                        >Expected</span
                                    >
                                </div>
                                <p
                                    class="mt-1 text-base font-bold text-blue-700 sm:text-lg dark:text-blue-300"
                                >
                                    {{ formatCurrency(summary.total_expected) }}
                                </p>
                            </div>
                            <div
                                class="rounded-lg p-3"
                                :class="
                                    summary.total_outstanding > 0
                                        ? 'bg-amber-50 dark:bg-amber-900/20'
                                        : 'bg-neutral-50 dark:bg-neutral-800'
                                "
                            >
                                <div class="flex items-center gap-2">
                                    <AlertCircle
                                        class="h-4 w-4"
                                        :class="
                                            summary.total_outstanding > 0
                                                ? 'text-amber-600 dark:text-amber-400'
                                                : 'text-neutral-500'
                                        "
                                    />
                                    <span
                                        class="text-xs font-medium"
                                        :class="
                                            summary.total_outstanding > 0
                                                ? 'text-amber-700 dark:text-amber-300'
                                                : 'text-neutral-600 dark:text-neutral-400'
                                        "
                                        >Outstanding</span
                                    >
                                </div>
                                <p
                                    class="mt-1 text-lg font-bold"
                                    :class="
                                        summary.total_outstanding > 0
                                            ? 'text-amber-700 dark:text-amber-300'
                                            : 'text-neutral-600 dark:text-neutral-400'
                                    "
                                >
                                    {{
                                        formatCurrency(
                                            summary.total_outstanding,
                                        )
                                    }}
                                </p>
                            </div>
                        </div>

                        <!-- Contributions List -->
                        <div
                            v-if="contributions.length > 0"
                            class="mt-4 space-y-2"
                        >
                            <div
                                v-for="contribution in contributions"
                                :key="contribution.id"
                                class="overflow-hidden rounded-lg border border-neutral-200 dark:border-neutral-700"
                            >
                                <!-- Contribution Row -->
                                <div
                                    class="flex cursor-pointer items-center justify-between p-3 transition-colors hover:bg-neutral-50 dark:hover:bg-neutral-800/50"
                                    @click="toggleExpand(contribution.id)"
                                >
                                    <div class="flex items-center gap-3">
                                        <div
                                            class="flex h-9 w-9 items-center justify-center rounded-full"
                                            :class="
                                                getStatusColor(
                                                    contribution.status,
                                                )
                                            "
                                        >
                                            <component
                                                :is="
                                                    getStatusIcon(
                                                        contribution.status,
                                                    )
                                                "
                                                class="h-4 w-4"
                                            />
                                        </div>
                                        <div>
                                            <p
                                                class="font-medium text-neutral-900 dark:text-neutral-100"
                                            >
                                                {{ contribution.period_label }}
                                            </p>
                                            <p
                                                class="text-xs text-neutral-500 dark:text-neutral-400"
                                            >
                                                Due
                                                {{
                                                    formatDate(
                                                        contribution.due_date,
                                                    )
                                                }}
                                            </p>
                                        </div>
                                    </div>
                                    <div
                                        class="flex items-center gap-2 sm:gap-4"
                                    >
                                        <div class="text-right">
                                            <p
                                                class="text-sm font-semibold text-neutral-900 sm:text-base dark:text-neutral-100"
                                            >
                                                {{
                                                    formatCurrency(
                                                        contribution.total_paid,
                                                    )
                                                }}
                                            </p>
                                            <p
                                                class="text-xs text-neutral-500 dark:text-neutral-400"
                                            >
                                                of
                                                {{
                                                    formatCurrency(
                                                        contribution.expected_amount,
                                                    )
                                                }}
                                            </p>
                                        </div>
                                        <Badge
                                            :variant="
                                                contribution.status === 'paid'
                                                    ? 'default'
                                                    : contribution.status ===
                                                        'overdue'
                                                      ? 'destructive'
                                                      : 'secondary'
                                            "
                                            class="hidden text-xs sm:inline-flex"
                                        >
                                            {{ contribution.status_label }}
                                        </Badge>
                                        <Button
                                            v-if="
                                                hasSingleEmailReminder(
                                                    contribution,
                                                )
                                            "
                                            variant="outline"
                                            size="sm"
                                            class="h-8 gap-1 px-2 text-xs text-blue-600 hover:bg-blue-50 hover:text-blue-700 dark:text-blue-400 dark:hover:bg-blue-900/30 dark:hover:text-blue-300"
                                            :disabled="
                                                sendingEmailReminderId ===
                                                contribution.id
                                            "
                                            :title="`Send email reminder for ${contribution.period_label}`"
                                            @click.stop="
                                                sendEmailReminder(
                                                    contribution.id,
                                                )
                                            "
                                        >
                                            <Mail
                                                class="h-3.5 w-3.5"
                                                :class="{
                                                    'animate-pulse':
                                                        sendingEmailReminderId ===
                                                        contribution.id,
                                                }"
                                            />
                                            <span class="hidden sm:inline"
                                                >Email</span
                                            >
                                        </Button>
                                        <Button
                                            v-else-if="
                                                hasSingleWhatsAppReminder(
                                                    contribution,
                                                )
                                            "
                                            variant="outline"
                                            size="sm"
                                            class="h-8 gap-1 px-2 text-xs text-emerald-600 hover:bg-emerald-50 hover:text-emerald-700 dark:text-emerald-400 dark:hover:bg-emerald-900/30 dark:hover:text-emerald-300"
                                            :disabled="
                                                sendingWhatsAppReminderId ===
                                                contribution.id
                                            "
                                            :title="`Send WhatsApp reminder for ${contribution.period_label}`"
                                            @click.stop="
                                                sendWhatsAppReminder(
                                                    contribution.id,
                                                )
                                            "
                                        >
                                            <MessageCircle
                                                class="h-3.5 w-3.5"
                                                :class="{
                                                    'animate-pulse':
                                                        sendingWhatsAppReminderId ===
                                                        contribution.id,
                                                }"
                                            />
                                            <span class="hidden sm:inline"
                                                >WhatsApp</span
                                            >
                                        </Button>
                                        <DropdownMenu
                                            v-else-if="
                                                hasMultipleReminderChannels(
                                                    contribution,
                                                )
                                            "
                                        >
                                            <DropdownMenuTrigger as-child>
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    class="h-8 gap-1 px-2 text-xs"
                                                    :disabled="
                                                        isSendingReminder(
                                                            contribution.id,
                                                        )
                                                    "
                                                    @click.stop
                                                >
                                                    <Send
                                                        class="h-3.5 w-3.5"
                                                        :class="{
                                                            'animate-pulse':
                                                                isSendingReminder(
                                                                    contribution.id,
                                                                ),
                                                        }"
                                                    />
                                                    <span
                                                        class="hidden sm:inline"
                                                        >Remind via</span
                                                    >
                                                    <ChevronDown
                                                        class="h-3 w-3 opacity-60"
                                                    />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent
                                                align="end"
                                                class="w-48"
                                            >
                                                <DropdownMenuItem
                                                    v-if="
                                                        canSendEmail(
                                                            contribution,
                                                        )
                                                    "
                                                    :disabled="
                                                        sendingEmailReminderId !==
                                                        null
                                                    "
                                                    @click.stop="
                                                        sendEmailReminder(
                                                            contribution.id,
                                                        )
                                                    "
                                                >
                                                    <Mail
                                                        class="mr-2 h-4 w-4"
                                                    />
                                                    Send email reminder
                                                </DropdownMenuItem>
                                                <DropdownMenuSeparator
                                                    v-if="
                                                        canSendEmail(
                                                            contribution,
                                                        ) &&
                                                        canSendWhatsApp(
                                                            contribution,
                                                        )
                                                    "
                                                />
                                                <DropdownMenuItem
                                                    v-if="
                                                        canSendWhatsApp(
                                                            contribution,
                                                        )
                                                    "
                                                    :disabled="
                                                        sendingWhatsAppReminderId !==
                                                        null
                                                    "
                                                    @click.stop="
                                                        sendWhatsAppReminder(
                                                            contribution.id,
                                                        )
                                                    "
                                                >
                                                    <MessageCircle
                                                        class="mr-2 h-4 w-4"
                                                    />
                                                    Send WhatsApp reminder
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                        <ChevronRight
                                            class="h-4 w-4 text-neutral-400 transition-transform"
                                            :class="{
                                                'rotate-90':
                                                    expandedContribution ===
                                                    contribution.id,
                                            }"
                                        />
                                    </div>
                                </div>

                                <!-- Progress Bar -->
                                <div
                                    class="h-1 bg-neutral-100 dark:bg-neutral-800"
                                >
                                    <div
                                        class="h-full transition-all duration-300"
                                        :class="{
                                            'bg-emerald-500':
                                                contribution.status === 'paid',
                                            'bg-amber-500':
                                                contribution.status ===
                                                'partial',
                                            'bg-red-500':
                                                contribution.status ===
                                                'overdue',
                                            'bg-neutral-300 dark:bg-neutral-600':
                                                contribution.status ===
                                                'unpaid',
                                        }"
                                        :style="{
                                            width: `${getProgressPercentage(contribution)}%`,
                                        }"
                                    />
                                </div>

                                <!-- Expanded Payments -->
                                <div
                                    v-if="
                                        expandedContribution ===
                                            contribution.id &&
                                        contribution.payments.length > 0
                                    "
                                    class="border-t border-neutral-200 bg-neutral-50/50 p-3 dark:border-neutral-700 dark:bg-neutral-800/30"
                                >
                                    <p
                                        class="mb-2 text-xs font-medium tracking-wide text-neutral-500 uppercase dark:text-neutral-400"
                                    >
                                        Payment History
                                    </p>
                                    <div class="space-y-2">
                                        <div
                                            v-for="payment in contribution.payments"
                                            :key="payment.id"
                                            class="flex items-center justify-between rounded-md bg-white p-2 shadow-sm dark:bg-neutral-800"
                                        >
                                            <div
                                                class="flex items-center gap-2"
                                            >
                                                <Receipt
                                                    class="h-4 w-4 text-neutral-400"
                                                />
                                                <div>
                                                    <p
                                                        class="text-sm font-medium text-neutral-900 dark:text-neutral-100"
                                                    >
                                                        {{
                                                            formatCurrency(
                                                                payment.amount,
                                                            )
                                                        }}
                                                    </p>
                                                    <p
                                                        class="text-xs text-neutral-500 dark:text-neutral-400"
                                                    >
                                                        {{
                                                            formatDate(
                                                                payment.paid_at,
                                                            )
                                                        }}
                                                        <span
                                                            v-if="payment.notes"
                                                            class="ml-1"
                                                            >•
                                                            {{
                                                                payment.notes
                                                            }}</span
                                                        >
                                                    </p>
                                                </div>
                                            </div>
                                            <span
                                                class="text-xs text-neutral-400"
                                            >
                                                by {{ payment.recorder.name }}
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <!-- No Payments Message -->
                                <div
                                    v-else-if="
                                        expandedContribution ===
                                            contribution.id &&
                                        contribution.payments.length === 0
                                    "
                                    class="border-t border-neutral-200 bg-neutral-50/50 p-4 text-center dark:border-neutral-700 dark:bg-neutral-800/30"
                                >
                                    <p
                                        class="text-sm text-neutral-500 dark:text-neutral-400"
                                    >
                                        No payments recorded yet
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Empty State -->
                        <div
                            v-else
                            class="mt-4 rounded-lg border-2 border-dashed border-neutral-200 p-8 text-center dark:border-neutral-700"
                        >
                            <Wallet
                                class="mx-auto h-10 w-10 text-neutral-300 dark:text-neutral-600"
                            />
                            <p
                                class="mt-2 text-sm text-neutral-500 dark:text-neutral-400"
                            >
                                No contribution history yet
                            </p>
                            <p
                                class="text-xs text-neutral-400 dark:text-neutral-500"
                            >
                                Contributions will appear here once payments are
                                recorded
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
