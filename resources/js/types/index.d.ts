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

export interface FeatureFlags {
    ai_assistant: boolean;
}

export interface Subscription {
    plan_name: string | null;
    member_count: number;
    max_members: number | null;
    can_add_members: boolean;
    features: string[];
}

export interface WebPush {
    enabled: boolean;
    publicKey: string | null;
    subscribed: boolean;
}

export interface ChangelogRelease {
    id: number;
    name: string;
    tag_name: string;
    body: string;
    html_url: string;
    published_at: string;
    prerelease: boolean;
}

export interface ChangelogUpdate {
    latest: ChangelogRelease | null;
    unseen: boolean;
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
    featureFlags?: FeatureFlags | null;
    subscription?: Subscription | null;
    webPush?: WebPush | null;
    changelogUpdate?: ChangelogUpdate | null;
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
    whatsapp_phone: string | null;
    whatsapp_verified_at: string | null;
    created_at?: string;
    updated_at?: string;
}

export type BreadcrumbItemType = BreadcrumbItem;
