<script setup lang="ts">
import {
    index as aiIndex,
    stream as aiStream,
    transcribe as aiTranscribe,
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
import { correctTranscriptMemberNameCasing } from '@/lib/transcript-corrections';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { useStream } from '@laravel/stream-vue';
import {
    Bot,
    Check,
    Loader2,
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
import { toast } from 'vue-sonner';

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
    id?: string;
    role: string;
    content: string;
    created_at?: string;
    tool_calls?: ToolCallMessageData[];
    tool_results?: ToolResultMessageData[];
    reasoning_summary?: string;
}

interface ToolCallMessageData {
    id: string;
    name: string;
    arguments: Record<string, unknown>;
    result_id?: string | null;
    reasoning_summary?: Array<{ text?: string } | string> | null;
}

interface ToolResultMessageData {
    id: string;
    name: string;
    arguments: Record<string, unknown>;
    result: unknown;
    result_id?: string | null;
}

interface AgentActivity {
    id: string;
    name: string;
    label: string;
    status: 'running' | 'completed';
    arguments: Record<string, unknown>;
    result?: unknown;
    reasoningSummary?: string | null;
}

interface Props {
    conversations?: Conversation[];
    messages?: Message[];
    activeConversationId?: string | null;
    memberNames?: string[];
    transcriptionAvailable?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    conversations: () => [],
    messages: () => [],
    activeConversationId: null,
    memberNames: () => [],
    transcriptionAvailable: false,
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'AI Assistant', href: aiIndex().url },
];

// Chat state
const messageInput = ref('');
const chatMessages = ref<Message[]>([...props.messages]);
const currentConversationId = ref<string | null>(props.activeConversationId);
const messagesContainer = ref<HTMLElement | null>(null);
const messageTextarea = ref<HTMLTextAreaElement | null>(null);
const showMobileSidebar = ref(false);
const MESSAGE_INPUT_MAX_HEIGHT = 192;

// Skip Teleport during SSR to prevent hydration mismatch
const isMounted = ref(false);
onMounted(() => {
    isMounted.value = true;
    initSpeechRecognition();
    resizeMessageTextarea();
});

// Parsed streaming text (extracted from SSE text_delta events)
const parsedStreamContent = ref('');
const streamedToolCalls = ref<ToolCallMessageData[]>([]);
const streamedToolResults = ref<ToolResultMessageData[]>([]);
const streamedReasoningSummary = ref('');

function prettifyToolName(name: string): string {
    const baseName = name.split('\\').pop() ?? name;

    return baseName
        .replace(/[_-]+/g, ' ')
        .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}

function normalizeToolArguments(value: unknown): Record<string, unknown> {
    if (value && typeof value === 'object' && !Array.isArray(value)) {
        return value as Record<string, unknown>;
    }

    return {};
}

function formatReasoningSummary(summary: unknown): string {
    if (!summary) {
        return '';
    }

    if (typeof summary === 'string') {
        return summary;
    }

    if (!Array.isArray(summary)) {
        return '';
    }

    return summary
        .map((item) => {
            if (typeof item === 'string') {
                return item;
            }

            if (item && typeof item === 'object' && 'text' in item) {
                return String((item as { text?: unknown }).text ?? '');
            }

            return '';
        })
        .filter(Boolean)
        .join(' ')
        .trim();
}

function formatArgumentValue(value: unknown): string {
    if (typeof value === 'string') {
        return value.length > 36 ? `${value.slice(0, 33)}...` : value;
    }

    if (typeof value === 'number' || typeof value === 'boolean') {
        return String(value);
    }

    if (Array.isArray(value)) {
        return value.length === 0
            ? '[]'
            : `${value.length} item${value.length === 1 ? '' : 's'}`;
    }

    if (value && typeof value === 'object') {
        return 'Object';
    }

    return '—';
}

function summarizeToolArguments(
    argumentsValue: Record<string, unknown>,
): Array<{ key: string; value: string }> {
    return Object.entries(argumentsValue)
        .filter(
            ([, value]) =>
                value !== null && value !== undefined && value !== '',
        )
        .slice(0, 3)
        .map(([key, value]) => ({
            key: key.replace(/_/g, ' '),
            value: formatArgumentValue(value),
        }));
}

