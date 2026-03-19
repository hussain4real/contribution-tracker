<script setup lang="ts">
import { edit } from '@/actions/App/Http/Controllers/FamilySettingsController';
import Heading from '@/components/Heading.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Form, Head, router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';

interface Category {
    id: number;
    name: string;
    monthly_amount: number;
    sort_order: number;
    members_count: number;
}

interface Props {
    family?: {
        id: number;
        name: string;
        currency: string;
        due_day: number;
        bank_name: string | null;
        account_name: string | null;
        account_number: string | null;
    };
    categories?: Category[];
}

const props = withDefaults(defineProps<Props>(), {
    family: () => ({
        id: 0,
        name: '',
        currency: '',
        due_day: 28,
        bank_name: null,
        account_name: null,
        account_number: null,
    }),
    categories: () => [],
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Family Settings', href: edit().url },
];

const editingCategory = ref<Category | null>(null);
const showNewCategoryForm = ref(false);

function formatCurrency(amount: number): string {
    return `${props.family.currency}${amount.toLocaleString()}`;
}

function deleteCategory(id: number): void {
    if (confirm('Are you sure you want to delete this category?')) {
        router.delete(route('family.categories.destroy', id));
    }
}

function startEditCategory(category: Category): void {
    editingCategory.value = { ...category };
}

function cancelEdit(): void {
    editingCategory.value = null;
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Family Settings" />

        <div class="mx-auto w-full max-w-3xl space-y-8 p-6">
            <Heading
                title="Family Settings"
                description="Manage your family group settings, categories, and contribution amounts."
            />

            <!-- General Settings -->
            <section>
                <HeadingSmall
                    title="General"
                    description="Update your family group name, currency, and due date."
                />

                <Form
                    action="/family/settings"
                    method="put"
                    #default="{ errors, processing, recentlySuccessful }"
                    class="mt-4 space-y-4"
                >
                    <div class="grid gap-2">
                        <Label for="name">Family Name</Label>
                        <Input
                            id="name"
                            name="name"
                            type="text"
                            :default-value="family.name"
                            required
                        />
                        <InputError :message="errors.name" />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="grid gap-2">
                            <Label for="currency">Currency Symbol</Label>
                            <Input
                                id="currency"
                                name="currency"
                                type="text"
                                :default-value="family.currency"
                                required
                            />
                            <InputError :message="errors.currency" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="due_day">Due Day of Month</Label>
                            <Input
                                id="due_day"
                                name="due_day"
                                type="number"
                                :default-value="String(family.due_day)"
                                min="1"
                                max="28"
                                required
                            />
                            <InputError :message="errors.due_day" />
                        </div>
                    </div>

                    <!-- Bank Details -->
                    <div class="border-t pt-4">
                        <p class="mb-3 text-sm font-medium">
                            Bank Details
                            <span class="text-muted-foreground"
                                >(shown to members for contributions)</span
                            >
                        </p>
                        <div class="grid gap-4 sm:grid-cols-3">
                            <div class="grid gap-2">
                                <Label for="bank_name">Bank Name</Label>
                                <Input
                                    id="bank_name"
                                    name="bank_name"
                                    type="text"
                                    :default-value="family.bank_name ?? ''"
                                    placeholder="e.g. GTBank"
                                />
                                <InputError :message="errors.bank_name" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="account_name">Account Name</Label>
                                <Input
                                    id="account_name"
                                    name="account_name"
                                    type="text"
                                    :default-value="family.account_name ?? ''"
                                    placeholder="e.g. John Doe"
                                />
                                <InputError :message="errors.account_name" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="account_number"
                                    >Account Number</Label
                                >
                                <Input
                                    id="account_number"
                                    name="account_number"
                                    type="text"
                                    :default-value="family.account_number ?? ''"
                                    placeholder="e.g. 0123456789"
                                />
                                <InputError :message="errors.account_number" />
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-4">
                        <Button type="submit" :disabled="processing"
                            >Save Changes</Button
                        >
                        <span
                            v-if="recentlySuccessful"
                            class="text-sm text-green-600"
                            >Saved.</span
                        >
                    </div>
                </Form>
            </section>

            <!-- Categories -->
            <section>
                <HeadingSmall
                    title="Contribution Categories"
                    description="Define the categories and monthly amounts for your family members."
                />

                <div class="mt-4 space-y-3">
                    <div
                        v-for="category in categories"
                        :key="category.id"
                        class="flex items-center justify-between rounded-lg border p-4"
                    >
                        <template v-if="editingCategory?.id === category.id">
                            <Form
                                :action="`/family/categories/${category.id}`"
                                method="put"
                                #default="{ errors, processing }"
                                class="flex flex-1 items-end gap-3"
                            >
                                <div class="grid flex-1 gap-1">
                                    <Label :for="`edit-name-${category.id}`"
                                        >Name</Label
                                    >
                                    <Input
                                        :id="`edit-name-${category.id}`"
                                        name="name"
                                        type="text"
                                        :value="editingCategory.name"
                                        required
                                    />
                                    <InputError :message="errors.name" />
                                </div>
                                <div class="grid w-40 gap-1">
                                    <Label :for="`edit-amount-${category.id}`"
                                        >Monthly Amount</Label
                                    >
                                    <Input
                                        :id="`edit-amount-${category.id}`"
                                        name="monthly_amount"
                                        type="number"
                                        :value="
                                            String(
                                                editingCategory.monthly_amount,
                                            )
                                        "
                                        min="0"
                                        required
                                    />
                                    <InputError
                                        :message="errors.monthly_amount"
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    size="sm"
                                    :disabled="processing"
                                    >Save</Button
                                >
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    @click="cancelEdit"
                                    >Cancel</Button
                                >
                            </Form>
                        </template>
                        <template v-else>
                            <div>
                                <p class="font-medium">{{ category.name }}</p>
                                <p class="text-sm text-muted-foreground">
                                    {{
                                        formatCurrency(category.monthly_amount)
                                    }}
                                    / month &middot;
                                    {{ category.members_count }} member{{
                                        category.members_count !== 1 ? 's' : ''
                                    }}
                                </p>
                            </div>
                            <div class="flex items-center gap-1">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    @click="startEditCategory(category)"
                                >
                                    <Pencil class="h-4 w-4" />
                                </Button>
                                <Button
                                    v-if="category.members_count === 0"
                                    variant="ghost"
                                    size="icon"
                                    class="text-destructive hover:text-destructive"
                                    @click="deleteCategory(category.id)"
                                >
                                    <Trash2 class="h-4 w-4" />
                                </Button>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Add New Category -->
                <div class="mt-4">
                    <Button
                        v-if="!showNewCategoryForm"
                        variant="outline"
                        size="sm"
                        @click="showNewCategoryForm = true"
                    >
                        <Plus class="mr-1 h-4 w-4" />
                        Add Category
                    </Button>

                    <Form
                        v-if="showNewCategoryForm"
                        action="/family/categories"
                        method="post"
                        #default="{ errors, processing }"
                        class="flex items-end gap-3 rounded-lg border p-4"
                        @success="showNewCategoryForm = false"
                    >
                        <div class="grid flex-1 gap-1">
                            <Label for="new-name">Name</Label>
                            <Input
                                id="new-name"
                                name="name"
                                type="text"
                                placeholder="e.g. Senior"
                                required
                            />
                            <InputError :message="errors.name" />
                        </div>
                        <div class="grid w-40 gap-1">
                            <Label for="new-amount">Monthly Amount</Label>
                            <Input
                                id="new-amount"
                                name="monthly_amount"
                                type="number"
                                placeholder="0"
                                min="0"
                                required
                            />
                            <InputError :message="errors.monthly_amount" />
                        </div>
                        <Button type="submit" size="sm" :disabled="processing"
                            >Add</Button
                        >
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="showNewCategoryForm = false"
                            >Cancel</Button
                        >
                    </Form>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
