import {
    destroy,
    store,
} from '@/actions/App/Http/Controllers/Settings/WebPushSubscriptionController';
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type PushSubscriptionPayload = {
    endpoint: string;
    keys: {
        p256dh: string;
        auth: string;
    };
    contentEncoding: string;
};

const isSupported = ref(false);
const permission = ref<NotificationPermission>('default');
const subscribed = ref(false);
const processing = ref(false);
const ready = ref(false);
const error = ref<string | null>(null);
let initialized = false;

const PUSH_SERVICE_WORKER_URL = '/web-push-sw.js';
const PUSH_SERVICE_WORKER_SCOPE = '/web-push/';
const SERVICE_WORKER_ACTIVATION_TIMEOUT_MS = 8000;

function base64ToUint8Array(base64String: string): Uint8Array {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding)
        .replaceAll('-', '+')
        .replaceAll('_', '/');
    const rawData = window.atob(base64);
    const output = new Uint8Array(rawData.length);

    for (let i = 0; i < rawData.length; i += 1) {
        output[i] = rawData.charCodeAt(i);
    }

    return output;
}

function supportedContentEncoding(): string {
    const encodings =
        (PushManager as unknown as { supportedContentEncodings?: string[] })
            .supportedContentEncodings ?? [];

    return encodings.includes('aes128gcm') ? 'aes128gcm' : 'aesgcm';
}

function subscriptionPayload(
    subscription: PushSubscription,
): PushSubscriptionPayload {
    const json = subscription.toJSON();

    return {
        endpoint: json.endpoint ?? subscription.endpoint,
        keys: {
            p256dh: json.keys?.p256dh ?? '',
            auth: json.keys?.auth ?? '',
        },
        contentEncoding: supportedContentEncoding(),
    };
}

function serviceWorkerErrorMessage(exception: unknown): string {
    if (exception instanceof DOMException) {
        if (exception.name === 'NotAllowedError') {
            return 'Browser notification permission was not granted.';
        }

        if (exception.name === 'InvalidCharacterError') {
            return 'The browser notification key is invalid. Regenerate the VAPID keys and reload the page.';
        }
    }

    return 'We could not prepare browser notifications. Reload the page and try again.';
}

async function waitForActivation(
    registration: ServiceWorkerRegistration,
): Promise<ServiceWorkerRegistration> {
    if (registration.active) {
        return registration;
    }

    const worker = registration.installing ?? registration.waiting;

    if (!worker) {
        return registration;
    }

    await new Promise<void>((resolve, reject) => {
        const timeout = window.setTimeout(() => {
            worker.removeEventListener('statechange', handleStateChange);
            reject(new Error('Timed out waiting for the service worker.'));
        }, SERVICE_WORKER_ACTIVATION_TIMEOUT_MS);

        function handleStateChange(): void {
            if (worker.state === 'activated') {
                window.clearTimeout(timeout);
                worker.removeEventListener('statechange', handleStateChange);
                resolve();
            }
        }

        worker.addEventListener('statechange', handleStateChange);
        handleStateChange();
    });

    return registration;
}

async function registrationWithSubscription(): Promise<{
    registration: ServiceWorkerRegistration;
    subscription: PushSubscription;
} | null> {
    const registrations = await navigator.serviceWorker.getRegistrations();

    for (const registration of registrations) {
        const subscription = await registration.pushManager
            .getSubscription()
            .catch(() => null);

        if (subscription) {
            return { registration, subscription };
        }
    }

    return null;
}

async function webPushRegistration(): Promise<ServiceWorkerRegistration> {
    const existingSubscription = await registrationWithSubscription();

    if (existingSubscription) {
        return existingSubscription.registration;
    }

    const existingRegistration = await navigator.serviceWorker.getRegistration(
        PUSH_SERVICE_WORKER_SCOPE,
    );

    if (existingRegistration) {
        return waitForActivation(existingRegistration);
    }

    return waitForActivation(
        await navigator.serviceWorker.register(PUSH_SERVICE_WORKER_URL, {
            scope: PUSH_SERVICE_WORKER_SCOPE,
        }),
    );
}

async function currentSubscription(): Promise<PushSubscription | null> {
    const existingSubscription = await registrationWithSubscription();

    if (existingSubscription) {
        return existingSubscription.subscription;
    }

    const registration = await navigator.serviceWorker.getRegistration(
        PUSH_SERVICE_WORKER_SCOPE,
    );

    return registration?.pushManager.getSubscription() ?? null;
}

async function init(): Promise<void> {
    if (initialized || typeof window === 'undefined') return;
    initialized = true;

    const page = usePage();
    isSupported.value =
        !!page.props.webPush?.enabled &&
        'Notification' in window &&
        'serviceWorker' in navigator &&
        'PushManager' in window;

    if (!isSupported.value) {
        ready.value = true;
        return;
    }

    permission.value = Notification.permission;
    subscribed.value = !!page.props.webPush?.subscribed;

    if (permission.value === 'granted') {
        try {
            subscribed.value = (await currentSubscription()) !== null;
        } catch {
            subscribed.value = false;
        }
    }

    ready.value = true;
}

async function subscribe(): Promise<void> {
    const page = usePage();
    const publicKey = page.props.webPush?.publicKey;

    if (!isSupported.value || !publicKey) {
        error.value = 'Browser notifications are not available.';
        return;
    }

    processing.value = true;
    error.value = null;

    try {
        permission.value = await Notification.requestPermission();

        if (permission.value !== 'granted') {
            error.value = 'Browser notification permission was not granted.';
            return;
        }

        const existingSubscription = await registrationWithSubscription();
        const registration =
            existingSubscription?.registration ?? (await webPushRegistration());
        const subscription =
            existingSubscription?.subscription ??
            (await registration.pushManager.getSubscription()) ??
            (await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: base64ToUint8Array(publicKey),
            }));

        await new Promise<void>((resolve) => {
            router.visit(store(), {
                data: subscriptionPayload(subscription),
                preserveScroll: true,
                onSuccess: () => {
                    subscribed.value = true;
                    router.reload({ only: ['webPush'] });
                },
                onError: () => {
                    error.value =
                        'We could not save this browser subscription.';
                },
                onFinish: () => resolve(),
            });
        });
    } catch (exception) {
        error.value = serviceWorkerErrorMessage(exception);
    } finally {
        processing.value = false;
    }
}

async function unsubscribe(): Promise<void> {
    if (!isSupported.value) return;

    processing.value = true;
    error.value = null;

    try {
        const subscription = await currentSubscription();

        if (!subscription) {
            subscribed.value = false;
            return;
        }

        await new Promise<void>((resolve) => {
            router.visit(destroy(), {
                data: { endpoint: subscription.endpoint },
                preserveScroll: true,
                onSuccess: async () => {
                    await subscription.unsubscribe();
                    subscribed.value = false;
                    router.reload({ only: ['webPush'] });
                },
                onError: () => {
                    error.value =
                        'We could not remove this browser subscription.';
                },
                onFinish: () => resolve(),
            });
        });
    } finally {
        processing.value = false;
    }
}

export function useWebPush() {
    const denied = computed(() => permission.value === 'denied');

    return {
        isSupported,
        permission,
        subscribed,
        processing,
        ready,
        error,
        denied,
        init,
        subscribe,
        unsubscribe,
    };
}
