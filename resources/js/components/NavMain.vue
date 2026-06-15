<script setup lang="ts">
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { navItemIsActive } from '@/lib/appNavigation';
import { toUrl } from '@/lib/utils';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';

defineProps<{
    items: NavItem[];
    label?: string;
}>();

const page = usePage();
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel v-if="label">{{ label }}</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <SidebarMenuButton
                    as-child
                    :is-active="navItemIsActive(item, page.url, page.component)"
                    :tooltip="item.title"
                >
                    <a v-if="item.fullPageLoad" :href="toUrl(item.href)">
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                        <span
                            v-if="
                                item.badge === 'notifications' &&
                                (page.props.notifications?.unread_count ?? 0) >
                                    0
                            "
                            class="ml-auto flex min-w-5 items-center justify-center rounded-full bg-red-500 px-1.5 text-[10px] leading-5 font-bold text-white"
                        >
                            {{
                                (page.props.notifications?.unread_count ?? 0) >
                                99
                                    ? '99+'
                                    : page.props.notifications?.unread_count
                            }}
                        </span>
                    </a>
                    <Link
                        v-else
                        :href="item.href"
                        prefetch
                        :cache-for="['30s', '2m']"
                        view-transition
                    >
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                        <span
                            v-if="
                                item.badge === 'notifications' &&
                                (page.props.notifications?.unread_count ?? 0) >
                                    0
                            "
                            class="ml-auto flex min-w-5 items-center justify-center rounded-full bg-red-500 px-1.5 text-[10px] leading-5 font-bold text-white"
                        >
                            {{
                                (page.props.notifications?.unread_count ?? 0) >
                                99
                                    ? '99+'
                                    : page.props.notifications?.unread_count
                            }}
                        </span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
