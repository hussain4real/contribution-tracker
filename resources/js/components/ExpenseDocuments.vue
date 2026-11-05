<script setup lang="ts">
import {
    download,
    store,
} from '@/actions/App/Http/Controllers/ExpenseDocumentController';
import { useFinancialRequestKey } from '@/composables/useFinancialRequestKey';
import { useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<{
    expenseId?: number;
    requestId?: number;
    documents: { id: number; filename: string }[];
    canUpload: boolean;
}>();
const fileInput = ref<HTMLInputElement | null>(null);
const key = useFinancialRequestKey(
    crypto.randomUUID(),
    'document-' +
        (usePage().props.auth.user?.id ?? 'guest') +
        '-' +
        (props.expenseId ?? 'request') +
        '-' +
        (props.requestId ?? ''),
    'document',
);
const form = useForm({
    document: null as File | null,
    expense_id: props.expenseId,
    expense_request_id: props.requestId,
    idempotency_key: '',
});
function upload() {
    if (!key.begin()) return;
    form.idempotency_key = key.requestKey.value;
    form.post(store().url, {
        ...key.options,
        preserveScroll: true,
        onError: () => key.rejected(),
        onSuccess: () => {
            key.renew();
            form.reset();
            if (fileInput.value) fileInput.value.value = '';
        },
    });
}
</script>
<template>
    <section class="mt-3 space-y-2" aria-label="Private supporting documents">
        <ul v-if="documents.length" class="space-y-1">
            <li v-for="document in documents" :key="document.id">
                <a
                    :href="download({ expense_document: document.id }).url"
                    class="inline-flex min-h-11 items-center text-sm underline"
                    >Download {{ document.filename }}</a
                >
            </li>
        </ul>
        <form v-if="canUpload" class="space-y-2" @submit.prevent="upload">
            <label class="block text-sm"
                >Supporting document · PDF, JPG or PNG, up to 10 MB
                <input
                    ref="fileInput"
                    type="file"
                    accept=".pdf,.jpg,.jpeg,.png"
                    class="block max-w-full text-sm"
                    required
                    @change="
                        form.document =
                            ($event.target as HTMLInputElement).files?.[0] ??
                            null
                    "
                />
            </label>
            <p
                v-if="form.errors.document"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ form.errors.document }}
            </p>
            <p
                v-if="form.errors.idempotency_key || key.storageError.value"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ form.errors.idempotency_key || key.storageError.value }}
            </p>
            <p v-if="key.unconfirmed.value" role="status" class="text-sm">
                The upload may have succeeded. Check the listed documents before
                starting another upload.
            </p>
            <p class="text-sm text-muted-foreground">
                Only family officers can download these documents.
            </p>
            <button
                class="min-h-11 rounded-md border px-3"
                :disabled="form.processing || !key.storageReady.value"
            >
                {{ form.processing ? 'Uploading…' : 'Upload document' }}
            </button>
            <button
                v-if="key.unconfirmed.value || form.errors.idempotency_key"
                type="button"
                class="min-h-11 rounded-md border px-3"
                @click="key.startNew()"
            >
                Start another upload
            </button>
        </form>
    </section>
</template>
