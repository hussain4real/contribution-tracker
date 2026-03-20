<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

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
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`${family.name} — Platform Admin`" />

        <div class="mx-auto max-w-3xl space-y-8 p-6">
            <Heading
                :title="family.name"
                :description="`Slug: ${family.slug} · Created ${family.created_at}`"
            />

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
    </AppLayout>
</template>
