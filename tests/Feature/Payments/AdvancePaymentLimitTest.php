<?php

declare(strict_types=1);

use App\Models\User;

/**
 * FR-018: Advance Payment Limit
 *
 * "Advance payments allowed up to 6 months ahead"
 */
describe('Advance Payment Limit (FR-018)', function () {
    beforeEach(function () {
        $this->financialSecretary = User::factory()->financialSecretary()->create();
        $this->member = User::factory()->member()->employed()->create(['family_id' => $this->financialSecretary->family_id]);
    });

    it('rejects payment targeting more than 6 months ahead', function () {
        $this->travelTo(now()->setDate(2026, 10, 31));
        $sevenMonthsAhead = now()->startOfMonth()->addMonths(7);

        $this->actingAs($this->financialSecretary)
            ->postReviewedPayment(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 4000,
                'paid_at' => now()->toDateString(),
                'target_year' => $sevenMonthsAhead->year,
                'target_month' => $sevenMonthsAhead->month,
            ])
            ->assertSessionHasErrors('target_month');
    });

    it('rejects payment targeting 12 months ahead', function () {
        $yearAhead = now()->addYear();

        $this->actingAs($this->financialSecretary)
            ->postReviewedPayment(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 4000,
                'paid_at' => now()->toDateString(),
                'target_year' => $yearAhead->year,
                'target_month' => $yearAhead->month,
            ])
            ->assertSessionHasErrors('target_month');
    });

    it('allows exactly 6 months ahead', function () {
        $this->travelTo(now()->setDate(2026, 10, 31));
        $exactlySixMonths = now()->startOfMonth()->addMonths(6);

        $this->actingAs($this->financialSecretary)
            ->postReviewedPayment(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 4000,
                'paid_at' => now()->toDateString(),
                'target_year' => $exactlySixMonths->year,
                'target_month' => $exactlySixMonths->month,
            ])
            ->assertSessionDoesntHaveErrors('target_month')
            ->assertRedirect();
    });

    it('allows payment for past months with unpaid contributions', function () {
        $pastMonth = now()->subMonth();

        $this->actingAs($this->financialSecretary)
            ->postReviewedPayment(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 4000,
                'paid_at' => now()->toDateString(),
                'target_year' => $pastMonth->year,
                'target_month' => $pastMonth->month,
            ])
            ->assertSessionDoesntHaveErrors('target_month')
            ->assertRedirect();
    });
});
