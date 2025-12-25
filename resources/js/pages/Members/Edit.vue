<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, Form } from '@inertiajs/vue3';
import { type BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { index, show, update } from '@/actions/App/Http/Controllers/MemberController';
import { ref, computed } from 'vue';

interface Member {
    id: number;
    name: string;
    email: string;
    role: string;
    category: string | null;
}

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
    member: Member;
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
        title: props.member.name,
        href: show(props.member.id).url,
    },
    {
        title: 'Edit',
        href: '#',
    },
];

const selectedCategory = ref(props.member.category ?? 'employed');
const selectedRole = ref(props.member.role);

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
    <Head :title="`Edit ${member.name}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
            <div class="mx-auto w-full max-w-2xl">
                <div class="rounded-xl border border-sidebar-border/70 bg-white p-6 dark:border-sidebar-border dark:bg-neutral-900">
                    <HeadingSmall
                        title="Edit Member"
                        :description="`Update information for ${member.name}`"
                    />

                    <Form
                        v-bind="update.form(member.id)"
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
                                :default-value="member.name"
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
                                :default-value="member.email"
                                placeholder="email@example.com"
                            />
                            <InputError :message="errors.email" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="password">New Password (optional)</Label>
                            <Input
                                id="password"
                                name="password"
                                type="password"
                                autocomplete="new-password"
                                placeholder="Leave blank to keep current"
                            />
                            <InputError :message="errors.password" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="password_confirmation">Confirm New Password</Label>
                            <Input
                                id="password_confirmation"
                                name="password_confirmation"
                                type="password"
                                autocomplete="new-password"
                                placeholder="Confirm new password"
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
                            <p class="text-xs text-amber-600 dark:text-amber-400">
                                Note: Category changes take effect from the next month.
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
                                {{ processing ? 'Saving...' : 'Save Changes' }}
                            </Button>
                            <Link :href="show(member.id).url">
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
                                Member updated successfully.
                            </p>
                        </Transition>
                    </Form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
