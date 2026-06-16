<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Models\Contribution;
use App\Models\Expense;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\FundAdjustment;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
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

        // Eager-load payments (used for sum calculations) and user (for member filtering)
        $allContributions = Contribution::query()
            ->where('family_id', $family->id)
            ->with(['user.familyMemberships.familyCategory:id,name,monthly_amount', 'payments.recorder'])
            ->whereHas('user', function (Builder $query): void {
                $query->whereNull('archived_at');
            })
            ->get();

        $currentMonthContributions = $allContributions
            ->where('year', $currentYear)
            ->where('month', $currentMonth);

        $membersWithContributions = $currentMonthContributions->pluck('user_id');
        $membersNeedingContributions = $family->memberships()
            ->whereHas('user', function (Builder $query): void {
                $query->whereNull('archived_at');
            })
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
            $props['recent_payments'] = $this->getRecentPayments($allContributions);
            $props['overdue_members'] = $this->getOverdueMembers($allContributions);
        } else {
            // Member view (FR-015, FR-016)
            $props['family_aggregate'] = $this->calculateFamilyAggregate($currentMonthContributions);
            $props['personal'] = $this->getPersonalStatus($user, $family, $currentYear, $currentMonth);
        }

        return Inertia::render('Dashboard/Index', $props);
    }

    /**
     * Calculate summary statistics for current month.
     *
     * @param  Collection<int, Contribution>  $currentMonthContributions
     * @param  Collection<int, Contribution>  $allContributions
     * @return array<string, mixed>
     */
    private function calculateSummary(Family $family, Collection $currentMonthContributions, Collection $allContributions): array
    {
        $totalMembers = $family->members()->whereNull('users.archived_at')->count();
        $totalExpected = (int) $currentMonthContributions->sum(fn (Contribution $contribution): int => $contribution->expected_amount);
        $currentMonthCollected = (int) $currentMonthContributions->sum(fn (Contribution $contribution): int => $this->paidAmount($contribution));
        $totalOutstanding = $totalExpected - $currentMonthCollected;

        // All-time collected across all months
        $allTimeCollected = (int) $allContributions->sum(fn (Contribution $contribution): int => $this->paidAmount($contribution));

        // Monthly breakdown for the modal (grouped by year-month, sorted descending)
        $monthlyBreakdown = $this->monthlyBreakdown($allContributions);

        // Count all overdue contributions across all months (FR-006)
        $overdueCount = $allContributions->filter(fn (Contribution $contribution): bool => $contribution->status === PaymentStatus::Overdue)->count();

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
     * @param  Collection<int, Contribution>  $currentMonthContributions
     * @param  Collection<int, Contribution>  $allContributions
     * @return list<array<string, mixed>>
     */
    private function getMemberStatuses(Collection $currentMonthContributions, Collection $allContributions): array
    {
        $overdueByUser = [];
        $accruedByUser = [];

        foreach ($allContributions as $contribution) {
            $overdueByUser[$contribution->user_id] = ($overdueByUser[$contribution->user_id] ?? false)
                || $contribution->status === PaymentStatus::Overdue;
            $accruedByUser[$contribution->user_id] = ($accruedByUser[$contribution->user_id] ?? 0)
                + max(0, $contribution->expected_amount - $this->paidAmount($contribution));
        }

        $statuses = $currentMonthContributions->map(function (Contribution $contribution) use ($overdueByUser, $accruedByUser): array {
            $member = $contribution->user;
            $membership = $this->contributionMembership($contribution);
            $totalPaid = $this->paidAmount($contribution);
            $balance = $contribution->expected_amount - $totalPaid;
            $hasOverdue = $overdueByUser[$contribution->user_id] ?? false;
            $accruedBalance = $accruedByUser[$contribution->user_id] ?? 0;

            return [
                'id' => $member?->id,
                'name' => $member?->name,
                'category' => $membership?->category?->value,
                'category_label' => $membership?->categoryLabel(),
                'expected_amount' => $contribution->expected_amount,
                'total_paid' => $totalPaid,
                'current_month_status' => $contribution->status->value,
                'current_month_balance' => $balance,
                'accrued_balance' => $accruedBalance,
                'contribution_id' => $contribution->id,
                'has_overdue' => $hasOverdue,
            ];
        })->values()->all();

        return array_values($statuses);
    }

    /**
     * Get recent payments (last 10) from already-loaded contributions.
     *
     * @param  Collection<int, Contribution>  $allContributions
     * @return list<array<string, mixed>>
     */
    private function getRecentPayments(Collection $allContributions): array
    {
        $payments = [];

        foreach ($allContributions as $contribution) {
            foreach ($contribution->payments as $payment) {
                $payments[] = [
                    'id' => $payment->id,
                    'amount' => $payment->amount,
                    'paid_at' => $payment->paid_at->toDateString(),
                    'member_name' => $contribution->user instanceof User ? $contribution->user->name : 'Unknown',
                    'category' => $this->contributionMembership($contribution)?->categoryLabel(),
                    'recorded_by' => $payment->recorder?->name,
                    'month' => $contribution->month,
                    'year' => $contribution->year,
                ];
            }
        }

        usort($payments, fn (array $first, array $second): int => [$second['paid_at'], $second['id']] <=> [$first['paid_at'], $first['id']]);

        return array_slice($payments, 0, 10);
    }

    /**
     * Calculate family aggregate stats for member view (FR-015).
     *
     * @param  Collection<int, Contribution>  $contributions
     * @return array<string, int|float>
     */
    private function calculateFamilyAggregate(Collection $contributions): array
    {
        $totalExpected = (int) $contributions->sum(fn (Contribution $contribution): int => $contribution->expected_amount);
        $totalCollected = (int) $contributions->sum(fn (Contribution $contribution): int => $this->paidAmount($contribution));
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
        $expectedAmount = $user->membershipForFamily($family)?->monthlyAmount() ?? 0;

        if (! $contribution) {
            return [
                'expected_amount' => $expectedAmount,
                'total_paid' => 0,
                'current_month_balance' => $expectedAmount,
                'current_month_status' => 'unpaid',
            ];
        }

        $totalPaid = $this->paidAmount($contribution);
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
     * @param  Collection<int, Contribution>  $allContributions
     * @return list<array<string, mixed>>
     */
    private function getOverdueMembers(Collection $allContributions): array
    {
        $members = [];

        foreach ($allContributions as $contribution) {
            if ($contribution->status !== PaymentStatus::Overdue) {
                continue;
            }

            $totalPaid = $this->paidAmount($contribution);
            $membership = $this->contributionMembership($contribution);

            $members[] = [
                'id' => $contribution->user?->id,
                'name' => $contribution->user instanceof User ? $contribution->user->name : 'Unknown',
                'category' => $membership?->category?->value,
                'category_label' => $membership?->categoryLabel(),
                'month' => $contribution->month,
                'year' => $contribution->year,
                'expected_amount' => $contribution->expected_amount,
                'total_paid' => $totalPaid,
                'balance' => $contribution->expected_amount - $totalPaid,
                'contribution_id' => $contribution->id,
            ];
        }

        usort($members, fn (array $first, array $second): int => [
            $first['name'],
            -$first['year'],
            -$first['month'],
        ] <=> [
            $second['name'],
            -$second['year'],
            -$second['month'],
        ]);

        return $members;
    }

    /**
     * Calculate the total family fund balance.
     *
     * Fund Balance = SUM(payments) + SUM(fund_adjustments) - SUM(expenses)
     */
    private function calculateFundBalance(int $familyId): int
    {
        $totalPayments = (int) Payment::query()
            ->whereIn(
                'contribution_id',
                Contribution::query()->where('family_id', $familyId)->select('id'),
            )
            ->sum('amount');
        $totalAdjustments = (int) FundAdjustment::query()->where('family_id', $familyId)->sum('amount');
        $totalExpenses = (int) Expense::query()->where('family_id', $familyId)->sum('amount');

        return $totalPayments + $totalAdjustments - $totalExpenses;
    }

    private function paidAmount(Contribution $contribution): int
    {
        return (int) $contribution->payments->sum(fn (Payment $payment): int => $payment->amount);
    }

    private function contributionMembership(Contribution $contribution): ?FamilyMembership
    {
        return $contribution->user?->membershipForFamilyId($contribution->family_id);
    }

    /**
     * @param  Collection<int, Contribution>  $allContributions
     * @return list<array{period: string, year: int, month: int, expected: int, collected: int}>
     */
    private function monthlyBreakdown(Collection $allContributions): array
    {
        $breakdown = [];

        foreach ($allContributions as $contribution) {
            $key = $contribution->year.'-'.str_pad((string) $contribution->month, 2, '0', STR_PAD_LEFT);

            $breakdown[$key] ??= [
                'period' => $key,
                'year' => $contribution->year,
                'month' => $contribution->month,
                'expected' => 0,
                'collected' => 0,
            ];

            $breakdown[$key]['expected'] += $contribution->expected_amount;
            $breakdown[$key]['collected'] += $this->paidAmount($contribution);
        }

        krsort($breakdown);

        return array_values($breakdown);
    }
}
