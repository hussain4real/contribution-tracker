<script setup lang="ts">
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { switchMethod as switchFamilyRoute } from '@/routes/families';
import type { AppPageProps, UserFamily } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { Check, ChevronsUpDown, House } from '@lucide/vue';
import { computed } from 'vue';

const page = usePage<AppPageProps>();
const { isMobile, state } = useSidebar();

const currentFamily = computed(() => page.props.family);
const families = computed<UserFamily[]>(() => page.props.families ?? []);
const auth = computed(() => page.props.auth);
const hasMultipleFamilies = computed(() => families.value.length > 1);

function familyInitials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');
}

function switchToFamily(family: UserFamily): void {
    if (family.is_current) {
        return;
    }

    router.post(switchFamilyRoute({ family: family.slug }).url);
}
</script>

<template>
    <SidebarMenu v-if="currentFamily">
        <SidebarMenuItem>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <SidebarMenuButton
                        size="lg"
                        :disabled="!hasMultipleFamilies"
                        class="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
                        data-test="family-switcher"
                    >
                        <div
                            class="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground"
                        >
                            <span class="text-xs font-semibold">
                                {{ familyInitials(currentFamily.name) }}
                            </span>
                        </div>
                        <div
                            class="grid flex-1 text-left text-sm leading-tight"
                        >
                            <span class="truncate font-medium">
                                {{ currentFamily.name }}
                            </span>
                            <span
                                class="truncate text-xs text-muted-foreground"
                            >
                                {{ auth?.user?.role_label ?? 'Member' }}
                            </span>
                        </div>
                        <ChevronsUpDown
                            v-if="hasMultipleFamilies"
                            class="ml-auto size-4"
                        />
                    </SidebarMenuButton>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    v-if="hasMultipleFamilies"
                    class="w-(--reka-dropdown-menu-trigger-width) min-w-64 rounded-lg"
                    :side="
                        isMobile
                            ? 'bottom'
                            : state === 'collapsed'
                              ? 'left'
                              : 'bottom'
                    "
                    align="start"
                    :side-offset="4"
                >
                    <DropdownMenuLabel class="text-xs text-muted-foreground">
                        Families
                    </DropdownMenuLabel>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        v-for="family in families"
                        :key="family.id"
                        class="gap-3"
                        @click="switchToFamily(family)"
                    >
                        <div
                            class="flex size-7 items-center justify-center rounded-md bg-muted text-xs font-semibold"
                        >
                            <House v-if="!family.name" class="size-4" />
                            <span v-else>{{
                                familyInitials(family.name)
                            }}</span>
                        </div>
                        <div class="grid min-w-0 flex-1">
                            <span class="truncate">{{ family.name }}</span>
                            <span
                                class="truncate text-xs text-muted-foreground"
                            >
                                {{ family.role_label ?? 'Member' }}
                            </span>
                        </div>
                        <Check
                            v-if="family.is_current"
                            class="size-4 text-primary"
                        />
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </SidebarMenuItem>
    </SidebarMenu>
</template>
