<script setup lang="ts">
import {
    destroy,
    index as plansIndex,
    store,
    toggleActive,
    update,
} from '@/actions/App/Http/Controllers/PlatformPlanController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Power, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';

interface Plan {
    id: number;
    name: string;
    slug: string;
    price: number;
    formatted_price: string;
    max_members: number | null;
    features: string[];
    is_active: boolean;
    sort_order: number;
    families_count: number;
    paystack_plan_code: string | null;
    created_at: string;
}

interface Props {
    plans?: Plan[];
    available_features?: Record<string, string>;
}

const props = withDefaults(defineProps<Props>(), {
    plans: () => [],
    available_features: () => ({}),
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Platform Admin', href: '/platform' },
    { title: 'Plans', href: plansIndex().url },
];

const showFormDialog = ref(false);
const editingPlan = ref<Plan | null>(null);

const form = useForm({
    name: '',
    slug: '',
    price: 0,
    max_members: null as number | null,
    features: [] as string[],
    is_active: true,
    sort_order: 0,
});

function openCreate(): void {
    editingPlan.value = null;
    form.reset();
    form.sort_order = props.plans.length;
    showFormDialog.value = true;
}

function openEdit(plan: Plan): void {
    editingPlan.value = plan;
    form.name = plan.name;
    form.slug = plan.slug;
    form.price = plan.price;
    form.max_members = plan.max_members;
    form.features = [...plan.features];
    form.is_active = plan.is_active;
    form.sort_order = plan.sort_order;
    showFormDialog.value = true;
}

function generateSlug(): void {
    if (!editingPlan.value) {
        form.slug = form.name
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/(^-|-$)/g, '');
    }
}

function toggleFeature(feature: string, checked: boolean): void {
    if (checked) {
        if (!form.features.includes(feature)) {
            form.features.push(feature);
        }
    } else {
        form.features = form.features.filter((f) => f !== feature);
    }
}

function submitForm(): void {
    if (editingPlan.value) {
        form.put(update(editingPlan.value.id).url, {
            onSuccess: () => {
                showFormDialog.value = false;
            },
        });
    } else {
        form.post(store().url, {
            onSuccess: () => {
                showFormDialog.value = false;
                form.reset();
            },
        });
    }
}

function handleToggleActive(plan: Plan): void {
    router.post(toggleActive(plan.id).url);
}

