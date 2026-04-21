<script setup lang="ts">
import { useNetworkStatus } from '@/composables/useNetworkStatus';
import { Wifi, WifiOff } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref } from 'vue';

const { isOnline, cachedAt, justReconnected } = useNetworkStatus();

// Reactive "now" so the relative timestamp updates as time passes.
const now = ref(Date.now());
let ticker: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    ticker = setInterval(() => {
        now.value = Date.now();
    }, 30_000);
});

onUnmounted(() => {
    clearInterval(ticker);
});

const timeAgo = computed(() => {
    if (!cachedAt.value) return null;

    const seconds = Math.floor((now.value - cachedAt.value.getTime()) / 1000);

    if (seconds < 60) return 'just now';
    if (seconds < 3600) {
        const minutes = Math.floor(seconds / 60);
        return `${minutes} minute${minutes !== 1 ? 's' : ''} ago`;
    }
    if (seconds < 86400) {
        const hours = Math.floor(seconds / 3600);
        return `${hours} hour${hours !== 1 ? 's' : ''} ago`;
    }

    const days = Math.floor(seconds / 86400);
    return `${days} day${days !== 1 ? 's' : ''} ago`;
});

const visible = computed(() => !isOnline.value || justReconnected.value);
</script>

<template>
    <Transition
        enter-active-class="transition duration-300 ease-out"
        enter-from-class="-translate-y-full opacity-0"
        enter-to-class="translate-y-0 opacity-100"
        leave-active-class="transition duration-200 ease-in"
        leave-from-class="translate-y-0 opacity-100"
        leave-to-class="-translate-y-full opacity-0"
    >
        <div
            v-if="visible"
            :class="[
                'flex items-center justify-center gap-2 px-4 py-1.5 text-xs font-medium text-white',
                isOnline
                    ? 'bg-green-600 dark:bg-green-700'
                    : 'bg-amber-500 dark:bg-amber-600',
            ]"
            role="status"
            aria-live="polite"
        >
            <template v-if="isOnline">
                <Wifi class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                <span>Back online</span>
            </template>
            <template v-else>
                <WifiOff class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                <span>
                    You're offline
                    <template v-if="timeAgo">
                        — showing data from {{ timeAgo }}
                    </template>
                </span>
            </template>
        </div>
    </Transition>
</template>
