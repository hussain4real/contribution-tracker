<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Enums\ReportFormat;
use App\Enums\ReportScheduleFrequency;
use App\Enums\ReportType;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Payment;
use App\Models\ReportSchedule;
use App\Services\FamilyContributionReviewService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
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
    public function index(Request $request): Response
    {
        $currentYear = now()->year;
        $years = range($currentYear - 5, $currentYear);
        $user = $this->user($request);
        $family = $user->currentFamily ?? $user->family;
        abort_unless($family instanceof Family, 403);

        return Inertia::render('Reports/Index', [
            'years' => $years,
            'current_year' => $currentYear,
            'current_month' => now()->month,
            'report_types' => collect(ReportType::cases())
                ->reject(fn (ReportType $type): bool => $type === ReportType::Receipt)
                ->map(fn (ReportType $type): array => ['value' => $type->value, 'label' => $type->label()])
                ->values(),
            'formats' => collect(ReportFormat::cases())->map(fn (ReportFormat $format): array => [
                'value' => $format->value,
                'label' => strtoupper($format->value),
            ]),
            'frequencies' => collect(ReportScheduleFrequency::cases())->map(fn (ReportScheduleFrequency $frequency): array => [
                'value' => $frequency->value,
                'label' => str($frequency->value)->headline()->toString(),
            ]),
            'members' => $family->memberships()->active()->with('user:id,name')->get()
                ->map(fn (FamilyMembership $membership): array => ['id' => $membership->user_id, 'name' => $membership->displayName()])
                ->sortBy('name')->values(),
            'schedules' => $family->reportSchedules()->withCount('deliveries')->latest()->get()
                ->map(fn (ReportSchedule $schedule): array => [
                    'id' => $schedule->id,
                    'name' => $schedule->name,
                    'report_type' => $schedule->report_type->label(),
                    'format' => strtoupper($schedule->format->value),
                    'frequency' => str($schedule->frequency->value)->headline()->toString(),
                    'next_run_at' => $schedule->next_run_at->toIso8601String(),
                    'deliveries_count' => $schedule->deliveries_count,
                    'is_active' => $schedule->is_active,
                ]),
        ]);
    }

    /**
     * Display monthly contribution summary.
     */
    public function monthly(Request $request): Response
    {
        $year = $this->integerInput($request->get('year'), now()->year);
        $month = $this->integerInput($request->get('month'), now()->month);
        $monthDate = Carbon::parse(sprintf('%04d-%02d-01', $year, $month));

        $currentUser = $this->user($request);
        $family = $currentUser->currentFamily ?? $currentUser->family;

        abort_unless($family instanceof Family, 403);

        // Get all active members
        $members = $family->memberships()
            ->with([
                'familyCategory:id,name,monthly_amount',
                'user.contributions.payments',
            ])
            ->active()
            ->where(function (Builder $query): void {
                $query->whereNotNull('family_members.family_category_id')
                    ->orWhereNotNull('family_members.category');
            })
            ->join('users', 'users.id', '=', 'family_members.user_id')
            ->orderBy('users.name')
            ->select('family_members.*')
            ->paginate(15)
            ->through(function (FamilyMembership $membership) use ($family, $year, $month): array {
                $member = $membership->user;
                $contribution = $member->contributions->first(
                    fn (Contribution $contribution): bool => $contribution->family_id === $family->id
                        && $contribution->year === $year
                        && $contribution->month === $month,
                );
                $snapshot = $membership->contributionCategorySnapshot($year, $month);
                $expectedAmount = $snapshot['category_amount'] ?? 0;
                $paidAmount = 0;
                $categorySlug = $snapshot['category_slug'];
                $categoryName = $snapshot['category_name'];

                if ($contribution instanceof Contribution) {
                    $expectedAmount = $contribution->expected_amount;
                    $paidAmount = $this->paidAmount($contribution);
                    $categorySlug = $contribution->category_slug ?? $categorySlug;
                    $categoryName = $contribution->category_name ?? $categoryName;
                }

                return [
                    'id' => $member->id,
                    'name' => $membership->displayName(),
                    'category' => $categorySlug,
                    'category_label' => $categoryName,
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
        $year = $this->integerInput($request->get('year'), now()->year);

        $currentUser = $this->user($request);
        $family = $currentUser->currentFamily ?? $currentUser->family;

        abort_unless($family instanceof Family, 403);

        // Fetch all contributions for the year in a single query
        $allContributions = Contribution::query()
            ->where('contributions.family_id', $family->id)
            ->where('contributions.year', $year)
            ->with('payments')
            ->get()
            ->keyBy('id');

        // Generate monthly breakdown for all 12 months
        $monthlyBreakdown = [];
        $yearlyTotal = [
            'expected' => 0,
            'collected' => 0,
            'outstanding' => 0,
        ];

        for ($month = 1; $month <= 12; $month++) {
            $monthDate = Carbon::parse(sprintf('%04d-%02d-01', $year, $month));
            $monthContributions = $allContributions->filter(
                fn (Contribution $contribution): bool => $contribution->month === $month,
            );

            $expected = (int) $monthContributions->sum(fn (Contribution $contribution): int => $contribution->expected_amount);
            $collected = (int) $monthContributions->sum(fn (Contribution $contribution): int => $this->paidAmount($contribution));
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
                'contribution_count' => $monthContributions->count(),
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
        foreach ($allContributions->groupBy('category_slug') as $slug => $categoryContributions) {
            $slug = is_string($slug) && $slug !== '' ? $slug : 'uncategorized';

            $categoryExpected = (int) $categoryContributions->sum(fn (Contribution $contribution): int => $contribution->expected_amount);
            $categoryCollected = (int) $categoryContributions->sum(fn (Contribution $contribution): int => $this->paidAmount($contribution));

            $first = $categoryContributions->first();
            $byCategory[$slug] = [
                'label' => $first instanceof Contribution && filled($first->category_name)
                    ? $first->category_name
                    : 'Uncategorized',
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

    private function integerInput(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    private function paidAmount(Contribution $contribution): int
    {
        return (int) $contribution->payments->sum(fn (Payment $payment): int => $payment->amount);
    }
}
