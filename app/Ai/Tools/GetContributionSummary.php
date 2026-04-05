<?php

namespace App\Ai\Tools;

use App\Models\Contribution;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetContributionSummary implements Tool
{
    public function __construct(private User $user) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Retrieves contribution summary data for the family. Can filter by year and month. Returns total expected, total paid, outstanding balance, and per-member breakdown.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $year = $request['year'] ?? now()->year;
        $month = $request['month'] ?? null;

        $query = Contribution::query()
            ->where('family_id', $this->user->family_id)
            ->where('year', $year)
            ->with(['user', 'payments'])
            ->whereHas('user', fn ($q) => $q->whereNull('archived_at'));

        if ($month) {
            $query->where('month', $month);
        }

        $contributions = $query->get();

        $totalExpected = $contributions->sum('expected_amount');
        $totalPaid = $contributions->sum(fn ($c) => $c->payments->sum('amount'));
        $outstanding = $totalExpected - $totalPaid;

        $memberBreakdown = $contributions->groupBy('user_id')->map(function ($userContributions) {
            $user = $userContributions->first()->user;
            $expected = $userContributions->sum('expected_amount');
            $paid = $userContributions->sum(fn ($c) => $c->payments->sum('amount'));

            return [
                'name' => $user->name,
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
}
