import {
    challengeOptions as loginChallengeOptions,
    login as passkeyLogin,
} from '@/actions/App/Http/Controllers/Auth/PasskeyLoginController';
import {
    challengeOptions as twoFactorChallengeOptions,
    verify as twoFactorVerify,
    hasPasskeys as checkHasPasskeys,
} from '@/actions/App/Http/Controllers/Auth/PasskeyTwoFactorController';
import {
    createOptions,
    store as storePasskey,
    destroy as destroyPasskey,
} from '@/actions/App/Http/Controllers/Settings/PasskeyController';
import {
    startAuthentication,
    startRegistration,
} from '@simplewebauthn/browser';
import { ref } from 'vue';

const isSupported = ref<boolean>(false);

if (typeof window !== 'undefined' && typeof window.PublicKeyCredential !== 'undefined') {
    isSupported.value = true;
}

const isProcessing = ref<boolean>(false);
const error = ref<string | null>(null);

function getCsrfToken(): string {
    const cookie = document.cookie
        .split('; ')
        .find((c) => c.startsWith('XSRF-TOKEN='));

    return cookie ? decodeURIComponent(cookie.split('=')[1]) : '';
}

async function jsonRequest<T = any>(
    url: string,
    method: string = 'POST',
    data?: Record<string, any>,
): Promise<T> {
    const response = await fetch(url, {
        method,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': getCsrfToken(),
        },
        credentials: 'same-origin',
        body: data ? JSON.stringify(data) : undefined,
    });

    const responseData = response.headers
        .get('content-type')
        ?.includes('application/json')
        ? await response.json()
        : null;

    if (!response.ok) {
        const err: any = new Error(
            responseData?.message ||
            `Request failed with status ${response.status} (${url})`,
        );
        err.response = { data: responseData, status: response.status };
        throw err;
    }

    return responseData as T;
}

export const useWebAuthn = () => {
    const clearError = (): void => {
        error.value = null;
    };

    const registerPasskey = async (
        name: string,
    ): Promise<{ id: number; name: string } | null> => {
        clearError();
        isProcessing.value = true;

        try {
            const options = await jsonRequest(createOptions.url());

            const credential = await startRegistration({ optionsJSON: options });

            const result = await jsonRequest(storePasskey.url(), 'POST', {
                name,
                credential,
            });

            return result.passkey;
        } catch (e: any) {
            if (e.name === 'NotAllowedError') {
                error.value =
                    'Registration was cancelled or not allowed by the browser.';
            } else {
                error.value =
                    e.response?.data?.message ||
                    e.message ||
                    'Failed to register passkey.';
            }

            return null;
        } finally {
            isProcessing.value = false;
        }
    };

    const loginWithPasskey =
        async (): Promise<{ redirect: string } | null> => {
            clearError();
            isProcessing.value = true;

            try {
                const options = await jsonRequest(
                    loginChallengeOptions.url(),
                );

                const assertion = await startAuthentication({
                    optionsJSON: options,
                });

                const result = await jsonRequest(passkeyLogin.url(), 'POST', {
                    assertion,
                });

                return result;
            } catch (e: any) {
                if (e.name === 'NotAllowedError') {
                    error.value =
                        'Authentication was cancelled or not allowed by the browser.';
                } else {
                    error.value =
                        e.response?.data?.message ||
                        e.message ||
                        'Passkey authentication failed.';
                }

                return null;
            } finally {
                isProcessing.value = false;
            }
        };

    const verifyTwoFactorWithPasskey =
        async (): Promise<{ redirect: string } | null> => {
            clearError();
            isProcessing.value = true;

            try {
                const options = await jsonRequest(
                    twoFactorChallengeOptions.url(),
                );

                const assertion = await startAuthentication({
                    optionsJSON: options,
                });

                const result = await jsonRequest(twoFactorVerify.url(), 'POST', {
                    assertion,
                });

                return result;
            } catch (e: any) {
                if (e.name === 'NotAllowedError') {
                    error.value =
                        'Biometric verification was cancelled or not allowed.';
                } else {
                    error.value =
                        e.response?.data?.message ||
                        e.message ||
                        'Biometric verification failed.';
                }

                return null;
            } finally {
                isProcessing.value = false;
            }
        };

    const deletePasskey = async (passkeyId: number): Promise<boolean> => {
        clearError();
        isProcessing.value = true;

        try {
            await jsonRequest(destroyPasskey.url(passkeyId), 'DELETE');

            return true;
        } catch (e: any) {
            error.value =
                e.response?.data?.message ||
                e.message ||
                'Failed to remove passkey.';

            return false;
        } finally {
            isProcessing.value = false;
        }
    };

    const checkUserHasPasskeys = async (): Promise<boolean> => {
        try {
            const result = await jsonRequest(checkHasPasskeys.url());

            return result.hasPasskeys;
        } catch {
            return false;
        }
    };

    return {
        isSupported,
        isProcessing,
        error,
        clearError,
        registerPasskey,
        loginWithPasskey,
        verifyTwoFactorWithPasskey,
        deletePasskey,
        checkUserHasPasskeys,
    };
};