function summarizeToolResult(result: unknown): string | null {
    let normalized = result;

    if (typeof normalized === 'string') {
        const stringResult = normalized;

        try {
            normalized = JSON.parse(stringResult) as unknown;
        } catch {
            return stringResult.length > 96
                ? `${stringResult.slice(0, 93)}...`
                : stringResult;
        }
    }

    if (
        !normalized ||
        typeof normalized !== 'object' ||
        Array.isArray(normalized)
    ) {
        return null;
    }

    const data = normalized as Record<string, unknown>;

    if (typeof data.error === 'string' && data.error !== '') {
        return data.error;
    }

    if (typeof data.message === 'string' && data.message !== '') {
        return data.message;
    }

    if (typeof data.summary === 'string' && data.summary !== '') {
        return data.summary;
    }

    if (typeof data.period === 'string' && data.period !== '') {
        return `Retrieved data for ${data.period}.`;
    }

    const fieldCount = Object.keys(data).length;

    return fieldCount > 0
        ? `Returned ${fieldCount} field${fieldCount === 1 ? '' : 's'}.`
        : null;
}

function normalizeActivityCollection<T>(items: unknown): T[] {
    if (Array.isArray(items)) {
        return items as T[];
    }

    if (items && typeof items === 'object') {
        return Object.values(items as Record<string, T>);
    }

    return [];
}

function buildAgentActivities(
    toolCalls: unknown = [],
    toolResults: unknown = [],
): AgentActivity[] {
    const normalizedToolCalls =
        normalizeActivityCollection<ToolCallMessageData>(toolCalls);
    const normalizedToolResults =
        normalizeActivityCollection<ToolResultMessageData>(toolResults);

    const resultsById = new Map(
        normalizedToolResults.map((toolResult) => [toolResult.id, toolResult]),
    );

    return normalizedToolCalls.map((toolCall) => {
        const matchingResult = resultsById.get(toolCall.id);

        return {
            id: toolCall.id,
            name: toolCall.name,
            label: prettifyToolName(toolCall.name),
            status: matchingResult ? 'completed' : 'running',
            arguments: normalizeToolArguments(toolCall.arguments),
            result: matchingResult?.result,
            reasoningSummary: formatReasoningSummary(
                toolCall.reasoning_summary,
            ),
        };
    });
}

function getMessageActivities(message: Message): AgentActivity[] {
    return buildAgentActivities(
        message.tool_calls ?? [],
        message.tool_results ?? [],
    );
}

function getMessageReasoningSummary(message: Message): string {
    if (message.reasoning_summary?.trim()) {
        return message.reasoning_summary.trim();
    }

    return normalizeActivityCollection<ToolCallMessageData>(message.tool_calls)
        .map((toolCall) => formatReasoningSummary(toolCall.reasoning_summary))
        .filter((summary) => summary !== '')
        .join(' ')
        .trim();
}

function hasMessageActivity(message: Message): boolean {
    return (
        getMessageActivities(message).length > 0 ||
        getMessageReasoningSummary(message) !== ''
    );
}

function resetStreamActivity(): void {
    streamedToolCalls.value = [];
    streamedToolResults.value = [];
    streamedReasoningSummary.value = '';
}

/**
 * Parse raw SSE chunk and extract text deltas from AI SDK stream events.
 * The AI SDK sends events like: data: {"type":"text_delta","delta":"Hello!"}
 */
