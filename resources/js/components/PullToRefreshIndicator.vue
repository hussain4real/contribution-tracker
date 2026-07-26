<script setup lang="ts">
import { LoaderCircle, RotateCw } from '@lucide/vue';

defineProps<{
    distance: number;
    pulling: boolean;
    refreshing: boolean;
}>();
</script>

<template>
    <div
        class="pointer-events-none fixed inset-x-0 top-[calc(env(safe-area-inset-top)+0.75rem)] z-[60] flex justify-center md:hidden"
        :style="{ transform: `translateY(${Math.max(distance - 48, 0)}px)` }"
        aria-hidden="true"
    >
        <Transition
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="-translate-y-3 opacity-0"
            enter-to-class="translate-y-0 opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="translate-y-0 opacity-100"
            leave-to-class="-translate-y-3 opacity-0"
        >
            <div
                v-if="pulling || refreshing"
                class="flex size-9 items-center justify-center rounded-full border bg-background shadow-sm"
            >
                <LoaderCircle v-if="refreshing" class="size-4 animate-spin" />
                <RotateCw
                    v-else
                    class="size-4 transition-transform"
                    :style="{
                        transform: `rotate(${Math.min(distance * 3, 180)}deg)`,
                    }"
                />
            </div>
        </Transition>
    </div>
</template>
