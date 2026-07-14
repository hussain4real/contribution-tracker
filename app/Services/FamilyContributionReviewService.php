<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Enums\ReportType;
use App\Models\AuditEvent;
use App\Models\Contribution;
use App\Models\Expense;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\FinancialReversal;
use App\Models\FundAdjustment;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * @phpstan-type RegisterRow array{id: int, member_id: int, member_name: string, member_email: string, period: string, period_date: string, due_date: string, category: string, category_label: string, expected_amount: int, paid_amount: int, outstanding_amount: int, status: string, status_label: string, age_days: int}
 * @phpstan-type ReportData array{title: string, columns: array<string, string>, rows: array<int, array<string, mixed>>, totals: array<string, int|float|string>, filters: array<string, mixed>}
 */
class FamilyContributionReviewService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, RegisterRow>
     */
    public function register(Family $family, array $filters): LengthAwarePaginator
    {
        $rows = collect($this->registerRows($family, $filters));
        $requestedPerPage = $this->filterInteger($filters, 'per_page', 25);
        $perPage = in_array($requestedPerPage, [25, 50, 100], true) ? $requestedPerPage : 25;
        $page = LengthAwarePaginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath(), 'query' => request()->query()],
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{total_expected: int, total_collected: int, total_outstanding: int, collection_rate: float|int, record_count: int, status_counts: array<string, int>}
     */
    public function registerSummary(Family $family, array $filters): array
    {
        $rows = collect($this->registerRows($family, $filters));
        $expected = $this->integerValue($rows->sum('expected_amount'));
        $collected = $this->integerValue($rows->sum('paid_amount'));

        return [
            'total_expected' => $expected,
            'total_collected' => $collected,
            'total_outstanding' => $this->integerValue($rows->sum('outstanding_amount')),
            'collection_rate' => $expected > 0 ? round(($collected / $expected) * 100, 1) : 0,
            'record_count' => $rows->count(),
            'status_counts' => collect(PaymentStatus::cases())
                ->mapWithKeys(fn (PaymentStatus $status): array => [
                    $status->value => $rows->where('status', $status->value)->count(),
                ])
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, RegisterRow>
     */
    public function registerRows(Family $family, array $filters): array
    {
        $query = Contribution::query()
            ->where('family_id', $family->id)
            ->with(['user:id,name,email', 'payments:id,contribution_id,amount'])
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->orderBy('user_id');

        $dateFrom = $this->filterString($filters, 'date_from');
        $dateTo = $this->filterString($filters, 'date_to');
        $memberId = $this->filterInteger($filters, 'member_id');
        $category = $this->filterString($filters, 'category');
        $search = mb_strtolower(trim($this->filterString($filters, 'search')));
        $status = $this->filterString($filters, 'status');
        $minimumOutstanding = $this->filterInteger($filters, 'min_outstanding', -1);

        if ($dateFrom !== '') {
            $query->whereDate('due_date', '>=', $dateFrom);
        }

        if ($dateTo !== '') {
            $query->whereDate('due_date', '<=', $dateTo);
        }

        if ($memberId > 0) {
            $query->where('user_id', $memberId);
        }

        if ($category !== '') {
            $query->where('category_slug', $category);
        }

        if ($search !== '') {
            $query->where(function (Builder $query) use ($search): void {
                $query->whereRaw('LOWER(COALESCE(category_name, \'\')) LIKE ?', ["%{$search}%"])
                    ->orWhereHas('user', fn (Builder $query): Builder => $query
                        ->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                        ->orWhereRaw('LOWER(email) LIKE ?', ["%{$search}%"]));
            });
        }

        return $query->get()
            ->map(function (Contribution $contribution): array {
                $paid = $contribution->total_paid;
                $outstanding = max(0, $contribution->expected_amount - $paid);
                $member = $contribution->user;

                return [
                    'id' => $contribution->id,
                    'member_id' => $contribution->user_id,
                    'member_name' => $member instanceof User ? $member->name : 'Unknown member',
                    'member_email' => $member instanceof User ? $member->email : '',
                    'period' => $contribution->period_label,
                    'period_date' => CarbonImmutable::parse(sprintf('%04d-%02d-01', $contribution->year, $contribution->month))->toDateString(),
                    'due_date' => $contribution->due_date->toDateString(),
                    'category' => $contribution->category_slug ?? 'uncategorized',
                    'category_label' => $contribution->category_name ?? 'Uncategorized',
                    'expected_amount' => $contribution->expected_amount,
                    'paid_amount' => $paid,
                    'outstanding_amount' => $outstanding,
                    'status' => $contribution->status->value,
                    'status_label' => $contribution->status->label(),
                    'age_days' => $outstanding > 0 && $contribution->due_date->isPast()
                        ? intval($contribution->due_date->startOfDay()->diffInDays(now()->startOfDay()))
                        : 0,
                ];
            })
            ->when(
                $status !== '',
                fn (Collection $rows): Collection => $rows->where('status', $status),
            )
            ->when(
                $minimumOutstanding >= 0,
                fn (Collection $rows): Collection => $rows->where('outstanding_amount', '>=', $minimumOutstanding),
            )
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return ReportData
     */
    public function report(Family $family, ReportType $type, array $filters): array
    {
        return match ($type) {
            ReportType::ContributionRegister => $this->contributionReport($family, $type, $filters),
            ReportType::ContributionAging => $this->agingReport($family, $filters),
            ReportType::MemberStatement => $this->contributionReport($family, $type, $filters),
            ReportType::CategoryPerformance => $this->categoryPerformanceReport($family, $filters),
            ReportType::FundStatement => $this->fundStatementReport($family, $filters),
            ReportType::CashFlow => $this->cashFlowReport($family, $filters),
            ReportType::ExpenseTotals => $this->expenseTotalsReport($family, $filters),
            ReportType::Reversals => $this->reversalReport($family, $filters),
            ReportType::AuditActivity => $this->auditReport($family, $filters),
            ReportType::Receipt => $this->contributionReport($family, ReportType::MemberStatement, $filters),
        };
    }

    /** @param array<string, mixed> $filters
     * @return ReportData
     */
    private function contributionReport(Family $family, ReportType $type, array $filters): array
    {
        $rows = collect($this->registerRows($family, $filters));

        return $this->reportPayload($type, [
            'member_name' => 'Member', 'period' => 'Period', 'category_label' => 'Category',
            'expected_amount' => 'Expected', 'paid_amount' => 'Paid',
            'outstanding_amount' => 'Outstanding', 'status_label' => 'Status',
        ], $rows, [
            'expected' => $this->integerValue($rows->sum('expected_amount')),
            'collected' => $this->integerValue($rows->sum('paid_amount')),
            'outstanding' => $this->integerValue($rows->sum('outstanding_amount')),
        ], $filters);
    }

    /** @param array<string, mixed> $filters
     * @return ReportData
     */
    private function agingReport(Family $family, array $filters): array
    {
        $rows = collect($this->registerRows($family, $filters))
            ->where('outstanding_amount', '>', 0)
            ->map(function (array $row): array {
                $days = $row['age_days'];
                $row['age_bucket'] = match (true) {
                    $days <= 0 => 'Current',
                    $days <= 30 => '1-30 days',
                    $days <= 60 => '31-60 days',
                    $days <= 90 => '61-90 days',
                    default => '90+ days',
                };

                return $row;
            })->values();

        return $this->reportPayload(ReportType::ContributionAging, [
            'member_name' => 'Member', 'period' => 'Period', 'due_date' => 'Due Date',
            'outstanding_amount' => 'Outstanding', 'age_days' => 'Age (days)', 'age_bucket' => 'Age Bucket',
        ], $rows, ['outstanding' => $this->integerValue($rows->sum('outstanding_amount'))], $filters);
    }

    /** @param array<string, mixed> $filters
     * @return ReportData
     */
    private function categoryPerformanceReport(Family $family, array $filters): array
    {
        $rows = collect($this->registerRows($family, $filters))->groupBy('category')
            ->map(function (Collection $items): array {
                $expected = $this->integerValue($items->sum('expected_amount'));
                $paid = $this->integerValue($items->sum('paid_amount'));
                $first = $items->first();

                return [
                    'category' => is_array($first) ? $first['category_label'] : 'Uncategorized',
                    'records' => $items->count(),
                    'expected' => $expected,
                    'collected' => $paid,
                    'outstanding' => $this->integerValue($items->sum('outstanding_amount')),
                    'collection_rate' => $expected > 0 ? round(($paid / $expected) * 100, 1) : 0,
                ];
            })->values();

        return $this->reportPayload(ReportType::CategoryPerformance, [
            'category' => 'Category', 'records' => 'Records', 'expected' => 'Expected',
            'collected' => 'Collected', 'outstanding' => 'Outstanding', 'collection_rate' => 'Rate (%)',
        ], $rows, ['expected' => $this->integerValue($rows->sum('expected')), 'collected' => $this->integerValue($rows->sum('collected'))], $filters);
    }

    /** @param array<string, mixed> $filters
     * @return ReportData
     */
    private function fundStatementReport(Family $family, array $filters): array
    {
        $from = CarbonImmutable::parse($this->filterString($filters, 'date_from', now()->startOfYear()->toDateString()))->startOfDay();
        $to = CarbonImmutable::parse($this->filterString($filters, 'date_to', now()->endOfYear()->toDateString()))->endOfDay();
        $payments = Payment::query()->effective()->whereIn('contribution_id', Contribution::query()->where('family_id', $family->id)->select('id'));
        $openingPayments = (int) (clone $payments)->where('paid_at', '<', $from)->sum('amount');
        $postedPayments = (int) (clone $payments)->whereBetween('paid_at', [$from, $to])->sum('amount');
        $openingAdjustments = (int) FundAdjustment::query()->effective()->where('family_id', $family->id)->where('recorded_at', '<', $from)->sum('amount');
        $periodAdjustments = (int) FundAdjustment::query()->effective()->where('family_id', $family->id)->whereBetween('recorded_at', [$from, $to])->sum('amount');
        $openingExpenses = (int) Expense::query()->effective()->where('family_id', $family->id)->where('spent_at', '<', $from)->sum('amount');
        $periodExpenses = (int) Expense::query()->effective()->where('family_id', $family->id)->whereBetween('spent_at', [$from, $to])->sum('amount');
        $reversalCount = FinancialReversal::query()->where('family_id', $family->id)->whereBetween('created_at', [$from, $to])->count();
        $opening = $openingPayments + $openingAdjustments - $openingExpenses;
        $closing = $opening + $postedPayments + $periodAdjustments - $periodExpenses;
        $rows = collect([
            ['item' => 'Opening balance', 'amount' => $opening],
            ['item' => 'Posted payments', 'amount' => $postedPayments],
            ['item' => 'Adjustments', 'amount' => $periodAdjustments],
            ['item' => 'Expenses', 'amount' => -$periodExpenses],
            ['item' => 'Reversals posted', 'amount' => 0, 'count' => $reversalCount],
            ['item' => 'Closing balance', 'amount' => $closing],
            ['item' => 'Reconciliation variance', 'amount' => 0],
        ]);

        return $this->reportPayload(ReportType::FundStatement, ['item' => 'Statement Item', 'amount' => 'Amount', 'count' => 'Count'], $rows, [
            'opening_balance' => $opening,
            'posted_payments' => $postedPayments,
            'adjustments' => $periodAdjustments,
            'expenses' => $periodExpenses,
            'reversals' => $reversalCount,
            'closing_balance' => $closing,
            'reconciliation_variance' => 0,
        ], $filters);
    }

    /** @param array<string, mixed> $filters
     * @return ReportData
     */
    private function cashFlowReport(Family $family, array $filters): array
    {
        $from = $this->filterString($filters, 'date_from', now()->startOfYear()->toDateString());
        $to = $this->filterString($filters, 'date_to', now()->endOfYear()->toDateString());
        $payments = Payment::query()->effective()->whereIn('contribution_id', Contribution::query()->where('family_id', $family->id)->select('id'))
            ->whereBetween('paid_at', [$from, $to])->get()->map(fn (Payment $payment): array => [
                'date' => $payment->paid_at->toDateString(), 'type' => 'Payment', 'description' => $payment->notes ?? 'Contribution payment', 'amount' => $payment->amount,
            ]);
        $expenses = Expense::query()->effective()->where('family_id', $family->id)->whereBetween('spent_at', [$from, $to])->get()
            ->map(fn (Expense $expense): array => ['date' => $expense->spent_at->toDateString(), 'type' => 'Expense', 'description' => $expense->description, 'amount' => -$expense->amount]);
        $adjustments = FundAdjustment::query()->effective()->where('family_id', $family->id)->whereBetween('recorded_at', [$from, $to])->get()
            ->map(fn (FundAdjustment $adjustment): array => ['date' => $adjustment->recorded_at->toDateString(), 'type' => 'Adjustment', 'description' => $adjustment->description, 'amount' => $adjustment->amount]);
        $rows = $payments->concat($expenses)->concat($adjustments)->sortBy('date')->values();

        return $this->reportPayload(ReportType::CashFlow, ['date' => 'Date', 'type' => 'Type', 'description' => 'Description', 'amount' => 'Amount'], $rows, ['net_cash_flow' => $this->integerValue($rows->sum('amount'))], $filters);
    }

    /** @param array<string, mixed> $filters
     * @return ReportData
     */
    private function expenseTotalsReport(Family $family, array $filters): array
    {
        $rows = Expense::query()->effective()->where('family_id', $family->id)
            ->whereBetween('spent_at', [$this->filterString($filters, 'date_from'), $this->filterString($filters, 'date_to')])
            ->get()->groupBy(fn (Expense $expense): string => mb_strtolower(trim($expense->description)))
            ->map(fn (Collection $expenses, string $category): array => [
                'category' => str($category)->headline()->toString(),
                'count' => $expenses->count(),
                'amount' => $this->integerValue($expenses->sum('amount')),
            ])->values();

        return $this->reportPayload(ReportType::ExpenseTotals, ['category' => 'Expense Category', 'count' => 'Entries', 'amount' => 'Total'], $rows, ['expenses' => $this->integerValue($rows->sum('amount'))], $filters);
    }

    /** @param array<string, mixed> $filters
     * @return ReportData
     */
    private function reversalReport(Family $family, array $filters): array
    {
        $rows = FinancialReversal::query()->where('family_id', $family->id)
            ->whereBetween('created_at', [$this->filterString($filters, 'date_from'), $this->filterString($filters, 'date_to')])
            ->with('reverser:id,name')->latest()->get()->map(fn (FinancialReversal $reversal): array => [
                'date' => $reversal->created_at->toDateString(),
                'type' => str($reversal->reversible_type)->headline()->toString(),
                'record_id' => $reversal->reversible_id,
                'reason' => $reversal->reason,
                'reversed_by' => $reversal->reverser->name ?? 'System',
            ]);

        return $this->reportPayload(ReportType::Reversals, ['date' => 'Date', 'type' => 'Type', 'record_id' => 'Record', 'reason' => 'Reason', 'reversed_by' => 'Reversed By'], $rows, ['reversals' => $rows->count()], $filters);
    }

    /** @param array<string, mixed> $filters
     * @return ReportData
     */
    private function auditReport(Family $family, array $filters): array
    {
        $rows = AuditEvent::query()->where('family_id', $family->id)
            ->whereBetween('created_at', [$this->filterString($filters, 'date_from'), $this->filterString($filters, 'date_to')])
            ->with('actor:id,name')->latest()->get()->map(fn (AuditEvent $event): array => [
                'date' => $event->created_at->toDateTimeString(),
                'action' => $event->action,
                'record_type' => str($event->auditable_type)->headline()->toString(),
                'record_id' => $event->auditable_id,
                'actor' => $event->actor->name ?? 'System',
                'request_id' => $event->request_id,
            ]);

        return $this->reportPayload(ReportType::AuditActivity, ['date' => 'Date', 'action' => 'Action', 'record_type' => 'Record Type', 'record_id' => 'Record', 'actor' => 'Actor', 'request_id' => 'Request ID'], $rows, ['events' => $rows->count()], $filters);
    }

    /**
     * @param  array<string, string>  $columns
     * @param  iterable<int, mixed>  $rows
     * @param  array<string, int|float|string>  $totals
     * @param  array<string, mixed>  $filters
     * @return ReportData
     */
    private function reportPayload(ReportType $type, array $columns, iterable $rows, array $totals, array $filters): array
    {
        $normalizedRows = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $normalizedRow = [];

                foreach ($row as $key => $value) {
                    if (is_string($key)) {
                        $normalizedRow[$key] = $value;
                    }
                }

                $normalizedRows[] = $normalizedRow;
            }
        }

        return ['title' => $type->label(), 'columns' => $columns, 'rows' => $normalizedRows, 'totals' => $totals, 'filters' => $filters];
    }

    /**
     * Build a family-scoped contribution review for a single month.
     *
     * @return array{
     *     family: array{name: string|null, currency: string, period: string, year: int, month: int},
     *     summary: array<string, mixed>,
     *     by_category: array<string, array<string, mixed>>,
     *     members: array<int, array<string, mixed>>,
     *     filters: array<string, string>
     * }
     */
    public function monthly(User $user, int $year, int $month, ?string $status = null): array
    {
        $user->loadMissing(['currentFamily', 'family']);

        $family = $user->currentFamily ?? $user->family;
        $members = $this->membersForPeriod($user);
        $contributions = $family instanceof Family
            ? $this->contributionsForPeriod($family, $year, $month)
            : new EloquentCollection;
        $rows = $members->map(fn (FamilyMembership $membership): array => $this->memberRow(
            $membership,
            $contributions->firstWhere('user_id', $membership->user_id),
            $year,
            $month,
        ));
        $allRows = $rows->values();
        $filteredRows = $this->filterRows($allRows, $status);
        $period = CarbonImmutable::parse(sprintf('%04d-%02d-01', $year, $month));

        return [
            'family' => [
                'name' => $family instanceof Family ? $family->name : null,
                'currency' => $family instanceof Family ? $family->currency : 'NGN',
                'period' => $period->format('F Y'),
                'year' => $year,
                'month' => $month,
            ],
            'summary' => $this->summary($allRows),
            'by_category' => $this->categoryBreakdown($allRows),
            'members' => $filteredRows->values()->all(),
            'filters' => [
                'all' => 'All',
                PaymentStatus::Unpaid->value => PaymentStatus::Unpaid->label(),
                PaymentStatus::Partial->value => PaymentStatus::Partial->label(),
                PaymentStatus::Overdue->value => PaymentStatus::Overdue->label(),
                PaymentStatus::Paid->value => PaymentStatus::Paid->label(),
            ],
        ];
    }

    /**
     * @return EloquentCollection<int, FamilyMembership>
     */
    private function membersForPeriod(User $user): EloquentCollection
    {
        $family = $user->currentFamily ?? $user->family;

        if (! $family instanceof Family) {
            return new EloquentCollection;
        }

        return $family->memberships()
            ->with([
                'familyCategory:id,name,monthly_amount',
                'user' => function (Relation $query): void {
                    $query->getQuery()->withExists('pushSubscriptions');
                },
            ])
            ->active()
            ->where(function (Builder $query): void {
                $query->whereNotNull('family_members.family_category_id')
                    ->orWhereNotNull('family_members.category');
            })
            ->join('users', 'users.id', '=', 'family_members.user_id')
            ->orderBy('users.name')
            ->select('family_members.*')
            ->get();
    }

    /**
     * @return EloquentCollection<int, Contribution>
     */
    private function contributionsForPeriod(Family $family, int $year, int $month): EloquentCollection
    {
        return Contribution::query()
            ->where('family_id', $family->id)
            ->forMonth($year, $month)
            ->with('payments:id,contribution_id,amount')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function memberRow(
        FamilyMembership $membership,
        ?Contribution $contribution,
        int $year,
        int $month,
    ): array {
        $member = $membership->user;
        $snapshot = $membership->contributionCategorySnapshot($year, $month);
        $expectedAmount = $contribution instanceof Contribution
            ? $contribution->expected_amount
            : ($snapshot['category_amount'] ?? 0);
        $paidAmount = $contribution instanceof Contribution
            ? (int) $contribution->payments->sum(fn (Payment $payment): int => $payment->amount)
            : 0;
        $balance = max(0, $expectedAmount - $paidAmount);
        $status = $contribution instanceof Contribution ? $contribution->status : PaymentStatus::Unpaid;
        $eligibleChannels = $this->eligibleChannels($member);
        $isReminderEligible = $contribution !== null && $balance > 0 && $eligibleChannels !== [];
        $categorySlug = $snapshot['category_slug'];
        $categoryName = $snapshot['category_name'];

        if ($contribution instanceof Contribution) {
            $categorySlug = $contribution->category_slug ?? $categorySlug;
            $categoryName = $contribution->category_name ?? $categoryName;
        }

        return [
            'id' => $member->id,
            'name' => $membership->displayName(),
            'email' => $member->email,
            'category' => $categorySlug,
            'category_label' => $categoryName,
            'expected_amount' => $expectedAmount,
            'paid_amount' => $paidAmount,
            'balance' => $balance,
            'status' => $status->value,
            'status_label' => $status->label(),
            'contribution_id' => $contribution?->id,
            'due_date' => $contribution?->due_date->toDateString(),
            'period_label' => $contribution?->period_label,
            'reminder_eligible' => $isReminderEligible,
            'reminder_channels' => $isReminderEligible ? $eligibleChannels : [],
            'reminder_ineligible_reason' => $this->reminderIneligibleReason($contribution, $balance, $eligibleChannels),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function eligibleChannels(User $member): array
    {
        $channels = [];

        if (filled($member->email)) {
            $channels[] = 'mail';
        }

        if ($member->hasVerifiedWhatsApp()) {
            $channels[] = 'whatsapp';
        }

        if ((bool) ($member->push_subscriptions_exists ?? false)) {
            $channels[] = 'webpush';
        }

        return $channels;
    }

    /**
     * @param  array<int, string>  $eligibleChannels
     */
    private function reminderIneligibleReason(?Contribution $contribution, int $balance, array $eligibleChannels): ?string
    {
        if ($contribution === null) {
            return 'No contribution record exists for this period.';
        }

        if ($balance <= 0) {
            return 'This contribution is fully paid.';
        }

        if ($eligibleChannels === []) {
            return 'No eligible reminder channel is available.';
        }

        return null;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function filterRows(Collection $rows, ?string $status): Collection
    {
        if ($status === null || $status === '' || $status === 'all') {
            return $rows;
        }

        return $rows->where('status', $status);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summary(Collection $rows): array
    {
        $totalExpected = (int) $rows->sum(fn (array $row): int => $this->integerValue($row['expected_amount'] ?? 0));
        $totalCollected = (int) $rows->sum(fn (array $row): int => $this->integerValue($row['paid_amount'] ?? 0));
        $totalOutstanding = max(0, $totalExpected - $totalCollected);

        return [
            'total_expected' => $totalExpected,
            'total_collected' => $totalCollected,
            'total_outstanding' => $totalOutstanding,
            'collection_rate' => $totalExpected > 0 ? round(($totalCollected / $totalExpected) * 100, 1) : 0,
            'member_count' => $rows->count(),
            'reminder_eligible_count' => $rows->where('reminder_eligible', true)->count(),
            'status_counts' => [
                PaymentStatus::Paid->value => $rows->where('status', PaymentStatus::Paid->value)->count(),
                PaymentStatus::Partial->value => $rows->where('status', PaymentStatus::Partial->value)->count(),
                PaymentStatus::Unpaid->value => $rows->where('status', PaymentStatus::Unpaid->value)->count(),
                PaymentStatus::Overdue->value => $rows->where('status', PaymentStatus::Overdue->value)->count(),
            ],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function categoryBreakdown(Collection $rows): array
    {
        $categories = [];

        foreach ($rows->groupBy('category') as $slug => $categoryRows) {
            $slug = is_string($slug) && $slug !== '' ? $slug : 'uncategorized';
            $expected = (int) $categoryRows->sum(fn (array $row): int => $this->integerValue($row['expected_amount'] ?? 0));
            $collected = (int) $categoryRows->sum(fn (array $row): int => $this->integerValue($row['paid_amount'] ?? 0));

            $first = $categoryRows->first();
            $categories[$slug] = [
                'label' => is_array($first) && is_string($first['category_label'] ?? null)
                    ? $first['category_label']
                    : 'Uncategorized',
                'expected' => $expected,
                'collected' => $collected,
                'outstanding' => max(0, $expected - $collected),
                'count' => $categoryRows->count(),
            ];
        }

        return $categories;
    }

    private function integerValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /** @param array<string, mixed> $filters */
    private function filterString(array $filters, string $key, string $default = ''): string
    {
        $value = $filters[$key] ?? null;

        return is_scalar($value) ? strval($value) : $default;
    }

    /** @param array<string, mixed> $filters */
    private function filterInteger(array $filters, string $key, int $default = 0): int
    {
        $value = $filters[$key] ?? null;

        return is_numeric($value) ? intval($value) : $default;
    }
}
