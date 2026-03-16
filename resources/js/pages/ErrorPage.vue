<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    status: number;
}>();

const title = computed(() => {
    return (
        {
            503: '503: Service Unavailable',
            500: '500: Server Error',
            404: '404: Page Not Found',
            403: '403: Forbidden',
        }[props.status] ?? `${props.status}: Error`
    );
});

const description = computed(() => {
    return (
        {
            503: 'Sorry, we are doing some maintenance. Please check back soon.',
            500: 'Whoops, something went wrong on our servers.',
            404: 'Sorry, the page you are looking for could not be found.',
            403: 'Sorry, you are forbidden from accessing this page.',
        }[props.status] ?? 'An unexpected error occurred.'
    );
});
</script>

<template>
    <div>
        <Head :title="title" />

        <div
            class="flex min-h-screen items-center justify-center bg-white dark:bg-neutral-950"
        >
            <div class="text-center">
                <h1
                    class="text-6xl font-bold text-neutral-900 dark:text-neutral-100"
                >
                    {{ status }}
                </h1>
                <p class="mt-4 text-lg text-neutral-600 dark:text-neutral-400">
                    {{ description }}
                </p>
                <div class="mt-8">
                    <Link href="/">
                        <Button>Go Home</Button>
                    </Link>
                </div>
            </div>
        </div>
    </div>
</template>
