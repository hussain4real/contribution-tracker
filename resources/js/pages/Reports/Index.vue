<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { type BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import { index, monthly, annual } from '@/actions/App/Http/Controllers/ReportController';
import { FileBarChart2, Calendar, CalendarDays } from 'lucide-vue-next';

interface Props {
    years: number[];
    current_year: number;
    current_month: number;
}

defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Reports',
        href: index().url,
    },
];

const monthNames = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December'
];
</script>

<template>
    <Head title="Reports" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
            <!-- Header -->
            <div class="flex items-center gap-3">
                <FileBarChart2 class="h-6 w-6 text-neutral-500" />
                <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">
                    Contribution Reports
                </h1>
            </div>

            <!-- Report Type Cards -->
            <div class="grid gap-6 md:grid-cols-2">
                <!-- Monthly Report Card -->
                <div class="rounded-xl border border-sidebar-border/70 bg-white p-6 dark:border-sidebar-border dark:bg-neutral-900">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-100 dark:bg-blue-900">
                            <Calendar class="h-5 w-5 text-blue-600 dark:text-blue-400" />
                        </div>
                        <div>
                            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                                Monthly Report
                            </h2>
                            <p class="text-sm text-neutral-600 dark:text-neutral-400">
                                View contribution summary for a specific month
                            </p>
                        </div>
                    </div>
                    <p class="text-sm text-neutral-600 dark:text-neutral-400 mb-4">
                        Get detailed insights into member contributions, payment statuses,
                        and collection rates for any month.
                    </p>
                    <Link :href="monthly({ year: current_year, month: current_month }).url">
                        <Button class="w-full">
                            <Calendar class="mr-2 h-4 w-4" />
                            View {{ monthNames[current_month - 1] }} {{ current_year }} Report
                        </Button>
                    </Link>
                </div>

                <!-- Annual Report Card -->
                <div class="rounded-xl border border-sidebar-border/70 bg-white p-6 dark:border-sidebar-border dark:bg-neutral-900">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-green-100 dark:bg-green-900">
                            <CalendarDays class="h-5 w-5 text-green-600 dark:text-green-400" />
                        </div>
                        <div>
                            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                                Annual Report
                            </h2>
                            <p class="text-sm text-neutral-600 dark:text-neutral-400">
                                View contribution summary for an entire year
                            </p>
                        </div>
                    </div>
                    <p class="text-sm text-neutral-600 dark:text-neutral-400 mb-4">
                        See yearly trends, monthly breakdowns, and category-wise
                        contribution analysis for any year.
                    </p>
                    <Link :href="annual({ year: current_year }).url">
                        <Button variant="outline" class="w-full">
                            <CalendarDays class="mr-2 h-4 w-4" />
                            View {{ current_year }} Annual Report
                        </Button>
                    </Link>
                </div>
            </div>

            <!-- Quick Links by Year -->
            <div class="rounded-xl border border-sidebar-border/70 bg-white p-6 dark:border-sidebar-border dark:bg-neutral-900">
                <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100 mb-4">
                    Historical Reports
                </h2>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="year in years" :key="year" class="rounded-lg border border-neutral-200 dark:border-neutral-700 p-4">
                        <h3 class="font-medium text-neutral-900 dark:text-neutral-100 mb-3">
                            {{ year }}
                        </h3>
                        <div class="flex gap-2">
                            <Link :href="annual({ year }).url" class="flex-1">
                                <Button variant="outline" size="sm" class="w-full">
                                    Annual
                                </Button>
                            </Link>
                            <Link :href="monthly({ year, month: current_month }).url" class="flex-1">
                                <Button variant="ghost" size="sm" class="w-full">
                                    Monthly
                                </Button>
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
