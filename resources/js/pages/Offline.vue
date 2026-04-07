<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Offline" />

    <div class="flex min-h-svh items-center justify-center bg-background p-6">
        <div class="mx-auto flex max-w-md flex-col items-center text-center">
            <div class="mb-6 flex h-14 w-14 items-center justify-center">
                <AppLogoIcon class="size-14 fill-current text-foreground" />
            </div>

            <div class="mb-2 text-4xl">📡</div>

            <h1
                class="mb-2 text-2xl font-semibold tracking-tight text-foreground"
            >
                You're Offline
            </h1>

            <p class="mb-8 text-sm leading-relaxed text-muted-foreground">
                It looks like you've lost your internet connection. Check your
                network and try again.
            </p>

            <Button
                variant="default"
                size="lg"
                @click="() => window.location.reload()"
            >
                Try Again
            </Button>
        </div>
    </div>
</template>
