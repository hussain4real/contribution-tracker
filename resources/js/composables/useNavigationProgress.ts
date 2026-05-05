import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

const isNavigating = ref(false);
let delayTimer: ReturnType<typeof setTimeout> | undefined;
let initialized = false;

export function useNavigationProgress() {
    if (!initialized && typeof window !== 'undefined') {
        initialized = true;

        router.on('start', () => {
            clearTimeout(delayTimer);
            delayTimer = setTimeout(() => {
                isNavigating.value = true;
            }, 180);
        });

        router.on('finish', () => {
            clearTimeout(delayTimer);
            isNavigating.value = false;
        });
    }

    return { isNavigating };
}
