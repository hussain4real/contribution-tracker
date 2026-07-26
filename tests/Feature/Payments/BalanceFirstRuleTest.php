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

/**
 * FR-020: Balance-First Rule
 *
 * "Part payment should be allowed... any payment after will have to
 * balance the incomplete month payment before registering a new month payment"
 */
describe('Balance-First Rule (FR-020)', function () {
    beforeEach(function () {
        $this->family = Family::factory()->create();
        $this->financialSecretary = User::factory()->financialSecretary()->create(['family_id' => $this->family->id]);
        $this->member = User::factory()->member()->employed()->create(['family_id' => $this->family->id]);
    });

    it('auto-applies payment to oldest incomplete month first', function () {
        // Use startOfMonth to avoid date overflow issues (Dec 29 + 2 months = March, not February)
        $month1 = now()->startOfMonth()->addMonths(2);
        $month2 = now()->startOfMonth()->addMonths(3);
        $month3 = now()->startOfMonth()->addMonths(4);

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
            ->create(['amount' => 2000]);

        $first->refresh();

        expect($first->balance)->toBe(2000); // ₦2,000 remaining

        // Member pays ₦4,000 targeting third month
        // Balance-first rule: should complete first month first, then apply rest to second
        $this->actingAs($this->financialSecretary)
            ->post(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 4000, // ₦4,000
                'paid_at' => now()->toDateString(),
                'target_year' => $month3->year,
                'target_month' => $month3->month,
            ])
            ->assertRedirect();

        $first->refresh();
        $second->refresh();
        $third->refresh();

        // First month should now be fully paid (₦2,000 remaining was completed)
        expect($first->status)->toBe(PaymentStatus::Paid);
        expect($first->balance)->toBe(0);

        // Second month should have ₦2,000 applied (remainder after completing first)
        expect($second->total_paid)->toBe(2000);
        expect($second->status)->toBe(PaymentStatus::Partial);

        // Third month should have nothing yet
        expect($third->total_paid)->toBe(0);
    });

    it('completes multiple incomplete months in order', function () {
        // Use months within the 6-month advance limit, starting from current month
        $month1 = now()->startOfMonth();
        $month2 = now()->startOfMonth()->addMonth();
        $month3 = now()->startOfMonth()->addMonths(2);

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
                'amount' => 10000, // ₦10,000
                'paid_at' => now()->toDateString(),
                'target_year' => $month3->year,
                'target_month' => $month3->month,
            ])
            ->assertRedirect();

        $first->refresh();
        $second->refresh();
        $third->refresh();

        // First: ₦4,000 - PAID
        expect($first->status)->toBe(PaymentStatus::Paid);

        // Second: ₦4,000 - PAID
        expect($second->status)->toBe(PaymentStatus::Paid);

        // Third: ₦2,000 of ₦4,000 - PARTIAL
        expect($third->total_paid)->toBe(2000);
        expect($third->status)->toBe(PaymentStatus::Partial);
    });

    it('skips already paid months', function () {
        // Use months within the 6-month advance limit
        $month1 = now()->startOfMonth()->addMonths(3);
        $month2 = now()->startOfMonth()->addMonths(4);

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
            ->create(['amount' => 4000]);

        $first->refresh();

        expect($first->isPaid())->toBeTrue();

        // Pay ₦4,000 - should go directly to second month
        $this->actingAs($this->financialSecretary)
            ->post(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 4000,
                'paid_at' => now()->toDateString(),
                'target_year' => $month2->year,
                'target_month' => $month2->month,
            ])
            ->assertRedirect();

        $first->refresh();
        $second->refresh();

        // First month unchanged
        expect($first->total_paid)->toBe(4000);

        // Second month fully paid
        expect($second->status)->toBe(PaymentStatus::Paid);
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
                'amount' => 4000,
                'paid_at' => now()->toDateString(),
                'target_year' => $nextMonth->year,
                'target_month' => $nextMonth->month,
            ])
            ->assertRedirect();

        // Contribution should be created and paid
        $contribution = Contribution::forUser($this->member->id)
            ->forMonth($nextMonth->year, $nextMonth->month)
            ->firstOrFail();

        expect($contribution->status)->toBe(PaymentStatus::Paid);
    });

    it('clamps created target month contribution due date for February', function () {
        Carbon::setTestNow(Carbon::create(2027, 1, 15, 12));

        $this->family->update(['due_day' => 30]);

        $this->actingAs($this->financialSecretary)
            ->post(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 4000,
                'paid_at' => now()->toDateString(),
                'target_year' => 2027,
                'target_month' => 2,
            ])
            ->assertRedirect();

        $contribution = Contribution::forUser($this->member->id)
            ->forMonth(2027, 2)
            ->firstOrFail();

        expect($contribution->due_date->toDateString())->toBe('2027-02-28');
    });
});