function parseSSEChunk(rawChunk: string): void {
    const lines = rawChunk.split('\n');

    for (const line of lines) {
        const trimmed = line.trim();

        if (!trimmed.startsWith('data:')) {
            continue;
        }

        const payload = trimmed.slice(5).trim();

        if (payload === '[DONE]') {
            continue;
        }

        try {
            const event = JSON.parse(payload);

            if (
                (event.type === 'text_delta' || event.type === 'text-delta') &&
                event.delta
            ) {
                parsedStreamContent.value += event.delta;

                continue;
            }

            if (
                (event.type === 'reasoning_delta' ||
                    event.type === 'reasoning-delta') &&
                event.delta
            ) {
                streamedReasoningSummary.value += event.delta;

                continue;
            }

            if (
                event.type === 'tool-input-available' &&
                event.toolCallId &&
                event.toolName
            ) {
                const toolCall: ToolCallMessageData = {
                    id: String(event.toolCallId),
                    name: String(event.toolName),
                    arguments: normalizeToolArguments(event.input),
                };

                const existingIndex = streamedToolCalls.value.findIndex(
                    (existingToolCall) => existingToolCall.id === toolCall.id,
                );

                if (existingIndex === -1) {
                    streamedToolCalls.value.push(toolCall);
                } else {
                    streamedToolCalls.value[existingIndex] = {
                        ...streamedToolCalls.value[existingIndex],
                        ...toolCall,
                    };
                }

                continue;
            }

            if (event.type === 'tool-output-available' && event.toolCallId) {
                const toolCallId = String(event.toolCallId);
                const existingToolCall = streamedToolCalls.value.find(
                    (toolCall) => toolCall.id === toolCallId,
                );
                const toolResult: ToolResultMessageData = {
                    id: toolCallId,
                    name: existingToolCall?.name ?? 'Tool',
                    arguments: existingToolCall?.arguments ?? {},
                    result: event.output,
                };

                const existingIndex = streamedToolResults.value.findIndex(
                    (existingToolResult) =>
                        existingToolResult.id === toolCallId,
                );

                if (existingIndex === -1) {
                    streamedToolResults.value.push(toolResult);
                } else {
                    streamedToolResults.value[existingIndex] = toolResult;
                }
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
            if (
                parsedStreamContent.value ||
                streamedToolCalls.value.length > 0 ||
                streamedReasoningSummary.value.trim() !== ''
            ) {
                chatMessages.value.push({
                    id: crypto.randomUUID(),
                    role: 'assistant',
                    content: parsedStreamContent.value,
                    tool_calls: [...streamedToolCalls.value],
                    tool_results: [...streamedToolResults.value],
                    reasoning_summary:
                        streamedReasoningSummary.value.trim() || undefined,
                    created_at: new Date().toISOString(),
                });
                parsedStreamContent.value = '';
            }

            resetStreamActivity();
            clearData();

            // Reload conversations, active ID, and messages so the freshly
            // persisted assistant message (with tool activity) replaces the
            // in-memory placeholder pushed above.
            const reloadOptions: Parameters<typeof router.reload>[0] = {
                only: ['conversations', 'activeConversationId', 'messages'],
                onSuccess: (page) => {
                    const props = page.props as Record<string, unknown>;
                    const updatedConversations = props.conversations as
                        | Conversation[]
                        | undefined;
                    const updatedMessages = props.messages as
                        | Message[]
                        | undefined;

                    let resolvedConversationId =
                        currentConversationId.value ?? null;

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
                            resolvedConversationId = updatedConversations[0].id;
                            window.history.replaceState(
                                {},
                                '',
                                `${aiIndex().url}?conversation=${updatedConversations[0].id}`,
                            );
                        }
                    }

                    if (updatedMessages && updatedMessages.length > 0) {
                        chatMessages.value = [...updatedMessages];
                        return;
                    }

                    // Brand-new conversation: the first reload didn't have
                    // ?conversation= in the URL, so messages came back empty.
                    // Fetch them now that we know the ID.
                    if (resolvedConversationId) {
                        router.reload({
                            only: ['messages'],
                            data: { conversation: resolvedConversationId },
                            onSuccess: (innerPage) => {
                                const refreshed = (
                                    innerPage.props as Record<string, unknown>
                                ).messages as Message[] | undefined;
                                if (refreshed && refreshed.length > 0) {
                                    chatMessages.value = [...refreshed];
                                }
                            },
                        });
                    }
                },
            };

            router.reload(reloadOptions);
        },
    });
}

const { isFetching, isStreaming, send, cancel, clearData } = createStream();

// Computed streaming message for display
const streamingContent = computed(() => parsedStreamContent.value || '');
const isProcessing = computed(() => isFetching.value || isStreaming.value);
const streamingAgentActivities = computed(() =>
    buildAgentActivities(streamedToolCalls.value, streamedToolResults.value),
);
const hasStreamingActivity = computed(
    () =>
        streamingAgentActivities.value.length > 0 ||
        streamedReasoningSummary.value.trim() !== '',
);

// Detect if the last assistant message is asking for confirmation
const pendingConfirmation = computed(() => {
    if (isProcessing.value) return false;
    if (chatMessages.value.length === 0) return false;

    const lastMessage = chatMessages.value[chatMessages.value.length - 1];
    if (lastMessage.role !== 'assistant') return false;

    const content = lastMessage.content.toLowerCase();
    const patterns = [
        'please confirm',
        'would you like me to go ahead',
        'would you like me to proceed',
        'confirm to proceed',
        'would you like to proceed',
        'should i proceed',
        'shall i proceed',
        'do you want me to proceed',
        'do you want me to go ahead',
        'let me know if i should proceed',
        'let me know if you would like me to',
        'let me know if you want me to',
        'shall i go ahead',
        'should i go ahead',
        'i have prepared',
        'ready to record',
        'ready to save',
    ];

    return patterns.some((phrase) => content.includes(phrase));
});

