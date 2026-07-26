<script setup lang="ts">
import { useNavigationProgress } from '@/composables/useNavigationProgress';

const { isNavigating } = useNavigationProgress();
</script>

<template>
    <Transition
        enter-active-class="transition duration-150 ease-out"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition duration-200 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
    >
        <div
            v-if="isNavigating"
            class="fixed inset-x-0 top-0 z-[70] h-0.5 overflow-hidden bg-primary/10"
            role="status"
            aria-label="Loading"
        >
            <div class="animate-app-progress h-full w-1/2 bg-primary" />
        </div>
    </Transition>
</template>
