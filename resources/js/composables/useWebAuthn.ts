import {
    destroy as destroyPasskey,
    login as passkeyLogin,
    loginOptions,
    registrationOptions,
    store as storePasskey,
} from '@/routes/passkey';
import {
    hasPasskeys as checkHasPasskeys,
    options as twoFactorChallengeOptions,
    verify as twoFactorVerify,
} from '@/routes/passkey/two-factor';
import { PasskeyError, Passkeys } from '@laravel/passkeys';
import { ref } from 'vue';

const isSupported = ref<boolean>(false);

if (typeof window !== 'undefined') {
    isSupported.value = Passkeys.isSupported();
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
    ): Promise<{ id: string; name: string } | null> => {
        clearError();
        isProcessing.value = true;

        try {
            const result = await Passkeys.register({
                name,
                routes: {
                    options: registrationOptions.url(),
                    submit: storePasskey.url(),
                },
            });

            return {
                id: result.id,
                name: result.name,
            };
        } catch (e: any) {
            if (e.name === 'UserCancelledError') {
                error.value =
                    'Registration was cancelled or not allowed by the browser.';
            } else if (e instanceof PasskeyError) {
                error.value = e.message || 'Failed to register passkey.';
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

    const loginWithPasskey = async (): Promise<{ redirect: string } | null> => {
        clearError();
        isProcessing.value = true;

        try {
            const result = await Passkeys.verify({
                routes: {
                    options: loginOptions.url(),
                    submit: passkeyLogin.url(),
                },
            });

            return result;
        } catch (e: any) {
            if (e.name === 'UserCancelledError') {
                error.value =
                    'Authentication was cancelled or not allowed by the browser.';
            } else if (e instanceof PasskeyError) {
                error.value = e.message || 'Passkey authentication failed.';
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

    const verifyTwoFactorWithPasskey = async (): Promise<{
        redirect: string;
    } | null> => {
        clearError();
        isProcessing.value = true;

        try {
            const result = await Passkeys.verify({
                routes: {
                    options: twoFactorChallengeOptions.url(),
                    submit: twoFactorVerify.url(),
                },
            });

            return result;
        } catch (e: any) {
            if (e.name === 'UserCancelledError') {
                error.value =
                    'Biometric verification was cancelled or not allowed.';
            } else if (e instanceof PasskeyError) {
                error.value = e.message || 'Biometric verification failed.';
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
