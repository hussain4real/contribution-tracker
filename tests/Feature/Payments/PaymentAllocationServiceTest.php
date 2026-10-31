<?php

declare(strict_types=1);

use App\Jobs\SendPaymentReceipt;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\Payment;
use App\Models\PaymentBatch;
use App\Models\User;
use App\Services\PaymentAllocationService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

it('does not record a payment explicitly targeting beyond the sixth advance month', function () {
    $this->travelTo(now()->setDate(2026, 10, 31));
    $family = Family::factory()->create();
    $recorder = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);

    expect(fn () => app(PaymentAllocationService::class)->createBatch(
        member: $member,
        amount: 4000,
        paidAt: now(),
        recordedBy: $recorder,
        targetYear: 2027,
        targetMonth: 5,
        family: $family,
    ))->toThrow(InvalidArgumentException::class, 'six-month advance-payment limit');

    expect(PaymentBatch::count())->toBe(0)
        ->and(Payment::count())->toBe(0);
});

it('replays a recorded payment even when the current advance limit has changed', function () {
    $this->travelTo(now()->setDate(2026, 10, 31));
    $family = Family::factory()->create();
    $recorder = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $service = app(PaymentAllocationService::class);
    $batch = $service->createBatch(
        member: $member, amount: 4000, paidAt: '2026-10-31', recordedBy: $recorder,
        targetYear: 2027, targetMonth: 4, family: $family, idempotencyKey: 'month-limit-replay',
    );

    $this->travelTo(now()->setDate(2026, 9, 30));
    $replay = $service->createBatch(
        member: $member, amount: 4000, paidAt: '2026-10-31', recordedBy: $recorder,
        targetYear: 2027, targetMonth: 4, family: $family, idempotencyKey: 'month-limit-replay',
    );

    expect($replay->id)->toBe($batch->id)
        ->and(PaymentBatch::count())->toBe(1)
        ->and(Payment::count())->toBe(1);
});

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

it('uses a contribution created after its initial list without creating a duplicate', function () {
    Queue::fake([SendPaymentReceipt::class]);
    $family = Family::factory()->create();
    $recorder = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $inserted = false;
    DB::listen(function (QueryExecuted $query) use (&$inserted, $member): void {
        if (! $inserted && str_contains($query->sql, 'from "contributions"') && str_contains($query->sql, 'order by "year"')) {
            $inserted = true;
            Contribution::factory()->forUser($member)->currentMonth()->create(['expected_amount' => 4000]);
        }
    });

    $batch = app(PaymentAllocationService::class)->createBatch(
        member: $member, amount: 1000, paidAt: now(), recordedBy: $recorder, family: $family,
    );

    expect($inserted)->toBeTrue()
        ->and(Contribution::query()->where('user_id', $member->id)->count())->toBe(1)
        ->and($batch->allocations->sole()->contribution_id)->toBe(Contribution::query()->where('user_id', $member->id)->sole()->id);
    Queue::assertPushed(SendPaymentReceipt::class, 1);
});

it('allocates through the sixth advance month regardless of the current time of day', function (int $hour): void {
    $this->travelTo(now()->setDate(2026, 10, 31)->setTime($hour, 0));
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
    expect($contribution->year)->toBe(now()->startOfMonth()->addMonths(6)->year)
        ->and($contribution->month)->toBe(now()->startOfMonth()->addMonths(6)->month);
})->with([0, 12, 23]);
