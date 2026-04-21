import { router } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';

const CACHE_NAME = 'inertia-pages';

const isOnline = ref(true);
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

export function useNetworkStatus() {
    let removeRouteListener: (() => void) | undefined;

    onMounted(() => {
        isOnline.value = navigator.onLine;

        window.addEventListener('online', updateOnlineStatus);
        window.addEventListener('offline', updateOnlineStatus);

        removeRouteListener = router.on('navigate', () => {
            if (!isOnline.value) {
                lookupCacheDate();
            }
        });

        if (!isOnline.value) {
            lookupCacheDate();
        }
    });

    onUnmounted(() => {
        window.removeEventListener('online', updateOnlineStatus);
        window.removeEventListener('offline', updateOnlineStatus);
        removeRouteListener?.();
        clearTimeout(reconnectedTimer);
    });

    return {
        isOnline,
        cachedAt,
        justReconnected,
    };
}
