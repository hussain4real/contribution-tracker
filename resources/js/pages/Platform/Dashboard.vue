<script setup lang="ts">
import { index } from '@/actions/App/Http/Controllers/PlatformAdminController';
import Heading from '@/components/Heading.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { Building2, DollarSign, Receipt, Users } from 'lucide-vue-next';

interface FamilySummary {
    id: number;
    name: string;
    slug: string;
    currency: string;
    members_count: number;
    created_at: string;
}

interface Props {
    stats?: {
        total_families: number;
        total_users: number;
        total_payments: number;
        total_expenses: number;
        total_contributions: number;
    };
    recent_families?: FamilySummary[];
}

const props = withDefaults(defineProps<Props>(), {
    stats: () => ({
        total_families: 0,
        total_users: 0,
        total_payments: 0,
        total_expenses: 0,
        total_contributions: 0,
    }),
    recent_families: () => [],
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Platform Admin', href: index().url },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Platform Admin" />

        <div class="space-y-6 p-6">
            <Heading
                title="Platform Overview"
                description="Global platform statistics and management."
            />

            <!-- Stats Cards -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-lg border p-4">
                    <div class="flex items-center gap-2 text-muted-foreground">
                        <Building2 class="h-4 w-4" />
                        <span class="text-sm font-medium">Families</span>
                    </div>
                    <p class="mt-2 text-2xl font-bold">
                        {{ props.stats.total_families }}
                    </p>
                </div>
                <div class="rounded-lg border p-4">
                    <div class="flex items-center gap-2 text-muted-foreground">
                        <Users class="h-4 w-4" />
                        <span class="text-sm font-medium">Users</span>
                    </div>
                    <p class="mt-2 text-2xl font-bold">
                        {{ props.stats.total_users }}
                    </p>
                </div>
                <div class="rounded-lg border p-4">
                    <div class="flex items-center gap-2 text-muted-foreground">
                        <DollarSign class="h-4 w-4" />
                        <span class="text-sm font-medium">Total Payments</span>
                    </div>
                    <p class="mt-2 text-2xl font-bold">
                        {{ props.stats.total_payments.toLocaleString() }}
                    </p>
                </div>
                <div class="rounded-lg border p-4">
                    <div class="flex items-center gap-2 text-muted-foreground">
                        <Receipt class="h-4 w-4" />
                        <span class="text-sm font-medium">Total Expenses</span>
                    </div>
                    <p class="mt-2 text-2xl font-bold">
                        {{ props.stats.total_expenses.toLocaleString() }}
                    </p>
                </div>
            </div>

            <!-- Recent Families -->
            <div>
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-semibold">Recent Families</h3>
                    <Link
                        href="/platform/families"
                        class="text-sm text-primary hover:underline"
                        >View All</Link
                    >
                </div>
                <div class="mt-3 space-y-2">
                    <Link
                        v-for="family in props.recent_families"
                        :key="family.id"
                        :href="`/platform/families/${family.id}`"
                        class="flex items-center justify-between rounded-lg border p-4 transition-colors hover:bg-muted/50"
                    >
                        <div>
                            <p class="font-medium">{{ family.name }}</p>
                            <p class="text-sm text-muted-foreground">
                                {{ family.members_count }} member{{
                                    family.members_count !== 1 ? 's' : ''
                                }}
                                &middot; Created {{ family.created_at }}
                            </p>
                        </div>
                        <span class="text-sm text-muted-foreground">{{
                            family.currency
                        }}</span>
                    </Link>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
