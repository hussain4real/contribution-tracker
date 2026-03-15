<script setup lang="ts">
import { cn } from '@/lib/utils';
import { computed, useAttrs } from 'vue';

const attrs = useAttrs();
const computedClass = computed(() =>
    cn('p-4 align-middle [&:has([role=checkbox])]:pr-0', attrs.class as string)
);
</script>

<template>
    <td :class="computedClass">
        <slot />
    </td>
</template>
