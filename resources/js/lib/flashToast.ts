import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import type { FlashToast } from '@/types/ui';

export function initializeFlashToast(): void {
    // Handle new Inertia::flash('toast', ...) events
    router.on('flash', (event) => {
        const flash = (event as CustomEvent).detail?.flash;
        const data = flash?.toast as FlashToast | undefined;

        if (!data) {
            return;
        }

        toast[data.type](data.message);
    });

    // Handle existing session flash (success/error/warning) for backward compatibility.
    // Uses the 'success' event which fires after every successful Inertia response,
    // with the page object containing the latest props.
    router.on('success', (event) => {
        const flash = (event as CustomEvent).detail?.page?.props?.flash as
            | { success?: string | null; error?: string | null; warning?: string | null }
            | undefined;

        if (flash?.success) {
            toast.success(flash.success);
        }

        if (flash?.error) {
            toast.error(flash.error);
        }

        if (flash?.warning) {
            toast.warning(flash.warning);
        }
    });
}
