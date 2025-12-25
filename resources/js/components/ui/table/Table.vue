<script setup lang="ts">
import { cn } from '@/lib/utils';
import { computed, useAttrs } from 'vue';

const attrs = useAttrs();
const computedClass = computed(() => cn('w-full caption-bottom text-sm', attrs.class as string));
</script>

<template>
    <div class="relative w-full overflow-auto">
        <table :class="computedClass">
            <slot />
        </table>
    </div>
</template>
