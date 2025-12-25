<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Contribution;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Display the contribution dashboard with role-based props.
     *
     * - Super Admin & Financial Secretary: See all members, summary stats, recent payments
     * - Member: See personal status and family aggregate only (FR-015, FR-016)
     */
    public function index(): Response
    {
        /** @var User $user */
        $user = Auth::user();
        $currentYear = now()->year;
        $currentMonth = now()->month;

        // Check if user can see all member details (admin or financial secretary)
        $canSeeAllMembers = in_array($user->role, [Role::SuperAdmin, Role::FinancialSecretary]);
        $canRecordPayments = $user->role === Role::SuperAdmin || $user->role === Role::FinancialSecretary;

        // Get current month contributions with payments for calculations
        $currentMonthContributions = Contribution::query()
            ->with(['user', 'payments'])
            ->whereHas('user', fn ($q) => $q->whereNull('archived_at'))
            ->where('year', $currentYear)
            ->where('month', $currentMonth)
            ->get();

        // Get all contributions to count overdue (FR-006: past 28th of their month)
        $allContributions = Contribution::query()
            ->with(['user', 'payments'])
            ->whereHas('user', fn ($q) => $q->whereNull('archived_at'))
            ->get();

        $props = [
            'can_record_payments' => $canRecordPayments,
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
     * @param  \Illuminate\Database\Eloquent\Collection<Contribution>  $currentMonthContributions
     * @param  \Illuminate\Database\Eloquent\Collection<Contribution>  $allContributions
     */
    private function calculateSummary($currentMonthContributions, $allContributions): array
    {
        $totalMembers = $currentMonthContributions->count();
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
     * @param  \Illuminate\Database\Eloquent\Collection<Contribution>  $currentMonthContributions
     * @param  \Illuminate\Database\Eloquent\Collection<Contribution>  $allContributions
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
        return Payment::query()
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
                'recorded_by' => $payment->recorder?->name,
                'month' => $payment->contribution->month,
                'year' => $payment->contribution->year,
            ])
            ->toArray();
    }

    /**
     * Calculate family aggregate stats for member view (FR-015).
     *
     * @param  \Illuminate\Database\Eloquent\Collection<Contribution>  $contributions
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
                'expected_amount' => $user->category?->monthlyAmountInKobo() ?? 0,
                'total_paid' => 0,
                'current_month_balance' => $user->category?->monthlyAmountInKobo() ?? 0,
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
}
