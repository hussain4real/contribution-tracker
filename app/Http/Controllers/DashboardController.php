<?php

namespace App\Http\Controllers;

use App\Models\Contribution;
use App\Models\Expense;
use App\Models\FundAdjustment;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
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
        /** @var User $user */
        $user = Auth::user();
        $currentYear = now()->year;
        $currentMonth = now()->month;

        // Check if user can see all member details (admin or financial secretary)
        $canSeeAllMembers = $user->canViewAllMembers();
        $canRecordPayments = $user->canRecordPayments();

        // Get current month contributions with payments for calculations
        $currentMonthContributions = Contribution::query()
            ->where('family_id', $user->family_id)
            ->with(['user', 'payments'])
            ->whereHas('user', fn ($q) => $q->whereNull('archived_at'))
            ->where('year', $currentYear)
            ->where('month', $currentMonth)
            ->get();

        // Get all contributions to count overdue (FR-006: past 28th of their month)
        $allContributions = Contribution::query()
            ->where('family_id', $user->family_id)
            ->with(['user', 'payments'])
            ->whereHas('user', fn ($q) => $q->whereNull('archived_at'))
            ->get();

        $membersWithContributions = $currentMonthContributions->pluck('user_id');
        $membersNeedingContributions = User::query()
            ->where('family_id', $user->family_id)
            ->active()
            ->whereNotNull('category')
            ->whereNotIn('id', $membersWithContributions)
            ->exists();

        $props = [
            'can_record_payments' => $canRecordPayments,
            'can_generate_contributions' => $user->isAdmin(),
            'has_pending_contributions' => $membersNeedingContributions,
            'fund_balance' => $this->calculateFundBalance(),
        ];

        if ($canSeeAllMembers) {
            // Admin/Financial Secretary view
            $props['summary'] = $this->calculateSummary($currentMonthContributions, $allContributions);
            $props['member_statuses'] = $this->getMemberStatuses($currentMonthContributions, $allContributions);
            $props['recent_payments'] = $this->getRecentPayments();
        } else {
            // Member view (FR-015, FR-016)
            $props['family_aggregate'] = $this->calculateFamilyAggregate($currentMonthContributions);
            $props['personal'] = $this->getPersonalStatus($user, $currentYear, $currentMonth);
        }

        return Inertia::render('Dashboard/Index', $props);
    }

    /**
     * Calculate summary statistics for current month.
     *
     * @param  Collection<Contribution>  $currentMonthContributions
     * @param  Collection<Contribution>  $allContributions
     */
    private function calculateSummary($currentMonthContributions, $allContributions): array
    {
        /** @var User $user */
        $user = Auth::user();
        $totalMembers = User::where('family_id', $user->family_id)->active()->count();
        $totalExpected = $currentMonthContributions->sum('expected_amount');
        $totalCollected = $currentMonthContributions->sum(fn ($c) => $c->payments->sum('amount'));
        $totalOutstanding = $totalExpected - $totalCollected;

        // Count all overdue contributions across all months (FR-006)
        $overdueCount = $allContributions->filter(fn ($c) => $c->status->value === 'overdue')->count();

        return [
            'total_members' => $totalMembers,
            'total_expected' => $totalExpected,
            'total_collected' => $totalCollected,
            'total_outstanding' => $totalOutstanding,
            'overdue_count' => $overdueCount,
            'collection_rate' => $totalExpected > 0
                ? round(($totalCollected / $totalExpected) * 100, 1)
                : 0,
        ];
    }

    /**
     * Get all members' contribution statuses for the current month.
     *
     * @param  Collection<Contribution>  $currentMonthContributions
     * @param  Collection<Contribution>  $allContributions
     */
    private function getMemberStatuses($currentMonthContributions, $allContributions): array
    {
        // Group all contributions by user to check for overdue
        $overdueByUser = $allContributions
            ->filter(fn ($c) => $c->status->value === 'overdue')
            ->groupBy('user_id')
            ->map(fn ($contributions) => $contributions->isNotEmpty());

        return $currentMonthContributions->map(function ($contribution) use ($overdueByUser) {
            $totalPaid = $contribution->payments->sum('amount');
            $balance = $contribution->expected_amount - $totalPaid;
            $hasOverdue = $overdueByUser->get($contribution->user_id, false);

            return [
                'id' => $contribution->user->id,
                'name' => $contribution->user->name,
                'category' => $contribution->user->category?->value,
                'expected_amount' => $contribution->expected_amount,
                'total_paid' => $totalPaid,
                'current_month_status' => $contribution->status->value,
                'current_month_balance' => $balance,
                'contribution_id' => $contribution->id,
                'has_overdue' => $hasOverdue,
            ];
        })->values()->toArray();
    }

    /**
     * Get recent payments (last 10).
     */
    private function getRecentPayments(): array
    {
        /** @var User $user */
        $user = Auth::user();

        return Payment::query()
            ->whereHas('contribution', fn ($q) => $q->where('family_id', $user->family_id))
            ->with(['contribution.user', 'recorder'])
            ->latest('paid_at')
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn ($payment) => [
                'id' => $payment->id,
                'amount' => $payment->amount,
                'paid_at' => $payment->paid_at->toDateString(),
                'member_name' => $payment->contribution->user->name,
                'category' => $payment->contribution->user->category,
                'recorded_by' => $payment->recorder?->name,
                'month' => $payment->contribution->month,
                'year' => $payment->contribution->year,
            ])
            ->toArray();
    }

    /**
     * Calculate family aggregate stats for member view (FR-015).
     *
     * @param  Collection<Contribution>  $contributions
     */
    private function calculateFamilyAggregate($contributions): array
    {
        $totalExpected = $contributions->sum('expected_amount');
        $totalCollected = $contributions->sum(fn ($c) => $c->payments->sum('amount'));
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
     */
    private function getPersonalStatus(User $user, int $year, int $month): array
    {
        $contribution = Contribution::query()
            ->with('payments')
            ->where('user_id', $user->id)
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        if (! $contribution) {
            return [
                'expected_amount' => $user->category?->monthlyAmount() ?? 0,
                'total_paid' => 0,
                'current_month_balance' => $user->category?->monthlyAmount() ?? 0,
                'current_month_status' => 'unpaid',
            ];
        }

        $totalPaid = $contribution->payments->sum('amount');
        $balance = $contribution->expected_amount - $totalPaid;

        return [
            'expected_amount' => $contribution->expected_amount,
            'total_paid' => $totalPaid,
            'current_month_balance' => $balance,
            'current_month_status' => $contribution->status->value,
        ];
    }

    /**
     * Calculate the total family fund balance.
     *
     * Fund Balance = SUM(payments) + SUM(fund_adjustments) - SUM(expenses)
     */
    private function calculateFundBalance(): int
    {
        /** @var User $user */
        $user = Auth::user();
        $familyId = $user->family_id;

        $totalPayments = (int) Payment::query()
            ->whereHas('contribution', fn ($q) => $q->where('family_id', $familyId))
            ->sum('amount');
        $totalAdjustments = (int) FundAdjustment::query()->where('family_id', $familyId)->sum('amount');
        $totalExpenses = (int) Expense::query()->where('family_id', $familyId)->sum('amount');

        return $totalPayments + $totalAdjustments - $totalExpenses;
    }
}
