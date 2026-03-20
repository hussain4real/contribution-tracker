<script setup lang="ts">
import { users as usersRoute } from '@/actions/App/Http/Controllers/PlatformAdminController';
import Heading from '@/components/Heading.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';

interface UserRow {
    id: number;
    name: string;
    email: string;
    role: string;
    category: string | null;
    family_name: string | null;
    family_id: number | null;
    is_active: boolean;
    created_at: string;
}

interface PaginatedUsers {
    data: UserRow[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
}

interface Props {
    users?: PaginatedUsers;
}

const props = withDefaults(defineProps<Props>(), {
    users: () => ({ data: [], links: [], current_page: 1, last_page: 1 }),
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Platform Admin', href: '/platform' },
    { title: 'Users', href: usersRoute().url },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="All Users" />

        <div class="space-y-6 p-6">
            <Heading
                title="All Users"
                description="Browse all users across the platform."
            />

            <div class="overflow-x-auto rounded-lg border">
                <table class="min-w-full divide-y">
                    <thead class="bg-muted/50">
                        <tr>
                            <th class="px-4 py-3 text-left text-sm font-medium">
                                Name
                            </th>
                            <th class="px-4 py-3 text-left text-sm font-medium">
                                Email
                            </th>
                            <th class="px-4 py-3 text-left text-sm font-medium">
                                Family
                            </th>
                            <th
                                class="px-4 py-3 text-center text-sm font-medium"
                            >
                                Role
                            </th>
                            <th
                                class="px-4 py-3 text-center text-sm font-medium"
                            >
                                Category
                            </th>
                            <th
                                class="px-4 py-3 text-center text-sm font-medium"
                            >
                                Status
                            </th>
                            <th class="px-4 py-3 text-left text-sm font-medium">
                                Joined
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="user in props.users.data"
                            :key="user.id"
                            class="hover:bg-muted/30"
                        >
                            <td class="px-4 py-3 font-medium">
                                {{ user.name }}
                            </td>
                            <td class="px-4 py-3 text-sm text-muted-foreground">
                                {{ user.email }}
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <Link
                                    v-if="user.family_id"
                                    :href="`/platform/families/${user.family_id}`"
                                    class="text-primary hover:underline"
                                >
                                    {{ user.family_name }}
                                </Link>
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </td>
                            <td class="px-4 py-3 text-center text-sm">
                                {{ user.role }}
                            </td>
                            <td class="px-4 py-3 text-center text-sm">
                                {{ user.category ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span
                                    class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="
                                        user.is_active
                                            ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                                            : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'
                                    "
                                >
                                    {{ user.is_active ? 'Active' : 'Archived' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm text-muted-foreground">
                                {{ user.created_at }}
                            </td>
                        </tr>
                        <tr v-if="props.users.data.length === 0">
                            <td
                                colspan="7"
                                class="px-4 py-8 text-center text-sm text-muted-foreground"
                            >
                                No users found.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div
                v-if="props.users.last_page > 1"
                class="flex justify-center gap-1"
            >
                <template v-for="link in props.users.links" :key="link.label">
                    <component
                        :is="link.url ? Link : 'span'"
                        :href="link.url ?? undefined"
                        class="rounded-md px-3 py-1.5 text-sm"
                        :class="[
                            link.url ? 'border' : 'text-muted-foreground',
                            link.active
                                ? 'bg-primary text-primary-foreground'
                                : link.url
                                  ? 'hover:bg-muted'
                                  : '',
                        ]"
                    >
                        <!-- eslint-disable-next-line vue/no-v-html -->
                        <span v-html="link.label" />
                    </component>
                </template>
            </div>
        </div>
    </AppLayout>
</template>
