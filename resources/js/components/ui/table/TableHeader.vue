<script setup lang="ts">
import { cn } from '@/lib/utils';
import { computed, useAttrs } from 'vue';

const attrs = useAttrs();
const computedClass = computed(() => cn('[&_tr]:border-b', attrs.class as string));
</script>

<template>
    <thead :class="computedClass">
        <slot />
    </thead>
</template>
