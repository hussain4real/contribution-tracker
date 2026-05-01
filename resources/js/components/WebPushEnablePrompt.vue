<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { useWebPush } from '@/composables/useWebPush';
import { edit as editProfile } from '@/routes/profile';
import { Link, usePage } from '@inertiajs/vue3';
import { BellRing, X } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

const PROMPT_INTERVAL_MS = 60 * 60 * 1000;
const STORAGE_PREFIX = 'web_push_prompt';

const page = usePage();
const {
    isSupported,
    subscribed,
    processing,
    ready,
    error: webPushError,
    denied,
    init,
    subscribe,
} = useWebPush();

const promptVisible = ref(false);
let promptTimer: ReturnType<typeof setTimeout> | undefined;

const userId = computed(() => page.props.auth?.user?.id);
const storageKey = computed(() =>
    userId.value ? `${STORAGE_PREFIX}:${userId.value}:dismissed_at` : null,
);
const sessionKey = computed(() =>
    userId.value ? `${STORAGE_PREFIX}:${userId.value}:seen_this_session` : null,
);

const canPrompt = computed(
    () =>
        ready.value &&
        !!userId.value &&
        !!page.props.webPush?.enabled &&
        !page.props.webPush.subscribed &&
        !subscribed.value &&
        isSupported.value,
);

function clearPromptTimer(): void {
    if (promptTimer) {
        clearTimeout(promptTimer);
        promptTimer = undefined;
    }
}

function dismissedAt(): number | null {
    if (!storageKey.value) {
        return null;
    }

    const storedValue = window.localStorage.getItem(storageKey.value);
    const timestamp = storedValue ? Number(storedValue) : Number.NaN;

    return Number.isFinite(timestamp) ? timestamp : null;
}

function hasSeenPromptThisSession(): boolean {
    return sessionKey.value
        ? window.sessionStorage.getItem(sessionKey.value) === 'true'
        : false;
}

function markPromptSeenThisSession(): void {
    if (sessionKey.value) {
        window.sessionStorage.setItem(sessionKey.value, 'true');
    }
}

function scheduleNextPrompt(): void {
    clearPromptTimer();

    const lastDismissedAt = dismissedAt();

    if (
        !lastDismissedAt ||
        Date.now() - lastDismissedAt >= PROMPT_INTERVAL_MS
    ) {
        promptVisible.value = canPrompt.value;
        if (promptVisible.value) {
            markPromptSeenThisSession();
        }

        return;
    }

    promptVisible.value = false;
    promptTimer = setTimeout(
        () => {
            if (canPrompt.value) {
                promptVisible.value = true;
                markPromptSeenThisSession();
            }
        },
        PROMPT_INTERVAL_MS - (Date.now() - lastDismissedAt),
    );
}

function refreshPrompt(): void {
    if (!canPrompt.value) {
        promptVisible.value = false;
        clearPromptTimer();

        return;
    }

    if (!hasSeenPromptThisSession()) {
        promptVisible.value = true;
        markPromptSeenThisSession();

        return;
    }

    scheduleNextPrompt();
}

function dismissPrompt(): void {
    promptVisible.value = false;

    if (storageKey.value) {
        window.localStorage.setItem(storageKey.value, String(Date.now()));
    }

    scheduleNextPrompt();
}

async function enableNotifications(): Promise<void> {
    await subscribe();

    if (subscribed.value || page.props.webPush?.subscribed) {
        promptVisible.value = false;
        clearPromptTimer();
    }
}

onMounted(() => {
    void init();
});

watch(canPrompt, refreshPrompt, { immediate: true });

onUnmounted(() => clearPromptTimer());
</script>

<template>
    <div
        v-if="promptVisible"
        class="flex items-center justify-between gap-3 border-b border-violet-200 bg-violet-50 px-4 py-2 text-sm dark:border-violet-800 dark:bg-violet-950/30"
    >
        <div class="flex min-w-0 items-center gap-2">
            <BellRing
                class="h-4 w-4 shrink-0 text-violet-600 dark:text-violet-300"
                aria-hidden="true"
            />
            <span class="truncate text-violet-950 dark:text-violet-100">
                {{
                    webPushError ??
                    'Enable browser notifications for contribution reminders.'
                }}
            </span>
        </div>

        <div class="flex shrink-0 items-center gap-2">
            <Link v-if="denied" :href="editProfile().url">
                <Button size="sm" variant="default">Review settings</Button>
            </Link>
            <Button
                v-else
                size="sm"
                variant="default"
                :disabled="processing"
                @click="enableNotifications"
            >
                {{ processing ? 'Enabling...' : 'Enable' }}
            </Button>
            <Button
                size="icon"
                variant="ghost"
                class="h-7 w-7"
                aria-label="Dismiss browser notification prompt"
                @click="dismissPrompt"
            >
                <X class="h-4 w-4" />
            </Button>
        </div>
    </div>
</template>
