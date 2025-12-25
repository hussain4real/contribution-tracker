<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { type BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { index, show, destroy, restore } from '@/actions/App/Http/Controllers/MemberController';
import { Archive, Pencil, RotateCcw, User } from 'lucide-vue-next';

interface Member {
    id: number;
    name: string;
    email: string;
    role: string;
    role_label: string;
    category: string | null;
    category_label: string | null;
    monthly_amount: number;
    is_archived: boolean;
    archived_at: string | null;
    created_at: string | null;
}

interface Props {
    member: Member;
    canManageMembers: boolean;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Members',
        href: index().url,
    },
    {
        title: props.member.name,
        href: show(props.member.id).url,
    },
];

function formatCurrency(kobo: number): string {
    const naira = kobo / 100;
    return new Intl.NumberFormat('en-NG', {
        style: 'currency',
        currency: 'NGN',
    }).format(naira);
}

function getRoleBadgeVariant(role: string) {
    switch (role) {
        case 'super_admin':
            return 'default';
        case 'financial_secretary':
            return 'secondary';
        default:
            return 'outline';
    }
}

function archiveMember() {
    if (confirm(`Are you sure you want to archive ${props.member.name}?`)) {
        router.delete(destroy(props.member.id).url);
    }
}

function restoreMember() {
    if (confirm(`Are you sure you want to restore ${props.member.name}?`)) {
        router.post(restore(props.member.id).url);
    }
}
</script>

<template>
    <Head :title="member.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
            <div class="mx-auto w-full max-w-2xl">
                <div class="rounded-xl border border-sidebar-border/70 bg-white p-6 dark:border-sidebar-border dark:bg-neutral-900">
                    <!-- Header -->
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-4">
                            <div class="flex h-16 w-16 items-center justify-center rounded-full bg-neutral-100 dark:bg-neutral-800">
                                <User class="h-8 w-8 text-neutral-500" />
                            </div>
                            <div>
                                <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">
                                    {{ member.name }}
                                </h1>
                                <p class="text-neutral-600 dark:text-neutral-400">
                                    {{ member.email }}
                                </p>
                            </div>
                        </div>
                        <div v-if="canManageMembers" class="flex items-center gap-2">
                            <template v-if="!member.is_archived">
                                <Link :href="`/members/${member.id}/edit`">
                                    <Button variant="outline" size="sm">
                                        <Pencil class="mr-2 h-4 w-4" />
                                        Edit
                                    </Button>
                                </Link>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="archiveMember"
                                >
                                    <Archive class="mr-2 h-4 w-4 text-amber-600" />
                                    Archive
                                </Button>
                            </template>
                            <template v-else>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="restoreMember"
                                >
                                    <RotateCcw class="mr-2 h-4 w-4 text-green-600" />
                                    Restore
                                </Button>
                            </template>
                        </div>
                    </div>

                    <!-- Archived Warning -->
                    <div
                        v-if="member.is_archived"
                        class="mt-4 rounded-lg bg-red-50 p-4 dark:bg-red-900/20"
                    >
                        <p class="text-sm font-medium text-red-800 dark:text-red-200">
                            This member is archived
                        </p>
                        <p class="mt-1 text-sm text-red-600 dark:text-red-400">
                            Archived on {{ member.archived_at }}
                        </p>
                    </div>

                    <!-- Details -->
                    <div class="mt-6 space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <p class="text-sm text-neutral-500 dark:text-neutral-400">Role</p>
                                <div class="mt-1">
                                    <Badge :variant="getRoleBadgeVariant(member.role)">
                                        {{ member.role_label }}
                                    </Badge>
                                </div>
                            </div>
                            <div>
                                <p class="text-sm text-neutral-500 dark:text-neutral-400">Category</p>
                                <p class="mt-1 font-medium text-neutral-900 dark:text-neutral-100">
                                    {{ member.category_label ?? '—' }}
                                </p>
                            </div>
                            <div>
                                <p class="text-sm text-neutral-500 dark:text-neutral-400">Monthly Amount</p>
                                <p class="mt-1 text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                                    {{ formatCurrency(member.monthly_amount) }}
                                </p>
                            </div>
                            <div>
                                <p class="text-sm text-neutral-500 dark:text-neutral-400">Member Since</p>
                                <p class="mt-1 font-medium text-neutral-900 dark:text-neutral-100">
                                    {{ member.created_at ?? '—' }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Contribution History Placeholder -->
                    <div class="mt-8 border-t border-neutral-200 pt-6 dark:border-neutral-700">
                        <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                            Contribution History
                        </h2>
                        <p class="mt-2 text-sm text-neutral-500 dark:text-neutral-400">
                            Contribution history will be displayed here once payments are recorded.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
