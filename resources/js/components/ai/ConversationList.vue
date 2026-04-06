<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { EllipsisVertical, Pencil, Trash2 } from 'lucide-vue-next';

interface Conversation {
    id: string;
    title: string;
    updated_at: string;
}

defineProps<{
    conversations: Conversation[];
    activeId: string | null;
}>();

const emit = defineEmits<{
    load: [conversationId: string];
    rename: [conversation: Conversation];
    delete: [conversation: Conversation];
}>();
</script>

<template>
    <div class="flex-1 overflow-y-auto p-2">
        <div
            v-if="conversations.length === 0"
            class="px-2 py-4 text-center text-xs text-gray-500 dark:text-gray-400"
        >
            No conversations yet
        </div>

        <div
            v-for="conversation in conversations"
            :key="conversation.id"
            class="group mb-1"
        >
            <div
                class="flex cursor-pointer items-center justify-between rounded-md px-2 py-2 text-sm transition-colors hover:bg-gray-100 dark:hover:bg-gray-800"
                :class="{
                    'bg-gray-100 dark:bg-gray-800':
                        activeId === conversation.id,
                }"
                @click="emit('load', conversation.id)"
            >
                <span class="truncate text-gray-700 dark:text-gray-300">{{
                    conversation.title
                }}</span>

                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button
                            variant="ghost"
                            size="sm"
                            class="h-6 w-6 shrink-0 p-0 opacity-0 group-hover:opacity-100"
                            @click.stop
                        >
                            <EllipsisVertical class="h-3 w-3" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem
                            @click.stop="emit('rename', conversation)"
                        >
                            <Pencil class="mr-2 h-3 w-3" />
                            Rename
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            class="text-red-600 dark:text-red-400"
                            @click.stop="emit('delete', conversation)"
                        >
                            <Trash2 class="mr-2 h-3 w-3" />
                            Delete
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>
    </div>
</template>
