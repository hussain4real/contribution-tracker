<?php

namespace App\Http\Controllers;

use App\Enums\MemberCategory;
use App\Enums\PaymentStatus;
use App\Models\Contribution;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    /**
     * Display the report dashboard.
     */
    public function index(): Response
    {
        $currentYear = now()->year;
        $years = range($currentYear - 5, $currentYear);

        return Inertia::render('Reports/Index', [
            'years' => $years,
            'current_year' => $currentYear,
            'current_month' => now()->month,
        ]);
    }

    /**
     * Display monthly contribution summary.
     */
    public function monthly(Request $request): Response
    {
        $year = (int) $request->get('year', now()->year);
        $month = (int) $request->get('month', now()->month);
        $monthDate = Carbon::create($year, $month, 1);

        // Get all active members
        $members = User::query()
            ->whereNull('archived_at')
            ->where('id', '!=', 1) // Exclude first user (super admin seed)
            ->with(['contributions' => function ($query) use ($year, $month) {
                $query->where('year', $year)
                    ->where('month', $month)
                    ->with('payments');
            }])
            ->paginate(15)
            ->through(function ($member) {
                $contribution = $member->contributions->first();
                $expectedAmount = $member->category->monthlyAmountInKobo();
                $paidAmount = $contribution ? $contribution->payments->sum('amount') : 0;

                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'category' => $member->category->value,
                    'category_label' => $member->category->label(),
                    'expected_amount' => $expectedAmount,
                    'paid_amount' => $paidAmount,
                    'balance' => $expectedAmount - $paidAmount,
                    'status' => $contribution ? $contribution->status->value : PaymentStatus::Unpaid->value,
                    'status_label' => $contribution ? $contribution->status->label() : PaymentStatus::Unpaid->label(),
                ];
            });

        // Calculate summary statistics
        $allContributions = Contribution::query()
            ->where('year', $year)
            ->where('month', $month)
            ->with('payments')
            ->get();

        $totalExpected = $allContributions->sum('expected_amount');
        $totalCollected = $allContributions->sum(fn ($c) => $c->payments->sum('amount'));
        $totalOutstanding = $totalExpected - $totalCollected;
        $collectionRate = $totalExpected > 0 ? round(($totalCollected / $totalExpected) * 100, 1) : 0;

        // Count members by status
        $statusCounts = [
            PaymentStatus::Paid->value => 0,
            PaymentStatus::Partial->value => 0,
            PaymentStatus::Unpaid->value => 0,
            PaymentStatus::Overdue->value => 0,
        ];

        foreach ($allContributions as $contribution) {
            $status = $contribution->status->value;
            if (isset($statusCounts[$status])) {
                $statusCounts[$status]++;
            }
        }

        // Count members without contributions as unpaid
        $membersWithContributions = $allContributions->pluck('user_id')->toArray();
        $membersWithoutContributions = User::query()
            ->whereNull('archived_at')
            ->whereNotIn('id', $membersWithContributions)
            ->count();
        $statusCounts[PaymentStatus::Unpaid->value] += $membersWithoutContributions;

        // Breakdown by category
        $byCategory = [];
        foreach (MemberCategory::cases() as $category) {
            $categoryContributions = $allContributions->filter(function ($c) use ($category) {
                return $c->user && $c->user->category === $category;
            });

            $categoryExpected = $categoryContributions->sum('expected_amount');
            $categoryCollected = $categoryContributions->sum(fn ($c) => $c->payments->sum('amount'));

            $byCategory[$category->value] = [
                'label' => $category->label(),
                'expected' => $categoryExpected,
                'collected' => $categoryCollected,
                'outstanding' => $categoryExpected - $categoryCollected,
                'count' => $categoryContributions->count(),
            ];
        }

        return Inertia::render('Reports/Monthly', [
            'year' => $year,
            'month' => $month,
            'month_name' => $monthDate->format('F'),
            'summary' => [
                'total_expected' => $totalExpected,
                'total_collected' => $totalCollected,
                'total_outstanding' => $totalOutstanding,
                'collection_rate' => $collectionRate,
                'member_count' => $allContributions->count() + $membersWithoutContributions,
                'status_counts' => $statusCounts,
            ],
            'by_category' => $byCategory,
            'members' => $members,
        ]);
    }

    /**
     * Display annual contribution summary.
     */
    public function annual(Request $request): Response
    {
        $year = (int) $request->get('year', now()->year);

        // Generate monthly breakdown for all 12 months
        $monthlyBreakdown = [];
        $yearlyTotal = [
            'expected' => 0,
            'collected' => 0,
            'outstanding' => 0,
        ];

        for ($month = 1; $month <= 12; $month++) {
            $monthDate = Carbon::create($year, $month, 1);

            $contributions = Contribution::query()
                ->where('year', $year)
                ->where('month', $month)
                ->with('payments')
                ->get();

            $expected = $contributions->sum('expected_amount');
            $collected = $contributions->sum(fn ($c) => $c->payments->sum('amount'));
            $outstanding = $expected - $collected;
            $collectionRate = $expected > 0 ? round(($collected / $expected) * 100, 1) : 0;

            $monthlyBreakdown[] = [
                'month' => $month,
                'month_name' => $monthDate->format('F'),
                'short_name' => $monthDate->format('M'),
                'expected' => $expected,
                'collected' => $collected,
                'outstanding' => $outstanding,
                'collection_rate' => $collectionRate,
                'contribution_count' => $contributions->count(),
            ];

            $yearlyTotal['expected'] += $expected;
            $yearlyTotal['collected'] += $collected;
            $yearlyTotal['outstanding'] += $outstanding;
        }

        // Calculate yearly collection rate
        $yearlyTotal['collection_rate'] = $yearlyTotal['expected'] > 0
            ? round(($yearlyTotal['collected'] / $yearlyTotal['expected']) * 100, 1)
            : 0;

        // Calculate by category for the year
        $byCategory = [];
        foreach (MemberCategory::cases() as $category) {
            $categoryContributions = Contribution::query()
                ->where('year', $year)
                ->whereHas('user', fn ($q) => $q->where('category', $category))
                ->with('payments')
                ->get();

            $categoryExpected = $categoryContributions->sum('expected_amount');
            $categoryCollected = $categoryContributions->sum(fn ($c) => $c->payments->sum('amount'));

            $byCategory[$category->value] = [
                'label' => $category->label(),
                'expected' => $categoryExpected,
                'collected' => $categoryCollected,
                'outstanding' => $categoryExpected - $categoryCollected,
                'count' => $categoryContributions->count(),
            ];
        }

        return Inertia::render('Reports/Annual', [
            'year' => $year,
            'monthly_breakdown' => $monthlyBreakdown,
            'total' => $yearlyTotal,
            'by_category' => $byCategory,
        ]);
    }
}
