<?php

declare(strict_types=1);

use App\Models\Contribution;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\FinancialReversal;
use App\Models\Payment;
use App\Models\PaymentBatch;
use App\Models\User;
use App\Services\HistoricalPaymentRiskFeatureBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

function riskContribution(
    Family $family,
    User $member,
    string $createdAt,
    string $dueDate,
    int $expectedAmount = 100,
): Contribution {
    $date = CarbonImmutable::parse($dueDate);

    return Contribution::factory()->create([
        'family_id' => $family->id,
        'user_id' => $member->id,
        'year' => $date->year,
        'month' => $date->month,
        'expected_amount' => $expectedAmount,
        'due_date' => $dueDate,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

function recordedRiskPayment(Contribution $contribution, int $amount, string $recordedAt, ?int $batchId = null): Payment
{
    return Payment::factory()->create([
        'contribution_id' => $contribution->id,
        'payment_batch_id' => $batchId,
        'amount' => $amount,
        'paid_at' => $recordedAt,
        'created_at' => $recordedAt,
        'updated_at' => $recordedAt,
    ]);
}

it('reconstructs exact leakage-safe history features at the contribution cutoff', function () {
    $family = Family::factory()->create();
    $member = User::factory()->member()->create(['family_id' => $family->id]);
    $april = riskContribution($family, $member, '2026-04-01 09:00:00', '2026-04-28');
    $may = riskContribution($family, $member, '2026-05-01 09:00:00', '2026-05-28');
    $june = riskContribution($family, $member, '2026-06-01 09:00:00', '2026-06-28');
    $backfilled = riskContribution($family, $member, '2026-03-27 09:00:00', '2026-03-28');
    $target = riskContribution($family, $member, '2026-07-01 09:00:00', '2026-07-28', 200);
    recordedRiskPayment($april, 100, '2026-04-20 10:00:00');
    recordedRiskPayment($may, 40, '2026-05-20 10:00:00');
    recordedRiskPayment($may, 60, '2026-06-01 10:00:00');
    recordedRiskPayment($backfilled, 100, '2026-03-27 10:00:00');
    recordedRiskPayment($target, 200, '2026-07-01 10:00:00');

    $snapshots = app(HistoricalPaymentRiskFeatureBuilder::class)->buildForFamily(
        $family,
        new EloquentCollection([$target]),
    );
    $snapshot = $snapshots[$target->id];
    $features = $snapshot['features'];

    expect($snapshot['history_periods'])->toBe(3)
        ->and($snapshot['timing_eligible'])->toBeTrue()
        ->and($features['expected_amount_minor'])->toBe(20_000.0)
        ->and($features['days_until_due'])->toBe(27.0)
        ->and($features['calendar_month'])->toBe(7.0)
        ->and($features['calendar_quarter'])->toBe(3.0)
        ->and($features['prior_3_on_time_rate'])->toEqualWithDelta(1 / 3, 1.0E-12)
        ->and($features['prior_6_on_time_rate'])->toEqualWithDelta(1 / 3, 1.0E-12)
        ->and($features['prior_lifetime_on_time_rate'])->toEqualWithDelta(1 / 3, 1.0E-12)
        ->and($features['prior_partial_payment_rate'])->toEqualWithDelta(1 / 3, 1.0E-12)
        ->and($features['mean_recorded_settlement_delay_days'])->toBe(-2.0)
        ->and($features['median_recorded_settlement_delay_days'])->toBe(-2.0)
        ->and($features['prior_outstanding_count'])->toBe(1.0)
        ->and($features['prior_outstanding_amount_minor'])->toBe(10_000.0)
        ->and($features['overdue_streak'])->toBe(2.0);
});

it('evaluates reversal timing at the historical due-date horizon', function () {
    $family = Family::factory()->create();
    $member = User::factory()->member()->create(['family_id' => $family->id]);
    $membership = FamilyMembership::query()
        ->where('family_id', $family->id)
        ->where('user_id', $member->id)
        ->firstOrFail();
    $prior = riskContribution($family, $member, '2026-06-01 09:00:00', '2026-06-28');
    $target = riskContribution($family, $member, '2026-07-01 09:00:00', '2026-07-28');
    $batch = PaymentBatch::factory()->create([
        'family_id' => $family->id,
        'family_membership_id' => $membership->id,
        'member_name' => $membership->displayName(),
        'total_amount' => 100,
        'paid_at' => '2026-06-20',
        'created_at' => '2026-06-20 10:00:00',
        'updated_at' => '2026-06-20 10:00:00',
    ]);
    recordedRiskPayment($prior, 100, '2026-06-20 10:00:00', $batch->id);
    FinancialReversal::factory()->create([
        'family_id' => $family->id,
        'reversible_type' => PaymentBatch::MORPH_TYPE,
        'reversible_id' => $batch->id,
        'created_at' => '2026-06-30 10:00:00',
    ]);

    $features = app(HistoricalPaymentRiskFeatureBuilder::class)
        ->buildForFamily($family, new EloquentCollection([$target]))[$target->id]['features'];

    expect($features['previous_mature_period_count'])->toBe(1.0)
        ->and($features['prior_lifetime_on_time_rate'])->toBe(1.0)
        ->and($features['prior_outstanding_count'])->toBe(1.0)
        ->and($features['prior_outstanding_amount_minor'])->toBe(0.0)
        ->and($features['mean_recorded_settlement_delay_days'])->toBe(0.0);
});

it('marks obligations created fewer than seven days before due as unavailable', function () {
    $family = Family::factory()->create();
    $member = User::factory()->member()->create(['family_id' => $family->id]);
    $target = riskContribution($family, $member, '2026-07-24 09:00:00', '2026-07-28');

    $snapshot = app(HistoricalPaymentRiskFeatureBuilder::class)
        ->buildForFamily($family, new EloquentCollection([$target]))[$target->id];

    expect($snapshot['timing_eligible'])->toBeFalse()
        ->and($snapshot['history_periods'])->toBe(0);
});

it('treats seven calendar days as the exact scoring lead-time boundary', function () {
    $family = Family::factory()->create();
    $eligibleMember = User::factory()->member()->create(['family_id' => $family->id]);
    $ineligibleMember = User::factory()->member()->create(['family_id' => $family->id]);
    $eligible = riskContribution($family, $eligibleMember, '2026-07-21 23:59:59', '2026-07-28');
    $ineligible = riskContribution($family, $ineligibleMember, '2026-07-22 00:00:00', '2026-07-28');

    $snapshots = app(HistoricalPaymentRiskFeatureBuilder::class)->buildForFamily(
        $family,
        new EloquentCollection([$eligible, $ineligible]),
    );

    expect($snapshots[$eligible->id]['timing_eligible'])->toBeTrue()
        ->and($snapshots[$eligible->id]['features']['days_until_due'])->toBe(7.0)
        ->and($snapshots[$ineligible->id]['timing_eligible'])->toBeFalse()
        ->and($snapshots[$ineligible->id]['features']['days_until_due'])->toBe(6.0);
});

it('includes payments at the final due-date second and excludes the first second after due', function () {
    $family = Family::factory()->create();
    $member = User::factory()->member()->create(['family_id' => $family->id]);
    $exact = riskContribution($family, $member, '2026-05-01 09:00:00', '2026-05-28');
    $late = riskContribution($family, $member, '2026-06-01 09:00:00', '2026-06-28');
    $target = riskContribution($family, $member, '2026-07-01 09:00:00', '2026-07-28');
    recordedRiskPayment($exact, 100, '2026-05-28 23:59:59');
    recordedRiskPayment($late, 100, '2026-06-29 00:00:00');

    $features = app(HistoricalPaymentRiskFeatureBuilder::class)
        ->buildForFamily($family, new EloquentCollection([$target]))[$target->id]['features'];

    expect($features['previous_mature_period_count'])->toBe(2.0)
        ->and($features['prior_lifetime_on_time_rate'])->toBe(0.5)
        ->and($features['mean_recorded_settlement_delay_days'])->toBe(0.5)
        ->and($features['prior_outstanding_count'])->toBe(0.0);
});

it('applies reversals strictly before the target cutoff but not at exact equality', function () {
    $family = Family::factory()->create();
    $exactMember = User::factory()->member()->create(['family_id' => $family->id]);
    $earlierMember = User::factory()->member()->create(['family_id' => $family->id]);
    $exactPrior = riskContribution($family, $exactMember, '2026-06-01 09:00:00', '2026-06-28');
    $earlierPrior = riskContribution($family, $earlierMember, '2026-06-01 09:00:00', '2026-06-28');
    $exactTarget = riskContribution($family, $exactMember, '2026-07-01 09:00:00', '2026-07-28');
    $earlierTarget = riskContribution($family, $earlierMember, '2026-07-01 09:00:00', '2026-07-28');

    foreach ([
        [$exactMember, $exactPrior, '2026-07-01 09:00:00', 1001],
        [$earlierMember, $earlierPrior, '2026-07-01 08:59:59', 1002],
    ] as [$member, $prior, $reversedAt, $receiptNumber]) {
        $membership = FamilyMembership::query()
            ->where('family_id', $family->id)
            ->where('user_id', $member->id)
            ->firstOrFail();
        $batch = PaymentBatch::factory()->create([
            'family_id' => $family->id,
            'family_membership_id' => $membership->id,
            'member_name' => $membership->displayName(),
            'total_amount' => 100,
            'receipt_number' => $receiptNumber,
            'paid_at' => '2026-06-20',
            'created_at' => '2026-06-20 10:00:00',
            'updated_at' => '2026-06-20 10:00:00',
        ]);
        recordedRiskPayment($prior, 100, '2026-06-20 10:00:00', $batch->id);
        FinancialReversal::factory()->create([
            'family_id' => $family->id,
            'reversible_type' => PaymentBatch::MORPH_TYPE,
            'reversible_id' => $batch->id,
            'created_at' => $reversedAt,
        ]);
    }

    $snapshots = app(HistoricalPaymentRiskFeatureBuilder::class)->buildForFamily(
        $family,
        new EloquentCollection([$exactTarget, $earlierTarget]),
    );

    expect($snapshots[$exactTarget->id]['features']['prior_outstanding_count'])->toBe(0.0)
        ->and($snapshots[$exactTarget->id]['features']['prior_outstanding_amount_minor'])->toBe(0.0)
        ->and($snapshots[$earlierTarget->id]['features']['prior_outstanding_count'])->toBe(1.0);
});

it('uses payments strictly before the scoring cutoff while retaining due-date residuals', function () {
    $family = Family::factory()->create();
    $exactMember = User::factory()->member()->create(['family_id' => $family->id]);
    $earlierMember = User::factory()->member()->create(['family_id' => $family->id]);
    $exactPrior = riskContribution($family, $exactMember, '2026-06-01 09:00:00', '2026-06-28');
    $earlierPrior = riskContribution($family, $earlierMember, '2026-06-01 09:00:00', '2026-06-28');
    $exactTarget = riskContribution($family, $exactMember, '2026-07-01 09:00:00', '2026-07-28');
    $earlierTarget = riskContribution($family, $earlierMember, '2026-07-01 09:00:00', '2026-07-28');
    recordedRiskPayment($exactPrior, 100, '2026-07-01 09:00:00');
    recordedRiskPayment($earlierPrior, 100, '2026-07-01 08:59:59');

    $snapshots = app(HistoricalPaymentRiskFeatureBuilder::class)->buildForFamily(
        $family,
        new EloquentCollection([$exactTarget, $earlierTarget]),
    );

    expect($snapshots[$exactTarget->id]['features']['prior_outstanding_count'])->toBe(1.0)
        ->and($snapshots[$exactTarget->id]['features']['prior_outstanding_amount_minor'])->toBe(10_000.0)
        ->and($snapshots[$earlierTarget->id]['features']['prior_outstanding_count'])->toBe(0.0);
});

it('ignores payments without a recorded timestamp and accepts unreversed batches', function () {
    $family = Family::factory()->create();
    $member = User::factory()->member()->create(['family_id' => $family->id]);
    $membership = FamilyMembership::query()
        ->where('family_id', $family->id)
        ->where('user_id', $member->id)
        ->firstOrFail();
    $unrecordedPrior = riskContribution($family, $member, '2026-05-01 09:00:00', '2026-05-28');
    $batchedPrior = riskContribution($family, $member, '2026-06-01 09:00:00', '2026-06-28');
    $target = riskContribution($family, $member, '2026-07-01 09:00:00', '2026-07-28');
    $unrecorded = recordedRiskPayment($unrecordedPrior, 100, '2026-05-20 10:00:00');
    DB::table('payments')->where('id', $unrecorded->id)->update(['created_at' => null]);
    $batch = PaymentBatch::factory()->create([
        'family_id' => $family->id,
        'family_membership_id' => $membership->id,
        'member_name' => $membership->displayName(),
        'total_amount' => 100,
        'receipt_number' => 2001,
        'paid_at' => '2026-06-20',
        'created_at' => '2026-06-20 10:00:00',
        'updated_at' => '2026-06-20 10:00:00',
    ]);
    recordedRiskPayment($batchedPrior, 100, '2026-06-20 10:00:00', $batch->id);

    $features = app(HistoricalPaymentRiskFeatureBuilder::class)
        ->buildForFamily($family, new EloquentCollection([$target]))[$target->id]['features'];

    expect($features['previous_mature_period_count'])->toBe(2.0)
        ->and($features['prior_lifetime_on_time_rate'])->toBe(0.5)
        ->and($features['prior_outstanding_count'])->toBe(1.0);
});

it('fails closed for empty foreign membership-less and timestamp-less target collections', function () {
    $builder = app(HistoricalPaymentRiskFeatureBuilder::class);
    $family = Family::factory()->create();
    $otherFamily = Family::factory()->create();
    $member = User::factory()->member()->create(['family_id' => $family->id]);
    $otherMember = User::factory()->member()->create(['family_id' => $otherFamily->id]);
    $foreign = riskContribution($otherFamily, $otherMember, '2026-07-01 09:00:00', '2026-07-28');
    $membershipLess = riskContribution($family, $otherMember, '2026-08-01 09:00:00', '2026-08-28');
    $timestampLess = riskContribution($family, $member, '2026-07-01 09:00:00', '2026-07-28');
    $timestampLess->setAttribute('created_at', null);

    expect($builder->buildForFamily($family, new EloquentCollection))->toBe([])
        ->and(fn () => $builder->buildForFamily($family, new EloquentCollection([$foreign])))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $builder->buildForFamily($family, new EloquentCollection([$membershipLess])))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $builder->buildForFamily($family, new EloquentCollection([$timestampLess])))
        ->toThrow(InvalidArgumentException::class);
});

it('fails closed when any target after a valid cutoff lacks its timestamp', function () {
    $family = Family::factory()->create();
    $firstMember = User::factory()->member()->create(['family_id' => $family->id]);
    $secondMember = User::factory()->member()->create(['family_id' => $family->id]);
    $valid = riskContribution($family, $firstMember, '2026-07-01 09:00:00', '2026-07-28');
    $timestampLess = riskContribution($family, $secondMember, '2026-07-01 09:00:00', '2026-07-28');
    $timestampLess->setAttribute('created_at', null);

    expect(fn () => app(HistoricalPaymentRiskFeatureBuilder::class)->buildForFamily(
        $family,
        new EloquentCollection([$valid, $timestampLess]),
    ))->toThrow(InvalidArgumentException::class);
});
