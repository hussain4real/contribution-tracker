<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { type BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import { index } from '@/actions/App/Http/Controllers/PaymentController';
import { create as createPayment } from '@/actions/App/Http/Controllers/PaymentController';
import { index as membersIndex } from '@/actions/App/Http/Controllers/MemberController';
import { CreditCard, Users, ChevronRight } from 'lucide-vue-next';
import { ref, computed, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';

interface Member {
    id: number;
    name: string;
    email: string;
    category: string | null;
    category_label: string | null;
    monthly_amount: number;
}

interface Props {
    members?: Member[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Payments',
        href: index().url,
    },
];

const searchQuery = ref('');

const filteredMembers = computed(() => {
    if (!props.members) return [];
    if (!searchQuery.value) return props.members;

    const query = searchQuery.value.toLowerCase();
    return props.members.filter(member =>
        member.name.toLowerCase().includes(query) ||
        member.email.toLowerCase().includes(query)
    );
});

function formatCurrency(amount: number): string {
    return new Intl.NumberFormat('en-NG', {
        style: 'currency',
        currency: 'NGN',
    }).format(amount);
}
</script>

<template>
    <Head title="Record Payment" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
            <!-- Header -->
            <div class="flex items-center gap-3">
                <CreditCard class="h-6 w-6 text-neutral-500" />
                <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">
                    Record Payment
                </h1>
            </div>

            <!-- Instructions -->
            <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-900/20">
                <p class="text-sm text-blue-800 dark:text-blue-200">
                    Select a member below to record a payment for them.
                </p>
            </div>

            <!-- Search -->
            <div class="relative">
                <input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Search members by name or email..."
                    class="w-full rounded-lg border border-neutral-300 bg-white px-4 py-3 text-sm placeholder:text-neutral-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-neutral-600 dark:bg-neutral-800 dark:placeholder:text-neutral-500"
                />
            </div>

            <!-- Members List -->
            <div class="rounded-xl border border-sidebar-border/70 bg-white dark:border-sidebar-border dark:bg-neutral-900">
                <div class="border-b border-neutral-200 px-6 py-4 dark:border-neutral-700">
                    <div class="flex items-center gap-2">
                        <Users class="h-5 w-5 text-neutral-500" />
                        <h2 class="text-lg font-medium text-neutral-900 dark:text-neutral-100">
                            Select Member
                        </h2>
                    </div>
                </div>

                <div v-if="!members || members.length === 0" class="p-6 text-center">
                    <p class="text-neutral-600 dark:text-neutral-400">
                        No members found. <Link :href="membersIndex().url" class="text-blue-600 hover:underline dark:text-blue-400">Add members first</Link>.
                    </p>
                </div>

                <div v-else-if="filteredMembers.length === 0" class="p-6 text-center">
                    <p class="text-neutral-600 dark:text-neutral-400">
                        No members match your search.
                    </p>
                </div>

                <div v-else class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <Link
                        v-for="member in filteredMembers"
                        :key="member.id"
                        :href="createPayment(member.id).url"
                        class="flex items-center justify-between px-6 py-4 transition hover:bg-neutral-50 dark:hover:bg-neutral-800"
                    >
                        <div class="flex items-center gap-4">
                            <div class="flex h-10 w-10 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-900/30">
                                <span class="text-sm font-medium text-blue-600 dark:text-blue-400">
                                    {{ member.name.charAt(0).toUpperCase() }}
                                </span>
                            </div>
                            <div>
                                <p class="font-medium text-neutral-900 dark:text-neutral-100">
                                    {{ member.name }}
                                </p>
                                <p class="text-sm text-neutral-500 dark:text-neutral-400">
                                    {{ member.email }}
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-4">
                            <div class="text-right">
                                <p class="text-sm text-neutral-500 dark:text-neutral-400">
                                    {{ member.category_label || 'No category' }}
                                </p>
                                <p class="font-medium text-neutral-900 dark:text-neutral-100">
                                    {{ formatCurrency(member.monthly_amount) }}/month
                                </p>
                            </div>
                            <ChevronRight class="h-5 w-5 text-neutral-400" />
                        </div>
                    </Link>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
