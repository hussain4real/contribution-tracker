<?php

declare(strict_types=1);

use App\Actions\InstallPaymentRiskModel;
use App\Actions\ScoreFamilyPaymentRisk;
use App\Enums\PaymentRiskAdvisoryBand;
use App\Enums\PaymentRiskHistoryTier;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\Payment;
use App\Models\PaymentRiskPrediction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    installActiveTestPaymentRiskModel();
});

it('stores an immutable unavailable prediction idempotently for cold-start members', function () {
    $family = Family::factory()->create();
    $member = User::factory()->member()->create(['family_id' => $family->id]);
    $period = now()->toImmutable()->startOfMonth();
    $target = scoreTestContribution($family, $member, $period);
    $action = app(ScoreFamilyPaymentRisk::class);
    $first = $action->handle($family, $period->year, $period->month);
    $second = $action->handle($family, $period->year, $period->month);
    $prediction = PaymentRiskPrediction::query()->sole();

    expect($first)->toMatchArray(['created' => 1, 'existing' => 0, 'unavailable' => 1])
        ->and($second)->toMatchArray(['created' => 0, 'existing' => 1])
        ->and($prediction->contribution_id)->toBe($target->id)
        ->and($prediction->history_tier)->toBe(PaymentRiskHistoryTier::Unavailable)
        ->and($prediction->probability)->toBeNull()
        ->and($prediction->advisory_band)->toBeNull()
        ->and($prediction->feature_snapshot_hash)->toHaveLength(64)
        ->and(fn () => $prediction->update(['probability' => 0.99]))->toThrow(LogicException::class);
});

it('implements pooled experimental and standard cold-start tiers', function (int $historyCount, PaymentRiskHistoryTier $tier, bool $hasProbability) {
    $family = Family::factory()->create();
    $member = User::factory()->member()->create(['family_id' => $family->id]);
    $period = now()->toImmutable()->startOfMonth();
    createMatureRiskHistory($family, $member, $historyCount);
    scoreTestContribution($family, $member, $period);

    app(ScoreFamilyPaymentRisk::class)->handle($family, $period->year, $period->month);
    $prediction = PaymentRiskPrediction::query()->sole();

    expect($prediction->history_tier)->toBe($tier)
        ->and($prediction->history_periods)->toBe($historyCount)
        ->and($prediction->probability !== null)->toBe($hasProbability);

    if ($tier === PaymentRiskHistoryTier::Pooled) {
        expect($prediction->advisory_band)->toBe(PaymentRiskAdvisoryBand::RoutineReview)
            ->and($prediction->factors[0])->toContain('training-set prevalence');
    }
})->with([
    'pooled baseline' => [3, PaymentRiskHistoryTier::Pooled, false],
    'experimental score' => [6, PaymentRiskHistoryTier::Experimental, true],
    'standard score' => [12, PaymentRiskHistoryTier::Standard, true],
]);

it('stores the timing reason when a contribution is created fewer than seven days before due', function () {
    $family = Family::factory()->create();
    $member = User::factory()->member()->create(['family_id' => $family->id]);
    $period = now()->toImmutable()->startOfMonth();
    createMatureRiskHistory($family, $member, 3);
    scoreTestContribution($family, $member, $period, $period->day(24)->setTime(9, 0));

    $result = app(ScoreFamilyPaymentRisk::class)->handle($family, $period->year, $period->month);
    $prediction = PaymentRiskPrediction::query()->sole();

    expect($result['unavailable'])->toBe(1)
        ->and($prediction->history_tier)->toBe(PaymentRiskHistoryTier::Unavailable)
        ->and($prediction->factors)->toBe([
            'The contribution was generated fewer than seven days before its due date.',
        ]);
});

