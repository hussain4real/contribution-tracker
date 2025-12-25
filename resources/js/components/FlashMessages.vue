<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { CheckCircle, XCircle, AlertTriangle, X } from 'lucide-vue-next';

interface Flash {
    success?: string | null;
    error?: string | null;
    warning?: string | null;
}

const page = usePage<{ flash: Flash }>();

const flash = computed(() => page.props.flash);
const showSuccess = ref(false);
const showError = ref(false);
const showWarning = ref(false);

// Watch for flash changes and show/auto-hide
watch(
    () => flash.value?.success,
    (val) => {
        if (val) {
            showSuccess.value = true;
            setTimeout(() => {
                showSuccess.value = false;
            }, 5000);
        }
    },
    { immediate: true }
);

watch(
    () => flash.value?.error,
    (val) => {
        if (val) {
            showError.value = true;
            setTimeout(() => {
                showError.value = false;
            }, 8000);
        }
    },
    { immediate: true }
);

watch(
    () => flash.value?.warning,
    (val) => {
        if (val) {
            showWarning.value = true;
            setTimeout(() => {
                showWarning.value = false;
            }, 6000);
        }
    },
    { immediate: true }
);

function dismissSuccess() {
    showSuccess.value = false;
}

function dismissError() {
    showError.value = false;
}

function dismissWarning() {
    showWarning.value = false;
}
</script>

<template>
    <div class="fixed top-4 right-4 z-50 flex flex-col gap-2 max-w-md">
        <!-- Success Message -->
        <Transition
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="opacity-0 translate-x-4"
            enter-to-class="opacity-100 translate-x-0"
            leave-active-class="transition duration-200 ease-in"
            leave-from-class="opacity-100 translate-x-0"
            leave-to-class="opacity-0 translate-x-4"
        >
            <div
                v-if="showSuccess && flash?.success"
                class="flex items-start gap-3 rounded-lg border border-green-200 bg-green-50 p-4 shadow-lg dark:border-green-800 dark:bg-green-900/50"
            >
                <CheckCircle class="h-5 w-5 flex-shrink-0 text-green-600 dark:text-green-400" />
                <p class="flex-1 text-sm font-medium text-green-800 dark:text-green-200">
                    {{ flash.success }}
                </p>
                <button
                    @click="dismissSuccess"
                    class="flex-shrink-0 text-green-600 hover:text-green-800 dark:text-green-400 dark:hover:text-green-200"
                >
                    <X class="h-4 w-4" />
                </button>
            </div>
        </Transition>

        <!-- Error Message -->
        <Transition
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="opacity-0 translate-x-4"
            enter-to-class="opacity-100 translate-x-0"
            leave-active-class="transition duration-200 ease-in"
            leave-from-class="opacity-100 translate-x-0"
            leave-to-class="opacity-0 translate-x-4"
        >
            <div
                v-if="showError && flash?.error"
                class="flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 p-4 shadow-lg dark:border-red-800 dark:bg-red-900/50"
            >
                <XCircle class="h-5 w-5 flex-shrink-0 text-red-600 dark:text-red-400" />
                <p class="flex-1 text-sm font-medium text-red-800 dark:text-red-200">
                    {{ flash.error }}
                </p>
                <button
                    @click="dismissError"
                    class="flex-shrink-0 text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-200"
                >
                    <X class="h-4 w-4" />
                </button>
            </div>
        </Transition>

        <!-- Warning Message -->
        <Transition
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="opacity-0 translate-x-4"
            enter-to-class="opacity-100 translate-x-0"
            leave-active-class="transition duration-200 ease-in"
            leave-from-class="opacity-100 translate-x-0"
            leave-to-class="opacity-0 translate-x-4"
        >
            <div
                v-if="showWarning && flash?.warning"
                class="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 shadow-lg dark:border-amber-800 dark:bg-amber-900/50"
            >
                <AlertTriangle class="h-5 w-5 flex-shrink-0 text-amber-600 dark:text-amber-400" />
                <p class="flex-1 text-sm font-medium text-amber-800 dark:text-amber-200">
                    {{ flash.warning }}
                </p>
                <button
                    @click="dismissWarning"
                    class="flex-shrink-0 text-amber-600 hover:text-amber-800 dark:text-amber-400 dark:hover:text-amber-200"
                >
                    <X class="h-4 w-4" />
                </button>
            </div>
        </Transition>
    </div>
</template>
