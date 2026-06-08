<script setup lang="ts">
import {
    index,
    markAllAsRead,
    markAsRead,
} from '@/actions/App/Http/Controllers/NotificationController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { useCurrencyFormatter } from '@/lib/currency';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Bell,
    CalendarClock,
    Check,
    CheckCheck,
    ChevronLeft,
    ChevronRight,
    Clock3,
    Inbox,
    ReceiptText,
    Sparkles,
} from 'lucide-vue-next';
import { computed } from 'vue';

type NotificationStatus = 'all' | 'unread' | 'read';
type NotificationType = 'all' | 'reminder' | 'follow_up';

interface NotificationPayload {
    contribution_id?: number;
    family_name?: string;
    period_label?: string;
    amount_owed?: number | string;
    due_date?: string | null;
    type?: NotificationType | string;
}

interface NotificationItem {
    id: string;
    type: string;
    data: NotificationPayload;
    read_at: string | null;
    created_at: string | null;
}

interface PaginatedNotifications {
    data: NotificationItem[];
    current_page: number;
    last_page: number;
    next_page_url: string | null;
    prev_page_url: string | null;
}

interface NotificationSummary {
    total: number;
    unread: number;
    read: number;
    reminders: number;
    follow_ups: number;
}

interface NotificationFilters {
    status: NotificationStatus;
    type: NotificationType;
}

interface Props {
    notificationFeed?: PaginatedNotifications;
    notificationSummary?: NotificationSummary;
    notificationFilters?: NotificationFilters;
}

const props = defineProps<Props>();
const { formatCurrency } = useCurrencyFormatter({
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
});

const emptyFeed: PaginatedNotifications = {
    data: [],
    current_page: 1,
    last_page: 1,
    next_page_url: null,
    prev_page_url: null,
};

const defaultSummary: NotificationSummary = {
    total: 0,
    unread: 0,
    read: 0,
    reminders: 0,
    follow_ups: 0,
};

const defaultFilters: NotificationFilters = {
    status: 'all',
    type: 'all',
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard().url },
    { title: 'Notifications', href: index().url },
];

const feed = computed(() => props.notificationFeed ?? emptyFeed);
const summary = computed(() => props.notificationSummary ?? defaultSummary);
const filters = computed(() => props.notificationFilters ?? defaultFilters);
const hasNotifications = computed(() => feed.value.data.length > 0);
const hasUnread = computed(() => summary.value.unread > 0);

const statusFilters = computed(() => [
    { label: 'All', value: 'all' as const, count: summary.value.total },
    { label: 'Unread', value: 'unread' as const, count: summary.value.unread },
    { label: 'Read', value: 'read' as const, count: summary.value.read },
]);

const typeFilters = computed(() => [
    { label: 'All types', value: 'all' as const, count: summary.value.total },
    {
        label: 'Reminders',
        value: 'reminder' as const,
        count: summary.value.reminders,
    },
    {
        label: 'Follow-ups',
        value: 'follow_up' as const,
        count: summary.value.follow_ups,
    },
]);

function filterHref(
    nextFilters: Partial<NotificationFilters>,
): ReturnType<typeof index> {
    const status = nextFilters.status ?? filters.value.status;
    const type = nextFilters.type ?? filters.value.type;
    const query: Record<string, string> = {};

    if (status !== 'all') {
        query.status = status;
    }

    if (type !== 'all') {
        query.type = type;
    }

    return index({ query });
}

function handleMarkAsRead(notification: NotificationItem): void {
    router.patch(
        markAsRead(notification.id).url,
        {},
        {
            only: [
                'notificationFeed',
                'notificationFilters',
                'notificationSummary',
                'notifications',
            ],
            preserveScroll: true,
            preserveState: true,
        },
    );
}

function handleMarkAllAsRead(): void {
    router.post(
        markAllAsRead().url,
        {},
        {
            only: [
                'notificationFeed',
                'notificationFilters',
                'notificationSummary',
                'notifications',
            ],
            preserveScroll: true,
            preserveState: true,
        },
    );
}

function isFollowUp(notification: NotificationItem): boolean {
    return notification.data.type === 'follow_up';
}

function notificationTitle(notification: NotificationItem): string {
    if (isFollowUp(notification)) {
        return 'Contribution follow-up';
    }

    return 'Contribution reminder';
}

