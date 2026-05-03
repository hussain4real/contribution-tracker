<?php

namespace App\Http\Controllers;

use App\Enums\MemberCategory;
use App\Enums\PaymentStatus;
use App\Models\Contribution;
use App\Models\User;
use App\Services\FamilyContributionReviewService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function __construct(
        private readonly FamilyContributionReviewService $reviewService,
    ) {}

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

        /** @var User $currentUser */
        $currentUser = $request->user();
        $familyId = $currentUser->family_id;

        // Get all active members
        $members = User::query()
            ->where('family_id', $familyId)
            ->whereNull('archived_at')
            ->whereNotNull('category')
            ->with(['contributions' => function ($query) use ($year, $month) {
                $query->where('year', $year)
                    ->where('month', $month)
                    ->with('payments');
            }])
            ->paginate(15)
            ->through(function ($member) {
                $contribution = $member->contributions->first();
                $expectedAmount = $member->category->monthlyAmount();
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

        $review = $this->reviewService->monthly($currentUser, $year, $month);

        return Inertia::render('Reports/Monthly', [
            'year' => $year,
            'month' => $month,
            'month_name' => $monthDate->format('F'),
            'summary' => $review['summary'],
            'by_category' => $review['by_category'],
            'members' => $members,
        ]);
    }

    /**
     * Display annual contribution summary.
     */
    public function annual(Request $request): Response
    {
        $year = (int) $request->get('year', now()->year);

        /** @var User $currentUser */
        $currentUser = $request->user();
        $familyId = $currentUser->family_id;

        // Fetch all contributions for the year in a single query
        $allContributions = Contribution::query()
            ->where('family_id', $familyId)
            ->where('year', $year)
            ->with(['payments', 'user'])
            ->get();

        // Generate monthly breakdown for all 12 months
        $monthlyBreakdown = [];
        $yearlyTotal = [
            'expected' => 0,
            'collected' => 0,
            'outstanding' => 0,
        ];

        $contributionsByMonth = $allContributions->groupBy('month');

        for ($month = 1; $month <= 12; $month++) {
            $monthDate = Carbon::create($year, $month, 1);

            $contributions = $contributionsByMonth->get($month, collect());

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

        // Calculate by category for the year using already-loaded data
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

        return Inertia::render('Reports/Annual', [
            'year' => $year,
            'monthly_breakdown' => $monthlyBreakdown,
            'total' => $yearlyTotal,
            'by_category' => $byCategory,
        ]);
    }
}
