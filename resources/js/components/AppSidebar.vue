<script setup lang="ts">
import { index as aiIndex } from '@/actions/App/Http/Controllers/AiChatController';
import { my as myContributions } from '@/actions/App/Http/Controllers/ContributionController';
import { index as expensesIndex } from '@/actions/App/Http/Controllers/ExpenseController';
import { edit as familySettings } from '@/actions/App/Http/Controllers/FamilySettingsController';
import { index as fundAdjustmentsIndex } from '@/actions/App/Http/Controllers/FundAdjustmentController';
import { index as invitationsIndex } from '@/actions/App/Http/Controllers/InvitationController';
import { index as membersIndex } from '@/actions/App/Http/Controllers/MemberController';
import { show as payContributions } from '@/actions/App/Http/Controllers/MemberPaymentController';
import {
    index as platformDashboard,
    families as platformFamilies,
    users as platformUsers,
} from '@/actions/App/Http/Controllers/PlatformAdminController';
import { index as platformFeatureFlags } from '@/actions/App/Http/Controllers/PlatformFeatureFlagController';
import { index as platformPlans } from '@/actions/App/Http/Controllers/PlatformPlanController';
import { index as reportsIndex } from '@/actions/App/Http/Controllers/ReportController';
import { index as subscriptionIndex } from '@/actions/App/Http/Controllers/SubscriptionController';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import {
    Building2,
    CreditCard,
    FileBarChart2,
    FileText,
    Globe,
    Landmark,
    Layers,
    LayoutGrid,
    Mail,
    MessageSquare,
    Receipt,
    Rocket,
    Settings,
    Shield,
    Sparkles,
    ToggleLeft,
    Users,
    UsersRound,
    Wallet,
} from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const page = usePage();

const can = computed(() => page.props.auth?.can);

const mainNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [
        {
            title: 'Dashboard',
            href: dashboard(),
            icon: LayoutGrid,
            component: 'Dashboard/Index',
        },
        {
            title: 'Members',
            href: membersIndex(),
            icon: Users,
            component: 'Members/Index',
        },
        {
            title: 'My Contributions',
            href: myContributions(),
            icon: Wallet,
            component: 'Contributions/My',
        },
        {
            title: 'Pay Contributions',
            href: payContributions(),
            icon: CreditCard,
            component: 'Pay/Index',
        },
        {
            title: 'Expenses',
            href: expensesIndex(),
            icon: Receipt,
            component: 'Expenses/Index',
        },
        {
            title: 'Fund Adjustments',
            href: fundAdjustmentsIndex(),
            icon: Landmark,
            component: 'FundAdjustments/Index',
        },
    ];

    if (page.props.featureFlags?.ai_assistant) {
        items.push({
            title: 'AI Assistant',
            href: aiIndex(),
            icon: MessageSquare,
            component: 'Ai/Chat',
        });
    }

    if (can.value?.generate_reports) {
        items.push({
            title: 'Reports',
            href: reportsIndex(),
            icon: FileBarChart2,
            component: 'Reports/Index',
        });
    }

    items.push({
        title: "What's New",
        href: '/changelog',
        icon: Rocket,
        component: 'Changelog/Index',
    });

    return items;
});

const adminNavItems = computed<NavItem[]>(() => {
    if (page.props.auth?.user?.role !== 'admin') return [];

    return [
        {
            title: 'Family Settings',
            href: familySettings(),
            icon: Settings,
            component: 'Family/Settings',
        },
        {
            title: 'Invitations',
            href: invitationsIndex(),
            icon: Mail,
            component: 'Family/Invitations',
        },
        {
            title: 'Subscription',
            href: subscriptionIndex(),
            icon: Sparkles,
            component: 'Subscription/Index',
        },
    ];
});

const platformNavItems = computed<NavItem[]>(() => {
    if (!page.props.auth?.user?.is_super_admin) return [];

    return [
        {
            title: 'Platform Admin',
            href: platformDashboard(),
            icon: Globe,
            component: 'Platform/Dashboard',
        },
        {
            title: 'All Families',
            href: platformFamilies(),
            icon: Building2,
            component: 'Platform/Families',
        },
        {
            title: 'All Users',
            href: platformUsers(),
            icon: UsersRound,
            component: 'Platform/Users',
        },
        {
            title: 'Plans',
            href: platformPlans(),
            icon: Layers,
            component: 'Platform/Plans',
        },
        {
            title: 'Feature Flags',
            href: platformFeatureFlags(),
            icon: ToggleLeft,
            component: 'Platform/FeatureFlags',
        },
    ];
});

const footerNavItems: NavItem[] = [
    {
        title: 'Privacy Policy',
        href: '/privacy',
        icon: Shield,
    },
    {
        title: 'Terms of Service',
        href: '/terms',
        icon: FileText,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
            <NavMain
                v-if="adminNavItems.length"
                :items="adminNavItems"
                label="Family Admin"
            />
            <NavMain
                v-if="platformNavItems.length"
                :items="platformNavItems"
                label="Super Admin"
            />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
