<script setup lang="ts">
import {
    destroy,
    index,
} from '@/actions/App/Http/Controllers/InvitationController';
import Heading from '@/components/Heading.vue';
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
import { type BreadcrumbItem } from '@/types';
import { Form, Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowUpCircle, Mail, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Invitation {
    id: number;
    email: string;
    role: string;
    role_label: string;
    invited_by: string | null;
    is_accepted: boolean;
    is_expired: boolean;
    is_pending: boolean;
    expires_at: string;
    created_at: string;
}

interface Props {
    invitations?: Invitation[];
    family_name?: string;
}

const props = withDefaults(defineProps<Props>(), {
    invitations: () => [],
    family_name: '',
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Invitations', href: index().url },
];

const showInviteForm = ref(false);
const showDeleteDialog = ref(false);
const deletingInvitation = ref<Invitation | null>(null);
const deleting = ref(false);

const page = usePage();
const subscription = computed(() => page.props.subscription);
const canAddMore = computed(() => subscription.value?.can_add_members ?? true);

function promptDelete(invitation: Invitation): void {
    deletingInvitation.value = invitation;
    showDeleteDialog.value = true;
}

function confirmDelete(): void {
    if (!deletingInvitation.value) return;

    deleting.value = true;
    router.delete(destroy(deletingInvitation.value.id).url, {
        preserveScroll: true,
        onFinish: () => {
            deleting.value = false;
            showDeleteDialog.value = false;
            deletingInvitation.value = null;
        },
    });
}

function statusBadge(invitation: Invitation): { text: string; class: string } {
    if (invitation.is_accepted)
        return {
            text: 'Accepted',
            class: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
        };
    if (invitation.is_expired)
        return {
            text: 'Expired',
            class: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        };
    return {
        text: 'Pending',
        class: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
    };
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Invitations" />

        <div class="mx-auto w-full max-w-3xl space-y-6 p-6">
            <div class="flex items-center justify-between space-x-6">
                <Heading
                    title="Invitations"
                    :description="`Invite new members to join ${props.family_name}.`"
                />
                <Button v-if="!showInviteForm && canAddMore" @click="showInviteForm = true">
                    <Plus class="mr-1 h-4 w-4" />
                    Invite Member
                </Button>
            </div>

            <!-- Member limit banner -->
            <div
                v-if="!canAddMore"
                class="flex items-center justify-between rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-800 dark:bg-amber-950"
            >
                <p class="text-sm text-amber-800 dark:text-amber-200">
                    {{ subscription?.member_count }}/{{ subscription?.max_members }} members — upgrade your plan to invite more.
                </p>
                <Link href="/subscription">
                    <Button size="sm" variant="outline" class="shrink-0 border-amber-300 text-amber-800 hover:bg-amber-100 dark:border-amber-700 dark:text-amber-200 dark:hover:bg-amber-900">
                        <ArrowUpCircle class="mr-1 h-4 w-4" />
                        Upgrade
                    </Button>
                </Link>
            </div>

            <!-- Invite Form -->
            <Form
                v-if="showInviteForm"
                action="/family/invitations"
                method="post"
                #default="{ errors, processing }"
                class="space-y-4 rounded-lg border p-4"
                @success="showInviteForm = false"
            >
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="email">Email Address</Label>
                        <Input
                            id="email"
                            name="email"
                            type="email"
                            placeholder="member@example.com"
                            required
                        />
                        <InputError :message="errors.email" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="role">Role</Label>
                        <select
                            id="role"
                            name="role"
                            class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs transition-colors placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                            required
                        >
                            <option value="member">Member</option>
                            <option value="financial_secretary">
                                Financial Secretary
                            </option>
                            <option value="admin">Admin</option>
                        </select>
                        <InputError :message="errors.role" />
                    </div>
                </div>
                <div class="flex gap-2">
                    <Button type="submit" :disabled="processing">
                        <Mail class="mr-1 h-4 w-4" />
                        Send Invitation
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="showInviteForm = false"
                        >Cancel</Button
                    >
                </div>
            </Form>

            <!-- Invitations List -->
            <div class="space-y-3">
                <div
                    v-for="invitation in invitations"
                    :key="invitation.id"
                    class="flex items-center justify-between rounded-lg border p-4"
                >
                    <div>
                        <p class="font-medium">{{ invitation.email }}</p>
                        <p class="text-sm text-muted-foreground">
                            {{ invitation.role_label }} &middot; Invited
                            {{ invitation.created_at }}
                            <template v-if="invitation.invited_by">
                                by {{ invitation.invited_by }}</template
                            >
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <span
                            class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                            :class="statusBadge(invitation).class"
                        >
                            {{ statusBadge(invitation).text }}
                        </span>
                        <Button
                            v-if="invitation.is_pending"
                            variant="ghost"
                            size="icon"
                            class="text-destructive hover:text-destructive"
                            @click="promptDelete(invitation)"
                        >
                            <Trash2 class="h-4 w-4" />
                        </Button>
                    </div>
                </div>

                <p
                    v-if="props.invitations.length === 0"
                    class="py-8 text-center text-muted-foreground"
                >
                    No invitations yet. Click "Invite Member" to get started.
                </p>
            </div>
        </div>

        <!-- Cancel Invitation Dialog -->
        <Dialog
            :open="showDeleteDialog"
            @update:open="
                (val) => {
                    if (!val) {
                        showDeleteDialog = false;
                        deletingInvitation = null;
                    }
                }
            "
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Cancel Invitation</DialogTitle>
                    <DialogDescription>
                        Are you sure you want to cancel the invitation to
                        <strong>{{ deletingInvitation?.email }}</strong
                        >? This action cannot be undone.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="outline"
                        @click="
                            showDeleteDialog = false;
                            deletingInvitation = null;
                        "
                        >Keep Invitation</Button
                    >
                    <Button
                        variant="destructive"
                        :disabled="deleting"
                        @click="confirmDelete"
                    >
                        {{ deleting ? 'Cancelling...' : 'Cancel Invitation' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
