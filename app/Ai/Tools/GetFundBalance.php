<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Models\Family;
use App\Models\FundAdjustment;
use App\Models\User;
use App\Services\FamilyFinancialVisibility;
use App\Support\EffectiveLedger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class GetFundBalance implements Tool
{
    public function __construct(private User $user) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Calculates the current family fund balance. Formula: total payments + fund adjustments - expenses. Can optionally include a breakdown of each component. Use this tool when the user asks about the family balance, available funds, or how much money the family has.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        $family = app(FamilyFinancialVisibility::class)->activeFamily($this->user);

        if (! $family instanceof Family) {
            return json_encode(['error' => 'User is not associated with a family.'], JSON_THROW_ON_ERROR);
        }

        $visibility = app(FamilyFinancialVisibility::class);
        if (! $visibility->totals($this->user, $family)) {
            return json_encode(['error' => 'The family has restricted access to its totals.'], JSON_THROW_ON_ERROR);
        }

        $familyId = $family->id;
        $includeBreakdown = ($request['include_breakdown'] ?? false) === true
            && $visibility->transactions($this->user, $family);

        $ledger = app(EffectiveLedger::class);
        $totalPayments = $ledger->paymentsTotal($familyId);
        $totalAdjustments = $ledger->adjustmentsTotal($familyId);
        $totalExpenses = $ledger->expensesTotal($familyId);

        $balance = $totalPayments + $totalAdjustments - $totalExpenses;
        $result = [
            'as_of' => now()->toIso8601String(),
            'period' => 'All recorded effective entries, including future-dated entries',
            'source_url' => route('dashboard', ['current_family' => $family->slug]),
            'fund_balance' => $balance,
            'currency' => $family->currency,
        ];

        if ($includeBreakdown) {
            $recentAdjustments = FundAdjustment::query()
                ->where('family_id', $familyId)
                ->effective()
                ->with('recorder:id,name')
                ->latestFirst()
                ->limit(10)
                ->get()
                ->map(fn (FundAdjustment $adj) => [
                    'amount' => $adj->amount,
                    'description' => $adj->description,
                    'recorded_at' => $adj->recorded_at->format('Y-m-d'),
                    'recorded_by' => $adj->recorder->name ?? 'Unknown',
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
