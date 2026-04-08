<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { useSwUpdate } from '@/composables/useSwUpdate';
import { RefreshCw, X } from 'lucide-vue-next';
import { onMounted } from 'vue';

const { needRefresh, init, applyUpdate, dismissUpdate } = useSwUpdate();

onMounted(() => {
    init();
});
</script>

<template>
    <Transition
        enter-active-class="transition duration-300 ease-out"
        enter-from-class="translate-y-full opacity-0"
        enter-to-class="translate-y-0 opacity-100"
        leave-active-class="transition duration-200 ease-in"
        leave-from-class="translate-y-0 opacity-100"
        leave-to-class="translate-y-full opacity-0"
    >
        <div
            v-if="needRefresh"
            class="fixed bottom-4 left-1/2 z-50 flex w-[calc(100%-2rem)] max-w-md -translate-x-1/2 items-center gap-3 rounded-lg border bg-background p-4 shadow-lg"
        >
            <div class="flex-1">
                <p class="text-sm font-medium">Update available</p>
                <p class="text-xs text-muted-foreground">
                    A new version is ready. Reload to update.
                </p>
            </div>
            <Button size="sm" @click="applyUpdate">
                <RefreshCw class="mr-1.5 h-3.5 w-3.5" />
                Reload
            </Button>
            <button
                type="button"
                class="text-muted-foreground hover:text-foreground"
                aria-label="Dismiss update notification"
                @click="dismissUpdate"
            >
                <X class="h-4 w-4" aria-hidden="true" />
            </button>
        </div>
    </Transition>
</template>
