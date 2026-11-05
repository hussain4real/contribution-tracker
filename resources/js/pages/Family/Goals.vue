<script setup lang="ts">
import {
    archive,
    index,
    store,
    update,
} from '@/actions/App/Http/Controllers/FamilyGoalController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { useCurrencyFormatter } from '@/lib/currency';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Plus, Target } from '@lucide/vue';
import { ref } from 'vue';

type Measure = 'net_savings' | 'contributions';
type Goal = {
    id: number;
    name: string;
    measure: Measure;
    target_amount: number;
    starts_on: string;
    deadline: string;
    as_of: string;
    progress: number;
    remaining: number;
    percentage: number;
    status: 'active' | 'upcoming' | 'reached' | 'overdue' | 'archived';
    received: number;
    expenses: number;
    adjustments: number;
    source_url: string | null;
};
const props = defineProps<{
    goals: {
        data: Goal[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    can_manage: boolean;
    show_archived: boolean;
    measures: { value: Measure; label: string }[];
    idempotencyKey: string;
    today: string;
    defaultDeadline: string;
}>();
const { formatCurrency } = useCurrencyFormatter();
const open = ref(false);
const editing = ref<number | null>(null);
const archiving = ref<number | null>(null);
const form = useForm<{
    name: string;
    measure: Measure;
    target_amount: number;
    starts_on: string;
    deadline: string;
    idempotency_key: string;
}>({
    name: '',
    measure: 'net_savings',
    target_amount: 10000,
    starts_on: props.today,
    deadline: props.defaultDeadline,
    idempotency_key: props.idempotencyKey,
});

function start(goal?: Goal) {
    form.clearErrors();
    editing.value = goal?.id ?? null;
    form.name = goal?.name ?? '';
    form.measure = goal?.measure ?? 'net_savings';
    form.target_amount = goal?.target_amount ?? 10000;
    form.starts_on = goal?.starts_on ?? props.today;
    form.deadline = goal?.deadline ?? props.defaultDeadline;
    form.idempotency_key = props.idempotencyKey;
    open.value = true;
}
function save() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            form.reset();
        },
    };
    if (editing.value !== null)
        form.put(update({ family_goal: editing.value }).url, options);
    else form.post(store().url, options);
}
function setArchived(goal: Goal) {
    archiving.value = goal.id;
    router.patch(
        archive({ family_goal: goal.id }).url,
        { archived: goal.status !== 'archived' },
        {
            preserveScroll: true,
            onFinish: () => {
                archiving.value = null;
            },
        },
    );
}
function date(value: string) {
    return new Date(value).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        timeZone: 'UTC',
    });
}
</script>

