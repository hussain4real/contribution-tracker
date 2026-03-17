<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAppearance } from '@/composables/useAppearance';
import { Monitor, Moon, Sun } from 'lucide-vue-next';

const { appearance, updateAppearance } = useAppearance();

const options = [
    { value: 'light' as const, Icon: Sun, label: 'Light' },
    { value: 'dark' as const, Icon: Moon, label: 'Dark' },
    { value: 'system' as const, Icon: Monitor, label: 'System' },
];
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button variant="ghost" size="icon" class="h-9 w-9 cursor-pointer">
                <Sun
                    class="size-5 scale-100 rotate-0 transition-all dark:scale-0 dark:-rotate-90"
                />
                <Moon
                    class="absolute size-5 scale-0 rotate-90 transition-all dark:scale-100 dark:rotate-0"
                />
                <span class="sr-only">Toggle theme</span>
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
            <DropdownMenuItem
                v-for="option in options"
                :key="option.value"
                @click="updateAppearance(option.value)"
                :class="[
                    'cursor-pointer',
                    appearance === option.value
                        ? 'bg-accent text-accent-foreground'
                        : '',
                ]"
            >
                <component :is="option.Icon" class="mr-2 h-4 w-4" />
                {{ option.label }}
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
