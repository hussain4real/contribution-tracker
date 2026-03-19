<script setup lang="ts">
import { families as familiesRoute } from '@/actions/App/Http/Controllers/PlatformAdminController';
import Heading from '@/components/Heading.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';

interface FamilyRow {
    id: number;
    name: string;
    slug: string;
    currency: string;
    due_day: number;
    members_count: number;
    owner_name: string | null;
    created_at: string;
}

interface PaginatedFamilies {
    data: FamilyRow[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
}

interface Props {
    families?: PaginatedFamilies;
}

const props = withDefaults(defineProps<Props>(), {
    families: () => ({ data: [], links: [], current_page: 1, last_page: 1 }),
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Platform Admin', href: '/platform' },
    { title: 'Families', href: familiesRoute().url },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="All Families" />

        <div class="space-y-6 p-6">
            <Heading
                title="All Families"
                description="Browse and manage all family groups on the platform."
            />

            <div class="overflow-x-auto rounded-lg border">
                <table class="min-w-full divide-y">
                    <thead class="bg-muted/50">
                        <tr>
                            <th class="px-4 py-3 text-left text-sm font-medium">
                                Name
                            </th>
                            <th class="px-4 py-3 text-left text-sm font-medium">
                                Owner
                            </th>
                            <th
                                class="px-4 py-3 text-center text-sm font-medium"
                            >
                                Members
                            </th>
                            <th
                                class="px-4 py-3 text-center text-sm font-medium"
                            >
                                Currency
                            </th>
                            <th class="px-4 py-3 text-left text-sm font-medium">
                                Created
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="family in props.families.data"
                            :key="family.id"
                            class="hover:bg-muted/30"
                        >
                            <td class="px-4 py-3">
                                <Link
                                    :href="`/platform/families/${family.id}`"
                                    class="font-medium text-primary hover:underline"
                                >
                                    {{ family.name }}
                                </Link>
                            </td>
                            <td class="px-4 py-3 text-sm text-muted-foreground">
                                {{ family.owner_name ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-center text-sm">
                                {{ family.members_count }}
                            </td>
                            <td class="px-4 py-3 text-center text-sm">
                                {{ family.currency }}
                            </td>
                            <td class="px-4 py-3 text-sm text-muted-foreground">
                                {{ family.created_at }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div
                v-if="props.families.last_page > 1"
                class="flex justify-center gap-1"
            >
                <template
                    v-for="link in props.families.links"
                    :key="link.label"
                >
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
