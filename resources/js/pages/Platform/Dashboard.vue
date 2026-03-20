<script setup lang="ts">
import {
    families as familiesRoute,
    index,
    users as usersRoute,
} from '@/actions/App/Http/Controllers/PlatformAdminController';
import Heading from '@/components/Heading.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import {
    Activity,
    Building2,
    DollarSign,
    FileText,
    Receipt,
    TrendingUp,
    UserCheck,
    Users,
} from 'lucide-vue-next';

interface FamilySummary {
    id: number;
    name: string;
    slug: string;
    currency: string;
    members_count: number;
    created_at: string;
}

interface RecentPayment {
    id: number;
    amount: number;
    member_name: string | null;
    recorded_by: string | null;
    created_at: string;
}

interface Props {
    stats?: {
        total_families: number;
        total_users: number;
        active_users: number;
        archived_users: number;
        total_payments: number;
        total_expenses: number;
        total_contributions: number;
        new_families_this_month: number;
    };
    recent_families?: FamilySummary[];
    recent_payments?: RecentPayment[];
}

const props = withDefaults(defineProps<Props>(), {
    stats: () => ({
        total_families: 0,
        total_users: 0,
        active_users: 0,
        archived_users: 0,
        total_payments: 0,
        total_expenses: 0,
        total_contributions: 0,
        new_families_this_month: 0,
    }),
    recent_families: () => [],
    recent_payments: () => [],
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
                    <p
                        v-if="props.stats.new_families_this_month > 0"
                        class="mt-1 flex items-center gap-1 text-xs text-green-600 dark:text-green-400"
                    >
                        <TrendingUp class="h-3 w-3" />
                        +{{ props.stats.new_families_this_month }} this month
                    </p>
                </div>
                <Link
                    :href="usersRoute().url"
                    class="rounded-lg border p-4 transition-colors hover:bg-muted/50"
                >
                    <div class="flex items-center gap-2 text-muted-foreground">
                        <Users class="h-4 w-4" />
                        <span class="text-sm font-medium">Users</span>
                    </div>
                    <p class="mt-2 text-2xl font-bold">
                        {{ props.stats.total_users }}
                    </p>
                    <p
                        class="mt-1 flex items-center gap-1 text-xs text-muted-foreground"
                    >
                        <UserCheck class="h-3 w-3" />
                        {{ props.stats.active_users }} active ·
                        {{ props.stats.archived_users }} archived
                    </p>
                </Link>
                <div class="rounded-lg border p-4">
                    <div class="flex items-center gap-2 text-muted-foreground">
                        <DollarSign class="h-4 w-4" />
                        <span class="text-sm font-medium">Total Payments</span>
                    </div>
                    <p class="mt-2 text-2xl font-bold">
                        {{ props.stats.total_payments.toLocaleString() }}
                    </p>
                    <p
                        class="mt-1 flex items-center gap-1 text-xs text-muted-foreground"
                    >
                        <FileText class="h-3 w-3" />
                        {{ props.stats.total_contributions }} contributions
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

            <!-- Two-column layout for Recent Families and Recent Payments -->
            <div class="grid gap-6 lg:grid-cols-2">
                <!-- Recent Families -->
                <div>
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold">Recent Families</h3>
                        <Link
                            :href="familiesRoute().url"
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
                        <p
                            v-if="props.recent_families.length === 0"
                            class="py-4 text-center text-sm text-muted-foreground"
                        >
                            No families yet.
                        </p>
                    </div>
                </div>

                <!-- Recent Payments -->
                <div>
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold">Recent Payments</h3>
                    </div>
                    <div class="mt-3 space-y-2">
                        <div
                            v-for="payment in props.recent_payments"
                            :key="payment.id"
                            class="flex items-center justify-between rounded-lg border p-4"
                        >
                            <div>
                                <p class="font-medium">
                                    {{ payment.member_name ?? 'Unknown' }}
                                </p>
                                <p class="text-sm text-muted-foreground">
                                    Recorded by
                                    {{ payment.recorded_by ?? 'Unknown' }}
                                    &middot; {{ payment.created_at }}
                                </p>
                            </div>
                            <span class="font-semibold">
                                <Activity
                                    class="mr-1 inline-block h-3.5 w-3.5 text-green-500"
                                />
                                {{ payment.amount.toLocaleString() }}
                            </span>
                        </div>
                        <p
                            v-if="props.recent_payments.length === 0"
                            class="py-4 text-center text-sm text-muted-foreground"
                        >
                            No payments recorded yet.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
