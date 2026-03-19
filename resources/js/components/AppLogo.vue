<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const familyName = computed(() => page.props.family?.name);
</script>

<template>
    <div
        class="flex size-8 items-center justify-center rounded-lg bg-linear-to-br from-emerald-500 to-teal-600 shadow-lg shadow-emerald-500/25"
    >
        <AppLogoIcon class="size-5 text-white" />
    </div>
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span class="mb-0.5 truncate leading-tight font-semibold"
            >FamilyFund</span
        >
        <span
            v-if="familyName"
            class="truncate text-xs text-muted-foreground"
            >{{ familyName }}</span
        >
    </div>
</template>
