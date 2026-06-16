<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentAllocationService;
use App\Support\CurrencyFormatter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class RecordPayment implements Tool
{
    public function __construct(private User $user) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Records a payment for a family member. Accepts the member name (or part of it), amount, payment date, optional notes, and optional target year/month. The payment is automatically allocated to the oldest unpaid contribution first. Always call without confirmed=true first to preview.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        if (! $this->user->canRecordPayments()) {
            return json_encode(['error' => 'You do not have permission to record payments. Only admins and financial secretaries can do this.'], JSON_THROW_ON_ERROR);
        }

        $family = $this->user->currentFamily ?? $this->user->family;

        if (! $family instanceof Family) {
            return json_encode(['error' => 'User is not associated with a family.'], JSON_THROW_ON_ERROR);
        }

        $memberName = $this->nullableStringFromRequest($request['member_name'] ?? null);
        $amount = $this->nullableIntegerFromRequest($request['amount'] ?? null);
        $paidAt = $this->stringFromRequest($request['paid_at'] ?? null, now()->toDateString());
        $notes = $this->nullableStringFromRequest($request['notes'] ?? null);
        $targetYear = $this->nullableIntegerFromRequest($request['target_year'] ?? null);
        $targetMonth = $this->nullableIntegerFromRequest($request['target_month'] ?? null);
        $confirmed = ($request['confirmed'] ?? false) === true;

        if (! $memberName) {
            return json_encode(['error' => 'Please provide the member name.'], JSON_THROW_ON_ERROR);
        }

        if (! $amount || $amount < 1) {
            return json_encode(['error' => 'Amount is required and must be at least 1.'], JSON_THROW_ON_ERROR);
        }

        // Find member by name within the family
        $memberships = $family->memberships()
            ->with(['familyCategory:id,name,monthly_amount', 'user'])
            ->join('users', 'users.id', '=', 'family_members.user_id')
            ->whereNull('users.archived_at')
            ->where('users.name', 'like', "%{$memberName}%")
            ->select('family_members.*')
            ->get();

        if ($memberships->isEmpty()) {
            return json_encode(['error' => "No active family member found matching \"{$memberName}\"."], JSON_THROW_ON_ERROR);
        }

        if ($memberships->count() > 1) {
            $names = $memberships
                ->map(fn (FamilyMembership $membership): string => $membership->user->name)
                ->toArray();

            return json_encode([
                'error' => 'Multiple members match that name. Please be more specific.',
                'matching_members' => $names,
            ], JSON_THROW_ON_ERROR);
        }

        $membership = $memberships->first();

        $member = $membership->user;

        if ($membership->monthlyAmount() === null) {
            return json_encode(['error' => "{$member->name} does not have a contribution category assigned. Please assign one first."], JSON_THROW_ON_ERROR);
        }

        // Validate advance payment limit
        if ($targetYear !== null && $targetMonth !== null) {
            $targetDate = now()->setYear($targetYear)->setMonth($targetMonth)->startOfMonth();
            $maxAdvanceDate = now()->addMonths(6)->startOfMonth();

            if ($targetDate->gt($maxAdvanceDate)) {
                return json_encode(['error' => 'Advance payments are limited to 6 months ahead.'], JSON_THROW_ON_ERROR);
            }
        }

        $currency = $family->currency;
        $formattedAmount = CurrencyFormatter::format($amount, $currency);
        $targetInfo = ($targetYear !== null && $targetMonth !== null)
            ? ' for '.now()->setYear($targetYear)->setMonth($targetMonth)->format('F Y')
            : '';

        if (! $confirmed) {
            return json_encode([
                'status' => 'confirmation_required',
                'message' => "I'll record a payment of {$formattedAmount} for {$member->name} on {$paidAt}{$targetInfo}. The payment will be allocated to the oldest unpaid contribution first. Please confirm to proceed.",
                'details' => [
                    'member' => $member->name,
                    'amount' => $amount,
                    'paid_at' => $paidAt,
                    'notes' => $notes,
                    'target_year' => $targetYear,
                    'target_month' => $targetMonth,
                ],
            ], JSON_THROW_ON_ERROR);
        }

        $allocationService = app(PaymentAllocationService::class);

        $payments = $allocationService->allocate(
            member: $member,
            amount: $amount,
            paidAt: $paidAt,
            recordedBy: $this->user,
            notes: $notes,
            targetYear: $targetYear,
            targetMonth: $targetMonth,
            family: $family,
        );

        $totalAllocated = (int) $payments->sum(fn (Payment $payment): int => $payment->amount);
        $formattedAllocated = $currency.number_format($totalAllocated, 2);

        return json_encode([
            'status' => 'success',
            'message' => "Payment of {$formattedAllocated} recorded for {$member->name}. Allocated across {$payments->count()} contribution(s).",
            'payments_created' => $payments->count(),
            'total_allocated' => $totalAllocated,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'member_name' => $schema->string()->required(),
            'amount' => $schema->integer()->min(1)->required(),
            'paid_at' => $schema->string(),
            'notes' => $schema->string(),
            'target_year' => $schema->integer()->min(2020)->max(2030),
            'target_month' => $schema->integer()->min(1)->max(12),
            'confirmed' => $schema->boolean(),
        ];
    }

    private function nullableIntegerFromRequest(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function nullableStringFromRequest(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private function stringFromRequest(mixed $value, string $default): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }
}
