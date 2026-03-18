<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Check, Copy, Landmark } from 'lucide-vue-next';
import { ref } from 'vue';

const copied = ref(false);
const accountNumber = '0232875402';

function copyToClipboard(): void {
    navigator.clipboard.writeText(accountNumber);
    copied.value = true;
    setTimeout(() => {
        copied.value = false;
    }, 2000);
}
</script>

<template>
    <Card class="relative w-full overflow-hidden">
        <div class="pointer-events-none absolute right-0 top-0 opacity-5">
            <Landmark class="-mr-4 -mt-4 h-32 w-32" />
        </div>
        <CardHeader>
            <CardTitle class="flex items-center gap-2 text-lg">
                <Landmark class="h-5 w-5 text-green-600 dark:text-green-400" />
                Contribution Bank Details
            </CardTitle>
            <CardDescription>
                Use the following account for all your family fund
                contributions.
            </CardDescription>
        </CardHeader>
        <CardContent>
            <div class="grid gap-6 sm:grid-cols-3">
                <div class="space-y-1">
                    <p
                        class="text-sm font-medium text-neutral-500 dark:text-neutral-400"
                    >
                        Account Name
                    </p>
                    <p
                        class="font-semibold text-neutral-900 dark:text-neutral-100"
                    >
                        Musa Sadiya Halima
                    </p>
                </div>
                <div class="space-y-1">
                    <p
                        class="text-sm font-medium text-neutral-500 dark:text-neutral-400"
                    >
                        Bank
                    </p>
                    <p
                        class="font-semibold text-neutral-900 dark:text-neutral-100"
                    >
                        Guaranteed Trust Bank
                    </p>
                </div>
                <div class="space-y-1">
                    <p
                        class="text-sm font-medium text-neutral-500 dark:text-neutral-400"
                    >
                        Account Number
                    </p>
                    <div class="flex items-center gap-2">
                        <span
                            class="font-mono text-lg font-bold text-neutral-900 dark:text-neutral-100"
                        >
                            {{ accountNumber }}
                        </span>
                        <Button
                            variant="outline"
                            size="icon-sm"
                            class="text-neutral-500 transition-colors hover:text-green-600 dark:hover:text-green-400"
                            :title="copied ? 'Copied!' : 'Copy account number'"
                            @click="copyToClipboard"
                        >
                            <Check
                                v-if="copied"
                                class="h-4 w-4 text-green-500"
                            />
                            <Copy v-else class="h-4 w-4" />
                        </Button>
                    </div>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
