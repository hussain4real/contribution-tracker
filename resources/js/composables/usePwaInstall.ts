import { ref } from 'vue';

interface BeforeInstallPromptEvent extends Event {
    prompt(): Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

const deferredPrompt = ref<BeforeInstallPromptEvent | null>(null);
const isInstallable = ref(false);
const isInstalled = ref(false);
const dismissed = ref(false);

const DISMISS_KEY = 'pwa-install-dismissed';
const DISMISS_DURATION_MS = 7 * 24 * 60 * 60 * 1000; // 7 days
let initialized = false;

function isDismissed(): boolean {
    const raw = localStorage.getItem(DISMISS_KEY);
    if (!raw) return false;
    const timestamp = parseInt(raw, 10);
    if (Date.now() - timestamp > DISMISS_DURATION_MS) {
        localStorage.removeItem(DISMISS_KEY);
        return false;
    }
    return true;
}

function init(): void {
    if (initialized) return;
    initialized = true;
    // Check if already installed (standalone mode)
    if (
        window.matchMedia('(display-mode: standalone)').matches ||
        (navigator as Record<string, unknown>).standalone
    ) {
        isInstalled.value = true;
        return;
    }

    dismissed.value = isDismissed();

    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredPrompt.value = e as BeforeInstallPromptEvent;
        isInstallable.value = true;
    });

    window.addEventListener('appinstalled', () => {
        isInstalled.value = true;
        isInstallable.value = false;
        deferredPrompt.value = null;
    });
}

async function install(): Promise<boolean> {
    if (!deferredPrompt.value) return false;

    await deferredPrompt.value.prompt();
    const { outcome } = await deferredPrompt.value.userChoice;

    deferredPrompt.value = null;
    isInstallable.value = false;

    if (outcome === 'dismissed') {
        // Shorter 1-hour cooldown for native dialog dismissal (user showed intent to install)
        dismissed.value = true;
        localStorage.setItem(DISMISS_KEY, (Date.now() - DISMISS_DURATION_MS + 60 * 60 * 1000).toString());
    }

    return outcome === 'accepted';
}

function dismiss(): void {
    dismissed.value = true;
    localStorage.setItem(DISMISS_KEY, Date.now().toString());
}

export function usePwaInstall() {
    return {
        isInstallable,
        isInstalled,
        dismissed,
        init,
        install,
        dismiss,
    };
}
