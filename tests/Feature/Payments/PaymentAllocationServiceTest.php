<?php

declare(strict_types=1);

use App\Models\Family;
use App\Models\User;
use App\Services\PaymentAllocationService;

it('requires a family context before allocating payments', function () {
    $member = User::factory()->member()->employed()->create();
    $recorder = User::factory()->financialSecretary()->create(['family_id' => null]);

    expect(fn () => app(PaymentAllocationService::class)->allocate(
        member: $member,
        amount: 1000,
        paidAt: now(),
        recordedBy: $recorder,
    ))->toThrow(InvalidArgumentException::class, 'A family context is required to allocate a payment.');
});

it('requires the member to belong to the active family', function () {
    $activeFamily = Family::factory()->create();
    $otherFamily = Family::factory()->create();
    $recorder = User::factory()->financialSecretary()->create(['family_id' => $activeFamily->id]);
    $member = User::factory()->member()->employed()->create(['family_id' => $otherFamily->id]);

    expect(fn () => app(PaymentAllocationService::class)->allocate(
        member: $member,
        amount: 1000,
        paidAt: now(),
        recordedBy: $recorder,
    ))->toThrow(InvalidArgumentException::class, 'The selected member does not belong to the active family.');
});

it('allocates through the sixth advance month regardless of the current time of day', function (int $hour): void {
    $this->travelTo(now()->setTime($hour, 0));
    $family = Family::factory()->create();
    $recorder = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);

    $batch = app(PaymentAllocationService::class)->createBatch(
        member: $member, amount: 28000, paidAt: now(), recordedBy: $recorder, family: $family,
    );

    expect($batch->allocations)->toHaveCount(7)
        ->and((int) $batch->allocations()->sum('amount'))->toBe(28000);
    $last = $batch->allocations->last();
    assert($last !== null);
    $contribution = $last->contribution;
    assert($contribution !== null);
    expect($contribution->year)->toBe(now()->addMonths(6)->year)
        ->and($contribution->month)->toBe(now()->addMonths(6)->month);
})->with([0, 12, 23]);
