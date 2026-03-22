<script setup lang="ts">
import {
    markAllAsRead,
    markAsRead,
} from '@/actions/App/Http/Controllers/NotificationController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import type { AppNotification, BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { Bell, CheckCheck } from 'lucide-vue-next';

interface PaginatedNotifications {
    data: AppNotification[];
    current_page: number;
    last_page: number;
    next_page_url: string | null;
    prev_page_url: string | null;
}

interface Props {
    notifications: PaginatedNotifications;
}

defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard().url },
    { title: 'Notifications', href: '#' },
];

function handleMarkAsRead(notification: AppNotification): void {
    router.patch(markAsRead(notification.id).url, {}, { preserveScroll: true });
}

function handleMarkAllAsRead(): void {
    router.post(markAllAsRead().url, {}, { preserveScroll: true });
}

function formatNotificationMessage(notification: AppNotification): string {
    const { data } = notification;
    if (data.type === 'follow_up') {
        return `Follow-up: Your ${data.period_label} contribution of ₦${Number(data.amount_owed).toLocaleString()} is due today`;
    }
    return `Reminder: Your ${data.period_label} contribution of ₦${Number(data.amount_owed).toLocaleString()} is due soon`;
}

function formatDate(dateString: string): string {
    return new Date(dateString).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Notifications" />

        <div class="px-4 py-6">
            <div class="flex items-center justify-between">
                <Heading
                    title="Notifications"
                    description="View your contribution reminders and updates"
                />
                <Button
                    v-if="
                        notifications.data.some(
                            (n: AppNotification) => !n.read_at,
                        )
                    "
                    variant="outline"
                    size="sm"
                    @click="handleMarkAllAsRead"
                >
                    <CheckCheck class="mr-2 h-4 w-4" />
                    Mark all as read
                </Button>
            </div>

            <div
                v-if="notifications.data.length === 0"
                class="mt-12 text-center"
            >
                <Bell class="mx-auto mb-4 h-12 w-12 text-muted-foreground/40" />
                <h3 class="text-lg font-medium text-muted-foreground">
                    No notifications yet
                </h3>
                <p class="mt-1 text-sm text-muted-foreground/70">
                    You'll see contribution reminders and updates here.
                </p>
            </div>

            <div v-else class="mt-6 space-y-2">
                <div
                    v-for="notification in notifications.data"
                    :key="notification.id"
                    class="flex items-start gap-4 rounded-lg border p-4 transition-colors"
                    :class="
                        notification.read_at ? 'bg-background' : 'bg-muted/50'
                    "
                >
                    <div class="mt-0.5 shrink-0">
                        <div
                            class="flex h-8 w-8 items-center justify-center rounded-full"
                            :class="
                                notification.data.type === 'follow_up'
                                    ? 'bg-red-100 dark:bg-red-900/30'
                                    : 'bg-amber-100 dark:bg-amber-900/30'
                            "
                        >
                            <Bell
                                class="h-4 w-4"
                                :class="
                                    notification.data.type === 'follow_up'
                                        ? 'text-red-600 dark:text-red-400'
                                        : 'text-amber-600 dark:text-amber-400'
                                "
                            />
                        </div>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm leading-snug font-medium">
                            {{ formatNotificationMessage(notification) }}
                        </p>
                        <div class="mt-1 flex items-center gap-2">
                            <span class="text-xs text-muted-foreground">
                                {{ notification.data.family_name }}
                            </span>
                            <span class="text-xs text-muted-foreground"
                                >&middot;</span
                            >
                            <span class="text-xs text-muted-foreground">
                                {{ formatDate(notification.created_at) }}
                            </span>
                            <Badge
                                v-if="notification.data.type === 'follow_up'"
                                variant="destructive"
                                class="text-[10px]"
                            >
                                Urgent
                            </Badge>
                        </div>
                    </div>
                    <Button
                        v-if="!notification.read_at"
                        variant="ghost"
                        size="sm"
                        class="shrink-0"
                        @click="handleMarkAsRead(notification)"
                    >
                        Mark read
                    </Button>
                </div>
            </div>

            <!-- Pagination -->
            <div
                v-if="notifications.last_page > 1"
                class="mt-6 flex items-center justify-between"
            >
                <Link
                    v-if="notifications.prev_page_url"
                    :href="notifications.prev_page_url"
                    class="text-sm text-primary hover:underline"
                >
                    &larr; Previous
                </Link>
                <span v-else />
                <span class="text-sm text-muted-foreground">
                    Page {{ notifications.current_page }} of
                    {{ notifications.last_page }}
                </span>
                <Link
                    v-if="notifications.next_page_url"
                    :href="notifications.next_page_url"
                    class="text-sm text-primary hover:underline"
                >
                    Next &rarr;
                </Link>
                <span v-else />
            </div>
        </div>
    </AppLayout>
</template>
