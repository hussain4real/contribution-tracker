<?php

use App\Enums\PaymentStatus;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\Payment;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

describe('Record Partial Payment', function () {
    beforeEach(function () {
        $this->family = Family::factory()->create();
        $this->financialSecretary = User::factory()->financialSecretary()->create(['family_id' => $this->family->id]);
        $this->member = User::factory()->member()->employed()->create(['family_id' => $this->family->id]);
    });

    it('contribution shows Partial status after partial payment', function () {
        // Create a future month contribution to avoid overdue status
        $futureMonth = now()->addMonth();

        $contribution = Contribution::factory()
            ->forUser($this->member)
            ->forMonth($futureMonth->year, $futureMonth->month)
            ->employed()
            ->create();

        $this->actingAs($this->financialSecretary)
            ->post(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 2000, // ₦2,000 of ₦4,000
                'paid_at' => now()->toDateString(),
                'target_year' => $futureMonth->year,
                'target_month' => $futureMonth->month,
            ])
            ->assertRedirect();

        $contribution->refresh();

        expect($contribution->status)->toBe(PaymentStatus::Partial);
        expect($contribution->total_paid)->toBe(2000);
        expect($contribution->balance)->toBe(2000);
    });

    it('multiple partial payments accumulate correctly', function () {
        $futureMonth = now()->addMonth();

        $contribution = Contribution::factory()
            ->forUser($this->member)
            ->forMonth($futureMonth->year, $futureMonth->month)
            ->employed()
            ->create();

        // First partial payment - ₦1,000
        $this->actingAs($this->financialSecretary)
            ->post(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 1000,
                'paid_at' => now()->toDateString(),
                'target_year' => $futureMonth->year,
                'target_month' => $futureMonth->month,
            ]);

        expect($contribution->fresh()->total_paid)->toBe(1000);
        expect($contribution->fresh()->status)->toBe(PaymentStatus::Partial);

        // Second partial payment - ₦1,500
        $this->actingAs($this->financialSecretary)
            ->post(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 1500,
                'paid_at' => now()->toDateString(),
                'target_year' => $futureMonth->year,
                'target_month' => $futureMonth->month,
            ]);

        expect($contribution->fresh()->total_paid)->toBe(2500);
        expect($contribution->fresh()->balance)->toBe(1500);
        expect($contribution->fresh()->status)->toBe(PaymentStatus::Partial);

        // Third payment - remaining ₦1,500
        $this->actingAs($this->financialSecretary)
            ->post(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 1500,
                'paid_at' => now()->toDateString(),
                'target_year' => $futureMonth->year,
                'target_month' => $futureMonth->month,
            ]);

        expect($contribution->fresh()->total_paid)->toBe(4000);
        expect($contribution->fresh()->balance)->toBe(0);
        expect($contribution->fresh()->status)->toBe(PaymentStatus::Paid);
    });

    it('shows partial payment details on contribution page', function () {
        $contribution = Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->employed()
            ->create();

        Payment::factory()
            ->forContribution($contribution)
            ->recordedBy($this->financialSecretary)
            ->create(['amount' => 2000]); // ₦2,000 partial

        $this->actingAs($this->financialSecretary)
            ->get(route('contributions.show', $contribution))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Contributions/Show')
                ->has('contribution')
            );
    });
});