function correctTranscript(text: string): string {
    return correctTranscriptMemberNameCasing(text, props.memberNames);
}

function resizeMessageTextarea(): void {
    nextTick(() => {
        const textarea = messageTextarea.value;

        if (!textarea) {
            return;
        }

        textarea.style.height = 'auto';
        textarea.style.height = `${Math.min(
            textarea.scrollHeight,
            MESSAGE_INPUT_MAX_HEIGHT,
        )}px`;
        textarea.style.overflowY =
            textarea.scrollHeight > MESSAGE_INPUT_MAX_HEIGHT
                ? 'auto'
                : 'hidden';
    });
}

// Voice input state
const isListening = ref(false);
const isTranscribing = ref(false);
const speechSupported = ref(false);
const recordingSeconds = ref(0);
const recordingLevel = ref(0);
const MAX_RECORDING_SECONDS = 30;
const RECORDING_MIME_TYPES = [
    'audio/webm;codecs=opus',
    'audio/webm',
    'audio/mp4',
    'audio/ogg;codecs=opus',
    'audio/ogg',
];
let recognition: SpeechRecognition | null = null;
let mediaRecorder: MediaRecorder | null = null;
let audioChunks: Blob[] = [];
let recordingTimer: ReturnType<typeof setInterval> | null = null;
let recordingStartedAt = 0;
let recordingPeakLevel = 0;
let recordingChunkCount = 0;
let audioContext: AudioContext | null = null;
let audioSource: MediaStreamAudioSourceNode | null = null;
let audioAnalyser: AnalyserNode | null = null;
let audioLevelFrame: number | null = null;

// Use server-side transcription when available, fall back to Web Speech API
const useServerTranscription = computed(
    () =>
        props.transcriptionAvailable &&
        typeof navigator !== 'undefined' &&
        !!navigator.mediaDevices &&
        typeof MediaRecorder !== 'undefined',
);

const recordingLevelBars = computed(() =>
    Array.from({ length: 12 }, (_, index) => {
        const threshold = (index + 1) / 12;
        const active = recordingLevel.value >= threshold;

        return {
            active,
            height: `${6 + Math.min(index + 1, 7) * 2}px`,
            opacity: active ? 1 : 0.28,
        };
    }),
);

function initSpeechRecognition(): void {
    if (typeof window === 'undefined') return;

    // Server-side transcription uses MediaRecorder (available in all modern browsers)
    if (useServerTranscription.value) {
        speechSupported.value = true;
        return;
    }

    // Fall back to Web Speech API
    const SpeechRecognition =
        window.SpeechRecognition || window.webkitSpeechRecognition;

    if (!SpeechRecognition) return;

    speechSupported.value = true;
    recognition = new SpeechRecognition();
    recognition.continuous = false;
    recognition.interimResults = true;
    recognition.lang = 'en-NG'; // Set to Nigerian English for better local recognition

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

        messageInput.value = correctTranscript(finalTranscript || interim);
    };

    recognition.onend = () => {
        isListening.value = false;
    };

    recognition.onerror = (event: SpeechRecognitionErrorEvent) => {
        isListening.value = false;
        if (event.error !== 'no-speech' && event.error !== 'aborted') {
            console.error('Speech recognition error:', event.error);
        }
    };
}

function supportedRecordingMimeType(): string {
    if (
        typeof MediaRecorder === 'undefined' ||
        typeof MediaRecorder.isTypeSupported !== 'function'
    ) {
        return '';
    }

    return (
        RECORDING_MIME_TYPES.find((mimeType) =>
            MediaRecorder.isTypeSupported(mimeType),
        ) ?? ''
    );
}

function normalizeRecordedMimeType(mimeType: string): string {
    const baseType = mimeType.split(';')[0]?.trim().toLowerCase() ?? '';

    switch (baseType) {
        case 'audio/webm':
        case 'video/webm':
            return 'audio/webm';
        case 'audio/mp4':
        case 'audio/m4a':
        case 'audio/x-m4a':
        case 'video/mp4':
            return 'audio/mp4';
        case 'audio/ogg':
            return 'audio/ogg';
        default:
            return 'audio/webm';
    }
}

