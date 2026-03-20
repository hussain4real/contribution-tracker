<script setup lang="ts">
import {
    exportUsers,
    impersonate,
    sendPasswordReset,
    users as usersRoute,
} from '@/actions/App/Http/Controllers/PlatformAdminController';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { Download, KeyRound, UserCheck } from 'lucide-vue-next';
import { ref } from 'vue';

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

const showImpersonateDialog = ref(false);
const showResetDialog = ref(false);
const targetUser = ref<UserRow | null>(null);
const processing = ref(false);

function promptImpersonate(user: UserRow): void {
    targetUser.value = user;
    showImpersonateDialog.value = true;
}

function confirmImpersonate(): void {
    if (!targetUser.value) return;
    processing.value = true;
    router.post(
        impersonate(targetUser.value.id).url,
        {},
        {
            onFinish: () => {
                processing.value = false;
                showImpersonateDialog.value = false;
            },
        },
    );
}

function promptReset(user: UserRow): void {
    targetUser.value = user;
    showResetDialog.value = true;
}

function confirmReset(): void {
    if (!targetUser.value) return;
    processing.value = true;
    router.post(
        sendPasswordReset(targetUser.value.id).url,
        {},
        {
            onFinish: () => {
                processing.value = false;
                showResetDialog.value = false;
            },
        },
    );
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="All Users" />

        <div class="space-y-6 p-6">
            <div class="flex items-center justify-between">
                <Heading
                    title="All Users"
                    description="Browse all users across the platform."
                />
                <a :href="exportUsers().url">
                    <Button variant="outline" size="sm">
                        <Download class="mr-2 h-4 w-4" />
                        Export CSV
                    </Button>
                </a>
            </div>

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
                            <th
                                class="px-4 py-3 text-center text-sm font-medium"
                            >
                                Actions
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
                            <td class="px-4 py-3 text-center">
                                <div
                                    class="flex items-center justify-center gap-1"
                                >
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        title="Impersonate"
                                        @click="promptImpersonate(user)"
                                    >
                                        <UserCheck class="h-4 w-4" />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        title="Send Password Reset"
                                        @click="promptReset(user)"
                                    >
                                        <KeyRound class="h-4 w-4" />
                                    </Button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="props.users.data.length === 0">
                            <td
                                colspan="8"
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

        <!-- Impersonate Dialog -->
        <Dialog v-model:open="showImpersonateDialog">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Impersonate User</DialogTitle>
                    <DialogDescription>
                        You will be logged in as
                        <strong>{{ targetUser?.name }}</strong>
                        ({{ targetUser?.email }}). You can stop impersonating
                        from the banner at the top of the page.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="outline"
                        @click="showImpersonateDialog = false"
                    >
                        Cancel
                    </Button>
                    <Button :disabled="processing" @click="confirmImpersonate">
                        {{ processing ? 'Switching...' : 'Impersonate' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Password Reset Dialog -->
        <Dialog v-model:open="showResetDialog">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Send Password Reset</DialogTitle>
                    <DialogDescription>
                        A password reset email will be sent to
                        <strong>{{ targetUser?.email }}</strong
                        >.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" @click="showResetDialog = false">
                        Cancel
                    </Button>
                    <Button :disabled="processing" @click="confirmReset">
                        {{ processing ? 'Sending...' : 'Send Reset Email' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
