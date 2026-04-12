<script setup lang="ts">
import {
    activateForEveryone,
    activateForUser,
    deactivateForEveryone,
    deactivateForUser,
    index as featureFlagsIndex,
} from '@/actions/App/Http/Controllers/PlatformFeatureFlagController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Power, UserCheck, UserX, Users } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface FeatureFlag {
    key: string;
    name: string;
    description: string;
    status: 'active' | 'partial' | 'inactive';
    activated_count: number;
    total_resolved: number;
}

interface UserEntry {
    id: number;
    name: string;
    email: string;
}

interface Props {
    features?: FeatureFlag[];
    users?: UserEntry[];
}

const props = withDefaults(defineProps<Props>(), {
    features: () => [],
    users: () => [],
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Platform Admin', href: '/platform' },
    { title: 'Feature Flags', href: featureFlagsIndex().url },
];

const showUserDialog = ref(false);
const selectedFeature = ref<FeatureFlag | null>(null);
const userSearch = ref('');

const filteredUsers = computed(() => {
    if (!userSearch.value) return props.users;
    const q = userSearch.value.toLowerCase();
    return props.users.filter(
        (u) =>
            u.name.toLowerCase().includes(q) ||
            u.email.toLowerCase().includes(q),
    );
});

const userForm = useForm({
    user_id: null as number | null,
});

function statusVariant(
    status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (status === 'active') return 'default';
    if (status === 'partial') return 'secondary';
    return 'destructive';
}

function statusLabel(status: string): string {
    if (status === 'active') return 'Active for Everyone';
    if (status === 'partial') return 'Partially Rolled Out';
    return 'Inactive';
}

function handleActivateAll(feature: FeatureFlag): void {
    router.post(activateForEveryone(feature.key).url);
}

function handleDeactivateAll(feature: FeatureFlag): void {
    router.post(deactivateForEveryone(feature.key).url);
}

function openUserDialog(feature: FeatureFlag): void {
    selectedFeature.value = feature;
    userSearch.value = '';
    userForm.reset();
    showUserDialog.value = true;
}

function handleActivateForUser(userId: number): void {
    if (!selectedFeature.value) return;
    userForm.user_id = userId;
    userForm.post(activateForUser(selectedFeature.value.key).url, {
        onSuccess: () => {
            showUserDialog.value = false;
        },
        preserveScroll: true,
    });
}

function handleDeactivateForUser(userId: number): void {
    if (!selectedFeature.value) return;
    userForm.user_id = userId;
    userForm.post(deactivateForUser(selectedFeature.value.key).url, {
        onSuccess: () => {
            showUserDialog.value = false;
        },
        preserveScroll: true,
    });
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Feature Flags" />

        <div class="space-y-6 p-6">
            <Heading
                title="Feature Flags"
                description="Control the gradual rollout of features to users."
            />

            <!-- Flash Messages -->
            <div
                v-if="$page.props.flash?.success"
                class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800 dark:border-green-800 dark:bg-green-950 dark:text-green-200"
            >
                {{ $page.props.flash?.success }}
            </div>
            <div
                v-if="$page.props.flash?.error"
                class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200"
            >
                {{ $page.props.flash?.error }}
            </div>

            <!-- Feature Flags Table -->
            <div class="overflow-x-auto rounded-lg border">
                <table class="min-w-full divide-y">
                    <thead class="bg-muted/50">
                        <tr>
                            <th class="px-4 py-3 text-left text-sm font-medium">
                                Feature
                            </th>
                            <th
                                class="px-4 py-3 text-center text-sm font-medium"
                            >
                                Status
                            </th>
                            <th
                                class="px-4 py-3 text-center text-sm font-medium"
                            >
                                Activated Users
                            </th>
                            <th
                                class="px-4 py-3 text-right text-sm font-medium"
                            >
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="feature in features"
                            :key="feature.key"
                            class="hover:bg-muted/30"
                        >
                            <td class="px-4 py-3">
                                <div>
                                    <p class="font-medium">
                                        {{ feature.name }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ feature.description }}
                                    </p>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <Badge :variant="statusVariant(feature.status)">
                                    {{ statusLabel(feature.status) }}
                                </Badge>
                            </td>
                            <td class="px-4 py-3 text-center text-sm">
                                {{ feature.activated_count }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div
                                    class="flex items-center justify-end gap-1"
                                >
                                    <Button
                                        v-if="feature.status !== 'active'"
                                        variant="ghost"
                                        size="sm"
                                        title="Activate for Everyone"
                                        @click="handleActivateAll(feature)"
                                    >
                                        <Power
                                            class="mr-1 h-4 w-4 text-green-600"
                                        />
                                        Activate All
                                    </Button>
                                    <Button
                                        v-if="feature.status !== 'inactive'"
                                        variant="ghost"
                                        size="sm"
                                        title="Deactivate for Everyone"
                                        @click="handleDeactivateAll(feature)"
                                    >
                                        <Power
                                            class="mr-1 h-4 w-4 text-red-500"
                                        />
                                        Deactivate All
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        title="Manage per-user access"
                                        @click="openUserDialog(feature)"
                                    >
                                        <Users class="mr-1 h-4 w-4" />
                                        Per User
                                    </Button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="features.length === 0">
                            <td
                                colspan="4"
                                class="px-4 py-8 text-center text-sm text-muted-foreground"
                            >
                                No feature flags defined yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Per-User Dialog -->
        <Dialog v-model:open="showUserDialog">
            <DialogContent class="sm:max-w-lg">
                <DialogTitle>
                    Manage "{{ selectedFeature?.name }}" per User
                </DialogTitle>

                <div class="space-y-4">
                    <Input
                        v-model="userSearch"
                        placeholder="Search users by name or email..."
                        class="w-full"
                    />

                    <div class="max-h-64 space-y-1 overflow-y-auto">
                        <div
                            v-for="user in filteredUsers"
                            :key="user.id"
                            class="flex items-center justify-between rounded-md px-3 py-2 hover:bg-muted/50"
                        >
                            <div>
                                <p class="text-sm font-medium">
                                    {{ user.name }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ user.email }}
                                </p>
                            </div>
                            <div class="flex gap-1">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    title="Activate for this user"
                                    :disabled="userForm.processing"
                                    @click="handleActivateForUser(user.id)"
                                >
                                    <UserCheck class="h-4 w-4 text-green-600" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    title="Deactivate for this user"
                                    :disabled="userForm.processing"
                                    @click="handleDeactivateForUser(user.id)"
                                >
                                    <UserX class="h-4 w-4 text-red-500" />
                                </Button>
                            </div>
                        </div>
                        <p
                            v-if="filteredUsers.length === 0"
                            class="py-4 text-center text-sm text-muted-foreground"
                        >
                            No users found.
                        </p>
                    </div>
                </div>

                <DialogFooter>
                    <Button variant="outline" @click="showUserDialog = false">
                        Close
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
