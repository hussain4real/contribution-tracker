<?php

namespace App\Services;

use App\Enums\MemberCategory;
use App\Enums\PaymentStatus;
use App\Models\Contribution;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class FamilyContributionReviewService
{
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
        $user->loadMissing('family');

        $members = $this->membersForPeriod($user, $year, $month);
        $rows = $members->map(fn (User $member): array => $this->memberRow($member));
        $allRows = $rows->values();
        $filteredRows = $this->filterRows($allRows, $status);
        $period = CarbonImmutable::create($year, $month, 1);

        return [
            'family' => [
                'name' => $user->family?->name,
                'currency' => $user->family?->currency ?? 'NGN',
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
     * @return EloquentCollection<int, User>
     */
    private function membersForPeriod(User $user, int $year, int $month): EloquentCollection
    {
        return User::query()
            ->where('family_id', $user->family_id)
            ->active()
            ->payingMembers()
            ->with(['familyCategory:id,name,monthly_amount', 'contributions' => function ($query) use ($year, $month): void {
                $query->where('year', $year)
                    ->where('month', $month)
                    ->with('payments:id,contribution_id,amount');
            }])
            ->withExists('pushSubscriptions')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function memberRow(User $member): array
    {
        /** @var Contribution|null $contribution */
        $contribution = $member->contributions->first();
        $expectedAmount = $contribution?->expected_amount ?? $member->getMonthlyAmount() ?? 0;
        $paidAmount = (int) ($contribution?->payments->sum('amount') ?? 0);
        $balance = max(0, $expectedAmount - $paidAmount);
        $status = $contribution?->status ?? PaymentStatus::Unpaid;
        $eligibleChannels = $this->eligibleChannels($member);
        $isReminderEligible = $contribution !== null && $balance > 0 && $eligibleChannels !== [];

        return [
            'id' => $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'category' => $member->category?->value,
            'category_label' => $member->familyCategory?->name ?? $member->category?->label(),
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
        $totalExpected = (int) $rows->sum('expected_amount');
        $totalCollected = (int) $rows->sum('paid_amount');
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

        foreach (MemberCategory::cases() as $category) {
            $categoryRows = $rows->where('category', $category->value);
            $expected = (int) $categoryRows->sum('expected_amount');
            $collected = (int) $categoryRows->sum('paid_amount');

            $categories[$category->value] = [
                'label' => $category->label(),
                'expected' => $expected,
                'collected' => $collected,
                'outstanding' => max(0, $expected - $collected),
                'count' => $categoryRows->count(),
            ];
        }

        return $categories;
    }
}
