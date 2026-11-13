<script setup lang="ts">
import { preview } from '@/actions/App/Http/Controllers/ExpenseBudgetController';
import ExpenseBudgetNotice, {
    type BudgetAdvisory,
} from '@/components/ExpenseBudgetNotice.vue';
import { ref, watch } from 'vue';
const props = defineProps<{
    category: string;
    amount: string | number;
    date: string;
}>();
const advisory = ref<BudgetAdvisory | null>(null);
const state = ref('');
watch(
    () => [props.category, props.amount, props.date],
    (_, __, onCleanup) => {
        advisory.value = null;
        state.value = '';
        const amount = Number(props.amount);
        if (
            !props.category ||
            !/^\d{4}-\d{2}-\d{2}$/.test(props.date) ||
            !Number.isSafeInteger(amount) ||
            amount < 1 ||
            amount > 999999999
        )
            return;
        const controller = new AbortController();
        state.value = 'Checking monthly budget…';
        const timer = setTimeout(async () => {
            try {
                const response = await fetch(
                    preview(
                        {},
                        {
                            query: {
                                category: props.category,
                                amount,
                                month: props.date.slice(0, 7),
                            },
                        },
                    ).url,
                    {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                        signal: controller.signal,
                    },
                );
                if (!response.ok) throw new Error('Budget preview unavailable');
                const data = await response.json();
                if (controller.signal.aborted) return;
                advisory.value = data.advisory;
                state.value = data.advisory
                    ? ''
                    : 'No budget set for this category and month.';
            } catch {
                if (!controller.signal.aborted)
                    state.value =
                        'Budget preview unavailable. Check the monthly budget before continuing.';
            }
        }, 250);
        onCleanup(() => {
            clearTimeout(timer);
            controller.abort();
        });
    },
    { immediate: true },
);
</script>
<template>
    <div>
        <p v-if="state" role="status" class="text-sm text-muted-foreground">
            {{ state }}
        </p>
        <ExpenseBudgetNotice :advisory="advisory" />
    </div>
</template>
