import { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from 'lucide-vue-next';

export interface Auth {
    user: User | null;
    can: Permissions | null;
}

export interface Permissions {
    manage_members: boolean;
    record_payments: boolean;
    generate_reports: boolean;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
    component?: string;
}

export interface Family {
    id: number;
    name: string;
    currency: string;
    due_day: number;
}

export interface AppNotification {
    id: string;
    type: string;
    data: {
        contribution_id: number;
        family_name: string;
        period_label: string;
        amount_owed: number;
        due_date: string;
        type: 'reminder' | 'follow_up';
    };
    read_at: string | null;
    created_at: string;
}

export interface Notifications {
    unread_count: number;
    recent: AppNotification[];
}

export interface Flash {
    success?: string | null;
    error?: string | null;
    warning?: string | null;
}

export type AppPageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    family: Family | null;
    sidebarOpen: boolean;
    notifications: Notifications | null;
    flash?: Flash;
};

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    role: string;
    role_label: string;
    category: string | null;
    category_label: string | null;
    family_id: number | null;
    is_super_admin: boolean;
    created_at?: string;
    updated_at?: string;
}

export type BreadcrumbItemType = BreadcrumbItem;
