<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Models\Contribution;
use App\Models\Family;
use App\Models\Payment;
use App\Models\User;
use App\Services\FamilyFinancialVisibility;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class GetContributionSummary implements Tool
{
    public function __construct(private User $user) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Retrieves contribution summary data for the family. Can filter by year and month. Returns total expected, total paid, outstanding balance, and per-member breakdown.';
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

        $validated = $request->validate(['year' => ['sometimes', 'integer', 'between:2020,2030'], 'month' => ['sometimes', 'integer', 'between:1,12']]);
        $input = new \Illuminate\Http\Request($validated);
        $year = $input->filled('year') ? $input->integer('year') : now()->year;
        $month = $input->filled('month') ? $input->integer('month') : null;

        $query = Contribution::query()
            ->where('family_id', $family->id)
            ->where('year', $year)
            ->with(['user:id,name', 'payments:id,contribution_id,amount'])
            ->whereHas('user.familyMemberships', function ($query) use ($family): void {
                $query->where('family_members.family_id', $family->id)
                    ->whereNull('family_members.archived_at');
            });

        if (! app(FamilyFinancialVisibility::class)->totals($this->user, $family)) {
            $query->where('user_id', $this->user->id);
        }

        if ($month !== null) {
            $query->where('month', $month);
        }

        $contributions = $query->get();

        $totalExpected = (int) $contributions->sum(fn (Contribution $contribution): int => $contribution->expected_amount);
        $totalPaid = (int) $contributions->sum(
            fn (Contribution $contribution): int => (int) $contribution->payments->sum(fn (Payment $payment): int => $payment->amount)
        );
        $outstanding = (int) $contributions->sum(fn (Contribution $contribution): int => max(0, $contribution->balance));

        $visibleContributions = app(FamilyFinancialVisibility::class)->officer($this->user, $family) ? $contributions : $contributions->where('user_id', $this->user->id);
        $memberBreakdown = $visibleContributions->groupBy('user_id')->map(function ($userContributions): array {
            $firstContribution = $userContributions->first();
            $user = $firstContribution instanceof Contribution ? $firstContribution->user : null;
            $expected = (int) $userContributions->sum(fn (Contribution $contribution): int => $contribution->expected_amount);
            $paid = (int) $userContributions->sum(
                fn (Contribution $contribution): int => (int) $contribution->payments->sum(fn (Payment $payment): int => $payment->amount)
            );

            return [
                'name' => $user instanceof User
                    ? ($user->membershipForFamilyId($firstContribution->family_id)?->displayName() ?? $user->name)
                    : 'Unknown',
                'expected' => $expected,
                'paid' => $paid,
                'outstanding' => (int) $userContributions->sum(fn (Contribution $contribution): int => max(0, $contribution->balance)),
                'months_covered' => $userContributions->count(),
            ];
        })->values();

        $period = $month
            ? now()->startOfMonth()->setYear($year)->setMonth($month)->format('F Y')
            : "Year {$year}";

        return json_encode([
            'as_of' => now()->toIso8601String(),
            'currency' => $family->currency,
            'scope' => app(FamilyFinancialVisibility::class)->totals($this->user, $family) ? 'family' : 'personal',
            'source_url' => app(FamilyFinancialVisibility::class)->officer($this->user, $family)
                ? route('contributions.index', ['current_family' => $family->slug, 'date_from' => sprintf('%04d-%02d-01', $year, $month ?? 1), 'date_to' => now()->startOfMonth()->setYear($year)->setMonth($month ?? 12)->endOfMonth()->toDateString()])
                : route('contributions.my', ['current_family' => $family->slug]),
            'period' => $period,
            'total_expected' => $totalExpected,
            'total_paid' => $totalPaid,
            'outstanding' => $outstanding,
            'collection_rate' => $totalExpected > 0 ? round(($totalPaid / $totalExpected) * 100, 1) : 0,
            'member_count' => $memberBreakdown->count(),
            'members' => $memberBreakdown->toArray(),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'year' => $schema->integer()->min(2020)->max(2030),
            'month' => $schema->integer()->min(1)->max(12),
        ];
    }
}