function extensionForAudioMimeType(mimeType: string): string {
    if (mimeType.includes('mp4')) return 'mp4';
    if (mimeType.includes('ogg')) return 'ogg';

    return 'webm';
}

function resetRecordingDiagnostics(): void {
    recordingStartedAt = Date.now();
    recordingPeakLevel = 0;
    recordingLevel.value = 0;
    recordingChunkCount = 0;
}

function startAudioLevelMonitor(stream: MediaStream): void {
    stopAudioLevelMonitor();

    const AudioContextConstructor =
        window.AudioContext ||
        (window as Window & { webkitAudioContext?: typeof AudioContext })
            .webkitAudioContext;

    if (!AudioContextConstructor) return;

    audioContext = new AudioContextConstructor();
    audioSource = audioContext.createMediaStreamSource(stream);
    audioAnalyser = audioContext.createAnalyser();
    audioAnalyser.fftSize = 1024;
    audioSource.connect(audioAnalyser);

    const samples = new Uint8Array(audioAnalyser.fftSize);

    const measure = () => {
        if (!audioAnalyser) return;

        audioAnalyser.getByteTimeDomainData(samples);

        let peak = 0;
        for (const sample of samples) {
            peak = Math.max(peak, Math.abs(sample - 128) / 128);
        }

        recordingPeakLevel = Math.max(recordingPeakLevel, peak);
        recordingLevel.value = Math.min(1, peak * 6);
        audioLevelFrame = window.requestAnimationFrame(measure);
    };

    measure();
}

function stopAudioLevelMonitor(): void {
    if (audioLevelFrame !== null) {
        window.cancelAnimationFrame(audioLevelFrame);
        audioLevelFrame = null;
    }

    audioSource?.disconnect();
    audioSource = null;
    audioAnalyser = null;

    if (audioContext) {
        void audioContext.close();
        audioContext = null;
    }
}

async function startMediaRecording(): Promise<void> {
    try {
        const stream = await navigator.mediaDevices.getUserMedia({
            audio: {
                echoCancellation: true,
                noiseSuppression: true,
                autoGainControl: true,
            },
        });
        audioChunks = [];
        resetRecordingDiagnostics();
        startAudioLevelMonitor(stream);

        const mimeType = supportedRecordingMimeType();
        const recorderOptions: MediaRecorderOptions = {
            audioBitsPerSecond: 128000,
        };

        if (mimeType) {
            recorderOptions.mimeType = mimeType;
        }

        mediaRecorder = new MediaRecorder(stream, recorderOptions);

        mediaRecorder.ondataavailable = (event) => {
            if (event.data.size > 0) {
                audioChunks.push(event.data);
                recordingChunkCount++;
            }
        };

        mediaRecorder.onstop = async () => {
            // Stop all tracks to release the microphone
            stream.getTracks().forEach((track) => track.stop());
            stopAudioLevelMonitor();
            clearRecordingTimer();

            if (audioChunks.length === 0) {
                toast.error(
                    'No microphone audio was captured. Check your microphone and try again.',
                );
                return;
            }

            const durationSeconds = Math.max(
                0,
                (Date.now() - recordingStartedAt) / 1000,
            );
            const actualType = normalizeRecordedMimeType(
                mediaRecorder?.mimeType || mimeType,
            );
            const ext = extensionForAudioMimeType(actualType);
            const audioBlob = new Blob(audioChunks, { type: actualType });
            audioChunks = [];

            await sendAudioForTranscription(audioBlob, ext, {
                durationSeconds,
                audioLevel: recordingPeakLevel,
                chunkCount: recordingChunkCount,
                mimeType: actualType,
            });
        };

        mediaRecorder.start(250);
        isListening.value = true;
        startRecordingTimer();
    } catch {
        isListening.value = false;
        stopAudioLevelMonitor();
        toast.error('Could not access your microphone.');
    }
}

function startRecordingTimer(): void {
    recordingSeconds.value = 0;
    recordingTimer = setInterval(() => {
        recordingSeconds.value++;
        if (recordingSeconds.value >= MAX_RECORDING_SECONDS) {
            stopMediaRecording();
        }
    }, 1000);
}

function clearRecordingTimer(): void {
    if (recordingTimer) {
        clearInterval(recordingTimer);
        recordingTimer = null;
    }
    recordingSeconds.value = 0;
}

function stopMediaRecording(): void {
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        try {
            mediaRecorder.requestData();
        } catch {
            // Continue stopping even if the browser has no pending data chunk.
        }
        mediaRecorder.stop();
    }
    isListening.value = false;
}

