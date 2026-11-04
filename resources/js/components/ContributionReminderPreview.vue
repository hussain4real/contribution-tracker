<script setup lang="ts">
defineProps<{ familyName: string }>();
</script>

<template>
    <aside
        class="rounded-lg border bg-muted/30 p-4"
        aria-label="Example email reminder"
    >
        <p class="text-sm font-semibold">Example email reminder</p>
        <p class="mt-2 text-sm">
            Hi [member name], your [month] contribution for
            {{ familyName }} is due on [due date]. Your remaining balance
            includes any earlier unpaid contributions.
        </p>
        <p class="mt-1 text-xs text-muted-foreground">
            The actual message includes the member's balance and a link to their
            contributions.
        </p>
    </aside>
</template>
