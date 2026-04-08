<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { usePwaInstall } from '@/composables/usePwaInstall';
import { Download } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

const { isInstallable, isInstalled, dismissed, init, install, dismiss } =
    usePwaInstall();

const open = ref(false);
const installing = ref(false);

onMounted(() => {
    init();
});

const shouldShow = computed(
    () => isInstallable.value && !isInstalled.value && !dismissed.value,
);

// Show the dialog 3 seconds after the app becomes installable
let showTimer: ReturnType<typeof setTimeout> | undefined;

watch(
    shouldShow,
    (canShow) => {
        clearTimeout(showTimer);
        if (canShow) {
            showTimer = setTimeout(() => {
                if (shouldShow.value) {
                    open.value = true;
                }
            }, 3000);
        }
    },
    { immediate: true },
);

onUnmounted(() => clearTimeout(showTimer));

async function handleInstall(): Promise<void> {
    installing.value = true;
    try {
        await install();
    } finally {
        installing.value = false;
        open.value = false;
    }
}

function handleDismiss(): void {
    dismiss();
    open.value = false;
}
</script>

<template>
    <Dialog
        v-model:open="open"
        @update:open="
            (val: boolean) => {
                if (!val && !dismissed) handleDismiss();
            }
        "
    >
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <div class="flex items-center gap-3">
                    <div
                        class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-primary/10"
                    >
                        <AppLogoIcon class="size-8 fill-current text-primary" />
                    </div>
                    <div>
                        <DialogTitle>Install FamilyFunds</DialogTitle>
                        <DialogDescription>
                            Get a faster, app-like experience
                        </DialogDescription>
                    </div>
                </div>
            </DialogHeader>

            <div class="space-y-3 py-2">
                <div class="flex items-start gap-3">
                    <div
                        class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400"
                    >
                        ✓
                    </div>
                    <p class="text-sm text-muted-foreground">
                        Launch instantly from your home screen
                    </p>
                </div>
                <div class="flex items-start gap-3">
                    <div
                        class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400"
                    >
                        ✓
                    </div>
                    <p class="text-sm text-muted-foreground">
                        Works offline with cached pages
                    </p>
                </div>
                <div class="flex items-start gap-3">
                    <div
                        class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400"
                    >
                        ✓
                    </div>
                    <p class="text-sm text-muted-foreground">
                        Full-screen experience without browser bars
                    </p>
                </div>
            </div>

            <DialogFooter class="gap-2 sm:gap-6">
                <Button variant="ghost" @click="handleDismiss">
                    Not now
                </Button>
                <Button @click="handleInstall" :disabled="installing">
                    <Download class="mr-2 h-4 w-4" />
                    {{ installing ? 'Installing...' : 'Install' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
