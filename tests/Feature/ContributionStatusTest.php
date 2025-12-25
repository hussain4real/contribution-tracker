<?php

use App\Enums\PaymentStatus;
use App\Models\Contribution;
use App\Models\Payment;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Contribution Status Calculation', function () {
    beforeEach(function () {
        $this->member = User::factory()->member()->employed()->create();
    });

    it('returns Paid status when contribution is fully paid', function () {
        $contribution = Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->create();

        Payment::factory()
            ->forContribution($contribution)
            ->create(['amount' => $contribution->expected_amount]);

        expect($contribution->fresh()->status)->toBe(PaymentStatus::Paid);
        expect($contribution->fresh()->isPaid())->toBeTrue();
    });

    it('returns Paid status when contribution is overpaid', function () {
        $contribution = Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->create();

        Payment::factory()
            ->forContribution($contribution)
            ->create(['amount' => $contribution->expected_amount + 100000]);

        expect($contribution->fresh()->status)->toBe(PaymentStatus::Paid);
    });

    it('returns Partial status when contribution has partial payment before due date', function () {
        // Set up a future contribution to ensure it's not overdue
        $futureMonth = now()->addMonth();

        $contribution = Contribution::factory()
            ->forUser($this->member)
            ->forMonth($futureMonth->year, $futureMonth->month)
            ->create();

        Payment::factory()
            ->forContribution($contribution)
            ->create(['amount' => (int) ($contribution->expected_amount / 2)]);

        expect($contribution->fresh()->status)->toBe(PaymentStatus::Partial);
        expect($contribution->fresh()->isPartiallyPaid())->toBeTrue();
    });

    it('returns Unpaid status when no payments made before due date', function () {
        // Use a future month to ensure not overdue
        $futureMonth = now()->addMonth();

        $contribution = Contribution::factory()
            ->forUser($this->member)
            ->forMonth($futureMonth->year, $futureMonth->month)
            ->create();

        expect($contribution->status)->toBe(PaymentStatus::Unpaid);
        expect($contribution->total_paid)->toBe(0);
    });

    it('returns Overdue status when unpaid and past due date', function () {
        $pastMonth = now()->subMonths(2);

        $contribution = Contribution::factory()
            ->forUser($this->member)
            ->forMonth($pastMonth->year, $pastMonth->month)
            ->create();

        expect($contribution->status)->toBe(PaymentStatus::Overdue);
        expect($contribution->isOverdue())->toBeTrue();
    });

    it('returns Overdue status when partially paid and past due date', function () {
        $pastMonth = now()->subMonths(2);

        $contribution = Contribution::factory()
            ->forUser($this->member)
            ->forMonth($pastMonth->year, $pastMonth->month)
            ->create();

        Payment::factory()
            ->forContribution($contribution)
            ->create(['amount' => (int) ($contribution->expected_amount / 2)]);

        expect($contribution->fresh()->status)->toBe(PaymentStatus::Overdue);
    });

    it('calculates total_paid correctly with multiple payments', function () {
        $contribution = Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->create();

        Payment::factory()
            ->forContribution($contribution)
            ->create(['amount' => 100000]); // ₦1,000

        Payment::factory()
            ->forContribution($contribution)
            ->create(['amount' => 150000]); // ₦1,500

        expect($contribution->fresh()->total_paid)->toBe(250000); // ₦2,500
    });

    it('calculates balance correctly', function () {
        $contribution = Contribution::factory()
            ->forUser($this->member)
            ->employed()
            ->currentMonth()
            ->create();

        Payment::factory()
            ->forContribution($contribution)
            ->create(['amount' => 100000]); // ₦1,000 of ₦4,000

        // Expected: ₦4,000 - ₦1,000 = ₦3,000
        expect($contribution->fresh()->balance)->toBe(300000);
    });

    it('returns zero balance when fully paid', function () {
        $contribution = Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->create();

        Payment::factory()
            ->forContribution($contribution)
            ->create(['amount' => $contribution->expected_amount]);

        expect($contribution->fresh()->balance)->toBe(0);
    });

    it('uses correct amounts for each member category', function () {
        $employedContribution = Contribution::factory()
            ->employed()
            ->currentMonth()
            ->create();

        $unemployedContribution = Contribution::factory()
            ->unemployed()
            ->currentMonth()
            ->create();

        $studentContribution = Contribution::factory()
            ->student()
            ->currentMonth()
            ->create();

        expect($employedContribution->expected_amount)->toBe(400000);  // ₦4,000
        expect($unemployedContribution->expected_amount)->toBe(200000); // ₦2,000
        expect($studentContribution->expected_amount)->toBe(100000);   // ₦1,000
    });
});
