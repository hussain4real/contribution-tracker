<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { index, store } from '@/actions/App/Http/Controllers/ExpenseController';
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Expenses',
        href: index().url,
    },
    {
        title: 'Record Expense',
        href: '#',
    },
];

const amount = ref<string>('');
const description = ref<string>('');
const spentAt = ref<string>(new Date().toISOString().split('T')[0]);
</script>

<template>
    <Head title="Record Expense" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-2xl space-y-6 p-4">
            <HeadingSmall
                title="Record Expense"
                description="Record an expense from the family fund."
            />

            <Form
                :action="store()"
                class="space-y-6"
                #default="{ errors, validate, processing, recentlySuccessful }"
            >
                <!-- Amount Field -->
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

                <!-- Description Field -->
                <div class="grid gap-2">
                    <Label for="description">Description</Label>
                    <Input
                        id="description"
                        type="text"
                        name="description"
                        v-model="description"
                        placeholder="e.g., Transport to meeting, Phone calls"
                        required
                        maxlength="1000"
                        @change="validate('description')"
                    />
                    <InputError :message="errors.description" />
                </div>

                <!-- Date Field -->
                <div class="grid gap-2">
                    <Label for="spent_at">Date</Label>
                    <Input
                        id="spent_at"
                        type="date"
                        name="spent_at"
                        v-model="spentAt"
                        required
                    />
                    <InputError :message="errors.spent_at" />
                </div>

                <!-- Submit Button -->
                <div class="flex items-center gap-4">
                    <Button type="submit" :disabled="processing">
                        {{ processing ? 'Recording...' : 'Record Expense' }}
                    </Button>

                    <Transition
                        enter-active-class="transition ease-in-out"
                        enter-from-class="opacity-0"
                        leave-active-class="transition ease-in-out"
                        leave-to-class="opacity-0"
                    >
                        <p
                            v-show="recentlySuccessful"
                            class="text-sm text-green-600"
                        >
                            Expense recorded successfully.
                        </p>
                    </Transition>
                </div>
            </Form>
        </div>
    </AppLayout>
</template>
