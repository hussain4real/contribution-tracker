<script setup lang="ts">
import {
    index,
    show,
    update,
} from '@/actions/App/Http/Controllers/MemberController';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { useCurrencyFormatter } from '@/lib/currency';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

interface Member {
    id: number;
    name: string;
    email: string;
    role: string;
    family_category_id: number | null;
}

interface CategoryOption {
    value: number;
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
        href: show({ member: props.member.id }).url,
    },
    {
        title: 'Edit',
        href: '#',
    },
];

const selectedCategory = ref<number | null>(
    props.member.family_category_id ?? props.categories[0]?.value ?? null,
);
const selectedRole = ref(props.member.role);
const showRoleConfirmDialog = ref(false);
const processing = ref(false);
const errors = reactive<Record<string, string>>({});

const formData = reactive({
    display_name: props.member.name,
    effective_immediately: false,
});

const selectedCategoryAmount = computed(() => {
    const category = props.categories.find(
        (c) => c.value === selectedCategory.value,
    );
    return category ? category.amount : 0;
});

const roleHasChanged = computed(() => selectedRole.value !== props.member.role);

const oldRoleLabel = computed(() => {
    const role = props.roles.find((r) => r.value === props.member.role);
    return role ? role.label : props.member.role;
});

const newRoleLabel = computed(() => {
    const role = props.roles.find((r) => r.value === selectedRole.value);
    return role ? role.label : selectedRole.value;
});

const { formatCurrency } = useCurrencyFormatter();

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

    router.put(
        update({ member: props.member.id }).url,
        {
            display_name: formData.display_name,
            family_category_id: selectedCategory.value,
            role: selectedRole.value,
            effective_immediately: formData.effective_immediately,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                processing.value = false;
            },
            onError: (newErrors) => {
                processing.value = false;
                Object.assign(errors, newErrors);
            },
        },
    );
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
                <div
                    class="rounded-xl border border-sidebar-border/70 bg-white p-6 dark:border-sidebar-border dark:bg-neutral-900"
                >
                    <HeadingSmall
                        title="Edit Member"
                        :description="`Update information for ${member.name}`"
                    />

                    <form @submit.prevent="handleSubmit" class="mt-6 space-y-6">
                        <div class="grid gap-2">
                            <Label for="display_name"
                                >Family Display Name</Label
                            >
                            <Input
                                id="display_name"
                                v-model="formData.display_name"
                                type="text"
                                required
                                autocomplete="name"
                                placeholder="Enter full name"
                            />
                            <InputError :message="errors.display_name" />
                            <p class="text-sm text-muted-foreground">
                                This name is used only in this family. The
                                member controls their account email and
                                password.
                            </p>
                        </div>

                        <div class="grid gap-2">
                            <Label for="family_category_id"
                                >Member Category</Label
                            >
                            <select
                                id="family_category_id"
                                name="family_category_id"
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
                            <p
                                class="text-xs text-amber-600 dark:text-amber-400"
                            >
                                Category changes take effect next month unless
                                you choose the immediate option below.
                            </p>
                            <InputError :message="errors.family_category_id" />
                        </div>

                        <label
                            class="flex items-start gap-3 rounded-lg border p-4"
                        >
                            <input
                                v-model="formData.effective_immediately"
                                type="checkbox"
                                class="mt-1"
                            />
                            <span>
                                <span class="block text-sm font-medium"
                                    >Apply category change this month</span
                                >
                                <span
                                    class="block text-sm text-muted-foreground"
                                >
                                    Leave unchecked to apply it from the first
                                    day of next month.
                                </span>
                            </span>
                        </label>

                        <div class="grid gap-2">
                            <Label for="role">Role</Label>
                            <select
                                id="role"
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
                            <p
                                v-if="roleHasChanged"
                                class="text-xs text-amber-600 dark:text-amber-400"
                            >
                                ⚠️ Role will change from {{ oldRoleLabel }} to
                                {{ newRoleLabel }}
                            </p>
                            <InputError :message="errors.role" />
                        </div>

                        <div class="flex items-center gap-4">
                            <Button type="submit" :disabled="processing">
                                {{ processing ? 'Saving...' : 'Save Changes' }}
                            </Button>
                            <Link :href="show({ member: member.id }).url">
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
        <Dialog
            :open="showRoleConfirmDialog"
            @update:open="
                (val) => {
                    if (!val) cancelRoleChange();
                }
            "
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Confirm Role Change</DialogTitle>
                    <DialogDescription>
                        <p class="mb-2">
                            You are about to change
                            <strong>{{ member.name }}'s</strong> role from
                            <strong>{{ oldRoleLabel }}</strong> to
                            <strong>{{ newRoleLabel }}</strong
                            >.
                        </p>
                        <p class="text-amber-600 dark:text-amber-400">
                            This will affect their permissions and access within
                            the system.
                        </p>
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" @click="cancelRoleChange"
                        >Cancel</Button
                    >
                    <Button @click="confirmRoleChange">Confirm Change</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
