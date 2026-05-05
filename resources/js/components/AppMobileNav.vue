<script setup lang="ts">
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { useAppNavigation } from '@/lib/appNavigation';
import { urlIsActive } from '@/lib/utils';
import type { NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { Menu } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const page = usePage();
const { mobileTabs, moreGroups } = useAppNavigation();
const moreOpen = ref(false);

const unreadCount = computed(() => page.props.notifications?.unread_count ?? 0);

function isActive(item: NavItem): boolean {
    return urlIsActive(item.href, page.url);
}

function badgeCount(item: NavItem): number {
    if (item.badge === 'notifications') {
        return unreadCount.value;
    }

    return 0;
}

const hasMoreBadge = computed(() =>
    moreGroups.value.some((group) =>
        group.items.some((item) => badgeCount(item) > 0),
    ),
);
</script>

<template>
    <nav
        class="fixed inset-x-0 bottom-0 z-40 border-t bg-background/95 pb-[env(safe-area-inset-bottom)] shadow-[0_-10px_30px_rgba(15,23,42,0.06)] backdrop-blur md:hidden"
        aria-label="Mobile navigation"
    >
        <div class="grid h-16 grid-cols-5 px-1">
            <Link
                v-for="item in mobileTabs"
                :key="item.title"
                :href="item.href"
                :component="item.component"
                prefetch
                :cache-for="['30s', '2m']"
                view-transition
                class="app-tap relative flex min-w-0 flex-col items-center justify-center gap-1 rounded-md px-1 text-[11px] font-medium text-muted-foreground"
                :class="{ 'text-foreground': isActive(item) }"
            >
                <component :is="item.icon" class="size-5" />
                <span class="w-full truncate text-center">{{
                    item.title
                }}</span>
                <span
                    v-if="badgeCount(item) > 0"
                    class="absolute top-1.5 right-3 flex min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] leading-4 font-bold text-white"
                >
                    {{ badgeCount(item) > 9 ? '9+' : badgeCount(item) }}
                </span>
            </Link>

            <Sheet v-model:open="moreOpen">
                <SheetTrigger as-child>
                    <button
                        class="app-tap relative flex min-w-0 flex-col items-center justify-center gap-1 rounded-md px-1 text-[11px] font-medium text-muted-foreground"
                        :class="{
                            'text-foreground': moreGroups.some((group) =>
                                group.items.some(isActive),
                            ),
                        }"
                    >
                        <Menu class="size-5" />
                        <span class="w-full truncate text-center">More</span>
                        <span
                            v-if="hasMoreBadge"
                            class="absolute top-1.5 right-3 size-2 rounded-full bg-red-500"
                        />
                    </button>
                </SheetTrigger>
                <SheetContent
                    side="bottom"
                    class="max-h-[82dvh] overflow-y-auto rounded-t-xl px-4 pt-3 pb-[calc(env(safe-area-inset-bottom)+1rem)]"
                >
                    <SheetHeader class="mb-2 text-left">
                        <SheetTitle>More</SheetTitle>
                        <SheetDescription>
                            FamilyFunds tools and settings
                        </SheetDescription>
                    </SheetHeader>

                    <div class="space-y-4">
                        <section
                            v-for="group in moreGroups.filter(
                                (group) => group.items.length > 0,
                            )"
                            :key="group.label ?? 'main'"
                            class="space-y-2"
                        >
                            <p
                                v-if="group.label"
                                class="px-1 text-xs font-medium text-muted-foreground"
                            >
                                {{ group.label }}
                            </p>
                            <div class="grid gap-1">
                                <Link
                                    v-for="item in group.items"
                                    :key="`${group.label ?? 'main'}-${item.title}`"
                                    :href="item.href"
                                    :component="item.component"
                                    prefetch
                                    view-transition
                                    class="app-tap flex items-center gap-3 rounded-lg px-3 py-3 text-sm font-medium"
                                    :class="
                                        isActive(item)
                                            ? 'bg-accent text-accent-foreground'
                                            : 'text-foreground hover:bg-accent/70'
                                    "
                                    @click="moreOpen = false"
                                >
                                    <component
                                        v-if="item.icon"
                                        :is="item.icon"
                                        class="size-5 text-muted-foreground"
                                    />
                                    <span class="min-w-0 flex-1 truncate">
                                        {{ item.title }}
                                    </span>
                                    <span
                                        v-if="badgeCount(item) > 0"
                                        class="flex min-w-5 items-center justify-center rounded-full bg-red-500 px-1.5 text-xs leading-5 font-bold text-white"
                                    >
                                        {{
                                            badgeCount(item) > 99
                                                ? '99+'
                                                : badgeCount(item)
                                        }}
                                    </span>
                                </Link>
                            </div>
                        </section>
                    </div>
                </SheetContent>
            </Sheet>
        </div>
    </nav>
</template>
