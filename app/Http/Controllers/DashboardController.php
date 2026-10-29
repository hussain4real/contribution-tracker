<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\Payment;
use App\Models\User;
use App\Support\EffectiveLedger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

/**
 * @phpstan-type DashboardContribution array{id: int, user_id: int, name: string, category_slug: string|null, category_label: string|null, category_name: string|null, year: int, month: int, expected_amount: int, total_paid: int, status: PaymentStatus}
 */
class DashboardController extends Controller
{
    public function __construct(private readonly EffectiveLedger $ledger) {}

    /**
     * Display the contribution dashboard with role-based props.
     *
     * - Admin & Financial Secretary: See all members, summary stats, recent payments
     * - Member: See personal status and family aggregate only (FR-015, FR-016)
     */
    public function index(): Response
    {
        $user = $this->authUser();
        $family = $user->currentFamily ?? $user->family;
        $currentYear = now()->year;
        $currentMonth = now()->month;

        abort_unless($family instanceof Family, 403);

        // Check if user can see all member details (admin or financial secretary)
        $canSeeAllMembers = $user->canViewAllMembers();
        $canRecordPayments = $user->canRecordPayments();

        $allContributions = $this->dashboardContributions($family, $canSeeAllMembers);

        $currentMonthContributions = $allContributions
            ->where('year', $currentYear)
            ->where('month', $currentMonth);

        $membersWithContributions = $currentMonthContributions->pluck('user_id');
        $membersNeedingContributions = $family->memberships()
            ->active()
            ->where(function (Builder $query): void {
                $query->whereNotNull('family_members.family_category_id')
                    ->orWhereNotNull('family_members.category');
            })
            ->whereNotIn('user_id', $membersWithContributions)
            ->exists();

        $props = [
            'can_record_payments' => $canRecordPayments,
            'can_generate_contributions' => $user->isAdmin(),
            'has_pending_contributions' => $membersNeedingContributions,
            'fund_balance' => $this->calculateFundBalance($family->id),
        ];

        if ($canSeeAllMembers) {
            // Admin/Financial Secretary view
            $props['summary'] = $this->calculateSummary($family, $currentMonthContributions, $allContributions);
            $props['member_statuses'] = $this->getMemberStatuses($currentMonthContributions, $allContributions);
            $props['recent_payments'] = $this->getRecentPayments($family, $allContributions);
            $props['overdue_members'] = Inertia::optional(fn (): array => $this->getOverdueMembers($allContributions));
        } else {
            // Member view (FR-015, FR-016)
            $props['family_aggregate'] = $this->calculateFamilyAggregate($currentMonthContributions);
            $props['personal'] = $this->getPersonalStatus($user, $family, $currentYear, $currentMonth);
        }

        return Inertia::render('Dashboard/Index', $props);
    }

    /** @return Builder<Contribution> */
    private function contributionsForFamily(Family $family): Builder
    {
        return Contribution::query()
            ->where('family_id', $family->id)
            ->whereHas('user.familyMemberships', function (Builder $query) use ($family): void {
                $query->where('family_members.family_id', $family->id)
                    ->whereNull('family_members.archived_at');
            });
    }

    /** @return Collection<int, DashboardContribution> */
    private function dashboardContributions(Family $family, bool $includeHistory): Collection
    {
        $members = [];
        if ($includeHistory) {
            foreach ($family->memberships()->active()->with(['user:id,name', 'familyCategory:id,name,monthly_amount'])->get() as $membership) {
                $members[$membership->user_id] = [
                    'name' => $membership->displayName(),
                    'category_label' => $membership->categoryLabel(),
                ];
            }
        }
        $query = $this->contributionsForFamily($family);
        if (! $includeHistory) {
            $query->forMonth(now()->year, now()->month);
        }
        $now = now();
        $overdueDates = [];

        return $query->withSum('payments as paid_amount', 'amount')->toBase()->get()
            ->map(/** @return DashboardContribution */ function (stdClass $row) use ($members, $now, &$overdueDates): array {
                /** @var stdClass&object{id: int, user_id: int, expected_amount: int, paid_amount: int|null, due_date: string|null, year: int, month: int, category_slug: string|null, category_name: string|null} $row */
                $expected = (int) $row->expected_amount;
                $paid = (int) $row->paid_amount;
                $dueKey = $row->due_date === null ? 'period:'.$row->year.'-'.$row->month : 'date:'.$row->due_date;
                $isOverdue = $overdueDates[$dueKey] ??= $now->greaterThan($row->due_date === null
                    ? Contribution::dueDateForMonth((int) $row->year, (int) $row->month, Contribution::DUE_DAY)
                    : Carbon::parse($row->due_date));
                $status = $paid >= $expected ? PaymentStatus::Paid : (
                    $isOverdue ? PaymentStatus::Overdue : ($paid > 0 ? PaymentStatus::Partial : PaymentStatus::Unpaid)
                );

                return [
                    'id' => (int) $row->id,
                    'user_id' => (int) $row->user_id,
                    'name' => $members[$row->user_id]['name'] ?? '',
                    'category_slug' => $row->category_slug,
                    'category_label' => $row->category_name ?? ($members[$row->user_id]['category_label'] ?? null),
                    'category_name' => $row->category_name,
                    'year' => (int) $row->year,
                    'month' => (int) $row->month,
                    'expected_amount' => $expected,
                    'total_paid' => $paid,
                    'status' => $status,
                ];
            });
    }

