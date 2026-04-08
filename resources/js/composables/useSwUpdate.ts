import { ref } from 'vue';

const needRefresh = ref(false);
let updateSW: ((reloadPage?: boolean) => Promise<void>) | undefined;

function init(): void {
    if (typeof window === 'undefined' || !('serviceWorker' in navigator))
        return;

    import('virtual:pwa-register')
        .then(({ registerSW }) => {
            updateSW = registerSW({
                immediate: true,
                onNeedRefresh() {
                    needRefresh.value = true;
                },
                onOfflineReady() {
                    // SW installed and ready for offline use — no action needed
                },
            });
        })
        .catch((err: unknown) => {
            if (import.meta.env.DEV) {
                console.warn('[PWA] SW registration skipped in dev:', err);
            }
        });
}

async function applyUpdate(): Promise<void> {
    if (updateSW) {
        try {
            await updateSW(true);
        } catch {
            needRefresh.value = false;
        }
    }
}

function dismissUpdate(): void {
    needRefresh.value = false;
}

export function useSwUpdate() {
    return {
        needRefresh,
        init,
        applyUpdate,
        dismissUpdate,
    };
}
