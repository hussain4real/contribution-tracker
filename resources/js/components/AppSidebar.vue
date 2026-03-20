<script setup lang="ts">
import { my as myContributions } from '@/actions/App/Http/Controllers/ContributionController';
import { index as expensesIndex } from '@/actions/App/Http/Controllers/ExpenseController';
import { edit as familySettings } from '@/actions/App/Http/Controllers/FamilySettingsController';
import { index as fundAdjustmentsIndex } from '@/actions/App/Http/Controllers/FundAdjustmentController';
import { index as invitationsIndex } from '@/actions/App/Http/Controllers/InvitationController';
import { index as membersIndex } from '@/actions/App/Http/Controllers/MemberController';
import {
    index as platformDashboard,
    families as platformFamilies,
    users as platformUsers,
} from '@/actions/App/Http/Controllers/PlatformAdminController';
import { index as reportsIndex } from '@/actions/App/Http/Controllers/ReportController';
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
    FileBarChart2,
    FileText,
    Globe,
    Landmark,
    LayoutGrid,
    Mail,
    Receipt,
    Rocket,
    Settings,
    Shield,
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
    ];

    // Members link - visible to all authenticated users
    items.push({
        title: 'Members',
        href: membersIndex(),
        icon: Users,
        component: 'Members/Index',
    });

    // My Contributions - visible to all authenticated users
    items.push({
        title: 'My Contributions',
        href: myContributions(),
        icon: Wallet,
        component: 'Contributions/My',
    });

    // Expenses - visible to all authenticated users
    items.push({
        title: 'Expenses',
        href: expensesIndex(),
        icon: Receipt,
        component: 'Expenses/Index',
    });

    // Fund Adjustments - visible to all authenticated users
    items.push({
        title: 'Fund Adjustments',
        href: fundAdjustmentsIndex(),
        icon: Landmark,
        component: 'FundAdjustments/Index',
    });

    // Reports - only for Financial Secretary and Admin
    if (can.value?.generate_reports) {
        items.push({
            title: 'Reports',
            href: reportsIndex(),
            icon: FileBarChart2,
            component: 'Reports/Index',
        });
    }

    // Family Settings - Admin only
    if (page.props.auth?.user?.role === 'admin') {
        items.push({
            title: 'Family Settings',
            href: familySettings(),
            icon: Settings,
            component: 'Family/Settings',
        });

        items.push({
            title: 'Invitations',
            href: invitationsIndex(),
            icon: Mail,
            component: 'Family/Invitations',
        });
    }

    // Platform Admin - super admin only
    if (page.props.auth?.user?.is_super_admin) {
        items.push(
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
        );
    }

    // What's New - visible to all authenticated users
    items.push({
        title: "What's New",
        href: '/changelog',
        icon: Rocket,
        component: 'Changelog/Index',
    });

    return items;
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
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
