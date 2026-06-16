<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Models\Contribution;
use App\Models\Family;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
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
        $family = $this->user->currentFamily ?? $this->user->family;

        if (! $family instanceof Family) {
            return json_encode(['error' => 'User is not associated with a family.'], JSON_THROW_ON_ERROR);
        }

        $year = $this->integerFromRequest($request['year'] ?? null, now()->year);
        $month = $this->nullableIntegerFromRequest($request['month'] ?? null);

        $query = Contribution::query()
            ->where('family_id', $family->id)
            ->where('year', $year)
            ->with(['user:id,name', 'payments:id,contribution_id,amount'])
            ->whereHas('user', fn (Builder $query): Builder => $query->whereNull('archived_at'));

        if ($month !== null) {
            $query->where('month', $month);
        }

        $contributions = $query->get();

        $totalExpected = (int) $contributions->sum(fn (Contribution $contribution): int => $contribution->expected_amount);
        $totalPaid = (int) $contributions->sum(
            fn (Contribution $contribution): int => (int) $contribution->payments->sum(fn (Payment $payment): int => $payment->amount)
        );
        $outstanding = $totalExpected - $totalPaid;

        $memberBreakdown = $contributions->groupBy('user_id')->map(function ($userContributions): array {
            $firstContribution = $userContributions->first();
            $user = $firstContribution instanceof Contribution ? $firstContribution->user : null;
            $expected = (int) $userContributions->sum(fn (Contribution $contribution): int => $contribution->expected_amount);
            $paid = (int) $userContributions->sum(
                fn (Contribution $contribution): int => (int) $contribution->payments->sum(fn (Payment $payment): int => $payment->amount)
            );

            return [
                'name' => $user instanceof User ? $user->name : 'Unknown',
                'expected' => $expected,
                'paid' => $paid,
                'outstanding' => $expected - $paid,
                'months_covered' => $userContributions->count(),
            ];
        })->values();

        $period = $month
            ? now()->setYear($year)->setMonth($month)->format('F Y')
            : "Year {$year}";

        return json_encode([
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

    private function integerFromRequest(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    private function nullableIntegerFromRequest(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
