<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Expense;
use App\Models\ExpenseApproval;
use App\Models\ExpenseRequest;
use App\Models\Family;
use App\Models\PaystackTransaction;
use App\Models\User;
use App\Support\AuditEventRecorder;
use App\Support\PlatformPlanCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpenseGovernance
{
    public function supportsApprovals(Family $family): bool
    {
        return $family->platformPlan?->slug === PlatformPlanCatalog::Organization
            && $family->hasActiveSubscription()
            && ($family->current_period_end === null || $family->current_period_end->isFuture());
    }

    public function requiredApprovals(Family $family, int $amount): int
    {
        $settings = $family->expense_approval_settings;
        if ($settings === null || ($settings['enabled'] ?? null) === false) {
            return 0;
        }
        if (($settings['enabled'] ?? null) !== true || ! is_int($settings['threshold'] ?? null)
            || $settings['threshold'] < 1 || ! is_int($settings['approvers'] ?? null)
            || $settings['approvers'] < 1 || $settings['approvers'] > 10) {
            throw ValidationException::withMessages(['amount' => 'Expense approval settings need the family owner’s review.']);
        }

        return $amount >= $settings['threshold'] ? $settings['approvers'] : 0;
    }

    public function requiredForRequest(Family $family, ExpenseRequest $request): int
    {
        return max($request->required_approvals, $this->requiredApprovals($family, $request->amount));
    }

    public function approvedCount(ExpenseRequest $request): int
    {
        return $request->approvals()->where('decision', 'approved')->where('user_id', '!=', $request->requested_by)->count();
    }

    /** Enforce the policy for web, AI, corrections and other model-based posting paths. */
    public function ensurePostingAllowed(Expense $expense): void
    {
        $family = Family::query()->lockForUpdate()->findOrFail($expense->family_id);
        if ($expense->providerFeeTransactionId() !== null) {
            $transaction = PaystackTransaction::query()->where('family_id', $family->id)
                ->lockForUpdate()->find($expense->providerFeeTransactionId());
            if (! $transaction instanceof PaystackTransaction || $transaction->type !== TransactionType::Contribution
                || $transaction->status !== TransactionStatus::Verified || $transaction->verified_at === null
                || $transaction->fee_expense_id !== null || $transaction->settled_amount_kobo === null
                || ($transaction->paystack_response['status'] ?? null) !== 'success') {
                throw ValidationException::withMessages(['amount' => 'Provider fee posting requires a verified contribution settlement.']);
            }
            $shortfall = $transaction->contributionAmountKobo() - $transaction->settled_amount_kobo;
            if ($shortfall <= 0 || $expense->amount !== intdiv($shortfall + 99, 100)
                || $expense->recorded_by !== $transaction->user_id || $expense->expense_request_id !== null) {
                throw ValidationException::withMessages(['amount' => 'The provider fee must match the verified settlement shortfall.']);
            }

            return;
        }
        $required = $this->requiredApprovals($family, $expense->amount);
        if ($expense->expense_request_id === null && $required === 0) {
            if ($expense->idempotency_key !== null && ExpenseRequest::query()->where('family_id', $family->id)
                ->where('idempotency_key', $expense->idempotency_key)->exists()) {
                throw ValidationException::withMessages(['idempotency_key' => 'This request is reserved for an expense request. Record its payment from the request list.']);
            }

            return;
        }
        $request = ExpenseRequest::query()->where('family_id', $family->id)
            ->lockForUpdate()->find($expense->expense_request_id);
        if (! $request instanceof ExpenseRequest || ! in_array($request->status, ['submitted', 'approved'], true)
            || $request->amount !== $expense->amount || $request->description !== $expense->description
            || $request->spent_at->toDateString() !== $expense->spent_at->toDateString()
            || $this->approvedCount($request) < $this->requiredForRequest($family, $request)
            || $request->expense()->exists()) {
            throw ValidationException::withMessages(['amount' => 'Submit an expense request and obtain the required independent approvals before recording this expense.']);
        }
    }

    private function authorizeOfficer(ExpenseRequest $request, User $actor): void
    {
        abort_unless(($actor->current_family_id ?? $actor->family_id) === $request->family_id
            && $actor->membershipForFamilyId($request->family_id)?->role->canRecordPayments() === true, 403);
    }

    public function decide(ExpenseRequest $request, User $actor, string $decision, ?string $reason): void
    {
        $this->authorizeOfficer($request, $actor);
        DB::transaction(function () use ($request, $actor, $decision, $reason): void {
            $family = Family::query()->lockForUpdate()->findOrFail($request->family_id);
            $locked = ExpenseRequest::query()->lockForUpdate()->findOrFail($request->id);
            abort_if($locked->requested_by === $actor->id, 403, 'You cannot decide your own expense request.');
            $previous = $locked->approvals()->where('user_id', $actor->id)->first();
            if ($previous instanceof ExpenseApproval) {
                if ($previous->decision !== $decision || $previous->reason !== $reason) {
                    throw ValidationException::withMessages(['decision' => 'Your decision has already been recorded.']);
                }

                return;
            }
            if (! in_array($locked->status, ['submitted', 'approved'], true) || $locked->expense()->exists()) {
                throw ValidationException::withMessages(['decision' => 'This request is no longer awaiting a decision.']);
            }
            $approval = $locked->approvals()->create([
                'family_id' => $family->id, 'user_id' => $actor->id, 'decision' => $decision, 'reason' => $reason,
            ]);
            $locked->update(['status' => $decision === 'rejected' ? 'rejected'
                : ($this->approvedCount($locked) >= $this->requiredForRequest($family, $locked) ? 'approved' : 'submitted')]);
            app(AuditEventRecorder::class)->record($approval, 'expense_request.'.$decision, $family->id, $actor->id);
        });
    }

    public function pay(ExpenseRequest $request, User $actor): Expense
    {
        $this->authorizeOfficer($request, $actor);

        return DB::transaction(function () use ($request, $actor): Expense {
            Family::query()->lockForUpdate()->findOrFail($request->family_id);
            $locked = ExpenseRequest::query()->lockForUpdate()->findOrFail($request->id);
            $existing = $locked->expense()->first();
            if ($existing instanceof Expense) {
                return $existing;
            }
            $expense = Expense::query()->create([
                'family_id' => $locked->family_id, 'expense_request_id' => $locked->id,
                'amount' => $locked->amount, 'description' => $locked->description,
                'spent_at' => $locked->spent_at, 'recorded_by' => $actor->id,
                'idempotency_key' => $locked->idempotency_key,
            ]);
            $locked->update(['status' => 'paid']);
            app(AuditEventRecorder::class)->record($locked, 'expense_request.paid', $locked->family_id, $actor->id);

            return $expense;
        });
    }
}