async function sendAudioForTranscription(
    audioBlob: Blob,
    ext: string,
    metadata: {
        durationSeconds: number;
        audioLevel: number;
        chunkCount: number;
        mimeType: string;
    },
): Promise<void> {
    isTranscribing.value = true;

    try {
        const formData = new FormData();
        formData.append('audio', audioBlob, `recording.${ext}`);
        formData.append(
            'duration_seconds',
            metadata.durationSeconds.toFixed(2),
        );
        formData.append('audio_level', metadata.audioLevel.toFixed(4));
        formData.append('chunk_count', String(metadata.chunkCount));
        formData.append('client_mime_type', metadata.mimeType);

        const response = await fetch(aiTranscribe().url, {
            method: 'POST',
            headers: {
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie
                        .split('; ')
                        .find((row) => row.startsWith('XSRF-TOKEN='))
                        ?.split('=')
                        .slice(1)
                        .join('=') ?? '',
                ),
                Accept: 'application/json',
            },
            credentials: 'same-origin',
            body: formData,
        });

        if (!response.ok) {
            const errorData = (await response.json().catch(() => null)) as {
                message?: string;
            } | null;

            throw new Error(errorData?.message || 'Transcription failed.');
        }

        const data = (await response.json()) as { text: string };
        if (data.text) {
            messageInput.value = correctTranscript(data.text);
        } else {
            throw new Error(
                'No speech was recognized. Check your microphone and try again.',
            );
        }
    } catch (error) {
        toast.error(
            error instanceof Error
                ? error.message
                : 'Transcription failed. Please try again.',
        );
    } finally {
        isTranscribing.value = false;
    }
}

function toggleVoiceInput(): void {
    if (isTranscribing.value) return;

    if (useServerTranscription.value) {
        if (isListening.value) {
            stopMediaRecording();
        } else {
            messageInput.value = '';
            startMediaRecording();
        }
        return;
    }

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
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        mediaRecorder.stop();
    }
    stopAudioLevelMonitor();
    clearRecordingTimer();
});

