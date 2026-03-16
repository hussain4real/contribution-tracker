<script setup lang="ts">
import {
    destroy,
    restore,
    show,
} from '@/actions/App/Http/Controllers/MemberController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Link, router } from '@inertiajs/vue3';
import { Archive, Eye, Pencil, RotateCcw } from 'lucide-vue-next';

interface Member {
    id: number;
    name: string;
    email: string;
    role: string;
    role_label: string;
    category: string | null;
    category_label: string | null;
    monthly_amount: number;
    is_archived: boolean;
    archived_at?: string;
}

interface Props {
    member: Member;
    canManageMembers: boolean;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    archived: [member: Member];
    restored: [member: Member];
}>();

function formatCurrency(amount: number): string {
    return new Intl.NumberFormat('en-NG', {
        style: 'currency',
        currency: 'NGN',
    }).format(amount);
}

function getRoleBadgeVariant(role: string) {
    switch (role) {
        case 'super_admin':
            return 'default';
        case 'financial_secretary':
            return 'secondary';
        default:
            return 'outline';
    }
}

function archiveMember() {
    if (confirm(`Are you sure you want to archive ${props.member.name}?`)) {
        router
            .optimistic((pageProps: any) => ({
                members: pageProps.members.filter(
                    (m: any) => m.id !== props.member.id,
                ),
                archivedMembers: [
                    ...pageProps.archivedMembers,
                    { ...props.member, is_archived: true },
                ],
            }))
            .delete(destroy(props.member.id).url, {
                onSuccess: () => emit('archived', props.member),
            });
    }
}

function restoreMember() {
    if (confirm(`Are you sure you want to restore ${props.member.name}?`)) {
        router
            .optimistic((pageProps: any) => ({
                archivedMembers: pageProps.archivedMembers.filter(
                    (m: any) => m.id !== props.member.id,
                ),
                members: [
                    ...pageProps.members,
                    {
                        ...props.member,
                        is_archived: false,
                        archived_at: undefined,
                    },
                ],
            }))
            .post(
                restore(props.member.id).url,
                {},
                {
                    onSuccess: () => emit('restored', props.member),
                },
            );
    }
}
</script>

<template>
    <tr
        class="border-b border-neutral-100 hover:bg-neutral-50 dark:border-neutral-800 dark:hover:bg-neutral-800/50"
    >
        <td class="px-6 py-4">
            <div class="font-medium text-neutral-900 dark:text-neutral-100">
                {{ member.name }}
            </div>
            <div
                v-if="member.archived_at"
                class="text-xs text-red-500 dark:text-red-400"
            >
                Archived {{ member.archived_at }}
            </div>
        </td>
        <td class="px-6 py-4 text-neutral-600 dark:text-neutral-400">
            {{ member.email }}
        </td>
        <td class="px-6 py-4">
            <Badge :variant="getRoleBadgeVariant(member.role)">
                {{ member.role_label }}
            </Badge>
        </td>
        <td class="px-6 py-4 text-neutral-600 dark:text-neutral-400">
            {{ member.category_label ?? '—' }}
        </td>
        <td
            class="px-6 py-4 text-right font-medium text-neutral-900 dark:text-neutral-100"
        >
            {{ formatCurrency(member.monthly_amount) }}
        </td>
        <td class="px-6 py-4">
            <div class="flex items-center justify-end gap-2">
                <Link :href="show(member.id).url">
                    <Button variant="ghost" size="icon" title="View">
                        <Eye class="h-4 w-4" />
                    </Button>
                </Link>
                <template v-if="canManageMembers">
                    <template v-if="!member.is_archived">
                        <Link :href="`/members/${member.id}/edit`">
                            <Button variant="ghost" size="icon" title="Edit">
                                <Pencil class="h-4 w-4" />
                            </Button>
                        </Link>
                        <Button
                            variant="ghost"
                            size="icon"
                            title="Archive"
                            @click="archiveMember"
                        >
                            <Archive class="h-4 w-4 text-amber-600" />
                        </Button>
                    </template>
                    <template v-else>
                        <Button
                            variant="ghost"
                            size="icon"
                            title="Restore"
                            @click="restoreMember"
                        >
                            <RotateCcw class="h-4 w-4 text-green-600" />
                        </Button>
                    </template>
                </template>
            </div>
        </td>
    </tr>
</template>
