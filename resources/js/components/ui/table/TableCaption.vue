<script setup lang="ts">
import { cn } from '@/lib/utils';
import { computed, useAttrs } from 'vue';

const attrs = useAttrs();
const computedClass = computed(() => cn('mt-4 text-sm text-muted-foreground', attrs.class as string));
</script>

<template>
    <caption :class="computedClass">
        <slot />
    </caption>
</template>
