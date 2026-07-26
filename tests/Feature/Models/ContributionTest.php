<?php

declare(strict_types=1);

use App\Enums\PaymentStatus;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\Payment;
use App\Models\User;
use Carbon\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

it('filters overdue contributions and computes statuses and formatted amounts', function () {
    Carbon::setTestNow('2026-05-30 12:00:00');

    $member = User::factory()->member()->employed()->create();
    $overdue = Contribution::factory()->forUser($member)->create([
        'year' => 2026,
        'month' => 5,
        'expected_amount' => 4000,
        'due_date' => '2026-05-28',
    ]);
    $future = Contribution::factory()->forUser($member)->create([
        'year' => 2026,
        'month' => 6,
        'expected_amount' => 4000,
        'due_date' => '2026-06-28',
    ]);
    Payment::factory()->forContribution($future)->create(['amount' => 1000]);

    expect(Contribution::overdue()->pluck('id')->all())->toBe([$overdue->id])
        ->and($overdue->status)->toBe(PaymentStatus::Overdue)
        ->and($future->status)->toBe(PaymentStatus::Partial)
        ->and($future->isPartiallyPaid())->toBeTrue()
        ->and($future->isPaid())->toBeFalse()
        ->and($future->canAcceptPayment())->toBeTrue()
        ->and($future->formattedExpectedAmount())->toBe("\u{20A6}4,000.00")
        ->and($future->formattedTotalPaid())->toBe("\u{20A6}1,000.00")
        ->and($future->formattedBalance())->toBe("\u{20A6}3,000.00");
});

it('uses eager loaded payments for total paid and explicit due dates when present', function () {
    $member = User::factory()->member()->employed()->create();
    $contribution = Contribution::factory()->forUser($member)->create([
        'expected_amount' => 4000,
        'due_date' => '2026-05-20',
    ]);
    Payment::factory()->forContribution($contribution)->create(['amount' => 4000]);

    $loaded = Contribution::query()->with('payments')->findOrFail($contribution->id);

    expect($loaded->total_paid)->toBe(4000)
        ->and($loaded->balance)->toBe(0)
        ->and($loaded->status)->toBe(PaymentStatus::Paid)
        ->and($loaded->isPaid())->toBeTrue()
        ->and($loaded->canAcceptPayment())->toBeFalse()
        ->and($loaded->due_date->toDateString())->toBe('2026-05-20');
});

it('formats contribution amounts with the family currency', function () {
    $family = Family::factory()->create(['currency' => 'QAR']);
    $member = User::factory()->member()->employed()->create([
        'family_id' => $family->id,
    ]);
    $contribution = Contribution::factory()->forUser($member)->create([
        'family_id' => $family->id,
        'expected_amount' => 4000,
    ]);
    Payment::factory()->forContribution($contribution)->create(['amount' => 1000]);

    expect($contribution->formattedExpectedAmount())->toBe('QAR 4,000.00')
        ->and($contribution->formattedTotalPaid())->toBe('QAR 1,000.00')
        ->and($contribution->formattedBalance())->toBe('QAR 3,000.00');
});
