<script setup lang="ts">
import {
    index,
    store,
} from '@/actions/App/Http/Controllers/FundAdjustmentController';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Form, Head, Link, router } from '@inertiajs/vue3';
import { Landmark, Plus, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';

interface AdjustmentItem {
    id: number;
    amount: number;
    description: string;
    recorded_at: string;
    recorded_by: string | null;
    created_at: string;
}

interface PaginatedAdjustments {
    data: AdjustmentItem[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
}

interface Props {
    adjustments?: PaginatedAdjustments;
    can_create?: boolean;
}

defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Fund Adjustments',
        href: index().url,
    },
];

const showForm = ref(false);
const amount = ref<string>('');
const description = ref<string>('');
const recordedAt = ref<string>(new Date().toISOString().split('T')[0]);

function formatCurrency(value: number): string {
    return new Intl.NumberFormat('en-NG', {
        style: 'currency',
        currency: 'NGN',
    }).format(value);
}

function formatDate(date: string): string {
    return new Date(date).toLocaleDateString('en-NG', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

function deleteAdjustment(id: number): void {
    if (confirm('Are you sure you want to delete this fund adjustment?')) {
        router.delete(route('fund-adjustments.destroy', id));
    }
}

function resetForm(): void {
    amount.value = '';
    description.value = '';
    recordedAt.value = new Date().toISOString().split('T')[0];
    showForm.value = false;
}
</script>

<template>
    <Head title="Fund Adjustments" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
            <!-- Header -->
            <div
                class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="flex items-center gap-3">
                    <Landmark class="h-6 w-6 text-neutral-500" />
                    <h1
                        class="text-xl font-semibold text-neutral-900 sm:text-2xl dark:text-neutral-100"
                    >
                        Fund Adjustments
                    </h1>
                </div>
                <Button
                    v-if="can_create && !showForm"
                    size="sm"
                    @click="showForm = true"
                >
                    <Plus class="mr-2 h-4 w-4" />
                    Record Adjustment
                </Button>
            </div>

            <!-- Info -->
            <div
                class="rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-900/20"
            >
                <p class="text-sm text-blue-800 dark:text-blue-200">
                    Fund adjustments represent lump sums added to the family
                    fund (e.g., opening balance from previous contributions).
                </p>
            </div>

            <!-- Inline Form (Super Admin only) -->
            <div
                v-if="showForm && can_create"
                class="rounded-xl border border-sidebar-border/70 bg-white p-6 dark:border-sidebar-border dark:bg-neutral-900"
            >
                <HeadingSmall
                    title="Record Fund Adjustment"
                    description="Add a lump sum to the family fund balance."
                />

                <Form
                    :action="store()"
                    class="mt-4 space-y-4"
                    #default="{ errors, validate, processing }"
                    @success="resetForm"
                >
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="amount">Amount (₦)</Label>
                            <Input
                                id="amount"
                                type="number"
                                name="amount"
                                v-model="amount"
                                placeholder="Enter amount in Naira"
                                required
                                min="1"
                                step="1"
                                @change="validate('amount')"
                            />
                            <InputError :message="errors.amount" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="recorded_at">Date</Label>
                            <Input
                                id="recorded_at"
                                type="date"
                                name="recorded_at"
                                v-model="recordedAt"
                                required
                            />
                            <InputError :message="errors.recorded_at" />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="description">Description</Label>
                        <Input
                            id="description"
                            type="text"
                            name="description"
                            v-model="description"
                            placeholder="e.g., Opening balance from 2+ years of contributions"
                            required
                            maxlength="1000"
                            @change="validate('description')"
                        />
                        <InputError :message="errors.description" />
                    </div>

                    <div class="flex items-center gap-4">
                        <Button type="submit" :disabled="processing">
                            {{
                                processing
                                    ? 'Recording...'
                                    : 'Record Adjustment'
                            }}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            @click="showForm = false"
                        >
                            Cancel
                        </Button>
                    </div>
                </Form>
            </div>

            <!-- Adjustments Table -->
            <div
                class="rounded-xl border border-sidebar-border/70 bg-white dark:border-sidebar-border dark:bg-neutral-900"
            >
                <div v-if="!adjustments?.data?.length" class="p-6 text-center">
                    <p class="text-neutral-600 dark:text-neutral-400">
                        No fund adjustments recorded yet.
                    </p>
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr
                                class="border-b border-neutral-200 dark:border-neutral-700"
                            >
                                <th
                                    class="px-4 py-3 font-medium text-neutral-600 sm:px-6 dark:text-neutral-400"
                                >
                                    Date
                                </th>
                                <th
                                    class="px-4 py-3 font-medium text-neutral-600 sm:px-6 dark:text-neutral-400"
                                >
                                    Description
                                </th>
                                <th
                                    class="px-4 py-3 text-right font-medium text-neutral-600 sm:px-6 dark:text-neutral-400"
                                >
                                    Amount
                                </th>
                                <th
                                    class="hidden px-6 py-3 font-medium text-neutral-600 md:table-cell dark:text-neutral-400"
                                >
                                    Recorded By
                                </th>
                                <th
                                    class="px-4 py-3 font-medium text-neutral-600 sm:px-6 dark:text-neutral-400"
                                ></th>
                            </tr>
                        </thead>
                        <tbody
                            class="divide-y divide-neutral-100 dark:divide-neutral-800"
                        >
                            <tr
                                v-for="adjustment in adjustments.data"
                                :key="adjustment.id"
                                class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50"
                            >
                                <td
                                    class="px-4 py-4 text-neutral-900 sm:px-6 dark:text-neutral-100"
                                >
                                    {{ formatDate(adjustment.recorded_at) }}
                                </td>
                                <td
                                    class="max-w-[120px] truncate px-4 py-4 text-neutral-700 sm:max-w-xs sm:px-6 dark:text-neutral-300"
                                >
                                    {{ adjustment.description }}
                                </td>
                                <td
                                    class="px-4 py-4 text-right font-medium text-green-600 sm:px-6 dark:text-green-400"
                                >
                                    +{{ formatCurrency(adjustment.amount) }}
                                </td>
                                <td
                                    class="hidden px-6 py-4 text-neutral-500 md:table-cell dark:text-neutral-400"
                                >
                                    {{ adjustment.recorded_by ?? 'System' }}
                                </td>
                                <td class="px-4 py-4 text-right sm:px-6">
                                    <Button
                                        v-if="can_create"
                                        variant="ghost"
                                        size="sm"
                                        @click="deleteAdjustment(adjustment.id)"
                                        class="text-red-500 hover:text-red-700"
                                    >
                                        <Trash2 class="h-4 w-4" />
                                    </Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div
                    v-if="adjustments && adjustments.last_page > 1"
                    class="flex items-center justify-center gap-2 border-t border-neutral-200 px-6 py-4 dark:border-neutral-700"
                >
                    <template
                        v-for="link in adjustments.links"
                        :key="link.label"
                    >
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            class="rounded-md px-3 py-1 text-sm"
                            :class="
                                link.active
                                    ? 'bg-primary text-primary-foreground'
                                    : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-400 dark:hover:bg-neutral-800'
                            "
                        >
                            <span v-html="link.label" />
                        </Link>
                        <span
                            v-else
                            class="px-3 py-1 text-sm text-neutral-400 dark:text-neutral-600"
                            v-html="link.label"
                        />
                    </template>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
