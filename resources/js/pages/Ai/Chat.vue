<script setup lang="ts">
import {
    index as aiIndex,
    stream as aiStream,
    destroy,
    rename,
} from '@/actions/App/Http/Controllers/AiChatController';
import ConversationList from '@/components/ai/ConversationList.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { useStream } from '@laravel/stream-vue';
import {
    Bot,
    Check,
    Menu,
    MessageSquarePlus,
    Mic,
    MicOff,
    Send,
    User,
    X,
    XCircle,
} from 'lucide-vue-next';
import { marked } from 'marked';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';

// Configure marked for safe rendering
marked.setOptions({
    breaks: true,
    gfm: true,
});

function renderMarkdown(content: string): string {
    return marked.parse(content, { async: false }) as string;
}

interface Conversation {
    id: string;
    title: string;
    updated_at: string;
}

interface Message {
    id: string;
    role: string;
    content: string;
    created_at: string;
}

interface Props {
    conversations?: Conversation[];
    messages?: Message[];
    activeConversationId?: string | null;
}

const props = withDefaults(defineProps<Props>(), {
    conversations: () => [],
    messages: () => [],
    activeConversationId: null,
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'AI Assistant', href: aiIndex().url },
];

// Chat state
const messageInput = ref('');
const chatMessages = ref<{ role: string; content: string }[]>([
    ...props.messages,
]);
const currentConversationId = ref<string | null>(props.activeConversationId);
const messagesContainer = ref<HTMLElement | null>(null);
const showMobileSidebar = ref(false);

// Skip Teleport during SSR to prevent hydration mismatch
const isMounted = ref(false);
onMounted(() => {
    isMounted.value = true;
    initSpeechRecognition();
});

// Parsed streaming text (extracted from SSE text_delta events)
const parsedStreamContent = ref('');

/**
 * Parse raw SSE chunk and extract text deltas from AI SDK stream events.
 * The AI SDK sends events like: data: {"type":"text_delta","delta":"Hello!"}
 */
function parseSSEChunk(rawChunk: string): void {
    const lines = rawChunk.split('\n');
    for (const line of lines) {
        const trimmed = line.trim();
        if (!trimmed.startsWith('data:')) continue;

        const payload = trimmed.slice(5).trim();
        if (payload === '[DONE]') continue;

        try {
            const event = JSON.parse(payload);
            if (event.type === 'text_delta' && event.delta) {
                parsedStreamContent.value += event.delta;
            }
        } catch {
            // skip non-JSON lines
        }
    }
}

// Stream hook — guarded for SSR (useStream requires browser APIs)
function createStream() {
    if (typeof window === 'undefined') {
        return {
            data: ref<string | null>(null),
            isFetching: ref(false),
            isStreaming: ref(false),
            send: (_body: Record<string, unknown>) => {
                void _body;
            },
            cancel: () => {},
            clearData: () => {},
            id: ref<string | null>(null),
        };
    }

    return useStream<{
        message: string;
        conversation_id?: string;
    }>(aiStream().url, {
        onData: (chunk: string) => {
            parseSSEChunk(chunk);
        },
        onFinish: () => {
            if (parsedStreamContent.value) {
                chatMessages.value.push({
                    role: 'assistant',
                    content: parsedStreamContent.value,
                });
                parsedStreamContent.value = '';
            }
            clearData();

            // Reload conversations and pick up the new conversation ID
            router.reload({
                only: ['conversations', 'activeConversationId'],
                onSuccess: (page) => {
                    const updatedConversations = (
                        page.props as Record<string, unknown>
                    ).conversations as Conversation[] | undefined;

                    if (updatedConversations?.length) {
                        const currentExists =
                            currentConversationId.value &&
                            updatedConversations.some(
                                (c) => c.id === currentConversationId.value,
                            );

                        if (!currentExists) {
                            // Current ID is stale or null — set to the most recent conversation
                            currentConversationId.value =
                                updatedConversations[0].id;
                            window.history.replaceState(
                                {},
                                '',
                                `${aiIndex().url}?conversation=${updatedConversations[0].id}`,
                            );
                        }
                    }
                },
            });
        },
    });
}

const { isFetching, isStreaming, send, cancel, clearData } = createStream();

// Computed streaming message for display
const streamingContent = computed(() => parsedStreamContent.value || '');
const isProcessing = computed(() => isFetching.value || isStreaming.value);

// Detect if the last assistant message is asking for confirmation
const pendingConfirmation = computed(() => {
    if (isProcessing.value) return false;
    if (chatMessages.value.length === 0) return false;

    const lastMessage = chatMessages.value[chatMessages.value.length - 1];
    if (lastMessage.role !== 'assistant') return false;

    const content = lastMessage.content.toLowerCase();
    return (
        content.includes('please confirm') ||
        content.includes('would you like me to go ahead') ||
        content.includes('confirm to proceed') ||
        content.includes('would you like to proceed')
    );
});

