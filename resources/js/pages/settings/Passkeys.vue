<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useWebAuthn } from '@/composables/useWebAuthn';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { show } from '@/routes/passkeys';
import { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { Fingerprint, KeyRound, Trash2 } from 'lucide-vue-next';
import { onMounted, ref } from 'vue';

interface PasskeyData {
    id: number;
    name: string;
    attachment_type: string | null;
    last_used_at: string | null;
    created_at: string;
}

interface Props {
    passkeys?: PasskeyData[];
}

const props = withDefaults(defineProps<Props>(), {
    passkeys: () => [],
});

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Passkeys',
        href: show.url(),
    },
];

const { isSupported, isProcessing, error, clearError, registerPasskey, deletePasskey } =
    useWebAuthn();

const isMounted = ref(false);

onMounted(() => {
    isMounted.value = true;
});

const showRegisterDialog = ref<boolean>(false);
const showDeleteDialog = ref<boolean>(false);
const passkeyName = ref<string>('');
const passkeyToDelete = ref<PasskeyData | null>(null);

const openRegisterDialog = (): void => {
    passkeyName.value = '';
    clearError();
    showRegisterDialog.value = true;
};

const handleRegister = async (): Promise<void> => {
    if (!passkeyName.value.trim()) {
        return;
    }

    const result = await registerPasskey(passkeyName.value.trim());

    if (result) {
        showRegisterDialog.value = false;
        router.reload({ only: ['passkeys'] });
    }
};

const confirmDelete = (passkey: PasskeyData): void => {
    passkeyToDelete.value = passkey;
    clearError();
    showDeleteDialog.value = true;
};

const handleDelete = async (): Promise<void> => {
    if (!passkeyToDelete.value) {
        return;
    }

    const success = await deletePasskey(passkeyToDelete.value.id);

    if (success) {
        showDeleteDialog.value = false;
        passkeyToDelete.value = null;
        router.reload({ only: ['passkeys'] });
    }
};

const formatDate = (dateString: string | null): string => {
    if (!dateString) {
        return 'Never';
    }

    return new Date(dateString).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Passkeys" />
        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall
                    title="Passkeys"
                    description="Manage your passkeys for passwordless sign-in and biometric authentication"
                />

                <div
                    v-if="isMounted && !isSupported"
                    class="rounded-lg border border-yellow-200 bg-yellow-50 p-4 text-sm text-yellow-800 dark:border-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-200"
                >
                    Your browser does not support passkeys. Please use a
                    modern browser like Chrome, Safari, Firefox, or Edge.
                </div>

                <template v-else>
                    <div class="flex items-center justify-between">
                        <p class="text-sm text-muted-foreground">
                            Passkeys let you sign in using your fingerprint,
                            face, or screen lock. They are more secure than
                            passwords and can also be used as a second factor.
                        </p>
                    </div>

                    <div>
                        <Button @click="openRegisterDialog" :disabled="isProcessing">
                            <Fingerprint class="h-4 w-4" />
                            Register new passkey
                        </Button>
                    </div>

                    <div
                        v-if="props.passkeys.length === 0"
                        class="rounded-lg border border-dashed p-8 text-center"
                    >
                        <KeyRound
                            class="mx-auto h-10 w-10 text-muted-foreground"
                        />
                        <p class="mt-2 text-sm text-muted-foreground">
                            No passkeys registered yet. Register one to enable
                            passwordless sign-in.
                        </p>
                    </div>

                    <div v-else class="space-y-3">
                        <div
                            v-for="passkey in props.passkeys"
                            :key="passkey.id"
                            class="flex items-center justify-between rounded-lg border p-4"
                        >
                            <div class="flex items-center gap-4">
                                <div
                                    class="flex h-10 w-10 items-center justify-center rounded-full bg-muted"
                                >
                                    <Fingerprint
                                        class="h-5 w-5 text-muted-foreground"
                                    />
                                </div>
                                <div>
                                    <p class="font-medium">
                                        {{ passkey.name }}
                                    </p>
                                    <div
                                        class="flex gap-3 text-xs text-muted-foreground"
                                    >
                                        <span
                                            >Added
                                            {{
                                                formatDate(passkey.created_at)
                                            }}</span
                                        >
                                        <span
                                            >Last used:
                                            {{
                                                formatDate(
                                                    passkey.last_used_at,
                                                )
                                            }}</span
                                        >
                                    </div>
                                </div>
                            </div>
                            <Button
                                variant="ghost"
                                size="icon"
                                @click="confirmDelete(passkey)"
                                :disabled="isProcessing"
                            >
                                <Trash2 class="h-4 w-4 text-destructive" />
                            </Button>
                        </div>
                    </div>
                </template>
            </div>

            <!-- Register Passkey Dialog -->
            <Dialog v-model:open="showRegisterDialog">
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Register a new passkey</DialogTitle>
                        <DialogDescription>
                            Give your passkey a name to identify it later, then
                            follow your browser's prompts to create it.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="space-y-4 py-4">
                        <div class="space-y-2">
                            <Label for="passkey-name">Passkey name</Label>
                            <Input
                                id="passkey-name"
                                v-model="passkeyName"
                                placeholder="e.g. MacBook Pro, iPhone"
                                @keydown.enter="handleRegister"
                            />
                        </div>

                        <div
                            v-if="error"
                            class="rounded-md bg-destructive/10 p-3 text-sm text-destructive"
                        >
                            {{ error }}
                        </div>
                    </div>

                    <DialogFooter>
                        <Button
                            variant="outline"
                            @click="showRegisterDialog = false"
                            :disabled="isProcessing"
                        >
                            Cancel
                        </Button>
                        <Button
                            @click="handleRegister"
                            :disabled="isProcessing || !passkeyName.trim()"
                        >
                            <Spinner v-if="isProcessing" />
                            <Fingerprint v-else class="h-4 w-4" />
                            Register
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <!-- Delete Passkey Dialog -->
            <Dialog v-model:open="showDeleteDialog">
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Remove passkey</DialogTitle>
                        <DialogDescription>
                            Are you sure you want to remove
                            <strong>{{
                                passkeyToDelete?.name
                            }}</strong>? You will no longer be able to use it to
                            sign in.
                        </DialogDescription>
                    </DialogHeader>

                    <div
                        v-if="error"
                        class="rounded-md bg-destructive/10 p-3 text-sm text-destructive"
                    >
                        {{ error }}
                    </div>

                    <DialogFooter>
                        <Button
                            variant="outline"
                            @click="showDeleteDialog = false"
                            :disabled="isProcessing"
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            @click="handleDelete"
                            :disabled="isProcessing"
                        >
                            <Spinner v-if="isProcessing" />
                            Remove passkey
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </SettingsLayout>
    </AppLayout>
</template>
