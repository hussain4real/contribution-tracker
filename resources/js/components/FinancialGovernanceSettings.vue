<script setup lang="ts">
import { update } from '@/actions/App/Http/Controllers/FinancialGovernanceSettingsController';
import { useForm } from '@inertiajs/vue3';
const props = defineProps<{
    governance: {
        member_financial_visibility: string;
        settings: { enabled: boolean; threshold: number; approvers: number };
        can_configure_approvals: boolean;
        organization: boolean;
    };
}>();
const form = useForm({
    member_financial_visibility: props.governance.member_financial_visibility,
    ...props.governance.settings,
});
function save() {
    form.put(update().url, { preserveScroll: true });
}
</script>
<template>
    <section
        class="space-y-4 rounded-xl border p-4"
        aria-labelledby="financial-settings-heading"
    >
        <h2 id="financial-settings-heading" class="text-lg font-semibold">
            Financial visibility and approvals
        </h2>
        <form class="grid gap-4" @submit.prevent="save">
            <label class="grid gap-1"
                >What members can see
                <select
                    id="member-financial-visibility"
                    name="member_financial_visibility"
                    v-model="form.member_financial_visibility"
                    class="min-h-11 rounded-md border bg-background px-3"
                >
                    <option value="personal">
                        Their own contributions and receipts
                    </option>
                    <option value="summary">
                        Their own records and family totals
                    </option>
                    <option value="transactions">
                        Family totals and transaction history
                    </option>
                </select>
            </label>
            <p class="text-sm text-muted-foreground">
                Officers retain access to family financial records. Supporting
                documents are restricted to officers.
            </p>
            <fieldset
                class="space-y-3 rounded-md border p-3"
                :disabled="!governance.can_configure_approvals"
            >
                <legend class="px-1 font-medium">Expense approvals</legend>
                <label class="flex min-h-11 items-center gap-2"
                    ><input v-model="form.enabled" type="checkbox" />Require
                    independent approval</label
                >
                <label class="grid gap-1"
                    >Approval threshold
                    <input
                        v-model.number="form.threshold"
                        type="number"
                        min="1"
                        max="999999999"
                        step="1"
                        required
                        class="min-h-11 rounded-md border bg-background px-3"
                    />
                </label>
                <label class="grid gap-1"
                    >Distinct approvers required
                    <input
                        v-model.number="form.approvers"
                        type="number"
                        min="1"
                        max="10"
                        step="1"
                        required
                        class="min-h-11 rounded-md border bg-background px-3"
                    />
                </label>
                <p class="text-sm text-muted-foreground">
                    At or above the threshold, submit a request before posting a
                    manual expense. Verified provider processing fees are
                    recorded automatically after settlement. The requester
                    cannot approve their own request. Lowering a rule does not
                    remove approvals already required by pending requests.
                </p>
            </fieldset>
            <p
                v-if="!governance.organization"
                class="text-sm text-muted-foreground"
            >
                Enabling expense approvals requires an active Organization plan.
            </p>
            <p
                v-if="!governance.can_configure_approvals"
                class="text-sm text-muted-foreground"
            >
                Only the family owner can change approval rules.
            </p>
            <p
                v-for="message in form.errors"
                :key="message"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ message }}
            </p>
            <button
                class="min-h-11 justify-self-start rounded-md border px-4"
                :disabled="form.processing"
            >
                {{ form.processing ? 'Saving…' : 'Save financial settings' }}
            </button>
        </form>
    </section>
</template>
