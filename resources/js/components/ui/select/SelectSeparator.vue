<script setup lang="ts">
import { cn } from '@/lib/utils';
import { SelectSeparator, type SelectSeparatorProps } from 'reka-ui';
import { computed, type HTMLAttributes } from 'vue';

const props = defineProps<SelectSeparatorProps & { class?: HTMLAttributes['class'] }>();

const delegatedProps = computed(() => {
    const { class: _, ...rest } = props;
    return rest;
});
</script>

<template>
    <SelectSeparator v-bind="delegatedProps" :class="cn('-mx-1 my-1 h-px bg-muted', props.class)" />
</template>
