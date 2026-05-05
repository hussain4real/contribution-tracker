<script setup lang="ts">
import { index as aiIndex } from '@/actions/App/Http/Controllers/AiChatController';
import { router, usePage } from '@inertiajs/vue3';
import { BotMessageSquare, GripHorizontal, Sparkles } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const page = usePage();

const STORAGE_KEY = 'familyfunds_floating_ai_position';
const BUTTON_SIZE = 60;
const VIEWPORT_MARGIN = 12;
const MOBILE_BOTTOM_GAP = 92;
const DESKTOP_BOTTOM_GAP = 24;
const DRAG_THRESHOLD = 5;

type Position = {
    x: number;
    y: number;
};

const position = ref<Position>({ x: 0, y: 0 });
const ready = ref(false);
const dragging = ref(false);
const suppressClick = ref(false);
const dragStart = ref({
    pointerId: 0,
    clientX: 0,
    clientY: 0,
    x: 0,
    y: 0,
});

const showAssistant = computed(
    () =>
        !!page.props.featureFlags?.ai_assistant && !page.url.startsWith('/ai'),
);

const buttonStyle = computed(() => ({
    transform: `translate3d(${position.value.x}px, ${position.value.y}px, 0)`,
}));

function clampPosition(next: Position): Position {
    if (typeof window === 'undefined') {
        return next;
    }

    return {
        x: Math.min(
            Math.max(VIEWPORT_MARGIN, next.x),
            window.innerWidth - BUTTON_SIZE - VIEWPORT_MARGIN,
        ),
        y: Math.min(
            Math.max(VIEWPORT_MARGIN, next.y),
            window.innerHeight - BUTTON_SIZE - VIEWPORT_MARGIN,
        ),
    };
}

function defaultPosition(): Position {
    const isMobile = window.matchMedia('(max-width: 767px)').matches;

    return clampPosition({
        x: window.innerWidth - BUTTON_SIZE - 16,
        y:
            window.innerHeight -
            BUTTON_SIZE -
            (isMobile ? MOBILE_BOTTOM_GAP : DESKTOP_BOTTOM_GAP),
    });
}

function readStoredPosition(): Position | null {
    const rawPosition = localStorage.getItem(STORAGE_KEY);

    if (!rawPosition) {
        return null;
    }

    try {
        const parsed = JSON.parse(rawPosition) as Partial<Position>;

        if (
            typeof parsed.x === 'number' &&
            Number.isFinite(parsed.x) &&
            typeof parsed.y === 'number' &&
            Number.isFinite(parsed.y)
        ) {
            return clampPosition({ x: parsed.x, y: parsed.y });
        }
    } catch {
        localStorage.removeItem(STORAGE_KEY);
    }

    return null;
}

function storePosition(): void {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(position.value));
}

function handlePointerDown(event: PointerEvent): void {
    if (event.button !== 0) {
        return;
    }

    dragging.value = true;
    suppressClick.value = false;
    dragStart.value = {
        pointerId: event.pointerId,
        clientX: event.clientX,
        clientY: event.clientY,
        x: position.value.x,
        y: position.value.y,
    };

    try {
        (event.currentTarget as HTMLElement | null)?.setPointerCapture?.(
            event.pointerId,
        );
    } catch {
        // Browser automation can synthesize pointer events without an active pointer.
    }
}

function handlePointerMove(event: PointerEvent): void {
    if (!dragging.value || event.pointerId !== dragStart.value.pointerId) {
        return;
    }

    const deltaX = event.clientX - dragStart.value.clientX;
    const deltaY = event.clientY - dragStart.value.clientY;

    if (
        Math.abs(deltaX) > DRAG_THRESHOLD ||
        Math.abs(deltaY) > DRAG_THRESHOLD
    ) {
        suppressClick.value = true;
    }

    position.value = clampPosition({
        x: dragStart.value.x + deltaX,
        y: dragStart.value.y + deltaY,
    });

    event.preventDefault();
}

function handlePointerUp(event: PointerEvent): void {
    if (!dragging.value || event.pointerId !== dragStart.value.pointerId) {
        return;
    }

    dragging.value = false;
    storePosition();

    if (suppressClick.value) {
        window.setTimeout(() => {
            suppressClick.value = false;
        }, 0);
    }
}

function openAssistant(event: MouseEvent): void {
    if (suppressClick.value) {
        event.preventDefault();
        window.setTimeout(() => {
            suppressClick.value = false;
        }, 0);

        return;
    }

    router.visit(aiIndex().url);
}

function resetWithinViewport(): void {
    position.value = clampPosition(position.value);
    storePosition();
}

onMounted(() => {
    position.value = readStoredPosition() ?? defaultPosition();
    ready.value = true;
    window.addEventListener('resize', resetWithinViewport);
});

onBeforeUnmount(() => {
    window.removeEventListener('resize', resetWithinViewport);
});
</script>

<template>
    <button
        v-if="showAssistant && ready"
        type="button"
        class="app-tap fixed top-0 left-0 z-50 flex size-15 touch-none items-center justify-center rounded-full bg-neutral-950 text-white shadow-[0_16px_40px_rgba(15,23,42,0.28)] ring-1 ring-white/20 transition-shadow hover:shadow-[0_18px_46px_rgba(15,23,42,0.34)] focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2 focus-visible:outline-none active:scale-100 dark:bg-neutral-50 dark:text-neutral-950 dark:ring-neutral-950/10"
        :class="{
            'cursor-grabbing shadow-2xl': dragging,
            'cursor-grab': !dragging,
        }"
        :style="buttonStyle"
        aria-label="Open AI assistant"
        title="Open AI assistant"
        @click="openAssistant"
        @pointerdown="handlePointerDown"
        @pointermove="handlePointerMove"
        @pointerup="handlePointerUp"
        @pointercancel="handlePointerUp"
    >
        <Sparkles
            class="absolute top-2 right-2 size-3.5 text-red-400 dark:text-red-500"
        />
        <BotMessageSquare class="size-7" />
        <GripHorizontal
            class="absolute bottom-1.5 left-1/2 size-4 -translate-x-1/2 opacity-55"
        />
    </button>
</template>
