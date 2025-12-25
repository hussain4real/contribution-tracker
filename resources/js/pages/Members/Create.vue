<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, Form } from '@inertiajs/vue3';
import { type BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { index, store } from '@/actions/App/Http/Controllers/MemberController';
import { ref, computed } from 'vue';

interface CategoryOption {
    value: string;
    label: string;
    amount: number;
}

interface RoleOption {
    value: string;
    label: string;
}

interface Props {
    categories: CategoryOption[];
    roles: RoleOption[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Members',
        href: index().url,
    },
    {
        title: 'Add Member',
        href: '#',
    },
];

const selectedCategory = ref('member');
const selectedRole = ref('member');

const selectedCategoryAmount = computed(() => {
    const category = props.categories.find(c => c.value === selectedCategory.value);
    return category ? category.amount : 0;
});

function formatCurrency(kobo: number): string {
    const naira = kobo / 100;
    return new Intl.NumberFormat('en-NG', {
        style: 'currency',
        currency: 'NGN',
    }).format(naira);
}
</script>

<template>
    <Head title="Add Member" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
            <div class="mx-auto w-full max-w-2xl">
                <div class="rounded-xl border border-sidebar-border/70 bg-white p-6 dark:border-sidebar-border dark:bg-neutral-900">
                    <HeadingSmall
                        title="Add New Member"
                        description="Create a new family member account"
                    />

                    <Form
                        v-bind="store.form()"
                        class="mt-6 space-y-6"
                        #default="{ errors, processing, recentlySuccessful }"
                    >
                        <div class="grid gap-2">
                            <Label for="name">Full Name</Label>
                            <Input
                                id="name"
                                name="name"
                                type="text"
                                required
                                autocomplete="name"
                                placeholder="Enter full name"
                            />
                            <InputError :message="errors.name" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="email">Email Address</Label>
                            <Input
                                id="email"
                                name="email"
                                type="email"
                                required
                                autocomplete="email"
                                placeholder="email@example.com"
                            />
                            <InputError :message="errors.email" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="password">Password</Label>
                            <Input
                                id="password"
                                name="password"
                                type="password"
                                required
                                autocomplete="new-password"
                                placeholder="Create a password"
                            />
                            <InputError :message="errors.password" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="password_confirmation">Confirm Password</Label>
                            <Input
                                id="password_confirmation"
                                name="password_confirmation"
                                type="password"
                                required
                                autocomplete="new-password"
                                placeholder="Confirm password"
                            />
                        </div>

                        <div class="grid gap-2">
                            <Label for="category">Member Category</Label>
                            <select
                                id="category"
                                name="category"
                                v-model="selectedCategory"
                                required
                                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <option value="" disabled>Select a category</option>
                                <option
                                    v-for="category in categories"
                                    :key="category.value"
                                    :value="category.value"
                                >
                                    {{ category.label }} ({{ formatCurrency(category.amount) }}/month)
                                </option>
                            </select>
                            <p class="text-sm text-muted-foreground">
                                Monthly contribution: <strong>{{ formatCurrency(selectedCategoryAmount) }}</strong>
                            </p>
                            <InputError :message="errors.category" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="role">Role</Label>
                            <select
                                id="role"
                                name="role"
                                v-model="selectedRole"
                                required
                                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <option value="" disabled>Select a role</option>
                                <option
                                    v-for="role in roles"
                                    :key="role.value"
                                    :value="role.value"
                                >
                                    {{ role.label }}
                                </option>
                            </select>
                            <InputError :message="errors.role" />
                        </div>

                        <div class="flex items-center gap-4">
                            <Button
                                type="submit"
                                :disabled="processing"
                            >
                                {{ processing ? 'Creating...' : 'Create Member' }}
                            </Button>
                            <Link :href="index().url">
                                <Button variant="outline" type="button">
                                    Cancel
                                </Button>
                            </Link>
                        </div>

                        <Transition
                            enter-active-class="transition ease-in-out"
                            enter-from-class="opacity-0"
                            leave-active-class="transition ease-in-out"
                            leave-to-class="opacity-0"
                        >
                            <p
                                v-show="recentlySuccessful"
                                class="text-sm text-green-600"
                            >
                                Member created successfully.
                            </p>
                        </Transition>
                    </Form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
