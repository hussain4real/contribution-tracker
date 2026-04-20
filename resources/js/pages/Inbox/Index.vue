<script setup lang="ts">
import {
    index,
    show,
} from '@/actions/App/Http/Controllers/WhatsAppInboxController';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { MessageCircle, MessageSquare } from 'lucide-vue-next';

interface Thread {
    phone: string;
    member_id: number | null;
    member_name: string | null;
    last_at: string;
    last_body: string | null;
    message_count: number;
}

interface Props {
    threads?: Thread[];
}

const { threads = [] } = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'WhatsApp Inbox',
        href: index().url,
    },
];

function formatRelative(iso: string): string {
    const date = new Date(iso);
    const diff = Date.now() - date.getTime();
    const minutes = Math.floor(diff / 60000);

    if (minutes < 1) return 'just now';
    if (minutes < 60) return `${minutes}m ago`;

    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}h ago`;

    const days = Math.floor(hours / 24);
    if (days < 7) return `${days}d ago`;

    return date.toLocaleDateString();
}

function formatPhone(phone: string): string {
    return `+${phone}`;
}
</script>

<template>
    <Head title="WhatsApp Inbox" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
            <div class="flex items-center gap-3">
                <MessageCircle class="h-6 w-6 text-green-600" />
                <h1
                    class="text-xl font-semibold text-neutral-900 sm:text-2xl dark:text-neutral-100"
                >
                    WhatsApp Inbox
                </h1>
            </div>

            <Card v-if="threads.length === 0">
                <CardContent class="flex flex-col items-center gap-3 py-12">
                    <MessageSquare class="h-10 w-10 text-muted-foreground" />
                    <p class="text-sm text-muted-foreground">
                        No WhatsApp conversations yet. When members reply to a
                        notification, their messages will appear here.
                    </p>
                </CardContent>
            </Card>

            <div v-else class="grid gap-3">
                <Card
                    v-for="thread in threads"
                    :key="thread.phone"
                    class="transition-colors hover:bg-muted/30"
                >
                    <CardHeader class="pb-2">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <CardTitle class="truncate text-base">
                                    {{
                                        thread.member_name ??
                                        formatPhone(thread.phone)
                                    }}
                                </CardTitle>
                                <p
                                    v-if="thread.member_name"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ formatPhone(thread.phone) }}
                                </p>
                            </div>
                            <span
                                class="shrink-0 text-xs text-muted-foreground"
                            >
                                {{ formatRelative(thread.last_at) }}
                            </span>
                        </div>
                    </CardHeader>
                    <CardContent class="flex items-end justify-between gap-3">
                        <p
                            class="line-clamp-2 flex-1 text-sm text-muted-foreground"
                        >
                            {{ thread.last_body ?? '(no text)' }}
                        </p>
                        <Link :href="show({ phone: thread.phone }).url">
                            <Button size="sm" variant="outline">Open</Button>
                        </Link>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
