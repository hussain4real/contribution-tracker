<?php

use App\Enums\PaymentStatus;
use App\Models\Contribution;
use App\Models\Payment;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * FR-020: Balance-First Rule
 *
 * "Part payment should be allowed... any payment after will have to
 * balance the incomplete month payment before registering a new month payment"
 */
describe('Balance-First Rule (FR-020)', function () {
    beforeEach(function () {
        $this->financialSecretary = User::factory()->financialSecretary()->create();
        $this->member = User::factory()->member()->employed()->create();
    });

    it('auto-applies payment to oldest incomplete month first', function () {
        // Use future months to avoid overdue status
        $month1 = now()->addMonth();
        $month2 = now()->addMonths(2);
        $month3 = now()->addMonths(3);

        // Create contributions for 3 consecutive months
        $first = Contribution::factory()
            ->forUser($this->member)
            ->forMonth($month1->year, $month1->month)
            ->employed()
            ->create();

        $second = Contribution::factory()
            ->forUser($this->member)
            ->forMonth($month2->year, $month2->month)
            ->employed()
            ->create();

        $third = Contribution::factory()
            ->forUser($this->member)
            ->forMonth($month3->year, $month3->month)
            ->employed()
            ->create();

        // First month has partial payment (₦2,000 of ₦4,000)
        Payment::factory()
            ->forContribution($first)
            ->recordedBy($this->financialSecretary)
            ->create(['amount' => 200000]);

        expect($first->fresh()->balance)->toBe(200000); // ₦2,000 remaining

        // Member pays ₦4,000 targeting third month
        // Balance-first rule: should complete first month first, then apply rest to second
        $this->actingAs($this->financialSecretary)
            ->post(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 400000, // ₦4,000
                'paid_at' => now()->toDateString(),
                'target_year' => $month3->year,
                'target_month' => $month3->month,
            ])
            ->assertRedirect();

        // First month should now be fully paid (₦2,000 remaining was completed)
        expect($first->fresh()->status)->toBe(PaymentStatus::Paid);
        expect($first->fresh()->balance)->toBe(0);

        // Second month should have ₦2,000 applied (remainder after completing first)
        expect($second->fresh()->total_paid)->toBe(200000);
        expect($second->fresh()->status)->toBe(PaymentStatus::Partial);

        // Third month should have nothing yet
        expect($third->fresh()->total_paid)->toBe(0);
    });

    it('completes multiple incomplete months in order', function () {
        $month1 = now()->addMonth();
        $month2 = now()->addMonths(2);
        $month3 = now()->addMonths(3);

        // Create contributions for 3 months
        $first = Contribution::factory()
            ->forUser($this->member)
            ->forMonth($month1->year, $month1->month)
            ->employed()
            ->create();

        $second = Contribution::factory()
            ->forUser($this->member)
            ->forMonth($month2->year, $month2->month)
            ->employed()
            ->create();

        $third = Contribution::factory()
            ->forUser($this->member)
            ->forMonth($month3->year, $month3->month)
            ->employed()
            ->create();

        // All months unpaid, pay ₦10,000 (enough for first + second + ₦2,000 towards third)
        $this->actingAs($this->financialSecretary)
            ->post(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 1000000, // ₦10,000
                'paid_at' => now()->toDateString(),
                'target_year' => $month3->year,
                'target_month' => $month3->month,
            ])
            ->assertRedirect();

        // First: ₦4,000 - PAID
        expect($first->fresh()->status)->toBe(PaymentStatus::Paid);

        // Second: ₦4,000 - PAID
        expect($second->fresh()->status)->toBe(PaymentStatus::Paid);

        // Third: ₦2,000 of ₦4,000 - PARTIAL
        expect($third->fresh()->total_paid)->toBe(200000);
        expect($third->fresh()->status)->toBe(PaymentStatus::Partial);
    });

    it('skips already paid months', function () {
        $month1 = now()->addMonth();
        $month2 = now()->addMonths(2);

        // Create first and second month
        $first = Contribution::factory()
            ->forUser($this->member)
            ->forMonth($month1->year, $month1->month)
            ->employed()
            ->create();

        $second = Contribution::factory()
            ->forUser($this->member)
            ->forMonth($month2->year, $month2->month)
            ->employed()
            ->create();

        // First month already fully paid
        Payment::factory()
            ->forContribution($first)
            ->recordedBy($this->financialSecretary)
            ->create(['amount' => 400000]);

        expect($first->fresh()->isPaid())->toBeTrue();

        // Pay ₦4,000 - should go directly to second month
        $this->actingAs($this->financialSecretary)
            ->post(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 400000,
                'paid_at' => now()->toDateString(),
                'target_year' => $month2->year,
                'target_month' => $month2->month,
            ])
            ->assertRedirect();

        // First month unchanged
        expect($first->fresh()->total_paid)->toBe(400000);

        // Second month fully paid
        expect($second->fresh()->status)->toBe(PaymentStatus::Paid);
    });

    it('creates contribution for target month if not exists', function () {
        $nextMonth = now()->addMonth();

        // No contributions exist for next month
        expect(Contribution::forUser($this->member->id)
            ->forMonth($nextMonth->year, $nextMonth->month)
            ->exists()
        )->toBeFalse();

        $this->actingAs($this->financialSecretary)
            ->post(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 400000,
                'paid_at' => now()->toDateString(),
                'target_year' => $nextMonth->year,
                'target_month' => $nextMonth->month,
            ])
            ->assertRedirect();

        // Contribution should be created and paid
        $contribution = Contribution::forUser($this->member->id)
            ->forMonth($nextMonth->year, $nextMonth->month)
            ->first();

        expect($contribution)->not->toBeNull();
        expect($contribution->status)->toBe(PaymentStatus::Paid);
    });
});