    /**
     * Calculate summary statistics for current month.
     *
     * @param  Collection<int, DashboardContribution>  $currentMonthContributions
     * @param  Collection<int, DashboardContribution>  $allContributions
     * @return array<string, mixed>
     */
    private function calculateSummary(Family $family, Collection $currentMonthContributions, Collection $allContributions): array
    {
        $totalMembers = $family->memberships()->active()->count();
        $totalExpected = (int) $currentMonthContributions->sum(fn (array $contribution): int => $contribution['expected_amount']);
        $currentMonthCollected = (int) $currentMonthContributions->sum(fn (array $contribution): int => $contribution['total_paid']);
        $totalOutstanding = $totalExpected - $currentMonthCollected;

        // All-time collected across all months
        $allTimeCollected = (int) $allContributions->sum(fn (array $contribution): int => $contribution['total_paid']);

        // Monthly breakdown for the modal (grouped by year-month, sorted descending)
        $monthlyBreakdown = $this->monthlyBreakdown($allContributions);

        // Count all overdue contributions across all months (FR-006)
        $overdueCount = $allContributions->filter(fn (array $contribution): bool => $contribution['status'] === PaymentStatus::Overdue)->count();

        return [
            'total_members' => $totalMembers,
            'total_expected' => $totalExpected,
            'total_collected' => $allTimeCollected,
            'current_month_collected' => $currentMonthCollected,
            'total_outstanding' => $totalOutstanding,
            'monthly_breakdown' => $monthlyBreakdown,
            'overdue_count' => $overdueCount,
            'current_month_collection_rate' => $totalExpected > 0
                ? round(($currentMonthCollected / $totalExpected) * 100, 1)
                : 0,
        ];
    }

    /**
     * Get all members' contribution statuses for the current month.
     *
     * @param  Collection<int, DashboardContribution>  $currentMonthContributions
     * @param  Collection<int, DashboardContribution>  $allContributions
     * @return list<array<string, mixed>>
     */
    private function getMemberStatuses(Collection $currentMonthContributions, Collection $allContributions): array
    {
        $overdueByUser = [];
        $accruedByUser = [];

        foreach ($allContributions as $contribution) {
            $overdueByUser[$contribution['user_id']] = ($overdueByUser[$contribution['user_id']] ?? false)
                || $contribution['status'] === PaymentStatus::Overdue;
            $accruedByUser[$contribution['user_id']] = ($accruedByUser[$contribution['user_id']] ?? 0)
                + max(0, $contribution['expected_amount'] - $contribution['total_paid']);
        }

        $statuses = $currentMonthContributions->map(function (array $contribution) use ($overdueByUser, $accruedByUser): array {
            $totalPaid = $contribution['total_paid'];
            $balance = $contribution['expected_amount'] - $totalPaid;
            $hasOverdue = $overdueByUser[$contribution['user_id']] ?? false;
            $accruedBalance = $accruedByUser[$contribution['user_id']] ?? 0;

            return [
                'id' => $contribution['user_id'],
                'name' => $contribution['name'],
                'category' => $contribution['category_slug'],
                'category_label' => $contribution['category_label'],
                'expected_amount' => $contribution['expected_amount'],
                'total_paid' => $totalPaid,
                'current_month_status' => $contribution['status']->value,
                'current_month_balance' => $balance,
                'accrued_balance' => $accruedBalance,
                'contribution_id' => $contribution['id'],
                'has_overdue' => $hasOverdue,
            ];
        })->values()->all();

        return array_values($statuses);
    }

