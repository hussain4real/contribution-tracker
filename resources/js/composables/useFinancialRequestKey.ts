import type { FormComponentOptions, VisitOptions } from '@inertiajs/core';
import { onMounted, ref } from 'vue';

export function useFinancialRequestKey(
    initialKey: string,
    scope: string,
    label: string,
) {
    const requestKey = ref(initialKey);
    const unconfirmed = ref(false);
    const storageReady = ref(false);
    const storageError = ref('');
    const storageKey = `familyfund:${scope}`;
    let unconfirmedBeforeSubmit = false;

    function saveRequestKey(uncertain: boolean): boolean {
        try {
            sessionStorage.setItem(
                storageKey,
                `${requestKey.value}|${uncertain ? '1' : '0'}`,
            );
            storageError.value = '';
            return true;
        } catch {
            storageError.value = `Recording an ${label} requires session storage to protect against duplicate entries. Please enable it and reload this page.`;
            return false;
        }
    }

    onMounted(() => {
        try {
            const saved = sessionStorage.getItem(storageKey);
            if (saved) {
                const [key, state] = saved.split('|');
                if (
                    !key ||
                    !/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(
                        key,
                    ) ||
                    (state !== undefined && state !== '0' && state !== '1')
                ) {
                    storageError.value =
                        'The saved request cannot be verified. Check the records before clearing browser session storage.';
                    return;
                }
                requestKey.value = key;
                unconfirmed.value = state === '1';
            }
            storageReady.value = saveRequestKey(unconfirmed.value);
        } catch {
            storageError.value = `Recording an ${label} requires session storage to protect against duplicate entries. Please enable it and reload this page.`;
        }
    });

    function begin(): boolean {
        if (!storageReady.value || !saveRequestKey(true)) return false;
        unconfirmedBeforeSubmit = unconfirmed.value;
        unconfirmed.value = true;
        return true;
    }

    function rejected(): void {
        unconfirmed.value = unconfirmedBeforeSubmit;
        storageReady.value = saveRequestKey(unconfirmed.value);
    }

    function complete(): void {
        unconfirmed.value = false;
        try {
            sessionStorage.removeItem(storageKey);
        } catch {
            storageReady.value = false;
        }
    }

    function renew(): void {
        requestKey.value = crypto.randomUUID();
        unconfirmed.value = false;
        storageReady.value = saveRequestKey(false);
    }

    function startNew(): boolean {
        if (
            !window.confirm(
                `Have you checked the ${label} list for the earlier entry? Starting a new ${label} can create another entry.`,
            )
        )
            return false;
        renew();
        return true;
    }

    const options: FormComponentOptions &
        Pick<VisitOptions, 'onHttpException' | 'onNetworkError'> = {
        onHttpException: (response) =>
            response.status >= 500 ? false : undefined,
        onNetworkError: () => false,
    };

    return {
        requestKey,
        unconfirmed,
        storageReady,
        storageError,
        options,
        begin,
        rejected,
        complete,
        renew,
        startNew,
    };
}
