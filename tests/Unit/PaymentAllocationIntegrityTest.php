<?php

declare(strict_types=1);

use App\Jobs\SendPaymentReceipt;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Payment;
use App\Models\PaymentBatch;
use App\Models\User;
use App\Services\PaymentAllocationPreview;
use App\Services\PaymentAllocationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

pest()->extend(TestCase::class);

beforeEach(function (): void {
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
    DB::purge('sqlite');
    $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])->assertSuccessful();
    DB::statement('pragma foreign_keys=off');
    Queue::fake();
    $this->family = Family::factory()->create();
    $this->member = User::factory()->member()->employed()->create(['family_id' => $this->family->id]);
    $this->officer = User::factory()->financialSecretary()->create(['family_id' => $this->family->id]);
    Contribution::factory()->forUser($this->member)->currentMonth()->create(['expected_amount' => 4000]);
    $membership = $this->member->membershipForFamily($this->family);
    if (! $membership instanceof FamilyMembership) {
        throw new RuntimeException('The synthetic member has no family membership.');
    }
    $this->review = app(PaymentAllocationPreview::class)->preview($this->member, $this->family, $membership, 1000)['allocations'];
});

it('rolls back a reviewed payment if an allocation has lost its contribution', function (): void {
    DB::statement('create trigger corrupt_allocation after insert on payments begin update payments set contribution_id=999999 where id=new.id; end');

    expect(fn () => app(PaymentAllocationService::class)->createBatch(
        member: $this->member, amount: 1000, paidAt: now(), recordedBy: $this->officer,
        family: $this->family, expectedAllocations: $this->review,
    ))->toThrow(InvalidArgumentException::class, 'The payment allocation has no contribution.');

    expect(PaymentBatch::count())->toBe(0)->and(Payment::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('refuses to replay a receipt with an orphaned allocation', function (): void {
    $paidAt = now();
    $batch = app(PaymentAllocationService::class)->createBatch(
        member: $this->member, amount: 1000, paidAt: $paidAt, recordedBy: $this->officer,
        family: $this->family, idempotencyKey: 'corrupt-replay', expectedAllocations: $this->review,
    );
    DB::table('payments')->where('payment_batch_id', $batch->id)->update(['contribution_id' => 999999]);

    expect(fn () => app(PaymentAllocationService::class)->createBatch(
        member: $this->member, amount: 1000, paidAt: $paidAt, recordedBy: $this->officer,
        family: $this->family, idempotencyKey: 'corrupt-replay', expectedAllocations: $this->review,
    ))->toThrow(InvalidArgumentException::class, 'The payment allocation has no contribution.');

    expect(PaymentBatch::count())->toBe(1)->and(Payment::count())->toBe(1);
    Queue::assertPushed(SendPaymentReceipt::class, 1);
});
