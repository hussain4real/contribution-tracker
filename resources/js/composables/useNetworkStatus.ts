import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

const CACHE_NAME = 'inertia-pages';

const isOnline = ref(
    typeof navigator !== 'undefined' ? navigator.onLine : true,
);
const cachedAt = ref<Date | null>(null);
const justReconnected = ref(false);

let reconnectedTimer: ReturnType<typeof setTimeout> | undefined;

function updateOnlineStatus(): void {
    const wasOffline = !isOnline.value;
    isOnline.value = navigator.onLine;

    if (isOnline.value) {
        cachedAt.value = null;

        if (wasOffline) {
            justReconnected.value = true;
            clearTimeout(reconnectedTimer);
            reconnectedTimer = setTimeout(() => {
                justReconnected.value = false;
            }, 3000);
        }
    } else {
        lookupCacheDate();
    }
}

async function lookupCacheDate(): Promise<void> {
    if (typeof caches === 'undefined') return;

    try {
        const cache = await caches.open(CACHE_NAME);
        const response = await cache.match(window.location.href);

        if (response) {
            const dateHeader = response.headers.get('date');
            cachedAt.value = dateHeader ? new Date(dateHeader) : null;
        } else {
            cachedAt.value = null;
        }
    } catch {
        cachedAt.value = null;
    }
}

// Initialize listeners once at the module level so the singleton state stays
// in sync regardless of how many components consume the composable.
if (typeof window !== 'undefined') {
    window.addEventListener('online', updateOnlineStatus);
    window.addEventListener('offline', updateOnlineStatus);

    router.on('navigate', () => {
        if (!isOnline.value) {
            lookupCacheDate();
        }
    });

    if (!isOnline.value) {
        lookupCacheDate();
    }
}

export function useNetworkStatus() {
    return {
        isOnline,
        cachedAt,
        justReconnected,
    };
}
