<?php

use App\Enums\PaymentStatus;
use App\Models\Contribution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * FR-018: Advance Payments
 *
 * "Advance payments allowed up to 6 months ahead"
 */
describe('Advance Payments (FR-018)', function () {
    beforeEach(function () {
        $this->financialSecretary = User::factory()->financialSecretary()->create();
        $this->member = User::factory()->member()->employed()->create();
    });

    it('allows payment for next month', function () {
        $nextMonth = now()->addMonth();

        $this->actingAs($this->financialSecretary)
            ->post(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 4000,
                'paid_at' => now()->toDateString(),
                'target_year' => $nextMonth->year,
                'target_month' => $nextMonth->month,
            ])
            ->assertRedirect();

        $contribution = Contribution::forUser($this->member->id)
            ->forMonth($nextMonth->year, $nextMonth->month)
            ->first();

        expect($contribution)->not->toBeNull();
        expect($contribution->status)->toBe(PaymentStatus::Paid);
    });

    it('handles string target_year and target_month from form input', function () {
        $nextMonth = now()->addMonth();

        $this->actingAs($this->financialSecretary)
            ->post(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 4000,
                'paid_at' => now()->toDateString(),
                'target_year' => (string) $nextMonth->year,
                'target_month' => (string) $nextMonth->month,
            ])
            ->assertRedirect();

        $contribution = Contribution::forUser($this->member->id)
            ->forMonth($nextMonth->year, $nextMonth->month)
            ->first();

        expect($contribution)->not->toBeNull();
        expect($contribution->status)->toBe(PaymentStatus::Paid);
    });

    it('payment form excludes fully paid contributions', function () {
        // Create a paid contribution
        $paidContribution = Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->employed()
            ->create();

        $paidContribution->payments()->create([
            'amount' => $paidContribution->expected_amount,
            'paid_at' => now(),
            'recorded_by' => $this->financialSecretary->id,
        ]);

        // Create an unpaid contribution for next month
        $nextMonth = now()->startOfMonth()->addMonth();
        $unpaidContribution = Contribution::factory()
            ->forUser($this->member)
            ->create([
                'year' => $nextMonth->year,
                'month' => $nextMonth->month,
            ]);

        $this->actingAs($this->financialSecretary)
            ->get(route('payments.create', $this->member))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pending_contributions', 1)
                ->where('pending_contributions.0.year', $nextMonth->year)
                ->where('pending_contributions.0.month', $nextMonth->month)
            );
    });

    it('allows payment for up to 6 months ahead', function () {
        $sixMonthsAhead = now()->addMonths(6);

        $this->actingAs($this->financialSecretary)
            ->post(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 4000,
                'paid_at' => now()->toDateString(),
                'target_year' => $sixMonthsAhead->year,
                'target_month' => $sixMonthsAhead->month,
            ])
            ->assertRedirect();

        $contribution = Contribution::forUser($this->member->id)
            ->forMonth($sixMonthsAhead->year, $sixMonthsAhead->month)
            ->first();

        expect($contribution)->not->toBeNull();
    });

    it('payment form shows only incomplete contributions', function () {
        // Create an unpaid contribution
        Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->employed()
            ->create();

        $this->actingAs($this->financialSecretary)
            ->get(route('payments.create', $this->member))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pending_contributions', 1)
                ->where('pending_contributions.0.balance', 4000)
            );
    });

    it('allows paying for multiple months at once using balance-first', function () {
        // Pay for 3 months in advance
        $this->actingAs($this->financialSecretary)
            ->post(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 12000, // ₦12,000 = 3 months
                'paid_at' => now()->toDateString(),
            ])
            ->assertRedirect();

        // Check current month is paid
        $currentContribution = Contribution::forUser($this->member->id)
            ->currentMonth()
            ->first();
        expect($currentContribution?->status)->toBe(PaymentStatus::Paid);

        // Check next month is paid (use startOfMonth to avoid day overflow issues)
        $nextMonth = now()->startOfMonth()->addMonth();
        $nextContribution = Contribution::forUser($this->member->id)
            ->forMonth($nextMonth->year, $nextMonth->month)
            ->first();
        expect($nextContribution?->status)->toBe(PaymentStatus::Paid);

        // Check month after next is paid
        $monthAfterNext = now()->startOfMonth()->addMonths(2);
        $thirdContribution = Contribution::forUser($this->member->id)
            ->forMonth($monthAfterNext->year, $monthAfterNext->month)
            ->first();
        expect($thirdContribution?->status)->toBe(PaymentStatus::Paid);
    });
});
