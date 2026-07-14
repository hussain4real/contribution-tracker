<script setup lang="ts">
import { index } from '@/actions/App/Http/Controllers/ReportController';
import {
    destroy,
    store,
} from '@/actions/App/Http/Controllers/ReportScheduleController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { exportMethod } from '@/routes/reports';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { CalendarClock, Download, FileBarChart2, Trash2 } from '@lucide/vue';

interface Option {
    value: string;
    label: string;
}
interface Schedule {
    id: number;
    name: string;
    report_type: string;
    format: string;
    frequency: string;
    next_run_at: string;
    deliveries_count: number;
    is_active: boolean;
}

interface Props {
    report_types: Option[];
    formats: Option[];
    frequencies: Option[];
    members: Array<{ id: number; name: string }>;
    schedules: Schedule[];
}

const props = defineProps<Props>();
const today = new Date();
const yearStart = `${today.getFullYear()}-01-01`;
const yearEnd = `${today.getFullYear()}-12-31`;
const defaultRun = new Date(today.getTime() + 60 * 60 * 1000)
    .toISOString()
    .slice(0, 16);
const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;

const exportFilters = useForm({
    type: props.report_types[0]?.value ?? 'contribution_register',
    format: 'pdf',
    date_from: yearStart,
    date_to: yearEnd,
    member_id: '',
    category: '',
    status: '',
    min_outstanding: '',
    search: '',
});

const scheduleForm = useForm({
    name: '',
    report_type: props.report_types[0]?.value ?? 'contribution_register',
    format: 'pdf',
    filters: { date_from: yearStart, date_to: yearEnd, member_id: '' },
    channels: ['email'] as string[],
    recipients_text: '',
    recipients: [] as string[],
    frequency: 'monthly',
    timezone,
    next_run_at: defaultRun,
});

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Reports', href: index().url }];

function submitSchedule(): void {
    scheduleForm
        .transform((data) => ({
            ...data,
            recipients: data.recipients_text
                .split(',')
                .map((value) => value.trim())
                .filter(Boolean),
        }))
        .post(store().url, {
            preserveScroll: true,
            onSuccess: () => scheduleForm.reset('name', 'recipients_text'),
        });
}

