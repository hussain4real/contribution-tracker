<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { computed } from 'vue';

type PaymentStatus = 'paid' | 'partial' | 'unpaid' | 'overdue';

interface Props {
    status: PaymentStatus;
}

const props = defineProps<Props>();

const statusConfig = computed(() => {
    switch (props.status) {
        case 'paid':
            return {
                label: 'Paid',
                variant: 'default' as const,
                class: 'bg-green-600 hover:bg-green-700 dark:bg-green-700',
            };
        case 'partial':
            return {
                label: 'Partial',
                variant: 'default' as const,
                class: 'bg-amber-500 hover:bg-amber-600 dark:bg-amber-600',
            };
        case 'unpaid':
            return {
                label: 'Unpaid',
                variant: 'secondary' as const,
                class: 'bg-neutral-200 text-neutral-700 dark:bg-neutral-700 dark:text-neutral-200',
            };
        case 'overdue':
            return {
                label: 'Overdue',
                variant: 'destructive' as const,
                class: '',
            };
        default:
            return {
                label: 'Unknown',
                variant: 'secondary' as const,
                class: '',
            };
    }
});
</script>

<template>
    <Badge :variant="statusConfig.variant" :class="statusConfig.class">
        {{ statusConfig.label }}
    </Badge>
</template>
