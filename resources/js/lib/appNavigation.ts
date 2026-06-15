import { index as aiIndex } from '@/actions/App/Http/Controllers/AiChatController';
import { my as myContributions } from '@/actions/App/Http/Controllers/ContributionController';
import { index as expensesIndex } from '@/actions/App/Http/Controllers/ExpenseController';
import { edit as familySettings } from '@/actions/App/Http/Controllers/FamilySettingsController';
import { index as fundAdjustmentsIndex } from '@/actions/App/Http/Controllers/FundAdjustmentController';
import { index as invitationsIndex } from '@/actions/App/Http/Controllers/InvitationController';
import { index as membersIndex } from '@/actions/App/Http/Controllers/MemberController';
import { show as payContributions } from '@/actions/App/Http/Controllers/MemberPaymentController';
import { index as notificationsIndex } from '@/actions/App/Http/Controllers/NotificationController';
import { index as paymentsIndex } from '@/actions/App/Http/Controllers/PaymentController';
import { index as reportsIndex } from '@/actions/App/Http/Controllers/ReportController';
import { index as subscriptionIndex } from '@/actions/App/Http/Controllers/SubscriptionController';
import { index as whatsappInboxIndex } from '@/actions/App/Http/Controllers/WhatsAppInboxController';
import { urlIsActive } from '@/lib/utils';
import { dashboard } from '@/routes';
import { edit as editProfile } from '@/routes/profile';
import type { NavItem } from '@/types';
import { usePage } from '@inertiajs/vue3';
import {
    Bell,
    CreditCard,
    FileBarChart2,
    FileText,
    Globe,
    Landmark,
    LayoutGrid,
    Mail,
    MessageCircle,
    MessageSquare,
    Receipt,
    Rocket,
    Settings,
    Shield,
    Sparkles,
    User,
    Users,
    Wallet,
} from 'lucide-vue-next';
import { computed } from 'vue';

type NavigationGroup = {
    label?: string;
    items: NavItem[];
};

export function navItemIsActive(
    item: NavItem,
    currentUrl: string,
    currentComponent: string,
): boolean {
    if (urlIsActive(item.href, currentUrl, item.exact ?? false)) {
        return true;
    }

    if (!item.component) {
        return false;
    }

    return (
        currentComponent === item.component ||
        currentComponent.startsWith(`${item.component}/`)
    );
}

export function useAppNavigation() {
    const page = usePage();
    const subscriptionFeatures = computed(
        () => page.props.subscription?.features ?? [],
    );
    const hasSubscriptionFeature = (feature: string) =>
        subscriptionFeatures.value.includes(feature);

    const paymentItem = computed<NavItem>(() => {
        if (page.props.auth?.can?.record_payments) {
            return {
                title: 'Payments',
                href: paymentsIndex(),
                icon: CreditCard,
                component: 'Payments/Index',
                section: 'main',
            };
        }

        return {
            title: 'Pay',
            href: payContributions(),
            icon: CreditCard,
            component: 'Pay/Index',
            section: 'main',
        };
    });

    const primaryItems = computed<NavItem[]>(() => {
        const items: NavItem[] = [
            {
                title: 'Dashboard',
                href: dashboard(),
                icon: LayoutGrid,
                component: 'Dashboard/Index',
                section: 'main',
            },
            {
                title: 'Members',
                href: membersIndex(),
                icon: Users,
                component: 'Members/Index',
                section: 'main',
            },
            {
                title: 'My Contributions',
                href: myContributions(),
                icon: Wallet,
                component: 'Contributions/My',
                section: 'main',
            },
            paymentItem.value,
            {
                title: 'Notifications',
                href: notificationsIndex(),
                icon: Bell,
                component: 'Notifications/Index',
                badge: 'notifications',
                section: 'main',
            },
            {
                title: 'Expenses',
                href: expensesIndex(),
                icon: Receipt,
                component: 'Expenses/Index',
                section: 'main',
            },
            {
                title: 'Fund Adjustments',
                href: fundAdjustmentsIndex(),
                icon: Landmark,
                component: 'FundAdjustments/Index',
                section: 'main',
            },
        ];

        if (
            page.props.featureFlags?.ai_assistant &&
            hasSubscriptionFeature('ai_assistant')
        ) {
            items.push({
                title: 'AI Assistant',
                href: aiIndex(),
                icon: MessageSquare,
                component: 'Ai/Chat',
                section: 'main',
            });
        }

        if (
            page.props.auth?.can?.generate_reports &&
            hasSubscriptionFeature('reports')
        ) {
            items.push({
                title: 'Reports',
                href: reportsIndex(),
                icon: FileBarChart2,
                component: 'Reports/Index',
                section: 'main',
            });
        }

        if (
            page.props.auth?.can?.generate_reports &&
            hasSubscriptionFeature('whatsapp_messaging')
        ) {
            items.push({
                title: 'WhatsApp Inbox',
                href: whatsappInboxIndex(),
                icon: MessageCircle,
                component: 'Inbox/Index',
                section: 'main',
            });
        }

        items.push({
            title: "What's New",
            href: '/changelog',
            icon: Rocket,
            component: 'Changelog/Index',
            section: 'main',
        });

        return items;
    });

    const adminItems = computed<NavItem[]>(() => {
        const isAdmin = page.props.auth?.user?.role === 'admin';
        const canAddMembers = page.props.auth?.can?.add_members === true;
        const items: NavItem[] = [];

        if (isAdmin) {
            items.push({
                title: 'Family Settings',
                href: familySettings(),
                icon: Settings,
                component: 'Family/Settings',
                section: 'Family Admin',
            });
        }

        if (canAddMembers) {
            items.push({
                title: 'Invitations',
                href: invitationsIndex(),
                icon: Mail,
                component: 'Family/Invitations',
                section: 'Family Admin',
            });
        }

        if (isAdmin) {
            items.push({
                title: 'Subscription',
                href: subscriptionIndex(),
                icon: Sparkles,
                component: 'Subscription/Index',
                section: 'Family Admin',
            });
        }

        return items;
    });

    const platformItems = computed<NavItem[]>(() => {
        if (!page.props.auth?.user?.is_super_admin) {
            return [];
        }

        return [
            {
                title: 'Platform Admin',
                href: '/platform',
                icon: Globe,
                fullPageLoad: true,
                exact: true,
                section: 'Super Admin',
            },
        ];
    });

    const footerItems: NavItem[] = [
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

    const mobileTabs = computed<NavItem[]>(() => [
        primaryItems.value[0],
        primaryItems.value[1],
        primaryItems.value[2],
        paymentItem.value,
    ]);

    const moreGroups = computed<NavigationGroup[]>(() => [
        {
            items: [
                primaryItems.value.find(
                    (item) => item.title === 'Notifications',
                ),
                ...primaryItems.value.slice(5),
                {
                    title: 'Profile',
                    href: editProfile(),
                    icon: User,
                    component: 'settings/Profile',
                    section: 'Account',
                },
            ].filter(Boolean) as NavItem[],
        },
        {
            label: 'Family Admin',
            items: adminItems.value,
        },
        {
            label: 'Super Admin',
            items: platformItems.value,
        },
    ]);

    return {
        primaryItems,
        adminItems,
        platformItems,
        footerItems,
        mobileTabs,
        moreGroups,
    };
}
