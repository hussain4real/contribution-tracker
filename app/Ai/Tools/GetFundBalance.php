<?php

namespace App\Ai\Tools;

use App\Models\Expense;
use App\Models\FundAdjustment;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetFundBalance implements Tool
{
    public function __construct(private User $user) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Calculates the current family fund balance. Formula: total payments + fund adjustments - expenses. Can optionally include a breakdown of each component. Use this tool when the user asks about the family balance, available funds, or how much money the family has.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $familyId = $this->user->family_id;

        if (! $familyId) {
            return json_encode(['error' => 'User is not associated with a family.'], JSON_THROW_ON_ERROR);
        }

        $includeBreakdown = $request['include_breakdown'] ?? true;

        // Amounts are stored as integers (whole currency units) across all models
        $totalPayments = (int) Payment::query()
            ->whereHas('contribution', fn ($q) => $q->where('family_id', $familyId))
            ->sum('amount');

        $totalAdjustments = (int) FundAdjustment::query()
            ->where('family_id', $familyId)
            ->sum('amount');

        $totalExpenses = (int) Expense::query()
            ->where('family_id', $familyId)
            ->sum('amount');

        $balance = $totalPayments + $totalAdjustments - $totalExpenses;

        $result = [
            'fund_balance' => $balance,
            'currency' => $this->user->family()->value('currency') ?? '₦',
        ];

        if ($includeBreakdown) {
            $recentAdjustments = FundAdjustment::query()
                ->where('family_id', $familyId)
                ->with('recorder:id,name')
                ->latestFirst()
                ->limit(10)
                ->get()
                ->map(fn (FundAdjustment $adj) => [
                    'amount' => $adj->amount,
                    'description' => $adj->description,
                    'recorded_at' => $adj->recorded_at->format('Y-m-d'),
                    'recorded_by' => $adj->recorder?->name ?? 'Unknown',
                ])->toArray();

            $result['breakdown'] = [
                'total_payments' => $totalPayments,
                'total_fund_adjustments' => $totalAdjustments,
                'total_expenses' => $totalExpenses,
            ];
            $result['recent_adjustments'] = $recentAdjustments;
        }

        return json_encode($result, JSON_THROW_ON_ERROR);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'include_breakdown' => $schema->boolean(),
        ];
    }
}