it('uses a priority pooled baseline when training prevalence reaches the model threshold', function () {
    $artifact = validPaymentRiskArtifact();
    $artifact['model_version'] = 'priority-pooled-model';
    $artifact = paymentRiskArtifactWith($artifact, 'training.overdue_prevalence', 0.8);
    app(InstallPaymentRiskModel::class)->handle(
        paymentRiskArtifactFile($artifact)->getPathname(),
        activate: true,
    );
    $family = Family::factory()->create();
    $member = User::factory()->member()->create(['family_id' => $family->id]);
    $period = now()->toImmutable()->startOfMonth();
    createMatureRiskHistory($family, $member, 3);
    scoreTestContribution($family, $member, $period);

    app(ScoreFamilyPaymentRisk::class)->handle($family, $period->year, $period->month);

    expect(PaymentRiskPrediction::query()->sole()->advisory_band)
        ->toBe(PaymentRiskAdvisoryBand::PriorityReview);
});

it('never changes contributions payments reminders or notifications while scoring', function () {
    Notification::fake();
    $family = Family::factory()->create();
    $member = User::factory()->member()->create(['family_id' => $family->id]);
    $period = now()->toImmutable()->startOfMonth();
    createMatureRiskHistory($family, $member, 6);
    $target = scoreTestContribution($family, $member, $period);
    $before = [
        'contributions' => Contribution::query()->count(),
        'payments' => Payment::query()->count(),
        'expected_amount' => $target->expected_amount,
        'reminder_sent_at' => $target->reminder_sent_at,
        'follow_up_sent_at' => $target->follow_up_sent_at,
    ];

    app(ScoreFamilyPaymentRisk::class)->handle($family, $period->year, $period->month);
    $target->refresh();

    expect(Contribution::query()->count())->toBe($before['contributions'])
        ->and(Payment::query()->count())->toBe($before['payments'])
        ->and($target->expected_amount)->toBe($before['expected_amount'])
        ->and($target->reminder_sent_at)->toBe($before['reminder_sent_at'])
        ->and($target->follow_up_sent_at)->toBe($before['follow_up_sent_at']);
    Notification::assertNothingSent();
});

it('keeps scoring and stored predictions isolated to the requested family', function () {
    $firstFamily = Family::factory()->create();
    $secondFamily = Family::factory()->create();
    $firstMember = User::factory()->member()->create(['family_id' => $firstFamily->id]);
    $secondMember = User::factory()->member()->create(['family_id' => $secondFamily->id]);
    $period = now()->toImmutable()->startOfMonth();
    scoreTestContribution($firstFamily, $firstMember, $period);
    scoreTestContribution($secondFamily, $secondMember, $period);

    app(ScoreFamilyPaymentRisk::class)->handle($firstFamily, $period->year, $period->month);

    expect(PaymentRiskPrediction::query()->count())->toBe(1)
        ->and(PaymentRiskPrediction::query()->sole()->family_id)->toBe($firstFamily->id);
});

it('keeps scoring queries bounded as the number of members grows', function () {
    $period = now()->toImmutable()->startOfMonth();
    $createScorableFamily = function (int $memberCount) use ($period): Family {
        $family = Family::factory()->create();
        $members = User::factory()->member()->count($memberCount)->create(['family_id' => $family->id]);

        foreach ($members as $member) {
            createMatureRiskHistory($family, $member, 6);
            scoreTestContribution($family, $member, $period);
        }

        return $family;
    };
    $countScoringQueries = function (Family $family) use ($period): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            app(ScoreFamilyPaymentRisk::class)->handle($family, $period->year, $period->month);

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    };
    $smallFamily = $createScorableFamily(2);
    $largerFamily = $createScorableFamily(8);

    $smallFamilyQueries = $countScoringQueries($smallFamily);
    $largerFamilyQueries = $countScoringQueries($largerFamily);

    expect($smallFamilyQueries)->toBeGreaterThan(0)
        ->and($largerFamilyQueries)->toBe($smallFamilyQueries)
        ->and($largerFamilyQueries)->toBeLessThanOrEqual(12);
});
