<script setup lang="ts">
import {
    suspendFamily,
    unsuspendFamily,
} from '@/actions/App/Http/Controllers/PlatformAdminController';
import Heading from '@/components/Heading.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
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
import { Head, router } from '@inertiajs/vue3';
import { Ban, CheckCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Props {
    family?: {
        id: number;
        name: string;
        slug: string;
        currency: string;
        due_day: number;
        owner: { id: number; name: string; email: string } | null;
        categories: Array<{ id: number; name: string; monthly_amount: number }>;
        members: Array<{
            id: number;
            name: string;
            email: string;
            role: string;
            is_active: boolean;
        }>;
        financial_summary: {
            total_contributions: number;
            total_collected: number;
            total_expected: number;
            collection_rate: number;
            active_members: number;
            archived_members: number;
        };
        created_at: string;
        suspended_at: string | null;
    };
}

const props = withDefaults(defineProps<Props>(), {
    family: () => ({
        id: 0,
        name: '',
        slug: '',
        currency: '',
        due_day: 28,
        owner: null,
        categories: [],
        members: [],
        financial_summary: {
            total_contributions: 0,
            total_collected: 0,
            total_expected: 0,
            collection_rate: 0,
            active_members: 0,
            archived_members: 0,
        },
        created_at: '',
        suspended_at: null,
    }),
});

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Platform Admin', href: '/platform' },
    { title: 'Families', href: '/platform/families' },
    {
        title: props.family.name || 'Loading...',
        href: `/platform/families/${props.family.id}`,
    },
]);

function formatCurrency(amount: number): string {
    return `${props.family.currency}${amount.toLocaleString()}`;
}

const showSuspendDialog = ref(false);
const processing = ref(false);