// Send a confirmation or decline response
function sendConfirmation(confirmed: boolean): void {
    const message = confirmed
        ? 'Yes, go ahead and confirm.'
        : 'No, cancel this action.';

    chatMessages.value.push({ role: 'user', content: message });
    parsedStreamContent.value = '';
    resetStreamActivity();

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

watch(
    [streamedToolCalls, streamedToolResults, streamedReasoningSummary],
    () => scrollToBottom(),
    {
        deep: true,
    },
);

watch(messageInput, () => resizeMessageTextarea());

// Send message
function sendMessage(): void {
    const message = messageInput.value.trim();
    if (!message || isProcessing.value) return;

    chatMessages.value.push({ role: 'user', content: message });
    messageInput.value = '';
    parsedStreamContent.value = '';
    resetStreamActivity();

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
    resetStreamActivity();
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
                                    v-else-if="msg.content"
                                    class="prose prose-sm max-w-none dark:prose-invert prose-headings:my-2 prose-p:my-1 prose-pre:my-2 prose-ol:my-1 prose-ul:my-1 prose-li:my-0.5"
                                    v-html="renderMarkdown(msg.content)"
                                />

                                <details
                                    v-if="
                                        msg.role === 'assistant' &&
                                        hasMessageActivity(msg)
                                    "
                                    class="mt-3 rounded-md border border-gray-200/80 bg-white/70 p-3 text-xs text-gray-600 dark:border-gray-700 dark:bg-gray-900/40 dark:text-gray-300"
                                >
                                    <summary
                                        class="cursor-pointer font-medium marker:text-gray-400 dark:marker:text-gray-500"
                                    >
                                        Agent activity
                                        <span
                                            v-if="
                                                getMessageActivities(msg)
                                                    .length > 0
                                            "
                                            class="ml-1 text-gray-500 dark:text-gray-400"
                                        >
                                            ·
                                            {{
                                                getMessageActivities(msg).length
                                            }}
                                            step{{
                                                getMessageActivities(msg)
                                                    .length === 1
                                                    ? ''
                                                    : 's'
                                            }}
                                        </span>
                                    </summary>

                                    <div class="mt-3 space-y-3">
                                        <p
                                            v-if="
                                                getMessageReasoningSummary(msg)
                                            "
                                            class="rounded-md bg-gray-100 px-3 py-2 leading-relaxed text-gray-600 dark:bg-gray-800 dark:text-gray-300"
                                        >
                                            {{
                                                getMessageReasoningSummary(msg)
                                            }}
                                        </p>

                                        <div
                                            v-for="activity in getMessageActivities(
                                                msg,
                                            )"
                                            :key="activity.id"
                                            class="rounded-md border border-gray-200/70 bg-gray-50 px-3 py-2 dark:border-gray-700 dark:bg-gray-800/80"
                                        >
                                            <div
                                                class="flex items-start justify-between gap-3"
                                            >
                                                <div class="min-w-0">
                                                    <p
                                                        class="font-medium text-gray-800 dark:text-gray-100"
                                                    >
                                                        {{ activity.label }}
                                                    </p>

                                                    <div
                                                        v-if="
                                                            summarizeToolArguments(
                                                                activity.arguments,
                                                            ).length
                                                        "
                                                        class="mt-2 flex flex-wrap gap-1.5"
                                                    >
                                                        <span
                                                            v-for="argument in summarizeToolArguments(
                                                                activity.arguments,
                                                            )"
                                                            :key="`${activity.id}-${argument.key}`"
                                                            class="rounded-full bg-white px-2 py-1 text-[11px] text-gray-600 dark:bg-gray-900 dark:text-gray-300"
                                                        >
                                                            <span
                                                                class="font-medium"
                                                                >{{
                                                                    argument.key
                                                                }}:</span
                                                            >
                                                            {{ argument.value }}
                                                        </span>
                                                    </div>

                                                    <p
                                                        v-if="
                                                            activity.reasoningSummary
                                                        "
                                                        class="mt-2 leading-relaxed text-gray-500 dark:text-gray-400"
                                                    >
                                                        {{
                                                            activity.reasoningSummary
                                                        }}
                                                    </p>

                                                    <p
                                                        v-if="
                                                            summarizeToolResult(
                                                                activity.result,
                                                            )
                                                        "
                                                        class="mt-2 leading-relaxed text-gray-500 dark:text-gray-400"
                                                    >
                                                        {{
                                                            summarizeToolResult(
                                                                activity.result,
                                                            )
                                                        }}
                                                    </p>
                                                </div>

                                                <span
                                                    class="shrink-0 rounded-full px-2 py-1 text-[11px] font-medium"
                                                    :class="
                                                        activity.status ===
                                                        'completed'
                                                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
                                                            : 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300'
                                                    "
                                                >
                                                    {{
                                                        activity.status ===
                                                        'completed'
                                                            ? 'Done'
                                                            : 'Running'
                                                    }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </details>

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

                                <details
                                    v-if="hasStreamingActivity"
                                    open
                                    class="mt-3 rounded-md border border-gray-200/80 bg-white/70 p-3 text-xs text-gray-600 dark:border-gray-700 dark:bg-gray-900/40 dark:text-gray-300"
                                >
                                    <summary
                                        class="cursor-pointer font-medium marker:text-gray-400 dark:marker:text-gray-500"
                                    >
                                        Agent activity
                                        <span
                                            v-if="
                                                streamingAgentActivities.length >
                                                0
                                            "
                                            class="ml-1 text-gray-500 dark:text-gray-400"
                                        >
                                            ·
                                            {{
                                                streamingAgentActivities.length
                                            }}
                                            step{{
                                                streamingAgentActivities.length ===
                                                1
                                                    ? ''
                                                    : 's'
                                            }}
                                        </span>
                                    </summary>

                                    <div class="mt-3 space-y-3">
                                        <p
                                            v-if="streamedReasoningSummary"
                                            class="rounded-md bg-gray-100 px-3 py-2 leading-relaxed text-gray-600 dark:bg-gray-800 dark:text-gray-300"
                                        >
                                            {{ streamedReasoningSummary }}
                                        </p>

                                        <div
                                            v-for="activity in streamingAgentActivities"
                                            :key="activity.id"
                                            class="rounded-md border border-gray-200/70 bg-gray-50 px-3 py-2 dark:border-gray-700 dark:bg-gray-800/80"
                                        >
                                            <div
                                                class="flex items-start justify-between gap-3"
                                            >
                                                <div class="min-w-0">
                                                    <p
                                                        class="font-medium text-gray-800 dark:text-gray-100"
                                                    >
                                                        {{ activity.label }}
                                                    </p>

                                                    <div
                                                        v-if="
                                                            summarizeToolArguments(
                                                                activity.arguments,
                                                            ).length
                                                        "
                                                        class="mt-2 flex flex-wrap gap-1.5"
                                                    >
                                                        <span
                                                            v-for="argument in summarizeToolArguments(
                                                                activity.arguments,
                                                            )"
                                                            :key="`${activity.id}-${argument.key}`"
                                                            class="rounded-full bg-white px-2 py-1 text-[11px] text-gray-600 dark:bg-gray-900 dark:text-gray-300"
                                                        >
                                                            <span
                                                                class="font-medium"
                                                                >{{
                                                                    argument.key
                                                                }}:</span
                                                            >
                                                            {{ argument.value }}
                                                        </span>
                                                    </div>

                                                    <p
                                                        v-if="
                                                            activity.reasoningSummary
                                                        "
                                                        class="mt-2 leading-relaxed text-gray-500 dark:text-gray-400"
                                                    >
                                                        {{
                                                            activity.reasoningSummary
                                                        }}
                                                    </p>

                                                    <p
                                                        v-if="
                                                            summarizeToolResult(
                                                                activity.result,
                                                            )
                                                        "
                                                        class="mt-2 leading-relaxed text-gray-500 dark:text-gray-400"
                                                    >
                                                        {{
                                                            summarizeToolResult(
                                                                activity.result,
                                                            )
                                                        }}
                                                    </p>
                                                </div>

                                                <span
                                                    class="shrink-0 rounded-full px-2 py-1 text-[11px] font-medium"
                                                    :class="
                                                        activity.status ===
                                                        'completed'
                                                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
                                                            : 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300'
                                                    "
                                                >
                                                    {{
                                                        activity.status ===
                                                        'completed'
                                                            ? 'Done'
                                                            : 'Running'
                                                    }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </details>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Input area -->
                <div class="border-t border-gray-200 p-4 dark:border-gray-700">
                    <!-- Recording timer -->
                    <div
                        v-if="isListening"
                        class="mb-2 flex items-center justify-center gap-2 text-xs text-red-500 dark:text-red-400"
                    >
                        <span
                            class="h-2 w-2 animate-pulse rounded-full bg-red-500"
                        />
                        Recording {{ recordingSeconds }}s /
                        {{ MAX_RECORDING_SECONDS }}s — tap mic to stop
                        <span
                            class="ml-2 flex h-5 items-center gap-0.5"
                            aria-hidden="true"
                        >
                            <span
                                v-for="(bar, index) in recordingLevelBars"
                                :key="index"
                                class="w-1 rounded-full bg-red-500 transition-all duration-75 ease-out dark:bg-red-400"
                                :style="{
                                    height: bar.height,
                                    opacity: bar.opacity,
                                    transform: bar.active
                                        ? 'scaleY(1)'
                                        : 'scaleY(0.45)',
                                }"
                            />
                        </span>
                    </div>
                    <div class="flex items-end gap-2">
                        <textarea
                            ref="messageTextarea"
                            v-model="messageInput"
                            rows="1"
                            aria-label="Ask AI assistant"
                            data-testid="ai-chat-input"
                            :placeholder="
                                isTranscribing
                                    ? 'Transcribing...'
                                    : isListening
                                      ? 'Listening...'
                                      : 'Ask about contributions, expenses, or reports...'
                            "
                            class="max-h-48 min-h-9 flex-1 resize-none rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 md:text-sm"
                            :class="{
                                'border-red-400 ring-1 ring-red-400':
                                    isListening,
                                'border-amber-400 ring-1 ring-amber-400':
                                    isTranscribing,
                            }"
                            :disabled="isProcessing || isTranscribing"
                            @keydown="handleKeydown"
                        />
                        <Button
                            v-if="speechSupported"
                            variant="outline"
                            :disabled="isProcessing || isTranscribing"
                            :class="{
                                'border-red-400 bg-red-50 text-red-600 hover:bg-red-100 dark:bg-red-950 dark:text-red-400 dark:hover:bg-red-900':
                                    isListening,
                                'border-amber-400 bg-amber-50 text-amber-600 dark:bg-amber-950 dark:text-amber-400':
                                    isTranscribing,
                            }"
                            @click="toggleVoiceInput"
                        >
                            <Loader2
                                v-if="isTranscribing"
                                class="h-4 w-4 animate-spin"
                            />
                            <MicOff v-else-if="isListening" class="h-4 w-4" />
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
