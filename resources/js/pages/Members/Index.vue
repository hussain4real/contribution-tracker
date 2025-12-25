<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { type BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { index, create, show, destroy, restore } from '@/actions/App/Http/Controllers/MemberController';
import { Archive, Eye, Pencil, Plus, RotateCcw, Users } from 'lucide-vue-next';
import { ref, computed } from 'vue';

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
    archived_at?: string;
}

interface Props {
    members: Member[];
    archivedMembers: Member[];
    canManageMembers: boolean;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Members',
        href: index().url,
    },
];

const showArchived = ref(false);

const displayedMembers = computed(() => {
    return showArchived.value ? props.archivedMembers : props.members;
});

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

function archiveMember(member: Member) {
    if (confirm(`Are you sure you want to archive ${member.name}?`)) {
        router.delete(destroy(member.id).url);
    }
}

function restoreMember(member: Member) {
    if (confirm(`Are you sure you want to restore ${member.name}?`)) {
        router.post(restore(member.id).url);
    }
}
</script>

<template>
    <Head title="Members" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <Users class="h-6 w-6 text-neutral-500" />
                    <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">
                        Family Members
                    </h1>
                </div>
                <div class="flex items-center gap-3">
                    <Button
                        variant="outline"
                        size="sm"
                        @click="showArchived = !showArchived"
                    >
                        <Archive class="mr-2 h-4 w-4" />
                        {{ showArchived ? 'Show Active' : 'Show Archived' }}
                    </Button>
                    <Link
                        v-if="canManageMembers"
                        :href="create().url"
                    >
                        <Button>
                            <Plus class="mr-2 h-4 w-4" />
                            Add Member
                        </Button>
                    </Link>
                </div>
            </div>

            <!-- Members Table -->
            <div class="rounded-xl border border-sidebar-border/70 bg-white dark:border-sidebar-border dark:bg-neutral-900">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-neutral-200 dark:border-neutral-700">
                                <th class="px-6 py-4 font-medium text-neutral-600 dark:text-neutral-400">
                                    Name
                                </th>
                                <th class="px-6 py-4 font-medium text-neutral-600 dark:text-neutral-400">
                                    Email
                                </th>
                                <th class="px-6 py-4 font-medium text-neutral-600 dark:text-neutral-400">
                                    Role
                                </th>
                                <th class="px-6 py-4 font-medium text-neutral-600 dark:text-neutral-400">
                                    Category
                                </th>
                                <th class="px-6 py-4 text-right font-medium text-neutral-600 dark:text-neutral-400">
                                    Monthly Amount
                                </th>
                                <th class="px-6 py-4 text-right font-medium text-neutral-600 dark:text-neutral-400">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="member in displayedMembers"
                                :key="member.id"
                                class="border-b border-neutral-100 dark:border-neutral-800 hover:bg-neutral-50 dark:hover:bg-neutral-800/50"
                            >
                                <td class="px-6 py-4">
                                    <div class="font-medium text-neutral-900 dark:text-neutral-100">
                                        {{ member.name }}
                                    </div>
                                    <div v-if="member.archived_at" class="text-xs text-red-500 dark:text-red-400">
                                        Archived {{ member.archived_at }}
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-neutral-600 dark:text-neutral-400">
                                    {{ member.email }}
                                </td>
                                <td class="px-6 py-4">
                                    <Badge :variant="getRoleBadgeVariant(member.role)">
                                        {{ member.role_label }}
                                    </Badge>
                                </td>
                                <td class="px-6 py-4 text-neutral-600 dark:text-neutral-400">
                                    {{ member.category_label ?? '—' }}
                                </td>
                                <td class="px-6 py-4 text-right font-medium text-neutral-900 dark:text-neutral-100">
                                    {{ formatCurrency(member.monthly_amount) }}
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center justify-end gap-2">
                                        <Link :href="show(member.id).url">
                                            <Button variant="ghost" size="icon" title="View">
                                                <Eye class="h-4 w-4" />
                                            </Button>
                                        </Link>
                                        <template v-if="canManageMembers">
                                            <template v-if="!member.is_archived">
                                                <Link :href="`/members/${member.id}/edit`">
                                                    <Button variant="ghost" size="icon" title="Edit">
                                                        <Pencil class="h-4 w-4" />
                                                    </Button>
                                                </Link>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    title="Archive"
                                                    @click="archiveMember(member)"
                                                >
                                                    <Archive class="h-4 w-4 text-amber-600" />
                                                </Button>
                                            </template>
                                            <template v-else>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    title="Restore"
                                                    @click="restoreMember(member)"
                                                >
                                                    <RotateCcw class="h-4 w-4 text-green-600" />
                                                </Button>
                                            </template>
                                        </template>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="displayedMembers.length === 0">
                                <td colspan="6" class="px-6 py-8 text-center text-neutral-500 dark:text-neutral-400">
                                    {{ showArchived ? 'No archived members' : 'No active members' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
