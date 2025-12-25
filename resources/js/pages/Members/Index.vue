<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { type BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import MemberListItem from '@/components/contributions/MemberListItem.vue';
import { index, create } from '@/actions/App/Http/Controllers/MemberController';
import { Archive, Plus, Users } from 'lucide-vue-next';
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
                            <MemberListItem
                                v-for="member in displayedMembers"
                                :key="member.id"
                                :member="member"
                                :can-manage-members="canManageMembers"
                            />
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
