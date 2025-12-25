<script setup lang="ts">
import { cn } from '@/lib/utils';
import { computed, useAttrs } from 'vue';

const attrs = useAttrs();
const computedClass = computed(() => cn('[&_tr:last-child]:border-0', attrs.class as string));
</script>

<template>
    <tbody :class="computedClass">
        <slot />
    </tbody>
</template>
