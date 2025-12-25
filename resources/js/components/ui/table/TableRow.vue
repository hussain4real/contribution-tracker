<script setup lang="ts">
import { cn } from '@/lib/utils';
import { computed, useAttrs } from 'vue';

const attrs = useAttrs();
const computedClass = computed(() =>
    cn(
        'border-b transition-colors hover:bg-muted/50 data-[state=selected]:bg-muted',
        attrs.class as string
    )
);
</script>

<template>
    <tr :class="computedClass">
        <slot />
    </tr>
</template>
