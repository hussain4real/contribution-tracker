<script setup lang="ts">
import {
    create,
    index,
    show,
} from '@/actions/App/Http/Controllers/MemberController';
import MemberListItem from '@/components/contributions/MemberListItem.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { useCurrencyFormatter } from '@/lib/currency';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    Archive,
    ArrowUpCircle,
    ChevronRight,
    Plus,
    Users,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

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
    members?: Member[];
    archivedMembers?: Member[];
    canManageMembers?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    members: () => [],
    archivedMembers: () => [],
    canManageMembers: false,
});

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

const page = usePage();
const subscription = computed(() => page.props.subscription);
const canAddMore = computed(() => subscription.value?.can_add_members ?? true);

const { formatCurrency } = useCurrencyFormatter();
</script>

<template>
    <Head title="Members" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
            <!-- Header -->
            <div
                class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="flex items-center gap-3">
                    <Users class="h-6 w-6 text-neutral-500" />
                    <h1
                        class="text-xl font-semibold text-neutral-900 sm:text-2xl dark:text-neutral-100"
                    >
                        Family Members
                    </h1>
                </div>
                <div class="flex items-center gap-2 sm:gap-3">
                    <Button
                        variant="outline"
                        size="sm"
                        @click="showArchived = !showArchived"
                    >
                        <Archive class="mr-1 h-4 w-4 sm:mr-2" />
                        <span class="hidden sm:inline">{{
                            showArchived ? 'Show Active' : 'Show Archived'
                        }}</span>
                        <span class="sm:hidden">{{
                            showArchived ? 'Active' : 'Archived'
                        }}</span>
                    </Button>
                    <Link
                        v-if="canManageMembers && canAddMore"
                        :href="create().url"
                    >
                        <Button size="sm">
                            <Plus class="mr-1 h-4 w-4 sm:mr-2" />
                            Add Member
                        </Button>
                    </Link>
                </div>
            </div>

            <!-- Member limit banner -->
            <div
                v-if="canManageMembers && !canAddMore"
                class="flex items-center justify-between rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-800 dark:bg-amber-950"
            >
                <p class="text-sm text-amber-800 dark:text-amber-200">
                    {{ subscription?.member_count }}/{{
                        subscription?.max_members
                    }}
                    members — upgrade your plan to add more.
                </p>
                <Link href="/subscription">
                    <Button
                        size="sm"
                        variant="outline"
                        class="shrink-0 border-amber-300 text-amber-800 hover:bg-amber-100 dark:border-amber-700 dark:text-amber-200 dark:hover:bg-amber-900"
                    >
                        <ArrowUpCircle class="mr-1 h-4 w-4" />
                        Upgrade
                    </Button>
                </Link>
            </div>

            <!-- Members Table -->
            <div
                class="rounded-xl border border-sidebar-border/70 bg-white dark:border-sidebar-border dark:bg-neutral-900"
            >
                <div
                    class="divide-y divide-neutral-100 md:hidden dark:divide-neutral-800"
                >
                    <Link
                        v-for="member in displayedMembers"
                        :key="member.id"
                        :href="show(member.id).url"
                        class="app-tap flex items-center gap-3 p-4"
                    >
                        <div
                            class="flex size-11 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-sm font-semibold text-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"
                        >
                            {{ member.name.charAt(0).toUpperCase() }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <p
                                    class="truncate font-medium text-neutral-900 dark:text-neutral-100"
                                >
                                    {{ member.name }}
                                </p>
                                <span
                                    v-if="member.is_archived"
                                    class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-300"
                                >
                                    Archived
                                </span>
                            </div>
                            <p
                                class="truncate text-sm text-neutral-500 dark:text-neutral-400"
                            >
                                {{ member.email }}
                            </p>
                            <div
                                class="mt-2 flex flex-wrap items-center gap-2 text-xs text-neutral-500 dark:text-neutral-400"
                            >
                                <span>{{ member.role_label }}</span>
                                <span>&middot;</span>
                                <span>{{
                                    member.category_label ?? 'No category'
                                }}</span>
                                <span>&middot;</span>
                                <span
                                    >{{
                                        formatCurrency(member.monthly_amount)
                                    }}/mo</span
                                >
                            </div>
                        </div>
                        <ChevronRight
                            class="size-5 shrink-0 text-neutral-400"
                        />
                    </Link>
                    <div
                        v-if="displayedMembers.length === 0"
                        class="px-6 py-8 text-center text-neutral-500 dark:text-neutral-400"
                    >
                        {{
                            showArchived
                                ? 'No archived members'
                                : 'No active members'
                        }}
                    </div>
                </div>

                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr
                                class="border-b border-neutral-200 dark:border-neutral-700"
                            >
                                <th
                                    class="px-4 py-4 font-medium text-neutral-600 sm:px-6 dark:text-neutral-400"
                                >
                                    Name
                                </th>
                                <th
                                    class="hidden px-6 py-4 font-medium text-neutral-600 md:table-cell dark:text-neutral-400"
                                >
                                    Email
                                </th>
                                <th
                                    class="hidden px-6 py-4 font-medium text-neutral-600 lg:table-cell dark:text-neutral-400"
                                >
                                    Role
                                </th>
                                <th
                                    class="px-4 py-4 font-medium text-neutral-600 sm:px-6 dark:text-neutral-400"
                                >
                                    Category
                                </th>
                                <th
                                    class="hidden px-6 py-4 text-right font-medium text-neutral-600 sm:table-cell dark:text-neutral-400"
                                >
                                    Monthly Amount
                                </th>
                                <th
                                    class="px-4 py-4 text-right font-medium text-neutral-600 sm:px-6 dark:text-neutral-400"
                                >
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
                                <td
                                    colspan="6"
                                    class="px-6 py-8 text-center text-neutral-500 dark:text-neutral-400"
                                >
                                    {{
                                        showArchived
                                            ? 'No archived members'
                                            : 'No active members'
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