function notificationMessage(notification: NotificationItem): string {
    const period = notification.data.period_label ?? 'This period';
    const amount = formatMoney(notification.data.amount_owed);

    if (isFollowUp(notification)) {
        return `${period} contribution of ${amount} is due today.`;
    }

    return `${period} contribution of ${amount} is coming due.`;
}

function notificationTone(notification: NotificationItem): string {
    if (isFollowUp(notification)) {
        return 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-900/60 dark:bg-rose-950/30 dark:text-rose-300';
    }

    return 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-300';
}

function notificationIconTone(notification: NotificationItem): string {
    if (isFollowUp(notification)) {
        return 'bg-rose-500 text-white shadow-rose-500/20';
    }

    return 'bg-amber-500 text-white shadow-amber-500/20';
}

function formatMoney(amount?: number | string): string {
    return formatCurrency(Number(amount ?? 0));
}

function formatDate(dateString?: string | null): string {
    const date = toDate(dateString);

    if (!date) {
        return 'Recently';
    }

    return date.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

function formatDateTime(dateString?: string | null): string {
    const date = toDate(dateString);

    if (!date) {
        return 'Recently';
    }

    return date.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

function toDate(dateString?: string | null): Date | null {
    if (!dateString) {
        return null;
    }

    const date = new Date(dateString);

    return Number.isNaN(date.getTime()) ? null : date;
}
</script>

<template>
    <Head title="Notifications" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="min-h-full bg-neutral-50/70 dark:bg-neutral-950">
            <div
                class="mx-auto flex w-full max-w-6xl flex-col gap-5 px-4 py-5 sm:px-6 lg:px-8"
            >
                <section
                    class="border-b border-neutral-200 pb-5 dark:border-neutral-800"
                >
                    <div
                        class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between"
                    >
                        <div class="max-w-2xl">
                            <div
                                class="mb-3 inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/30 dark:text-emerald-300"
                            >
                                <Sparkles class="size-3.5" />
                                FamilyFund inbox
                            </div>
                            <h1
                                class="text-2xl font-semibold tracking-tight text-neutral-950 sm:text-3xl dark:text-neutral-50"
                            >
                                Notification Center
                            </h1>
                            <p
                                class="mt-2 text-sm leading-6 text-neutral-600 dark:text-neutral-400"
                            >
                                Family alerts, contribution reminders, and
                                payment nudges in one focused timeline.
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <Button
                                variant="outline"
                                class="h-10"
                                :disabled="!hasUnread"
                                @click="handleMarkAllAsRead"
                            >
                                <CheckCheck class="mr-2 size-4" />
                                Mark all read
                            </Button>
                        </div>
                    </div>

                    <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div
                            class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900"
                        >
                            <div class="flex items-center justify-between">
                                <p
                                    class="text-sm font-medium text-neutral-500 dark:text-neutral-400"
                                >
                                    Total
                                </p>
                                <Inbox class="size-4 text-sky-500" />
                            </div>
                            <p
                                class="mt-3 text-2xl font-semibold text-neutral-950 dark:text-neutral-50"
                            >
                                {{ summary.total }}
                            </p>
                        </div>
                        <div
                            class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900"
                        >
                            <div class="flex items-center justify-between">
                                <p
                                    class="text-sm font-medium text-neutral-500 dark:text-neutral-400"
                                >
                                    Unread
                                </p>
                                <Bell class="size-4 text-emerald-500" />
                            </div>
                            <p
                                class="mt-3 text-2xl font-semibold text-neutral-950 dark:text-neutral-50"
                            >
                                {{ summary.unread }}
                            </p>
                        </div>
                        <div
                            class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900"
                        >
                            <div class="flex items-center justify-between">
                                <p
                                    class="text-sm font-medium text-neutral-500 dark:text-neutral-400"
                                >
                                    Reminders
                                </p>
                                <CalendarClock class="size-4 text-amber-500" />
                            </div>
                            <p
                                class="mt-3 text-2xl font-semibold text-neutral-950 dark:text-neutral-50"
                            >
                                {{ summary.reminders }}
                            </p>
                        </div>
                        <div
                            class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900"
                        >
                            <div class="flex items-center justify-between">
                                <p
                                    class="text-sm font-medium text-neutral-500 dark:text-neutral-400"
                                >
                                    Follow-ups
                                </p>
                                <AlertTriangle class="size-4 text-rose-500" />
                            </div>
                            <p
                                class="mt-3 text-2xl font-semibold text-neutral-950 dark:text-neutral-50"
                            >
                                {{ summary.follow_ups }}
                            </p>
                        </div>
                    </div>
                </section>

                <section class="grid gap-5 lg:grid-cols-[17rem_1fr]">
                    <aside class="space-y-4">
                        <div
                            class="rounded-lg border border-neutral-200 bg-white p-3 shadow-sm dark:border-neutral-800 dark:bg-neutral-900"
                        >
                            <p
                                class="px-2 pb-2 text-xs font-semibold tracking-wide text-neutral-500 uppercase dark:text-neutral-400"
                            >
                                Status
                            </p>
                            <div class="grid gap-1">
                                <Link
                                    v-for="item in statusFilters"
                                    :key="item.value"
                                    :href="filterHref({ status: item.value })"
                                    preserve-scroll
                                    class="app-tap flex items-center justify-between rounded-md px-3 py-2 text-sm font-medium"
                                    :class="
                                        filters.status === item.value
                                            ? 'bg-neutral-950 text-white dark:bg-neutral-50 dark:text-neutral-950'
                                            : 'text-neutral-700 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800'
                                    "
                                >
                                    <span>{{ item.label }}</span>
                                    <span
                                        class="rounded-full px-2 py-0.5 text-xs"
                                        :class="
                                            filters.status === item.value
                                                ? 'bg-white/15 text-white dark:bg-neutral-950/10 dark:text-neutral-950'
                                                : 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400'
                                        "
                                    >
                                        {{ item.count }}
                                    </span>
                                </Link>
                            </div>
                        </div>

                        <div
                            class="rounded-lg border border-neutral-200 bg-white p-3 shadow-sm dark:border-neutral-800 dark:bg-neutral-900"
                        >
                            <p
                                class="px-2 pb-2 text-xs font-semibold tracking-wide text-neutral-500 uppercase dark:text-neutral-400"
                            >
                                Type
                            </p>
                            <div class="grid gap-1">
                                <Link
                                    v-for="item in typeFilters"
                                    :key="item.value"
                                    :href="filterHref({ type: item.value })"
                                    preserve-scroll
                                    class="app-tap flex items-center justify-between rounded-md px-3 py-2 text-sm font-medium"
                                    :class="
                                        filters.type === item.value
                                            ? 'bg-neutral-950 text-white dark:bg-neutral-50 dark:text-neutral-950'
                                            : 'text-neutral-700 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800'
                                    "
                                >
                                    <span>{{ item.label }}</span>
                                    <span
                                        class="rounded-full px-2 py-0.5 text-xs"
                                        :class="
                                            filters.type === item.value
                                                ? 'bg-white/15 text-white dark:bg-neutral-950/10 dark:text-neutral-950'
                                                : 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400'
                                        "
                                    >
                                        {{ item.count }}
                                    </span>
                                </Link>
                            </div>
                        </div>
                    </aside>

                    <main
                        class="rounded-lg border border-neutral-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900"
                    >
                        <div
                            class="flex flex-col gap-3 border-b border-neutral-200 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-neutral-800"
                        >
                            <div>
                                <h2
                                    class="text-base font-semibold text-neutral-950 dark:text-neutral-50"
                                >
                                    Timeline
                                </h2>
                                <p
                                    class="mt-1 text-sm text-neutral-500 dark:text-neutral-400"
                                >
                                    {{ feed.data.length }} shown on this page
                                </p>
                            </div>
                            <Badge
                                variant="outline"
                                class="w-fit border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/30 dark:text-emerald-300"
                            >
                                {{ summary.unread }} unread
                            </Badge>
                        </div>

                        <div v-if="!hasNotifications" class="p-8 text-center">
                            <div
                                class="mx-auto flex size-14 items-center justify-center rounded-full bg-neutral-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400"
                            >
                                <Inbox class="size-7" />
                            </div>
                            <h3
                                class="mt-4 text-base font-semibold text-neutral-950 dark:text-neutral-50"
                            >
                                Nothing here right now
                            </h3>
                            <p
                                class="mx-auto mt-2 max-w-sm text-sm leading-6 text-neutral-500 dark:text-neutral-400"
                            >
                                New contribution alerts will land here as soon
                                as they arrive.
                            </p>
                        </div>

                        <div
                            v-else
                            class="divide-y divide-neutral-100 dark:divide-neutral-800"
                        >
                            <article
                                v-for="notification in feed.data"
                                :key="notification.id"
                                class="relative grid gap-4 p-4 transition-colors hover:bg-neutral-50 sm:grid-cols-[auto_1fr_auto] sm:p-5 dark:hover:bg-neutral-800/40"
                                :class="
                                    notification.read_at
                                        ? ''
                                        : 'bg-emerald-50/45 dark:bg-emerald-950/10'
                                "
                            >
                                <span
                                    v-if="!notification.read_at"
                                    class="absolute top-5 left-0 h-10 w-1 rounded-r-full bg-emerald-500"
                                />

                                <div
                                    class="flex size-11 items-center justify-center rounded-lg shadow-lg"
                                    :class="notificationIconTone(notification)"
                                >
                                    <AlertTriangle
                                        v-if="isFollowUp(notification)"
                                        class="size-5"
                                    />
                                    <CalendarClock v-else class="size-5" />
                                </div>

                                <div class="min-w-0">
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <h3
                                            class="text-sm font-semibold text-neutral-950 dark:text-neutral-50"
                                        >
                                            {{
                                                notificationTitle(notification)
                                            }}
                                        </h3>
                                        <Badge
                                            variant="outline"
                                            class="border text-[11px]"
                                            :class="
                                                notificationTone(notification)
                                            "
                                        >
                                            {{
                                                isFollowUp(notification)
                                                    ? 'Due today'
                                                    : 'Reminder'
                                            }}
                                        </Badge>
                                        <Badge
                                            v-if="!notification.read_at"
                                            class="bg-emerald-600 text-[11px] text-white hover:bg-emerald-600"
                                        >
                                            New
                                        </Badge>
                                    </div>

                                    <p
                                        class="mt-2 text-sm leading-6 text-neutral-700 dark:text-neutral-300"
                                    >
                                        {{ notificationMessage(notification) }}
                                    </p>

                                    <dl
                                        class="mt-3 grid gap-2 text-xs text-neutral-500 sm:grid-cols-3 dark:text-neutral-400"
                                    >
                                        <div class="flex items-center gap-1.5">
                                            <ReceiptText class="size-3.5" />
                                            <span class="truncate">
                                                {{
                                                    notification.data
                                                        .family_name ??
                                                    'Family fund'
                                                }}
                                            </span>
                                        </div>
                                        <div class="flex items-center gap-1.5">
                                            <Clock3 class="size-3.5" />
                                            <span>
                                                {{
                                                    formatDateTime(
                                                        notification.created_at,
                                                    )
                                                }}
                                            </span>
                                        </div>
                                        <div
                                            v-if="notification.data.due_date"
                                            class="flex items-center gap-1.5"
                                        >
                                            <CalendarClock class="size-3.5" />
                                            <span>
                                                Due
                                                {{
                                                    formatDate(
                                                        notification.data
                                                            .due_date,
                                                    )
                                                }}
                                            </span>
                                        </div>
                                    </dl>
                                </div>

                                <div
                                    class="flex items-center justify-end gap-2 sm:items-start"
                                >
                                    <Button
                                        v-if="!notification.read_at"
                                        variant="outline"
                                        size="sm"
                                        class="h-9"
                                        :aria-label="`Mark ${notificationTitle(notification)} as read`"
                                        @click="handleMarkAsRead(notification)"
                                    >
                                        <Check class="mr-2 size-4" />
                                        Mark read
                                    </Button>
                                    <div
                                        v-else
                                        class="inline-flex h-9 items-center gap-2 rounded-md border border-neutral-200 px-3 text-xs font-medium text-neutral-500 dark:border-neutral-800 dark:text-neutral-400"
                                    >
                                        <Check class="size-3.5" />
                                        Read
                                    </div>
                                </div>
                            </article>
                        </div>

                        <div
                            v-if="feed.last_page > 1"
                            class="flex items-center justify-between border-t border-neutral-200 p-4 dark:border-neutral-800"
                        >
                            <Link
                                v-if="feed.prev_page_url"
                                :href="feed.prev_page_url"
                                preserve-scroll
                            >
                                <Button variant="outline" size="sm">
                                    <ChevronLeft class="mr-2 size-4" />
                                    Previous
                                </Button>
                            </Link>
                            <span v-else />

                            <p
                                class="text-sm text-neutral-500 dark:text-neutral-400"
                            >
                                Page {{ feed.current_page }} of
                                {{ feed.last_page }}
                            </p>

                            <Link
                                v-if="feed.next_page_url"
                                :href="feed.next_page_url"
                                preserve-scroll
                            >
                                <Button variant="outline" size="sm">
                                    Next
                                    <ChevronRight class="ml-2 size-4" />
                                </Button>
                            </Link>
                            <span v-else />
                        </div>
                    </main>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
