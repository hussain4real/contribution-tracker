<script setup lang="ts">
import FamilySwitcher from '@/components/FamilySwitcher.vue';
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
import { useAppNavigation } from '@/lib/appNavigation';
import { dashboard } from '@/routes';
import { Link } from '@inertiajs/vue3';
import AppLogo from './AppLogo.vue';

const { primaryItems, adminItems, platformItems, footerItems } =
    useAppNavigation();
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
            <FamilySwitcher />
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="primaryItems" />
            <NavMain
                v-if="adminItems.length"
                :items="adminItems"
                label="Family Admin"
            />
            <NavMain
                v-if="platformItems.length"
                :items="platformItems"
                label="Super Admin"
            />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
