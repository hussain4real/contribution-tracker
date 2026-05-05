import { router } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';

const MAX_PULL_DISTANCE = 86;
const REFRESH_THRESHOLD = 72;

function isInteractiveTarget(target: EventTarget | null): boolean {
    if (!(target instanceof Element)) {
        return false;
    }

    return !!target.closest(
        'input, textarea, select, button, a, [role="dialog"], [data-slot="dialog-content"], [data-slot="sheet-content"], [data-no-pull-refresh]',
    );
}

function hasScrollableParent(target: EventTarget | null): boolean {
    if (!(target instanceof Element)) {
        return false;
    }

    let current: Element | null = target.parentElement;

    while (current && current !== document.body) {
        const style = window.getComputedStyle(current);
        const canScroll =
            /(auto|scroll)/.test(style.overflowY) &&
            current.scrollHeight > current.clientHeight;

        if (canScroll && current.scrollTop > 0) {
            return true;
        }

        current = current.parentElement;
    }

    return false;
}

function isTouchScreen(): boolean {
    return (
        window.matchMedia?.('(pointer: coarse)').matches === true ||
        navigator.maxTouchPoints > 0
    );
}

export function usePullToRefresh() {
    const pullDistance = ref(0);
    const isRefreshing = ref(false);
    const isPulling = ref(false);

    let startY = 0;
    let active = false;

    function resetPull(): void {
        active = false;
        isPulling.value = false;
        pullDistance.value = 0;
    }

    function onTouchStart(event: TouchEvent): void {
        if (
            isRefreshing.value ||
            !isTouchScreen() ||
            window.scrollY > 0 ||
            isInteractiveTarget(event.target) ||
            hasScrollableParent(event.target)
        ) {
            return;
        }

        startY = event.touches[0]?.clientY ?? 0;
        active = true;
    }

    function onTouchMove(event: TouchEvent): void {
        if (!active) {
            return;
        }

        const currentY = event.touches[0]?.clientY ?? 0;
        const distance = currentY - startY;

        if (distance <= 0) {
            resetPull();
            return;
        }

        event.preventDefault();
        isPulling.value = true;
        pullDistance.value = Math.min(
            MAX_PULL_DISTANCE,
            Math.round(distance * 0.45),
        );
    }

    function onTouchEnd(): void {
        if (!active) {
            return;
        }

        if (pullDistance.value >= REFRESH_THRESHOLD) {
            isRefreshing.value = true;
            router.reload({
                preserveScroll: true,
                onFinish: () => {
                    isRefreshing.value = false;
                    resetPull();
                },
            });

            return;
        }

        resetPull();
    }

    onMounted(() => {
        window.addEventListener('touchstart', onTouchStart, { passive: true });
        window.addEventListener('touchmove', onTouchMove, { passive: false });
        window.addEventListener('touchend', onTouchEnd, { passive: true });
        window.addEventListener('touchcancel', resetPull, { passive: true });
    });

    onUnmounted(() => {
        window.removeEventListener('touchstart', onTouchStart);
        window.removeEventListener('touchmove', onTouchMove);
        window.removeEventListener('touchend', onTouchEnd);
        window.removeEventListener('touchcancel', resetPull);
    });

    return {
        pullDistance,
        isPulling,
        isRefreshing,
    };
}
