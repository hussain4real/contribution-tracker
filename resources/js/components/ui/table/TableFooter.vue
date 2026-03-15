<script setup lang="ts">
import { cn } from '@/lib/utils';
import { computed, useAttrs } from 'vue';

const attrs = useAttrs();
const computedClass = computed(() =>
    cn('border-t bg-muted/50 font-medium [&>tr]:last:border-b-0', attrs.class as string)
);
</script>

<template>
    <tfoot :class="computedClass">
        <slot />
    </tfoot>
</template>