    /**
     * Get the latest ten effective payments without hydrating the payment history.
     *
     * @param  Collection<int, DashboardContribution>  $allContributions
     * @return list<array<string, mixed>>
     */
    private function getRecentPayments(Family $family, Collection $allContributions): array
    {
        $contributions = $allContributions->keyBy('id');

        return array_values(Payment::query()
            ->effective()
            ->whereIn('contribution_id', $this->contributionsForFamily($family)->select('id'))
            ->with('recorder:id,name')
            ->orderByRaw('date(paid_at) desc')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(function (Payment $payment) use ($contributions): array {
                $contribution = $contributions->get($payment->contribution_id);
                assert($contribution !== null);

                return [
                    'id' => $payment->id,
                    'amount' => $payment->amount,
                    'paid_at' => $payment->paid_at->toDateString(),
                    'member_name' => $contribution['name'],
                    'category' => $contribution['category_name'],
                    'recorded_by' => $payment->recorder?->name,
                    'month' => $contribution['month'],
                    'year' => $contribution['year'],
                ];
            })->all());
    }

    /**
     * Calculate family aggregate stats for member view (FR-015).
     *
     * @param  Collection<int, DashboardContribution>  $contributions
     * @return array<string, int|float>
     */
    private function calculateFamilyAggregate(Collection $contributions): array
    {
        $totalExpected = (int) $contributions->sum(fn (array $contribution): int => $contribution['expected_amount']);
        $totalCollected = (int) $contributions->sum(fn (array $contribution): int => $contribution['total_paid']);
        $totalOutstanding = $totalExpected - $totalCollected;

        return [
            'total_expected' => $totalExpected,
            'total_collected' => $totalCollected,
            'total_outstanding' => $totalOutstanding,
            'collection_rate' => $totalExpected > 0
                ? round(($totalCollected / $totalExpected) * 100, 1)
                : 0,
        ];
    }

    /**
     * Get personal contribution status for the logged-in member.
     *
     * @return array<string, int|string>
     */
    private function getPersonalStatus(User $user, Family $family, int $year, int $month): array
    {
        $contribution = Contribution::query()
            ->with('payments')
            ->where('family_id', $family->id)
            ->where('user_id', $user->id)
            ->where('year', $year)
            ->where('month', $month)
            ->first();
        $expectedAmount = $user->membershipForFamily($family)?->monthlyAmountForPeriod($year, $month) ?? 0;

        if (! $contribution) {
            return [
                'expected_amount' => $expectedAmount,
                'total_paid' => 0,
                'current_month_balance' => $expectedAmount,
                'current_month_status' => 'unpaid',
            ];
        }

        $totalPaid = (int) $contribution->total_paid;
        $balance = $contribution->expected_amount - $totalPaid;

        return [
            'expected_amount' => $contribution->expected_amount,
            'total_paid' => $totalPaid,
            'current_month_balance' => $balance,
            'current_month_status' => $contribution->status->value,
        ];
    }

    /**
     * Get detailed list of overdue members with their overdue contributions.
     *
     * @param  Collection<int, DashboardContribution>  $allContributions
     * @return list<array<string, mixed>>
     */
    private function getOverdueMembers(Collection $allContributions): array
    {
        $members = [];

        foreach ($allContributions as $contribution) {
            if ($contribution['status'] !== PaymentStatus::Overdue) {
                continue;
            }

            $totalPaid = $contribution['total_paid'];

            $members[] = [
                'id' => $contribution['user_id'],
                'name' => $contribution['name'],
                'category' => $contribution['category_slug'],
                'category_label' => $contribution['category_label'],
                'month' => $contribution['month'],
                'year' => $contribution['year'],
                'expected_amount' => $contribution['expected_amount'],
                'total_paid' => $totalPaid,
                'balance' => $contribution['expected_amount'] - $totalPaid,
                'contribution_id' => $contribution['id'],
            ];
        }

        usort($members, fn (array $first, array $second): int => ($first['name'] <=> $second['name'])
            ?: ($second['year'] <=> $first['year'])
            ?: ($second['month'] <=> $first['month']));

        return $members;
    }

    /**
     * Calculate the total family fund balance.
     *
     * Fund Balance = SUM(payments) + SUM(fund_adjustments) - SUM(expenses)
     */
    private function calculateFundBalance(int $familyId): int
    {
        return $this->ledger->balance($familyId);
    }

    /**
     * @param  Collection<int, DashboardContribution>  $allContributions
     * @return list<array{period: string, year: int, month: int, expected: int, collected: int}>
     */
    private function monthlyBreakdown(Collection $allContributions): array
    {
        $breakdown = [];

        foreach ($allContributions as $contribution) {
            $key = $contribution['year'].'-'.str_pad((string) $contribution['month'], 2, '0', STR_PAD_LEFT);

            $breakdown[$key] ??= [
                'period' => $key,
                'year' => $contribution['year'],
                'month' => $contribution['month'],
                'expected' => 0,
                'collected' => 0,
            ];

            $breakdown[$key]['expected'] += $contribution['expected_amount'];
            $breakdown[$key]['collected'] += $contribution['total_paid'];
        }

        krsort($breakdown);

        return array_values($breakdown);
    }
}
