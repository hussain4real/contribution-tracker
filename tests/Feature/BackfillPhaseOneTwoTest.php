<?php

declare(strict_types=1);

use App\Enums\MemberCategory;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\FamilyCategory;
use App\Models\FamilyMembership;
use App\Models\FamilyMembershipCategoryAssignment;
use App\Models\Payment;
use App\Models\PaymentBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('backfills legacy memberships contributions and payments idempotently', function () {
    $family = Family::factory()->create();
    $category = FamilyCategory::factory()->create([
        'family_id' => $family->id,
        'name' => 'Employed',
        'slug' => MemberCategory::Employed->value,
        'monthly_amount' => 4000,
    ]);
    $recorder = User::factory()->admin()->create(['family_id' => $family->id]);
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $membership = FamilyMembership::query()
        ->where('family_id', $family->id)
        ->where('user_id', $member->id)
        ->firstOrFail();

    DB::table('family_membership_category_assignments')
        ->where('family_membership_id', $membership->id)
        ->delete();
    DB::table('family_members')->where('id', $membership->id)->update([
        'display_name' => null,
        'family_category_id' => null,
        'category' => MemberCategory::Employed->value,
    ]);

    $contribution = Contribution::factory()->forUser($member)->create([
        'family_id' => $family->id,
        'year' => 2026,
        'month' => 6,
        'expected_amount' => 4000,
        'family_category_id' => null,
        'category_name' => null,
        'category_slug' => null,
        'category_amount' => null,
    ]);
    $payment = Payment::factory()->forContribution($contribution)->recordedBy($recorder)->create([
        'amount' => 2500,
        'payment_batch_id' => null,
    ]);

    $this->artisan('app:backfill-phase-one-two', ['--chunk' => 1])->assertSuccessful();

    $membership->refresh();
    $contribution->refresh();
    $payment->refresh();
    $batch = PaymentBatch::query()->findOrFail($payment->payment_batch_id);

    expect($membership->display_name)->toBe($member->name)
        ->and($membership->family_category_id)->toBe($category->id)
        ->and($membership->category)->toBeNull()
        ->and(FamilyMembershipCategoryAssignment::query()->where('family_membership_id', $membership->id)->count())->toBe(1)
        ->and($contribution->family_category_id)->toBe($category->id)
        ->and($contribution->category_name)->toBe('Employed')
        ->and($contribution->category_amount)->toBe(4000)
        ->and($batch->total_amount)->toBe(2500)
        ->and($batch->idempotency_key)->toBe("legacy-payment:{$payment->id}")
        ->and($batch->allocations()->whereKey($payment->id)->exists())->toBeTrue();

    $this->artisan('app:backfill-phase-one-two', ['--chunk' => 1])->assertSuccessful();

    expect(PaymentBatch::query()->where('family_id', $family->id)->count())->toBe(1)
        ->and(FamilyMembershipCategoryAssignment::query()->where('family_membership_id', $membership->id)->count())->toBe(1);
});

it('moves a legacy account archive onto its family membership', function () {
    $family = Family::factory()->create();
    $member = User::factory()->member()->create(['family_id' => $family->id]);
    $membership = FamilyMembership::query()
        ->where('family_id', $family->id)
        ->where('user_id', $member->id)
        ->firstOrFail();
    $archivedAt = now()->subDay()->startOfSecond();

    expect($membership->archived_at)->toBeNull();

    DB::table('users')->where('id', $member->id)->update(['archived_at' => $archivedAt]);

    $this->artisan('app:backfill-phase-one-two')->assertSuccessful();

    expect($member->refresh()->archived_at)->toBeNull()
        ->and($membership->refresh()->archived_at?->equalTo($archivedAt))->toBeTrue()
        ->and($membership->archive_reason)->toBe('Migrated from the legacy account archive state.');
});

it('skips contribution snapshots when no matching family membership exists', function () {
    $family = Family::factory()->create();
    $member = User::factory()->member()->create();
    $contribution = Contribution::factory()->forUser($member)->create([
        'family_id' => $family->id,
        'category_name' => null,
        'category_slug' => null,
        'category_amount' => null,
    ]);

    $this->artisan('app:backfill-phase-one-two')->assertSuccessful();

    expect($contribution->refresh()->category_name)->toBeNull();
});

it('reattaches existing legacy receipts and continues family receipt numbering', function () {
    $family = Family::factory()->create();
    $recorder = User::factory()->admin()->create(['family_id' => $family->id]);
    $member = User::factory()->member()->create(['family_id' => $family->id]);
    $firstContribution = Contribution::factory()->forUser($member)->create([
        'family_id' => $family->id,
        'year' => 2026,
        'month' => 5,
    ]);
    $secondContribution = Contribution::factory()->forUser($member)->create([
        'family_id' => $family->id,
        'year' => 2026,
        'month' => 6,
    ]);
    $firstPayment = Payment::factory()->forContribution($firstContribution)->recordedBy($recorder)->create([
        'payment_batch_id' => null,
    ]);
    $secondPayment = Payment::factory()->forContribution($secondContribution)->recordedBy($recorder)->create([
        'payment_batch_id' => null,
    ]);
    $existingBatch = PaymentBatch::factory()->create([
        'family_id' => $family->id,
        'family_membership_id' => $member->membershipForFamily($family)?->id,
        'recorded_by' => $recorder->id,
        'receipt_number' => 41,
        'idempotency_key' => "legacy-payment:{$firstPayment->id}",
    ]);

    $this->artisan('app:backfill-phase-one-two')->assertSuccessful();

    expect($firstPayment->refresh()->payment_batch_id)->toBe($existingBatch->id)
        ->and($secondPayment->refresh()->batch?->receipt_number)->toBe(42);
});
