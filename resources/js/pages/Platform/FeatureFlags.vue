<script setup lang="ts">
import { index as platformAdminIndex } from '@/actions/App/Http/Controllers/PlatformAdminController';
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
    DialogDescription,
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
    activated_user_ids: number[];
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
    { title: 'Platform Admin', href: platformAdminIndex().url },
    { title: 'Feature Flags', href: featureFlagsIndex().url },
];

const showUserDialog = ref(false);
const selectedFeature = ref<FeatureFlag | null>(null);
const userSearch = ref('');
const globalProcessing = ref(false);

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

function isUserActivated(userId: number): boolean {
    if (!selectedFeature.value) return false;
    // Find the live feature data from props (stays in sync after Inertia reloads)
    const liveFeature = props.features.find(
        (f) => f.key === selectedFeature.value?.key,
    );
    if (!liveFeature) return false;
    return (
        liveFeature.status === 'active' ||
        liveFeature.activated_user_ids.includes(userId)
    );
}

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
    globalProcessing.value = true;
    router.post(
        activateForEveryone(feature.key).url,
        {},
        {
            onFinish: () => (globalProcessing.value = false),
        },
    );
}

function handleDeactivateAll(feature: FeatureFlag): void {
    globalProcessing.value = true;
    router.post(
        deactivateForEveryone(feature.key).url,
        {},
        {
            onFinish: () => (globalProcessing.value = false),
        },
    );
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
        preserveScroll: true,
    });
}

function handleDeactivateForUser(userId: number): void {
    if (!selectedFeature.value) return;
    userForm.user_id = userId;
    userForm.post(deactivateForUser(selectedFeature.value.key).url, {
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
                                <span
                                    v-if="feature.status === 'active'"
                                    class="font-medium text-green-600"
                                    >All</span
                                >
                                <span
                                    v-else-if="feature.activated_count > 0"
                                    class="font-medium"
                                    >{{
                                        feature.activated_count
                                    }}
                                    user{{
                                        feature.activated_count !== 1
                                            ? 's'
                                            : ''
                                    }}</span
                                >
                                <span
                                    v-else
                                    class="text-muted-foreground"
                                    >&mdash;</span
                                >
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
                                        :disabled="globalProcessing"
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
                                        :disabled="globalProcessing"
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
                <DialogDescription>
                    Activate or deactivate this feature for individual users.
                </DialogDescription>

                <div class="space-y-4">
                    <p
                        v-if="selectedFeature?.status === 'active'"
                        class="rounded-md bg-green-50 px-3 py-2 text-sm text-green-700 dark:bg-green-950/30 dark:text-green-400"
                    >
                        This feature is currently active for everyone. Use
                        "Deactivate All" first to manage per-user access.
                    </p>

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
                            :class="{
                                'bg-green-50 dark:bg-green-950/20':
                                    isUserActivated(user.id),
                            }"
                        >
                            <div class="flex items-center gap-2">
                                <span
                                    class="inline-block h-2 w-2 shrink-0 rounded-full"
                                    :class="
                                        isUserActivated(user.id)
                                            ? 'bg-green-500'
                                            : 'bg-gray-300 dark:bg-gray-600'
                                    "
                                />
                                <div>
                                    <p class="text-sm font-medium">
                                        {{ user.name }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ user.email }}
                                    </p>
                                </div>
                            </div>
                            <div class="flex gap-1">
                                <Button
                                    v-if="!isUserActivated(user.id)"
                                    variant="ghost"
                                    size="icon"
                                    title="Activate for this user"
                                    :disabled="userForm.processing"
                                    @click="handleActivateForUser(user.id)"
                                >
                                    <UserCheck class="h-4 w-4 text-green-600" />
                                </Button>
                                <Button
                                    v-if="isUserActivated(user.id)"
                                    variant="ghost"
                                    size="icon"
                                    title="Deactivate for this user"
                                    :disabled="
                                        userForm.processing ||
                                        selectedFeature?.status === 'active'
                                    "
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
