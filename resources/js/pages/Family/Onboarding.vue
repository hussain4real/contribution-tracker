<script setup lang="ts">
import { index as onboarding } from '@/actions/App/Http/Controllers/FamilyOnboardingController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import AppLayout from '@/layouts/AppLayout.vue';
import { useCurrencyFormatter } from '@/lib/currency';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, CheckCircle2, Circle } from '@lucide/vue';

type SetupStep = {
    key: string;
    title: string;
    description: string;
    completed: boolean;
    optional: boolean;
    url: string;
};
defineProps<{
    guide: { completed: number; total: number; steps: SetupStep[] };
}>();
const { formatCurrency } = useCurrencyFormatter();
</script>

<template>
    <Head title="Get started" />
    <AppLayout
        :breadcrumbs="[{ title: 'Get started', href: onboarding().url }]"
    >
        <main class="mx-auto flex w-full max-w-4xl flex-col gap-8 p-4 md:p-8">
            <header class="space-y-3">
                <p
                    class="text-sm font-medium text-emerald-700 dark:text-emerald-400"
                >
                    Your family workspace
                </p>
                <h1 class="text-3xl font-semibold tracking-tight">
                    Get your family ready
                </h1>
                <p class="max-w-2xl text-muted-foreground">
                    Start with the essentials, then add what your family needs.
                    Progress comes from your current records, so you can leave
                    and resume at any time.
                </p>
                <p class="text-sm font-medium">
                    {{ guide.completed }} of {{ guide.total }} essentials ready
                </p>
                <progress
                    :value="guide.completed"
                    :max="guide.total"
                    aria-label="Family setup progress"
                    class="h-2 w-full accent-emerald-600"
                />
            </header>
            <ol class="divide-y rounded-xl border">
                <li
                    v-for="step in guide.steps"
                    :key="step.key"
                    class="flex items-start gap-4 p-5"
                >
                    <component
                        :is="step.completed ? CheckCircle2 : Circle"
                        aria-hidden="true"
                        class="mt-1 size-5 shrink-0"
                        :class="
                            step.completed
                                ? 'text-emerald-600'
                                : 'text-muted-foreground'
                        "
                    />
                    <div class="min-w-0 flex-1 space-y-1">
                        <Link
                            :href="step.url"
                            class="inline-flex min-h-10 items-center gap-2 font-medium underline-offset-4 hover:underline"
                            >{{ step.title
                            }}<ArrowRight
                                class="size-4 shrink-0"
                                aria-hidden="true"
                        /></Link>
                        <p class="text-sm text-muted-foreground">
                            {{ step.description }}
                        </p>
                        <p
                            class="text-xs font-medium"
                            :class="
                                step.completed
                                    ? 'text-emerald-700 dark:text-emerald-400'
                                    : 'text-muted-foreground'
                            "
                        >
                            {{
                                step.completed
                                    ? 'Ready'
                                    : step.optional
                                      ? 'Optional'
                                      : 'Next step'
                            }}<span v-if="step.completed && step.optional">
                                · Optional</span
                            >
                        </p>
                    </div>
                </li>
            </ol>
            <section class="rounded-xl bg-muted/40 p-6">
                <h2 class="text-lg font-semibold">See the member experience</h2>
                <p class="mt-2 mb-4 text-sm text-muted-foreground">
                    Preview a sample contribution summary before inviting
                    anyone. The preview does not add members or transactions.
                </p>
                <Dialog>
                    <DialogTrigger as-child
                        ><Button variant="outline"
                            >Preview member view</Button
                        ></DialogTrigger
                    >
                    <DialogContent>
                        <DialogHeader
                            ><DialogTitle>Example member view</DialogTitle
                            ><DialogDescription
                                >Preview only. These example amounts are not
                                saved to your family ledger.</DialogDescription
                            ></DialogHeader
                        >
                        <div class="space-y-4 py-4">
                            <h3 class="font-semibold">My Contributions</h3>
                            <dl class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <div class="rounded-lg border p-3">
                                    <dt class="text-sm text-muted-foreground">
                                        Expected
                                    </dt>
                                    <dd class="mt-1 font-semibold">
                                        {{ formatCurrency(4000) }}
                                    </dd>
                                </div>
                                <div class="rounded-lg border p-3">
                                    <dt class="text-sm text-muted-foreground">
                                        Paid
                                    </dt>
                                    <dd class="mt-1 font-semibold">
                                        {{ formatCurrency(1000) }}
                                    </dd>
                                </div>
                                <div class="rounded-lg border p-3">
                                    <dt class="text-sm text-muted-foreground">
                                        Outstanding
                                    </dt>
                                    <dd class="mt-1 font-semibold">
                                        {{ formatCurrency(3000) }}
                                    </dd>
                                </div>
                            </dl>
                            <p class="text-sm text-muted-foreground">
                                Members can review their own payment history,
                                open receipt links and choose reminder
                                preferences. This preview has no payment
                                controls.
                            </p>
                        </div>
                    </DialogContent>
                </Dialog>
            </section>
        </main>
    </AppLayout>
</template>
