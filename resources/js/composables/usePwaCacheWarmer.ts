import { onMounted } from 'vue';

const CACHE_NAME = 'inertia-pages-v2';
const SESSION_FLAG = 'pwa-cache-warmed';

/**
 * Routes to pre-fetch and cache so the installed PWA can render them
 * immediately on a cold offline launch. Each entry must be a fully
 * authenticated, cacheable GET route.
 */
const ROUTES_TO_WARM = [
    '/dashboard',
    '/members',
    '/contributions/my',
    '/contributions',
    '/payments',
];

function isStandalonePwa(): boolean {
    if (typeof window === 'undefined') {
        return false;
    }

    // iOS Safari exposes navigator.standalone; other browsers use display-mode.
    const navigatorStandalone = (
        window.navigator as Navigator & { standalone?: boolean }
    ).standalone;

    return (
        navigatorStandalone === true ||
        window.matchMedia?.('(display-mode: standalone)').matches === true
    );
}

async function warmCache(): Promise<void> {
    if (typeof window === 'undefined') {
        return;
    }
    if (!('caches' in window) || !('serviceWorker' in navigator)) {
        return;
    }
    if (!navigator.onLine) {
        return;
    }
    if (!navigator.serviceWorker.controller) {
        return;
    }
    if (sessionStorage.getItem(SESSION_FLAG) === '1') {
        return;
    }

    sessionStorage.setItem(SESSION_FLAG, '1');

    try {
        const cache = await caches.open(CACHE_NAME);

        await Promise.allSettled(
            ROUTES_TO_WARM.map(async (path) => {
                try {
                    const response = await fetch(path, {
                        credentials: 'same-origin',
                        headers: { Accept: 'text/html' },
                        // Bypass any HTTP cache so we always store a fresh copy.
                        cache: 'reload',
                    });

                    if (response.ok && response.type === 'basic') {
                        await cache.put(path, response.clone());
                    }
                } catch {
                    // Swallow per-route failures so one bad route doesn't
                    // abort warming the rest.
                }
            }),
        );
    } catch {
        // If cache warming fails entirely, allow a retry next session.
        sessionStorage.removeItem(SESSION_FLAG);
    }
}

export function usePwaCacheWarmer(): void {
    onMounted(() => {
        if (!isStandalonePwa()) {
            return;
        }

        // Defer to idle so we never compete with the initial paint.
        const schedule =
            (
                window as Window & {
                    requestIdleCallback?: (cb: () => void) => void;
                }
            ).requestIdleCallback ??
            ((cb: () => void) => window.setTimeout(cb, 1500));

        schedule(() => {
            void warmCache();
        });
    });
}