// Voice input via Web Speech API
const isListening = ref(false);
const speechSupported = ref(false);
let recognition: SpeechRecognition | null = null;

function initSpeechRecognition(): void {
    if (typeof window === 'undefined') return;

    const SpeechRecognition =
        window.SpeechRecognition || window.webkitSpeechRecognition;

    if (!SpeechRecognition) return;

    speechSupported.value = true;
    recognition = new SpeechRecognition();
    recognition.continuous = false;
    recognition.interimResults = true;
    recognition.lang = 'en-US';

    let finalTranscript = '';

    recognition.onresult = (event: SpeechRecognitionEvent) => {
        let interim = '';
        finalTranscript = '';

        for (let i = 0; i < event.results.length; i++) {
            const transcript = event.results[i][0].transcript;
            if (event.results[i].isFinal) {
                finalTranscript += transcript;
            } else {
                interim += transcript;
            }
        }

        messageInput.value = finalTranscript || interim;
    };

    recognition.onend = () => {
        isListening.value = false;
        if (finalTranscript && messageInput.value.trim()) {
            sendMessage();
        }
    };

    recognition.onerror = (event: SpeechRecognitionErrorEvent) => {
        isListening.value = false;
        if (event.error !== 'no-speech' && event.error !== 'aborted') {
        console.error('Speech recognition error:', event.error);
    }
    };
}

function toggleVoiceInput(): void {
    if (!recognition) return;

    if (isListening.value) {
        recognition.stop();
        isListening.value = false;
    } else {
        messageInput.value = '';
        recognition.start();
        isListening.value = true;
    }
}

onUnmounted(() => {
    if (recognition && isListening.value) {
        recognition.stop();
    }
});

// Send a confirmation or decline response
function sendConfirmation(confirmed: boolean): void {
    const message = confirmed
        ? 'Yes, go ahead and confirm.'
        : 'No, cancel this action.';

    chatMessages.value.push({ role: 'user', content: message });
    parsedStreamContent.value = '';

    send({
        message,
        ...(currentConversationId.value
            ? { conversation_id: currentConversationId.value }
            : {}),
    });
}

// Scroll to bottom when messages update
function scrollToBottom(): void {
    nextTick(() => {
        if (messagesContainer.value) {
            messagesContainer.value.scrollTop =
                messagesContainer.value.scrollHeight;
        }
    });
}

watch([chatMessages, parsedStreamContent], () => scrollToBottom(), {
    deep: true,
});

// Send message
function sendMessage(): void {
    const message = messageInput.value.trim();
    if (!message || isProcessing.value) return;

    chatMessages.value.push({ role: 'user', content: message });
    messageInput.value = '';
    parsedStreamContent.value = '';

    send({
        message,
        ...(currentConversationId.value
            ? { conversation_id: currentConversationId.value }
            : {}),
    });
}

// Handle Enter key
function handleKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        sendMessage();
    }
}

// New conversation
function startNewConversation(): void {
    currentConversationId.value = null;
    chatMessages.value = [];
    clearData();
    cancel();
    router.visit(aiIndex().url);
}

// Load conversation
function loadConversation(conversationId: string): void {
    showMobileSidebar.value = false;
    router.visit(`${aiIndex().url}?conversation=${conversationId}`);
}

// Rename conversation
const renamingConversation = ref<Conversation | null>(null);
const renameTitle = ref('');

function openRenameDialog(conversation: Conversation): void {
    renamingConversation.value = conversation;
    renameTitle.value = conversation.title;
}

function submitRename(): void {
    if (!renamingConversation.value || !renameTitle.value.trim()) return;

    router.patch(
        rename(renamingConversation.value.id).url,
        { title: renameTitle.value.trim() },
        {
            preserveScroll: true,
            onSuccess: () => {
                renamingConversation.value = null;
                renameTitle.value = '';
            },
        },
    );
}

// Delete conversation
const deletingConversation = ref<Conversation | null>(null);

function openDeleteDialog(conversation: Conversation): void {
    deletingConversation.value = conversation;
}

function confirmDelete(): void {
    if (!deletingConversation.value) return;

    const conversationId = deletingConversation.value.id;

    router.delete(destroy(conversationId).url, {
        preserveScroll: true,
        onSuccess: () => {
            deletingConversation.value = null;
            if (currentConversationId.value === conversationId) {
                startNewConversation();
            }
        },
    });
}
</script>

