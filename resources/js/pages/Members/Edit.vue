<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { type BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { index, show, update } from '@/actions/App/Http/Controllers/MemberController';
import { ref, computed, reactive } from 'vue';

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
const showRoleConfirmDialog = ref(false);
const processing = ref(false);
const errors = reactive<Record<string, string>>({});

const formData = reactive({
    name: props.member.name,
    email: props.member.email,
    password: '',
    password_confirmation: '',
});

const selectedCategoryAmount = computed(() => {
    const category = props.categories.find(c => c.value === selectedCategory.value);
    return category ? category.amount : 0;
});

const roleHasChanged = computed(() => selectedRole.value !== props.member.role);

const oldRoleLabel = computed(() => {
    const role = props.roles.find(r => r.value === props.member.role);
    return role ? role.label : props.member.role;
});

const newRoleLabel = computed(() => {
    const role = props.roles.find(r => r.value === selectedRole.value);
    return role ? role.label : selectedRole.value;
});

function formatCurrency(kobo: number): string {
    const naira = kobo / 100;
    return new Intl.NumberFormat('en-NG', {
        style: 'currency',
        currency: 'NGN',
    }).format(naira);
}

function handleSubmit() {
    // If role changed, show confirmation dialog first
    if (roleHasChanged.value) {
        showRoleConfirmDialog.value = true;
        return;
    }

    // Otherwise submit directly
    submitForm();
}

function submitForm() {
    processing.value = true;

    router.put(update(props.member.id).url, {
        name: formData.name,
        email: formData.email,
        password: formData.password || undefined,
        password_confirmation: formData.password_confirmation || undefined,
        category: selectedCategory.value,
        role: selectedRole.value,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            processing.value = false;
        },
        onError: (newErrors) => {
            processing.value = false;
            Object.assign(errors, newErrors);
        },
    });
}

function confirmRoleChange() {
    showRoleConfirmDialog.value = false;
    submitForm();
}

function cancelRoleChange() {
    showRoleConfirmDialog.value = false;
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

                    <form
                        @submit.prevent="handleSubmit"
                        class="mt-6 space-y-6"
                    >
                        <div class="grid gap-2">
                            <Label for="name">Full Name</Label>
                            <Input
                                id="name"
                                v-model="formData.name"
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
                                v-model="formData.email"
                                type="email"
                                required
                                autocomplete="email"
                                placeholder="email@example.com"
                            />
                            <InputError :message="errors.email" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="password">New Password (optional)</Label>
                            <Input
                                id="password"
                                v-model="formData.password"
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
                                v-model="formData.password_confirmation"
                                type="password"
                                autocomplete="new-password"
                                placeholder="Confirm new password"
                            />
                        </div>

                        <div class="grid gap-2">
                            <Label for="category">Member Category</Label>
                            <select
                                id="category"
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
                            <p v-if="roleHasChanged" class="text-xs text-amber-600 dark:text-amber-400">
                                ⚠️ Role will change from {{ oldRoleLabel }} to {{ newRoleLabel }}
                            </p>
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
                    </form>
                </div>
            </div>
        </div>

        <!-- Role Change Confirmation Dialog -->
        <Dialog :open="showRoleConfirmDialog" @update:open="(val) => { if (!val) cancelRoleChange(); }">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Confirm Role Change</DialogTitle>
                    <DialogDescription>
                        <p class="mb-2">
                            You are about to change <strong>{{ member.name }}'s</strong> role from
                            <strong>{{ oldRoleLabel }}</strong> to <strong>{{ newRoleLabel }}</strong>.
                        </p>
                        <p class="text-amber-600 dark:text-amber-400">
                            This will affect their permissions and access within the system.
                        </p>
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" @click="cancelRoleChange">Cancel</Button>
                    <Button @click="confirmRoleChange">Confirm Change</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
