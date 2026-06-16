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
