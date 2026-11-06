<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ result: unknown }>();
function isRecord(value: unknown): value is Record<string, unknown> {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}
const links = computed(() => {
    let data: unknown = props.result;
    if (typeof data === 'string') {
        try {
            data = JSON.parse(data);
        } catch {
            return [];
        }
    }
    if (!isRecord(data) || typeof window === 'undefined') return [];
    const candidates = [
        { value: data.review_url, label: 'Review draft in FamilyFund' },
        { value: data.source_url, label: 'View source records' },
        { value: data.url, label: 'Open in FamilyFund' },
    ];
    return candidates.flatMap(({ value, label }) => {
        if (typeof value !== 'string') return [];
        try {
            const url = new URL(value, window.location.origin);
            return url.origin === window.location.origin &&
                !url.username &&
                !url.password
                ? [{ href: url.pathname + url.search, label }]
                : [];
        } catch {
            return [];
        }
    });
});
</script>
<template>
    <div v-if="links.length" class="mt-2 flex flex-wrap gap-3">
        <a
            v-for="link in links"
            :key="link.href"
            :href="link.href"
            class="text-sm font-medium text-primary underline underline-offset-4"
            >{{ link.label }}</a
        >
    </div>
</template>
