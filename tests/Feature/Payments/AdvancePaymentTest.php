<?php

use App\Enums\PaymentStatus;
use App\Models\Contribution;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

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
                'amount' => 400000,
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

    it('allows payment for up to 6 months ahead', function () {
        $sixMonthsAhead = now()->addMonths(6);

        $this->actingAs($this->financialSecretary)
            ->post(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 400000,
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

    it('payment form shows current month plus next 6 months', function () {
        $this->actingAs($this->financialSecretary)
            ->get(route('payments.create', $this->member))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('available_months', 7) // Current + 6 future months
            );
    });

    it('allows paying for multiple months at once using balance-first', function () {
        // Pay for 3 months in advance
        $this->actingAs($this->financialSecretary)
            ->post(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 1200000, // ₦12,000 = 3 months
                'paid_at' => now()->toDateString(),
            ])
            ->assertRedirect();

        // Check current month is paid
        $currentContribution = Contribution::forUser($this->member->id)
            ->currentMonth()
            ->first();
        expect($currentContribution?->status)->toBe(PaymentStatus::Paid);

        // Check next month is paid
        $nextMonth = now()->addMonth();
        $nextContribution = Contribution::forUser($this->member->id)
            ->forMonth($nextMonth->year, $nextMonth->month)
            ->first();
        expect($nextContribution?->status)->toBe(PaymentStatus::Paid);

        // Check month after next is paid
        $monthAfterNext = now()->addMonths(2);
        $thirdContribution = Contribution::forUser($this->member->id)
            ->forMonth($monthAfterNext->year, $monthAfterNext->month)
            ->first();
        expect($thirdContribution?->status)->toBe(PaymentStatus::Paid);
    });
});
