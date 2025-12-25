<?php

use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * FR-018: Advance Payment Limit
 *
 * "Advance payments allowed up to 6 months ahead"
 */
describe('Advance Payment Limit (FR-018)', function () {
    beforeEach(function () {
        $this->financialSecretary = User::factory()->financialSecretary()->create();
        $this->member = User::factory()->member()->employed()->create();
    });

    it('rejects payment targeting more than 6 months ahead', function () {
        $sevenMonthsAhead = now()->addMonths(7);

        $this->actingAs($this->financialSecretary)
            ->post(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 400000,
                'paid_at' => now()->toDateString(),
                'target_year' => $sevenMonthsAhead->year,
                'target_month' => $sevenMonthsAhead->month,
            ])
            ->assertSessionHasErrors('target_month');
    });

    it('rejects payment targeting 12 months ahead', function () {
        $yearAhead = now()->addYear();

        $this->actingAs($this->financialSecretary)
            ->post(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 400000,
                'paid_at' => now()->toDateString(),
                'target_year' => $yearAhead->year,
                'target_month' => $yearAhead->month,
            ])
            ->assertSessionHasErrors('target_month');
    });

    it('allows exactly 6 months ahead', function () {
        $exactlySixMonths = now()->addMonths(6);

        $this->actingAs($this->financialSecretary)
            ->post(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 400000,
                'paid_at' => now()->toDateString(),
                'target_year' => $exactlySixMonths->year,
                'target_month' => $exactlySixMonths->month,
            ])
            ->assertSessionDoesntHaveErrors('target_month')
            ->assertRedirect();
    });

    it('rejects payment for past months', function () {
        $pastMonth = now()->subMonth();

        $this->actingAs($this->financialSecretary)
            ->post(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 400000,
                'paid_at' => now()->toDateString(),
                'target_year' => $pastMonth->year,
                'target_month' => $pastMonth->month,
            ])
            ->assertSessionHasErrors('target_month');
    });
});