<template>
    <Head title="Family goals" />
    <AppLayout :breadcrumbs="[{ title: 'Goals', href: index().url }]">
        <main class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-8">
            <header class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-3xl font-semibold tracking-tight">
                        Family goals
                    </h1>
                    <p class="mt-2 text-muted-foreground">
                        Visual targets for your shared family fund.
                    </p>
                </div>
                <Button v-if="can_manage" @click="start()"
                    ><Plus class="size-4" aria-hidden="true" />New goal</Button
                >
            </header>
            <section
                class="rounded-xl border bg-muted/30 p-5 text-sm leading-relaxed"
                aria-label="How goal progress is calculated"
            >
                <p>
                    <strong>Net savings</strong> = contributions received + fund
                    adjustments − expenses.
                    <strong>Contributions received</strong> counts contribution
                    payments only.
                </p>
                <p class="mt-2 text-muted-foreground">
                    Progress uses entry dates within each goal’s start and
                    deadline, through today, and excludes reversed entries. Each
                    target starts at zero. Money stays in the shared fund;
                    overlapping goals can reflect the same entries.
                </p>
            </section>
            <Link
                :href="
                    index(undefined, {
                        query: { archived: !show_archived ? 1 : 0 },
                    })
                "
                class="inline-flex min-h-11 items-center text-sm underline underline-offset-4"
                >{{
                    show_archived
                        ? 'Hide archived goals'
                        : 'Include archived goals'
                }}</Link
            >
            <section
                v-if="goals.data.length === 0"
                class="rounded-xl border border-dashed p-10 text-center"
            >
                <Target
                    class="mx-auto mb-4 size-9 text-muted-foreground"
                    aria-hidden="true"
                />
                <h2 class="font-semibold">
                    A shared target gives everyone a direction
                </h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    {{
                        can_manage
                            ? 'Create a goal with a target and deadline to follow your family’s progress.'
                            : 'Your family admin can create a goal for everyone to follow.'
                    }}
                </p>
            </section>
            <div class="grid gap-5 md:grid-cols-2">
                <article
                    v-for="goal in goals.data"
                    :key="goal.id"
                    class="flex flex-col gap-5 rounded-xl border p-5"
                    :aria-label="goal.name"
                >
                    <header>
                        <div class="flex items-start justify-between gap-3">
                            <h2 class="text-xl font-semibold">
                                {{ goal.name }}
                            </h2>
                            <span
                                class="rounded-full bg-muted px-3 py-1 text-xs font-medium capitalize"
                                >{{ goal.status }}</span
                            >
                        </div>
                        <p class="mt-2 text-sm text-muted-foreground">
                            {{
                                goal.measure === 'net_savings'
                                    ? 'Net savings'
                                    : 'Contributions received'
                            }}
                            · {{ date(goal.starts_on) }} –
                            {{ date(goal.deadline) }}
                        </p>
                    </header>
                    <div>
                        <p class="text-3xl font-semibold tracking-tight">
                            {{ formatCurrency(goal.progress) }}
                        </p>
                        <p class="mt-1 text-sm text-muted-foreground">
                            of {{ formatCurrency(goal.target_amount) }} target ·
                            {{ formatCurrency(goal.remaining) }} remaining
                        </p>
                        <progress
                            :value="goal.percentage"
                            max="100"
                            :aria-label="`${goal.name} progress`"
                            class="mt-4 h-2 w-full accent-emerald-600"
                        />
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{
                                goal.status === 'upcoming'
                                    ? `Starts ${date(goal.starts_on)}`
                                    : `As of ${date(goal.as_of)}`
                            }}
                        </p>
                    </div>
                    <dl
                        v-if="goal.measure === 'net_savings'"
                        class="grid grid-cols-3 gap-2 border-t pt-4 text-sm"
                    >
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Received
                            </dt>
                            <dd class="mt-1">
                                {{ formatCurrency(goal.received) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Adjustments
                            </dt>
                            <dd class="mt-1">
                                {{ formatCurrency(goal.adjustments) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Expenses
                            </dt>
                            <dd class="mt-1">
                                {{ formatCurrency(goal.expenses) }}
                            </dd>
                        </div>
                    </dl>
                    <footer
                        class="mt-auto flex flex-wrap items-center gap-3 text-sm"
                    >
                        <Link
                            v-if="goal.source_url"
                            :href="goal.source_url"
                            class="inline-flex min-h-11 items-center underline underline-offset-4"
                            >View supporting entries</Link
                        ><Button
                            v-if="can_manage"
                            variant="outline"
                            size="sm"
                            :aria-label="`Edit goal ${goal.name}`"
                            @click="start(goal)"
                            >Edit<span class="sr-only">
                                {{ goal.name }}</span
                            ></Button
                        ><Button
                            v-if="can_manage"
                            variant="ghost"
                            size="sm"
                            :disabled="archiving === goal.id"
                            :aria-label="`${goal.status === 'archived' ? 'Restore' : 'Archive'} goal ${goal.name}`"
                            @click="setArchived(goal)"
                            >{{
                                goal.status === 'archived'
                                    ? 'Restore'
                                    : 'Archive'
                            }}<span class="sr-only">
                                {{ goal.name }}</span
                            ></Button
                        >
                    </footer>
                </article>
            </div>
            <nav
                v-if="goals.prev_page_url || goals.next_page_url"
                aria-label="Goals pages"
                class="flex justify-between gap-4"
            >
                <Link
                    v-if="goals.prev_page_url"
                    :href="goals.prev_page_url"
                    class="underline"
                    >Previous</Link
                ><Link
                    v-if="goals.next_page_url"
                    :href="goals.next_page_url"
                    class="ml-auto underline"
                    >Next</Link
                >
            </nav>
            <Dialog v-model:open="open"
                ><DialogContent
                    ><DialogHeader
                        ><DialogTitle>{{
                            editing === null
                                ? 'Create a visual goal'
                                : 'Edit visual target'
                        }}</DialogTitle
                        ><DialogDescription
                            >Choose the movement to measure. Contributions and
                            expenses continue through the existing family
                            ledger.</DialogDescription
                        ></DialogHeader
                    >
                    <form class="grid gap-4" @submit.prevent="save">
                        <div class="grid gap-2">
                            <Label for="goal-name">Goal name</Label
                            ><Input
                                id="goal-name"
                                v-model="form.name"
                                required
                                maxlength="120"
                                placeholder="Education fund target"
                            /><InputError :message="form.errors.name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="goal-measure">Measure progress by</Label
                            ><select
                                id="goal-measure"
                                v-model="form.measure"
                                class="min-h-11 rounded-md border bg-background px-3"
                            >
                                <option
                                    v-for="measure in measures"
                                    :key="measure.value"
                                    :value="measure.value"
                                >
                                    {{ measure.label }}
                                </option>
                            </select>
                            <p class="text-xs text-muted-foreground">
                                {{
                                    form.measure === 'net_savings'
                                        ? 'Expenses reduce progress; fund adjustments can increase or reduce it.'
                                        : 'Only contribution payments count. Expenses and adjustments are excluded.'
                                }}
                            </p>
                            <InputError :message="form.errors.measure" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="goal-target">Target amount</Label
                            ><Input
                                id="goal-target"
                                v-model.number="form.target_amount"
                                type="number"
                                min="1"
                                max="999999999"
                                step="1"
                                required
                            /><InputError
                                :message="form.errors.target_amount"
                            />
                        </div>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="goal-start">Start date</Label
                                ><Input
                                    id="goal-start"
                                    v-model="form.starts_on"
                                    type="date"
                                    required
                                /><InputError
                                    :message="form.errors.starts_on"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="goal-deadline">Deadline</Label
                                ><Input
                                    id="goal-deadline"
                                    v-model="form.deadline"
                                    type="date"
                                    :min="form.starts_on"
                                    required
                                /><InputError :message="form.errors.deadline" />
                            </div>
                        </div>
                        <InputError
                            :message="form.errors.idempotency_key"
                        /><DialogFooter
                            ><Button
                                type="button"
                                variant="outline"
                                :disabled="form.processing"
                                @click="open = false"
                                >Cancel</Button
                            ><Button
                                type="submit"
                                :disabled="form.processing"
                                >{{
                                    form.processing
                                        ? 'Saving…'
                                        : editing === null
                                          ? 'Create visual goal'
                                          : 'Save changes'
                                }}</Button
                            ></DialogFooter
                        >
                    </form>
                </DialogContent></Dialog
            >
        </main>
    </AppLayout>
</template>
