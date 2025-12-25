<?php

use App\Enums\PaymentStatus;
use App\Models\Contribution;
use App\Models\Payment;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Record Partial Payment', function () {
    beforeEach(function () {
        $this->financialSecretary = User::factory()->financialSecretary()->create();
        $this->member = User::factory()->member()->employed()->create();
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
                'amount' => 200000, // ₦2,000 of ₦4,000
                'paid_at' => now()->toDateString(),
                'target_year' => $futureMonth->year,
                'target_month' => $futureMonth->month,
            ])
            ->assertRedirect();

        $contribution->refresh();

        expect($contribution->status)->toBe(PaymentStatus::Partial);
        expect($contribution->total_paid)->toBe(200000);
        expect($contribution->balance)->toBe(200000);
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
                'amount' => 100000,
                'paid_at' => now()->toDateString(),
                'target_year' => $futureMonth->year,
                'target_month' => $futureMonth->month,
            ]);

        expect($contribution->fresh()->total_paid)->toBe(100000);
        expect($contribution->fresh()->status)->toBe(PaymentStatus::Partial);

        // Second partial payment - ₦1,500
        $this->actingAs($this->financialSecretary)
            ->post(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 150000,
                'paid_at' => now()->toDateString(),
                'target_year' => $futureMonth->year,
                'target_month' => $futureMonth->month,
            ]);

        expect($contribution->fresh()->total_paid)->toBe(250000);
        expect($contribution->fresh()->balance)->toBe(150000);
        expect($contribution->fresh()->status)->toBe(PaymentStatus::Partial);

        // Third payment - remaining ₦1,500
        $this->actingAs($this->financialSecretary)
            ->post(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 150000,
                'paid_at' => now()->toDateString(),
                'target_year' => $futureMonth->year,
                'target_month' => $futureMonth->month,
            ]);

        expect($contribution->fresh()->total_paid)->toBe(400000);
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
            ->create(['amount' => 200000]); // ₦2,000 partial

        $this->actingAs($this->financialSecretary)
            ->get(route('contributions.show', $contribution))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Contributions/Show')
                ->has('contribution')
            );
    });
});
