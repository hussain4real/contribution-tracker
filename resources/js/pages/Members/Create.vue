<script setup lang="ts">
import { index, store } from '@/actions/App/Http/Controllers/MemberController';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowUpCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';

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
    const category = props.categories.find(
        (c) => c.value === selectedCategory.value,
    );
    return category ? category.amount : 0;
});

const page = usePage();
const canAddMore = computed(() => page.props.subscription?.can_add_members ?? true);
const subscription = computed(() => page.props.subscription);

function formatCurrency(amount: number): string {
    return new Intl.NumberFormat('en-NG', {
        style: 'currency',
        currency: 'NGN',
    }).format(amount);
}
</script>

<template>
    <Head title="Add Member" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
            <div class="mx-auto w-full max-w-2xl">
                <!-- Member limit reached -->
                <div
                    v-if="!canAddMore"
                    class="rounded-xl border border-amber-200 bg-amber-50 p-6 dark:border-amber-800 dark:bg-amber-950"
                >
                    <h2 class="text-lg font-semibold text-amber-800 dark:text-amber-200">Member Limit Reached</h2>
                    <p class="mt-2 text-sm text-amber-700 dark:text-amber-300">
                        Your plan allows up to {{ subscription?.max_members }} members and you currently have {{ subscription?.member_count }}. Upgrade your plan to add more members.
                    </p>
                    <div class="mt-4 flex gap-3">
                        <Link href="/subscription">
                            <Button>
                                <ArrowUpCircle class="mr-2 h-4 w-4" />
                                Upgrade Plan
                            </Button>
                        </Link>
                        <Link :href="index().url">
                            <Button variant="outline">Back to Members</Button>
                        </Link>
                    </div>
                </div>

                <div
                    v-else
                    class="rounded-xl border border-sidebar-border/70 bg-white p-6 dark:border-sidebar-border dark:bg-neutral-900"
                >
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
                            <Label for="password_confirmation"
                                >Confirm Password</Label
                            >
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
                                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <option value="" disabled>
                                    Select a category
                                </option>
                                <option
                                    v-for="category in categories"
                                    :key="category.value"
                                    :value="category.value"
                                >
                                    {{ category.label }} ({{
                                        formatCurrency(category.amount)
                                    }}/month)
                                </option>
                            </select>
                            <p class="text-sm text-muted-foreground">
                                Monthly contribution:
                                <strong>{{
                                    formatCurrency(selectedCategoryAmount)
                                }}</strong>
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
                                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
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
                            <Button type="submit" :disabled="processing">
                                {{
                                    processing ? 'Creating...' : 'Create Member'
                                }}
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
