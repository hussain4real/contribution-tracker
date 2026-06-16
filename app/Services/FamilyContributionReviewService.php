<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MemberCategory;
use App\Enums\PaymentStatus;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\Relation;
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
        $user->loadMissing(['currentFamily', 'family']);

        $family = $user->currentFamily ?? $user->family;
        $members = $this->membersForPeriod($user);
        $contributions = $family instanceof Family
            ? $this->contributionsForPeriod($family, $year, $month)
            : new EloquentCollection;
        $rows = $members->map(fn (FamilyMembership $membership): array => $this->memberRow(
            $membership,
            $contributions->firstWhere('user_id', $membership->user_id),
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
            ->whereHas('user', function (Builder $query): void {
                $query->whereNull('archived_at');
            })
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
    private function memberRow(FamilyMembership $membership, ?Contribution $contribution): array
    {
        $member = $membership->user;
        $expectedAmount = $contribution instanceof Contribution
            ? $contribution->expected_amount
            : ($membership->monthlyAmount() ?? 0);
        $paidAmount = $contribution instanceof Contribution
            ? (int) $contribution->payments->sum(fn (Payment $payment): int => $payment->amount)
            : 0;
        $balance = max(0, $expectedAmount - $paidAmount);
        $status = $contribution instanceof Contribution ? $contribution->status : PaymentStatus::Unpaid;
        $eligibleChannels = $this->eligibleChannels($member);
        $isReminderEligible = $contribution !== null && $balance > 0 && $eligibleChannels !== [];

        return [
            'id' => $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'category' => $membership->category?->value,
            'category_label' => $membership->categoryLabel(),
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

        foreach (MemberCategory::cases() as $category) {
            $categoryRows = $rows->where('category', $category->value);
            $expected = (int) $categoryRows->sum(fn (array $row): int => $this->integerValue($row['expected_amount'] ?? 0));
            $collected = (int) $categoryRows->sum(fn (array $row): int => $this->integerValue($row['paid_amount'] ?? 0));

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

    private function integerValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