function removeSchedule(id: number): void {
    router.delete(destroy({ reportSchedule: id }).url, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Reports & Exports" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
            <div class="flex items-center gap-3">
                <FileBarChart2 class="h-6 w-6 text-neutral-500" />
                <div>
                    <h1
                        class="text-xl font-semibold text-foreground sm:text-2xl"
                    >
                        Reports & exports
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        Generate reconciled files or schedule recurring
                        delivery.
                    </p>
                </div>
            </div>

            <section class="rounded-xl border bg-card p-5">
                <div class="mb-4 flex items-center gap-2">
                    <Download class="h-5 w-5" />
                    <div>
                        <h2 class="font-semibold">Generate report</h2>
                        <p class="text-sm text-muted-foreground">
                            PDFs and CSVs use the same filtered ledger dataset.
                        </p>
                    </div>
                </div>
                <form
                    :action="exportMethod().url"
                    method="get"
                    target="_blank"
                    class="grid gap-3 md:grid-cols-2 xl:grid-cols-4"
                >
                    <label class="grid gap-1 text-xs text-muted-foreground"
                        >Report type<select
                            v-model="exportFilters.type"
                            name="type"
                            class="rounded-md border bg-background px-3 py-2 text-sm text-foreground"
                        >
                            <option
                                v-for="option in report_types"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select></label
                    >
                    <label class="grid gap-1 text-xs text-muted-foreground"
                        >Format<select
                            v-model="exportFilters.format"
                            name="format"
                            class="rounded-md border bg-background px-3 py-2 text-sm text-foreground"
                        >
                            <option
                                v-for="option in formats"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select></label
                    >
                    <label class="grid gap-1 text-xs text-muted-foreground"
                        >From<input
                            v-model="exportFilters.date_from"
                            name="date_from"
                            type="date"
                            required
                            class="rounded-md border bg-background px-3 py-2 text-sm text-foreground"
                    /></label>
                    <label class="grid gap-1 text-xs text-muted-foreground"
                        >To<input
                            v-model="exportFilters.date_to"
                            name="date_to"
                            type="date"
                            required
                            class="rounded-md border bg-background px-3 py-2 text-sm text-foreground"
                    /></label>
                    <label class="grid gap-1 text-xs text-muted-foreground"
                        >Member<select
                            v-model="exportFilters.member_id"
                            name="member_id"
                            class="rounded-md border bg-background px-3 py-2 text-sm text-foreground"
                        >
                            <option value="">All members</option>
                            <option
                                v-for="member in members"
                                :key="member.id"
                                :value="member.id"
                            >
                                {{ member.name }}
                            </option>
                        </select></label
                    >
                    <label class="grid gap-1 text-xs text-muted-foreground"
                        >Status<select
                            v-model="exportFilters.status"
                            name="status"
                            class="rounded-md border bg-background px-3 py-2 text-sm text-foreground"
                        >
                            <option value="">All statuses</option>
                            <option value="paid">Paid</option>
                            <option value="partial">Partial</option>
                            <option value="unpaid">Unpaid</option>
                            <option value="overdue">Overdue</option>
                        </select></label
                    >
                    <label class="grid gap-1 text-xs text-muted-foreground"
                        >Minimum outstanding<input
                            v-model="exportFilters.min_outstanding"
                            name="min_outstanding"
                            min="0"
                            type="number"
                            class="rounded-md border bg-background px-3 py-2 text-sm text-foreground"
                    /></label>
                    <label class="grid gap-1 text-xs text-muted-foreground"
                        >Search<input
                            v-model="exportFilters.search"
                            name="search"
                            type="search"
                            class="rounded-md border bg-background px-3 py-2 text-sm text-foreground"
                    /></label>
                    <div class="md:col-span-2 xl:col-span-4">
                        <Button type="submit"
                            ><Download class="h-4 w-4" /> Generate &
                            download</Button
                        >
                    </div>
                </form>
            </section>

            <section class="rounded-xl border bg-card p-5">
                <div class="mb-4 flex items-center gap-2">
                    <CalendarClock class="h-5 w-5" />
                    <div>
                        <h2 class="font-semibold">Schedule delivery</h2>
                        <p class="text-sm text-muted-foreground">
                            Email receives an attachment; WhatsApp receives a
                            seven-day signed link.
                        </p>
                    </div>
                </div>
                <form
                    class="grid gap-3 md:grid-cols-2 xl:grid-cols-4"
                    @submit.prevent="submitSchedule"
                >
                    <label class="grid gap-1 text-xs text-muted-foreground"
                        >Schedule name<input
                            v-model="scheduleForm.name"
                            required
                            class="rounded-md border bg-background px-3 py-2 text-sm text-foreground" /><InputError
                            :message="scheduleForm.errors.name"
                    /></label>
                    <label class="grid gap-1 text-xs text-muted-foreground"
                        >Report type<select
                            v-model="scheduleForm.report_type"
                            class="rounded-md border bg-background px-3 py-2 text-sm text-foreground"
                        >
                            <option
                                v-for="option in report_types"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select></label
                    >
                    <label class="grid gap-1 text-xs text-muted-foreground"
                        >Format<select
                            v-model="scheduleForm.format"
                            class="rounded-md border bg-background px-3 py-2 text-sm text-foreground"
                        >
                            <option
                                v-for="option in formats"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select></label
                    >
                    <label class="grid gap-1 text-xs text-muted-foreground"
                        >Frequency<select
                            v-model="scheduleForm.frequency"
                            class="rounded-md border bg-background px-3 py-2 text-sm text-foreground"
                        >
                            <option
                                v-for="option in frequencies"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select></label
                    >
                    <label class="grid gap-1 text-xs text-muted-foreground"
                        >Run first at<input
                            v-model="scheduleForm.next_run_at"
                            required
                            type="datetime-local"
                            class="rounded-md border bg-background px-3 py-2 text-sm text-foreground"
                    /></label>
                    <label class="grid gap-1 text-xs text-muted-foreground"
                        >Recipients<input
                            v-model="scheduleForm.recipients_text"
                            required
                            placeholder="email@example.com, 974..."
                            class="rounded-md border bg-background px-3 py-2 text-sm text-foreground" /><InputError
                            :message="scheduleForm.errors.recipients"
                    /></label>
                    <fieldset class="grid gap-1 text-xs text-muted-foreground">
                        <legend>Channels</legend>
                        <div
                            class="flex h-10 items-center gap-4 rounded-md border px-3 text-sm text-foreground"
                        >
                            <label class="flex items-center gap-2"
                                ><input
                                    v-model="scheduleForm.channels"
                                    value="email"
                                    type="checkbox"
                                />
                                Email</label
                            ><label class="flex items-center gap-2"
                                ><input
                                    v-model="scheduleForm.channels"
                                    value="whatsapp"
                                    type="checkbox"
                                />
                                WhatsApp</label
                            >
                        </div>
                    </fieldset>
                    <div class="flex items-end">
                        <Button
                            :disabled="scheduleForm.processing"
                            type="submit"
                            >Create schedule</Button
                        >
                    </div>
                </form>
            </section>

            <section class="overflow-hidden rounded-xl border bg-card">
                <div class="border-b px-5 py-4">
                    <h2 class="font-semibold">Delivery schedules</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-sm">
                        <thead
                            class="bg-muted/60 text-left text-xs text-muted-foreground"
                        >
                            <tr>
                                <th class="px-4 py-3">Name</th>
                                <th class="px-4 py-3">Report</th>
                                <th class="px-4 py-3">Cadence</th>
                                <th class="px-4 py-3">Next run</th>
                                <th class="px-4 py-3">Deliveries</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="schedule in schedules"
                                :key="schedule.id"
                            >
                                <td class="px-4 py-3 font-medium">
                                    {{ schedule.name }}
                                </td>
                                <td class="px-4 py-3">
                                    {{ schedule.report_type }} ·
                                    {{ schedule.format }}
                                </td>
                                <td class="px-4 py-3">
                                    {{ schedule.frequency }}
                                </td>
                                <td class="px-4 py-3">
                                    {{
                                        new Date(
                                            schedule.next_run_at,
                                        ).toLocaleString()
                                    }}
                                </td>
                                <td class="px-4 py-3">
                                    {{ schedule.deliveries_count }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        aria-label="Delete schedule"
                                        @click="removeSchedule(schedule.id)"
                                        ><Trash2 class="h-4 w-4"
                                    /></Button>
                                </td>
                            </tr>
                            <tr v-if="schedules.length === 0">
                                <td
                                    colspan="6"
                                    class="px-4 py-10 text-center text-muted-foreground"
                                >
                                    No report schedules yet.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
