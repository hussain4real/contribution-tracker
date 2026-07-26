<?php

declare(strict_types=1);

use App\Models\Contribution;
use App\Models\Family;
use App\Models\Payment;
use App\Models\User;
use Carbon\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

it('casts payment values and exposes relationships', function () {
    $recorder = User::factory()->financialSecretary()->create();
    $member = User::factory()->member()->employed()->create();
    $contribution = Contribution::factory()
        ->forUser($member)
        ->forMonth(2026, 5)
        ->create();

    $payment = Payment::factory()
        ->forContribution($contribution)
        ->recordedBy($recorder)
        ->create([
            'amount' => 123456,
            'paid_at' => '2026-05-11 09:30:00',
        ]);
    $paymentMember = $payment->getMember();

    if (! $paymentMember instanceof User) {
        throw new RuntimeException('Expected payment to resolve its member.');
    }

    expect($payment->amount)->toBe(123456)
        ->and($payment->paid_at)->toBeInstanceOf(Carbon::class)
        ->and($payment->formatted_amount)->toBe("\u{20A6}123,456.00")
        ->and($payment->contribution()->firstOrFail()->is($contribution))->toBeTrue()
        ->and($payment->recorder()->firstOrFail()->is($recorder))->toBeTrue()
        ->and($paymentMember->is($member))->toBeTrue()
        ->and($payment->getPeriodLabel())->toBe('May 2026');
});

it('returns empty helper values when no contribution is loaded', function () {
    $payment = new Payment(['amount' => 1000]);

    expect($payment->getMember())->toBeNull()
        ->and($payment->getPeriodLabel())->toBe('');
});

it('formats payments with the contribution family currency', function () {
    $family = Family::factory()->create(['currency' => 'QAR']);
    $member = User::factory()->member()->employed()->create([
        'family_id' => $family->id,
    ]);
    $contribution = Contribution::factory()
        ->forUser($member)
        ->create(['family_id' => $family->id]);
    $payment = Payment::factory()
        ->forContribution($contribution)
        ->create(['amount' => 123456]);

    expect($payment->formatted_amount)->toBe('QAR 123,456.00');
});

it('filters payments with local query scopes', function () {
    Carbon::setTestNow('2026-05-11 12:00:00');

    $recorder = User::factory()->financialSecretary()->create();
    $otherRecorder = User::factory()->financialSecretary()->create();
    $currentContribution = Contribution::factory()->currentMonth()->create();
    $previousContribution = Contribution::factory()->previousMonth()->create();
    $todayPayment = Payment::factory()
        ->forContribution($currentContribution)
        ->recordedBy($recorder)
        ->paidOn('2026-05-11 09:00:00')
        ->create();
    $yesterdayPayment = Payment::factory()
        ->forContribution($currentContribution)
        ->recordedBy($recorder)
        ->paidOn('2026-05-10 09:00:00')
        ->create();
    $previousMonthPayment = Payment::factory()
        ->forContribution($previousContribution)
        ->recordedBy($otherRecorder)
        ->paidOn('2026-04-15 09:00:00')
        ->create();

    expect(Payment::currentMonth()->pluck('id')->all())->toEqualCanonicalizing([
        $todayPayment->id,
        $yesterdayPayment->id,
    ])
        ->and(Payment::recordedBy($recorder)->pluck('id')->all())->toEqualCanonicalizing([
            $todayPayment->id,
            $yesterdayPayment->id,
        ])
        ->and(Payment::recordedBy($otherRecorder->id)->pluck('id')->all())->toBe([
            $previousMonthPayment->id,
        ])
        ->and(Payment::paidBetween('2026-05-10 00:00:00', '2026-05-11 23:59:59')->pluck('id')->all())
        ->toEqualCanonicalizing([$todayPayment->id, $yesterdayPayment->id])
        ->and(Payment::today()->pluck('id')->all())->toBe([$todayPayment->id])
        ->and(Payment::latestFirst()->pluck('id')->all())->toBe([
            $todayPayment->id,
            $yesterdayPayment->id,
            $previousMonthPayment->id,
        ]);
});
