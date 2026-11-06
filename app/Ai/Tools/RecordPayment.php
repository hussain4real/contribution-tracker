<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Enums\AiActionKind;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\User;
use App\Services\AiActionDrafts;
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
        return 'Prepares a payment draft for human review in FamilyFund. Return the review_url to the user. This tool never posts financial records, even if asked to confirm in chat.';
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

        if (! app(AiActionDrafts::class)->allowed($this->user, $family, AiActionKind::Payment)) {
            return json_encode(['error' => 'You do not have permission to prepare this action.'], JSON_THROW_ON_ERROR);
        }

        $memberName = $this->nullableStringFromRequest($request['member_name'] ?? null);
        $amount = $this->nullableIntegerFromRequest($request['amount'] ?? null);
        $paidAt = $this->stringFromRequest($request['paid_at'] ?? null, now()->toDateString());
        $notes = $this->nullableStringFromRequest($request['notes'] ?? null);
        $targetYear = $this->nullableIntegerFromRequest($request['target_year'] ?? null);
        $targetMonth = $this->nullableIntegerFromRequest($request['target_month'] ?? null);

        if (! $memberName) {
            return json_encode(['error' => 'Please provide the member name.'], JSON_THROW_ON_ERROR);
        }

        if (! $amount || $amount < 1) {
            return json_encode(['error' => 'Amount is required and must be at least 1.'], JSON_THROW_ON_ERROR);
        }

        // Find member by name within the family
        $memberships = $family->memberships()
            ->with(['familyCategory:id,name,monthly_amount', 'user'])
            ->active()
            ->join('users', 'users.id', '=', 'family_members.user_id')
            ->where(function ($query) use ($memberName): void {
                $query->where('family_members.display_name', 'like', "%{$memberName}%")
                    ->orWhere('users.name', 'like', "%{$memberName}%");
            })
            ->select('family_members.*')
            ->get();

        if ($memberships->isEmpty()) {
            return json_encode(['error' => "No active family member found matching \"{$memberName}\"."], JSON_THROW_ON_ERROR);
        }

        if ($memberships->count() > 1) {
            $names = $memberships
                ->map(fn (FamilyMembership $membership): string => $membership->displayName())
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
            $targetDate = now()->startOfMonth()->setYear($targetYear)->setMonth($targetMonth);
            $maxAdvanceDate = now()->startOfMonth()->addMonths(6);

            if ($targetDate->gt($maxAdvanceDate)) {
                return json_encode(['error' => 'Advance payments are limited to 6 months ahead.'], JSON_THROW_ON_ERROR);
            }
        }

        return json_encode(app(AiActionDrafts::class)->create($this->user, $family, AiActionKind::Payment, [
            'member_id' => $member->id,
            'member' => $membership->displayName(),
            'amount' => $amount,
            'paid_at' => $paidAt,
            'notes' => $notes,
            'target_year' => $targetYear,
            'target_month' => $targetMonth,
        ]), JSON_THROW_ON_ERROR);
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
