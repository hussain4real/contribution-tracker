<script setup lang="ts">
import {
    index,
    markAllAsRead,
    markAsRead,
} from '@/actions/App/Http/Controllers/NotificationController';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { AppNotification } from '@/types';
import { Link, router, usePage } from '@inertiajs/vue3';
import { Bell } from 'lucide-vue-next';
import { computed } from 'vue';

const page = usePage();

const notifications = computed(() => page.props.notifications);
const unreadCount = computed(() => notifications.value?.unread_count ?? 0);
const recentNotifications = computed(
    () => (notifications.value?.recent ?? []) as AppNotification[],
);

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
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button variant="ghost" size="icon" class="relative h-8 w-8">
                <Bell class="h-4 w-4" />
                <span
                    v-if="unreadCount > 0"
                    class="absolute -top-0.5 -right-0.5 flex h-4 w-4 items-center justify-center rounded-full bg-red-500 text-[10px] font-bold text-white"
                >
                    {{ unreadCount > 9 ? '9+' : unreadCount }}
                </span>
                <span class="sr-only">Notifications</span>
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-80">
            <DropdownMenuLabel class="flex items-center justify-between">
                <span>Notifications</span>
                <button
                    v-if="unreadCount > 0"
                    class="text-xs font-normal text-primary hover:underline"
                    @click.stop="handleMarkAllAsRead"
                >
                    Mark all as read
                </button>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />

            <div
                v-if="recentNotifications.length === 0"
                class="px-4 py-6 text-center"
            >
                <Bell class="mx-auto mb-2 h-8 w-8 text-muted-foreground/50" />
                <p class="text-sm text-muted-foreground">
                    No new notifications
                </p>
            </div>

            <template v-else>
                <DropdownMenuItem
                    v-for="notification in recentNotifications"
                    :key="notification.id"
                    class="flex cursor-pointer flex-col items-start gap-1 p-3"
                    @click="handleMarkAsRead(notification)"
                >
                    <p class="text-sm leading-snug font-medium">
                        {{ formatNotificationMessage(notification) }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ notification.data.family_name }} &middot;
                        {{ notification.created_at }}
                    </p>
                </DropdownMenuItem>
            </template>

            <DropdownMenuSeparator v-if="recentNotifications.length > 0" />
            <DropdownMenuItem v-if="recentNotifications.length > 0" as-child>
                <Link
                    :href="index().url"
                    class="w-full cursor-pointer justify-center text-center text-sm text-primary"
                >
                    View all notifications
                </Link>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
