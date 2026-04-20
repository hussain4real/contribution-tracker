<script setup lang="ts">
import {
    index as inboxIndex,
    reply,
} from '@/actions/App/Http/Controllers/WhatsAppInboxController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import InputError from '@/components/InputError.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Form, Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, MessageCircle } from 'lucide-vue-next';

interface Message {
    id: number;
    direction: 'inbound' | 'outbound';
    body: string | null;
    template_name: string | null;
    status: string | null;
    created_at: string | null;
}

interface Member {
    id: number;
    name: string;
}

interface Props {
    phone: string;
    messages: Message[];
    canReply: boolean;
    replyWindowHours: number;
    member: Member | null;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'WhatsApp Inbox',
        href: inboxIndex().url,
    },
    {
        title: props.member?.name ?? `+${props.phone}`,
        href: '#',
    },
];

function formatTime(iso: string | null): string {
    if (!iso) return '';
    return new Date(iso).toLocaleString();
}
</script>

<template>
    <Head :title="`Conversation with ${member?.name ?? phone}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4 md:p-6">
            <div class="flex items-center gap-3">
                <Link :href="inboxIndex().url">
                    <Button size="icon" variant="ghost">
                        <ArrowLeft class="h-4 w-4" />
                    </Button>
                </Link>
                <MessageCircle class="h-5 w-5 text-green-600" />
                <div class="min-w-0 flex-1">
                    <h1 class="truncate text-lg font-semibold">
                        {{ member?.name ?? `+${phone}` }}
                    </h1>
                    <p
                        v-if="member"
                        class="text-xs text-muted-foreground"
                    >
                        +{{ phone }}
                    </p>
                </div>
            </div>

            <Card class="flex-1">
                <CardHeader>
                    <CardTitle class="text-sm">Conversation</CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col gap-3">
                    <div
                        v-if="messages.length === 0"
                        class="py-8 text-center text-sm text-muted-foreground"
                    >
                        No messages.
                    </div>

                    <div
                        v-for="message in messages"
                        :key="message.id"
                        class="flex"
                        :class="
                            message.direction === 'outbound'
                                ? 'justify-end'
                                : 'justify-start'
                        "
                    >
                        <div
                            class="max-w-[80%] rounded-lg px-3 py-2 text-sm"
                            :class="
                                message.direction === 'outbound'
                                    ? 'bg-green-600 text-white'
                                    : 'bg-muted'
                            "
                        >
                            <p
                                v-if="message.body"
                                class="whitespace-pre-wrap wrap-break-word"
                            >
                                {{ message.body }}
                            </p>
                            <p
                                v-else-if="message.template_name"
                                class="italic opacity-80"
                            >
                                Template: {{ message.template_name }}
                            </p>
                            <div
                                class="mt-1 flex items-center gap-2 text-[10px] opacity-70"
                            >
                                <span>{{ formatTime(message.created_at) }}</span>
                                <Badge
                                    v-if="message.status"
                                    variant="outline"
                                    class="h-4 px-1 text-[10px] capitalize"
                                    :class="
                                        message.direction === 'outbound'
                                            ? 'border-white/40 text-white'
                                            : ''
                                    "
                                >
                                    {{ message.status }}
                                </Badge>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardContent class="pt-6">
                    <div
                        v-if="!canReply"
                        class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
                    >
                        WhatsApp only allows free-form replies within
                        {{ replyWindowHours }} hours of the user's last
                        inbound message. Send an approved template instead.
                    </div>

                    <Form
                        v-else
                        v-bind="reply.form({ phone })"
                        :options="{ preserveScroll: true }"
                        reset-on-success
                        v-slot="{ errors, processing }"
                    >
                        <div class="grid gap-2">
                            <textarea
                                name="body"
                                placeholder="Type your reply…"
                                rows="3"
                                required
                                maxlength="4096"
                                class="flex min-h-20 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
                            />
                            <InputError :message="errors.body" />
                        </div>
                        <div class="mt-3 flex justify-end">
                            <Button type="submit" :disabled="processing">
                                Send reply
                            </Button>
                        </div>
                    </Form>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