function handleDelete(plan: Plan): void {
    if (
        confirm(
            `Are you sure you want to delete the "${plan.name}" plan? This cannot be undone.`,
        )
    ) {
        router.delete(destroy(plan.id).url);
    }
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Platform Plans" />

        <div class="space-y-6 p-6">
            <div class="flex items-center justify-between">
                <Heading
                    title="Platform Plans"
                    description="Manage subscription plans available to families."
                />
                <Button size="sm" @click="openCreate">
                    <Plus class="mr-2 h-4 w-4" />
                    New Plan
                </Button>
            </div>

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

            <!-- Plans Table -->
            <div class="overflow-x-auto rounded-lg border">
                <table class="min-w-full divide-y">
                    <thead class="bg-muted/50">
                        <tr>
                            <th class="px-4 py-3 text-left text-sm font-medium">
                                Plan
                            </th>
                            <th
                                class="px-4 py-3 text-center text-sm font-medium"
                            >
                                Price
                            </th>
                            <th
                                class="px-4 py-3 text-center text-sm font-medium"
                            >
                                Max Members
                            </th>
                            <th
                                class="px-4 py-3 text-center text-sm font-medium"
                            >
                                Features
                            </th>
                            <th
                                class="px-4 py-3 text-center text-sm font-medium"
                            >
                                Families
                            </th>
                            <th
                                class="px-4 py-3 text-center text-sm font-medium"
                            >
                                Status
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
                            v-for="plan in plans"
                            :key="plan.id"
                            class="hover:bg-muted/30"
                        >
                            <td class="px-4 py-3">
                                <div>
                                    <p class="font-medium">{{ plan.name }}</p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ plan.slug }}
                                    </p>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-center text-sm">
                                {{ plan.formatted_price }}
                            </td>
                            <td class="px-4 py-3 text-center text-sm">
                                {{ plan.max_members ?? 'Unlimited' }}
                            </td>
                            <td class="px-4 py-3 text-center">
                                <div
                                    class="flex flex-wrap justify-center gap-1"
                                >
                                    <Badge
                                        v-for="feature in plan.features"
                                        :key="feature"
                                        variant="secondary"
                                        class="text-xs"
                                    >
                                        {{
                                            available_features[feature] ??
                                            feature
                                        }}
                                    </Badge>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-center text-sm">
                                {{ plan.families_count }}
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span
                                    class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="
                                        plan.is_active
                                            ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                                            : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'
                                    "
                                >
                                    {{ plan.is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div
                                    class="flex items-center justify-end gap-1"
                                >
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        title="Edit"
                                        @click="openEdit(plan)"
                                    >
                                        <Pencil class="h-4 w-4" />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        :title="
                                            plan.is_active
                                                ? 'Deactivate'
                                                : 'Activate'
                                        "
                                        @click="handleToggleActive(plan)"
                                    >
                                        <Power
                                            class="h-4 w-4"
                                            :class="
                                                plan.is_active
                                                    ? 'text-green-600'
                                                    : 'text-muted-foreground'
                                            "
                                        />
                                    </Button>
                                    <Button
                                        v-if="plan.families_count === 0"
                                        variant="ghost"
                                        size="icon"
                                        class="text-destructive hover:text-destructive"
                                        title="Delete"
                                        @click="handleDelete(plan)"
                                    >
                                        <Trash2 class="h-4 w-4" />
                                    </Button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="plans.length === 0">
                            <td
                                colspan="7"
                                class="px-4 py-8 text-center text-sm text-muted-foreground"
                            >
                                No plans yet. Create one to get started.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Create / Edit Dialog -->
        <Dialog v-model:open="showFormDialog">
            <DialogContent class="sm:max-w-lg">
                <DialogTitle>
                    {{ editingPlan ? 'Edit Plan' : 'Create Plan' }}
                </DialogTitle>

                <form class="space-y-4" @submit.prevent="submitForm">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="plan-name">Name</Label>
                            <Input
                                id="plan-name"
                                v-model="form.name"
                                placeholder="e.g. Pro"
                                required
                                @input="generateSlug"
                            />
                            <InputError :message="form.errors.name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="plan-slug">Slug</Label>
                            <Input
                                id="plan-slug"
                                v-model="form.slug"
                                placeholder="e.g. pro"
                                required
                            />
                            <InputError :message="form.errors.slug" />
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="grid gap-2">
                            <Label for="plan-price">Price (₦)</Label>
                            <Input
                                id="plan-price"
                                v-model.number="form.price"
                                type="number"
                                min="0"
                                required
                            />
                            <InputError :message="form.errors.price" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="plan-max-members">Max Members</Label>
                            <Input
                                id="plan-max-members"
                                :model-value="form.max_members ?? undefined"
                                type="number"
                                min="1"
                                placeholder="Unlimited"
                                @update:model-value="
                                    (v: string | number) =>
                                        (form.max_members = v
                                            ? Number(v)
                                            : null)
                                "
                            />
                            <InputError :message="form.errors.max_members" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="plan-sort-order">Sort Order</Label>
                            <Input
                                id="plan-sort-order"
                                v-model.number="form.sort_order"
                                type="number"
                                min="0"
                                required
                            />
                            <InputError :message="form.errors.sort_order" />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label>Features</Label>
                        <div class="grid grid-cols-2 gap-3">
                            <label
                                v-for="(label, key) in available_features"
                                :key="key"
                                class="flex items-center gap-2 text-sm"
                            >
                                <Checkbox
                                    :model-value="
                                        form.features.includes(String(key))
                                    "
                                    @update:model-value="
                                        (v) =>
                                            toggleFeature(
                                                String(key),
                                                v === true,
                                            )
                                    "
                                />
                                {{ label }}
                            </label>
                        </div>
                        <InputError :message="form.errors.features" />
                    </div>

                    <div class="flex items-center gap-2">
                        <Checkbox
                            id="plan-active"
                            :model-value="form.is_active"
                            @update:model-value="
                                (v) => (form.is_active = v === true)
                            "
                        />
                        <Label for="plan-active">Active</Label>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="showFormDialog = false"
                        >
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="form.processing">
                            {{
                                form.processing
                                    ? 'Saving...'
                                    : editingPlan
                                      ? 'Update Plan'
                                      : 'Create Plan'
                            }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