function toggleSuspend(): void {
    processing.value = true;
    const route = props.family.suspended_at
        ? unsuspendFamily(props.family.id)
        : suspendFamily(props.family.id);

    router.post(route.url, {}, {
        onFinish: () => {
            processing.value = false;
            showSuspendDialog.value = false;
        },
    });
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`${family.name} — Platform Admin`" />

        <div class="mx-auto max-w-3xl space-y-8 p-6">
            <Heading
                :title="family.name"
                :description="`Slug: ${family.slug} · Created ${family.created_at}`"
            />

            <!-- Suspended Banner -->
            <div
                v-if="family.suspended_at"
                class="flex items-center justify-between rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-900/50 dark:bg-red-900/20"
            >
                <div class="flex items-center gap-2">
                    <Ban class="h-5 w-5 text-red-600 dark:text-red-400" />
                    <span class="text-sm font-medium text-red-700 dark:text-red-300">
                        This family was suspended on {{ family.suspended_at }}.
                    </span>
                </div>
                <Button
                    variant="outline"
                    size="sm"
                    @click="showSuspendDialog = true"
                >
                    <CheckCircle class="mr-2 h-4 w-4" />
                    Unsuspend
                </Button>
            </div>

            <!-- Overview -->
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-lg border p-4">
                    <p class="text-sm text-muted-foreground">Currency</p>
                    <p class="text-lg font-semibold">{{ family.currency }}</p>
                </div>
                <div class="rounded-lg border p-4">
                    <p class="text-sm text-muted-foreground">Due Day</p>
                    <p class="text-lg font-semibold">{{ family.due_day }}th</p>
                </div>
                <div class="rounded-lg border p-4">
                    <p class="text-sm text-muted-foreground">Owner</p>
                    <p class="text-lg font-semibold">
                        {{ family.owner?.name ?? '—' }}
                    </p>
                </div>
            </div>

            <!-- Suspend / Unsuspend Action -->
            <div
                v-if="!family.suspended_at"
                class="flex justify-end"
            >
                <Button
                    variant="destructive"
                    size="sm"
                    @click="showSuspendDialog = true"
                >
                    <Ban class="mr-2 h-4 w-4" />
                    Suspend Family
                </Button>
            </div>

            <!-- Financial Summary -->
            <section>
                <HeadingSmall
                    title="Financial Summary"
                    description="Overall contribution and payment statistics."
                />
                <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-lg border p-4">
                        <p class="text-sm text-muted-foreground">
                            Total Expected
                        </p>
                        <p class="text-lg font-semibold">
                            {{
                                formatCurrency(
                                    family.financial_summary.total_expected,
                                )
                            }}
                        </p>
                    </div>
                    <div class="rounded-lg border p-4">
                        <p class="text-sm text-muted-foreground">
                            Total Collected
                        </p>
                        <p class="text-lg font-semibold">
                            {{
                                formatCurrency(
                                    family.financial_summary.total_collected,
                                )
                            }}
                        </p>
                    </div>
                    <div class="rounded-lg border p-4">
                        <p class="text-sm text-muted-foreground">
                            Collection Rate
                        </p>
                        <p class="text-lg font-semibold">
                            {{ family.financial_summary.collection_rate }}%
                        </p>
                    </div>
                    <div class="rounded-lg border p-4">
                        <p class="text-sm text-muted-foreground">Members</p>
                        <p class="text-lg font-semibold">
                            {{ family.financial_summary.active_members }} active
                            <span
                                v-if="
                                    family.financial_summary.archived_members >
                                    0
                                "
                                class="text-sm font-normal text-muted-foreground"
                            >
                                ·
                                {{ family.financial_summary.archived_members }}
                                archived
                            </span>
                        </p>
                    </div>
                </div>
            </section>

            <!-- Categories -->
            <section>
                <HeadingSmall
                    title="Categories"
                    :description="`${family.categories.length} contribution categories defined.`"
                />
                <div class="mt-3 space-y-2">
                    <div
                        v-for="category in family.categories"
                        :key="category.id"
                        class="flex items-center justify-between rounded-lg border px-4 py-3"
                    >
                        <span class="font-medium">{{ category.name }}</span>
                        <span class="text-sm text-muted-foreground"
                            >{{ formatCurrency(category.monthly_amount) }} /
                            month</span
                        >
                    </div>
                    <p
                        v-if="family.categories.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        No categories defined.
                    </p>
                </div>
            </section>

            <!-- Members -->
            <section>
                <HeadingSmall
                    title="Members"
                    :description="`${family.members.length} members in this family.`"
                />
                <div class="mt-3 overflow-x-auto rounded-lg border">
                    <table class="min-w-full divide-y">
                        <thead class="bg-muted/50">
                            <tr>
                                <th
                                    class="px-4 py-2 text-left text-sm font-medium"
                                >
                                    Name
                                </th>
                                <th
                                    class="px-4 py-2 text-left text-sm font-medium"
                                >
                                    Email
                                </th>
                                <th
                                    class="px-4 py-2 text-left text-sm font-medium"
                                >
                                    Role
                                </th>
                                <th
                                    class="px-4 py-2 text-center text-sm font-medium"
                                >
                                    Status
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="member in family.members"
                                :key="member.id"
                            >
                                <td class="px-4 py-2 text-sm font-medium">
                                    {{ member.name }}
                                </td>
                                <td
                                    class="px-4 py-2 text-sm text-muted-foreground"
                                >
                                    {{ member.email }}
                                </td>
                                <td class="px-4 py-2 text-sm">
                                    {{ member.role }}
                                </td>
                                <td class="px-4 py-2 text-center">
                                    <span
                                        class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="
                                            member.is_active
                                                ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                                                : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'
                                        "
                                    >
                                        {{
                                            member.is_active
                                                ? 'Active'
                                                : 'Archived'
                                        }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <!-- Suspend/Unsuspend Dialog -->
        <Dialog v-model:open="showSuspendDialog">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {{
                            family.suspended_at
                                ? 'Unsuspend Family'
                                : 'Suspend Family'
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        <template v-if="family.suspended_at">
                            Are you sure you want to unsuspend
                            <strong>{{ family.name }}</strong
                            >? Members will be able to access their accounts
                            again.
                        </template>
                        <template v-else>
                            Are you sure you want to suspend
                            <strong>{{ family.name }}</strong
                            >? All members will be logged out and unable to
                            access their accounts.
                        </template>
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="outline"
                        @click="showSuspendDialog = false"
                    >
                        Cancel
                    </Button>
                    <Button
                        :variant="family.suspended_at ? 'default' : 'destructive'"
                        :disabled="processing"
                        @click="toggleSuspend"
                    >
                        {{
                            processing
                                ? 'Processing...'
                                : family.suspended_at
                                  ? 'Unsuspend'
                                  : 'Suspend'
                        }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