<template>
    <Head title="AI Assistant" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-[calc(100vh-8rem)] gap-4 p-4 lg:p-6">
            <!-- Mobile Sidebar Overlay -->
            <Teleport v-if="isMounted" to="body">
                <Transition name="fade">
                    <div
                        v-if="showMobileSidebar"
                        class="fixed inset-0 z-40 bg-black/50 lg:hidden"
                        @click="showMobileSidebar = false"
                    />
                </Transition>
                <Transition name="slide">
                    <div
                        v-if="showMobileSidebar"
                        class="fixed inset-y-0 left-0 z-50 flex w-72 flex-col border-r border-gray-200 bg-white lg:hidden dark:border-gray-700 dark:bg-gray-900"
                    >
                        <div
                            class="flex items-center justify-between border-b border-gray-200 p-3 dark:border-gray-700"
                        >
                            <h3
                                class="text-sm font-semibold text-gray-900 dark:text-gray-100"
                            >
                                Conversations
                            </h3>
                            <div class="flex items-center gap-1">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    @click="
                                        showMobileSidebar = false;
                                        startNewConversation();
                                    "
                                >
                                    <MessageSquarePlus class="h-4 w-4" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    @click="showMobileSidebar = false"
                                >
                                    <X class="h-4 w-4" />
                                </Button>
                            </div>
                        </div>

                        <ConversationList
                            :conversations="conversations"
                            :active-id="activeConversationId"
                            @load="loadConversation"
                            @rename="openRenameDialog"
                            @delete="openDeleteDialog"
                        />
                    </div>
                </Transition>
            </Teleport>

            <!-- Desktop Conversation Sidebar -->
            <div
                class="hidden w-64 shrink-0 flex-col rounded-lg border border-gray-200 bg-white lg:flex dark:border-gray-700 dark:bg-gray-900"
            >
                <div
                    class="flex items-center justify-between border-b border-gray-200 p-3 dark:border-gray-700"
                >
                    <h3
                        class="text-sm font-semibold text-gray-900 dark:text-gray-100"
                    >
                        Conversations
                    </h3>
                    <Button
                        variant="ghost"
                        size="sm"
                        @click="startNewConversation"
                    >
                        <MessageSquarePlus class="h-4 w-4" />
                    </Button>
                </div>

                <ConversationList
                    :conversations="conversations"
                    :active-id="activeConversationId"
                    @load="loadConversation"
                    @rename="openRenameDialog"
                    @delete="openDeleteDialog"
                />
            </div>

            <!-- Chat Area -->
            <div
                class="flex min-w-0 flex-1 flex-col rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900"
            >
                <!-- Mobile header -->
                <div
                    class="flex items-center gap-2 border-b border-gray-200 p-3 lg:hidden dark:border-gray-700"
                >
                    <Button
                        variant="ghost"
                        size="sm"
                        @click="showMobileSidebar = true"
                    >
                        <Menu class="h-4 w-4" />
                    </Button>
                    <span
                        class="truncate text-sm font-medium text-gray-900 dark:text-gray-100"
                    >
                        {{
                            conversations.find(
                                (c) => c.id === activeConversationId,
                            )?.title ?? 'New Conversation'
                        }}
                    </span>
                </div>

                <!-- Messages -->
                <div ref="messagesContainer" class="flex-1 overflow-y-auto p-4">
                    <!-- Empty state -->
                    <div
                        v-if="chatMessages.length === 0 && !isProcessing"
                        class="flex h-full flex-col items-center justify-center text-gray-500 dark:text-gray-400"
                    >
                        <Bot class="mb-4 h-12 w-12" />
                        <h3
                            class="mb-2 text-lg font-medium text-gray-900 dark:text-gray-100"
                        >
                            Family AI Assistant
                        </h3>
                        <p class="max-w-md text-center text-sm">
                            Ask me about contributions, expenses, member
                            payments, or request financial reports and analysis.
                        </p>
                    </div>

                    <!-- Messages list -->
                    <div class="space-y-4">
                        <div
                            v-for="(msg, idx) in chatMessages"
                            :key="idx"
                            class="flex gap-3"
                            :class="
                                msg.role === 'user'
                                    ? 'justify-end'
                                    : 'justify-start'
                            "
                        >
                            <div
                                v-if="msg.role !== 'user'"
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-teal-100 dark:bg-teal-900"
                            >
                                <Bot
                                    class="h-4 w-4 text-teal-600 dark:text-teal-400"
                                />
                            </div>

                            <div
                                class="max-w-[75%] rounded-lg px-4 py-2 text-sm"
                                :class="
                                    msg.role === 'user'
                                        ? 'bg-teal-600 text-white'
                                        : 'bg-gray-100 text-gray-900 dark:bg-gray-800 dark:text-gray-100'
                                "
                            >
                                <div
                                    v-if="msg.role === 'user'"
                                    class="whitespace-pre-wrap"
                                >
                                    {{ msg.content }}
                                </div>
                                <div
                                    v-else
                                    class="prose prose-sm max-w-none dark:prose-invert prose-headings:my-2 prose-p:my-1 prose-pre:my-2 prose-ol:my-1 prose-ul:my-1 prose-li:my-0.5"
                                    v-html="renderMarkdown(msg.content)"
                                />

                                <!-- Confirmation action buttons -->
                                <div
                                    v-if="
                                        pendingConfirmation &&
                                        msg.role === 'assistant' &&
                                        idx === chatMessages.length - 1
                                    "
                                    class="mt-3 flex gap-2"
                                >
                                    <Button
                                        size="sm"
                                        class="bg-teal-600 text-white hover:bg-teal-700"
                                        @click="sendConfirmation(true)"
                                    >
                                        <Check class="mr-1 h-3.5 w-3.5" />
                                        Confirm
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        class="text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950"
                                        @click="sendConfirmation(false)"
                                    >
                                        <XCircle class="mr-1 h-3.5 w-3.5" />
                                        Decline
                                    </Button>
                                </div>
                            </div>

                            <div
                                v-if="msg.role === 'user'"
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-teal-600"
                            >
                                <User class="h-4 w-4 text-white" />
                            </div>
                        </div>

                        <!-- Streaming response -->
                        <div v-if="isProcessing" class="flex gap-3">
                            <div
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-teal-100 dark:bg-teal-900"
                            >
                                <Bot
                                    class="h-4 w-4 text-teal-600 dark:text-teal-400"
                                />
                            </div>

                            <div
                                class="max-w-[75%] rounded-lg bg-gray-100 px-4 py-2 text-sm text-gray-900 dark:bg-gray-800 dark:text-gray-100"
                            >
                                <div
                                    v-if="streamingContent"
                                    class="prose prose-sm max-w-none dark:prose-invert prose-headings:my-2 prose-p:my-1 prose-pre:my-2 prose-ol:my-1 prose-ul:my-1 prose-li:my-0.5"
                                    v-html="renderMarkdown(streamingContent)"
                                />
                                <div v-else class="flex flex-col gap-2">
                                    <Skeleton class="h-4 w-48" />
                                    <Skeleton class="h-4 w-36" />
                                    <Skeleton class="h-4 w-24" />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Input area -->
                <div class="border-t border-gray-200 p-4 dark:border-gray-700">
                    <div class="flex gap-2">
                        <Input
                            v-model="messageInput"
                            type="text"
                            :placeholder="
                                isListening
                                    ? 'Listening...'
                                    : 'Ask about contributions, expenses, or reports...'
                            "
                            class="flex-1"
                            :class="{
                                'border-red-400 ring-1 ring-red-400':
                                    isListening,
                            }"
                            :disabled="isProcessing"
                            @keydown="handleKeydown"
                        />
                        <Button
                            v-if="speechSupported"
                            variant="outline"
                            :disabled="isProcessing"
                            :class="{
                                'border-red-400 bg-red-50 text-red-600 hover:bg-red-100 dark:bg-red-950 dark:text-red-400 dark:hover:bg-red-900':
                                    isListening,
                            }"
                            @click="toggleVoiceInput"
                        >
                            <MicOff v-if="isListening" class="h-4 w-4" />
                            <Mic v-else class="h-4 w-4" />
                        </Button>
                        <Button
                            :disabled="!messageInput.trim() || isProcessing"
                            @click="sendMessage"
                        >
                            <Send class="h-4 w-4" />
                        </Button>
                        <Button
                            v-if="isProcessing"
                            variant="outline"
                            @click="cancel"
                        >
                            Cancel
                        </Button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Rename Dialog -->
        <Dialog
            :open="!!renamingConversation"
            @update:open="(v: boolean) => !v && (renamingConversation = null)"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Rename Conversation</DialogTitle>
                    <DialogDescription
                        >Enter a new title for this
                        conversation.</DialogDescription
                    >
                </DialogHeader>

                <Input
                    v-model="renameTitle"
                    placeholder="Conversation title"
                    @keydown.enter="submitRename"
                />

                <DialogFooter>
                    <DialogClose as-child>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button
                        :disabled="!renameTitle.trim()"
                        @click="submitRename"
                        >Save</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Delete Dialog -->
        <Dialog
            :open="!!deletingConversation"
            @update:open="(v: boolean) => !v && (deletingConversation = null)"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete Conversation</DialogTitle>
                    <DialogDescription>
                        Are you sure you want to delete "{{
                            deletingConversation?.title
                        }}"? This action cannot be undone.
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter>
                    <DialogClose as-child>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button variant="destructive" @click="confirmDelete"
                        >Delete</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}
.slide-enter-active,
.slide-leave-active {
    transition: transform 0.2s ease;
}
.slide-enter-from,
.slide-leave-to {
    transform: translateX(-100%);
}
</style>
