<script setup lang="ts">
import { cn } from '@/lib/utils';
import { SelectLabel, type SelectLabelProps } from 'reka-ui';
import { computed, type HTMLAttributes } from 'vue';

const props = defineProps<SelectLabelProps & { class?: HTMLAttributes['class'] }>();

const delegatedProps = computed(() => {
    const { class: _, ...rest } = props;
    return rest;
});
</script>

<template>
    <SelectLabel
        v-bind="delegatedProps"
        :class="cn('py-1.5 pl-8 pr-2 text-sm font-semibold', props.class)"
    >
        <slot />
    </SelectLabel>
</template>
