<?php

use App\Models\Contribution;
use App\Models\Payment;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * T077 [US5] Feature test for partial payment details display
 */
describe('Partial Payment Details', function () {
    beforeEach(function () {
        $this->member = User::factory()->member()->employed()->create();
        $this->recorder = User::factory()->financialSecretary()->create();
    });

    it('shows partial status when payment is incomplete', function () {
        $contribution = Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->create(['expected_amount' => 400000]);

        Payment::factory()->create([
            'contribution_id' => $contribution->id,
            'amount' => 200000,
            'recorded_by' => $this->recorder->id,
        ]);

        $this->actingAs($this->member)
            ->get(route('contributions.my'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('contributions.0.status', 'partial')
                ->where('contributions.0.balance', 200000)
            );
    });

    it('shows paid status when fully paid', function () {
        $contribution = Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->create(['expected_amount' => 400000]);

        Payment::factory()->create([
            'contribution_id' => $contribution->id,
            'amount' => 400000,
            'recorded_by' => $this->recorder->id,
        ]);

        $this->actingAs($this->member)
            ->get(route('contributions.my'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('contributions.0.status', 'paid')
                ->where('contributions.0.balance', 0)
            );
    });

    it('shows unpaid status when no payment made', function () {
        Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->create(['expected_amount' => 400000]);

        $this->actingAs($this->member)
            ->get(route('contributions.my'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('contributions.0.status', 'unpaid')
                ->where('contributions.0.balance', 400000)
            );
    });

    it('shows overdue status for past unpaid contributions', function () {
        // Create a contribution from last month with no payment
        Contribution::factory()
            ->forUser($this->member)
            ->forMonth(now()->subMonth()->year, now()->subMonth()->month)
            ->create(['expected_amount' => 400000]);

        $this->actingAs($this->member)
            ->get(route('contributions.my'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('contributions.0.status', 'overdue')
            );
    });

    it('calculates balance correctly with multiple payments', function () {
        $contribution = Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->create(['expected_amount' => 400000]);

        Payment::factory()->create([
            'contribution_id' => $contribution->id,
            'amount' => 100000,
            'recorded_by' => $this->recorder->id,
        ]);

        Payment::factory()->create([
            'contribution_id' => $contribution->id,
            'amount' => 150000,
            'recorded_by' => $this->recorder->id,
        ]);

        $this->actingAs($this->member)
            ->get(route('contributions.my'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('contributions.0.total_paid', 250000)
                ->where('contributions.0.balance', 150000)
                ->where('contributions.0.status', 'partial')
            );
    });

    it('shows payment details in contribution', function () {
        $contribution = Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->create(['expected_amount' => 400000]);

        $payment = Payment::factory()->create([
            'contribution_id' => $contribution->id,
            'amount' => 200000,
            'paid_at' => now()->subDays(3),
            'notes' => 'Partial payment for December',
            'recorded_by' => $this->recorder->id,
        ]);

        $this->actingAs($this->member)
            ->get(route('contributions.my'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('contributions.0.payments', 1)
                ->where('contributions.0.payments.0.amount', 200000)
                ->has('contributions.0.payments.0.paid_at')
                ->where('contributions.0.payments.0.notes', 'Partial payment for December')
            );
    });

    it('shows expected amount based on category', function () {
        // Create employed member contribution
        $employedContribution = Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->create(['expected_amount' => 400000]);

        $this->actingAs($this->member)
            ->get(route('contributions.my'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('contributions.0.expected_amount', 400000)
            );

        // Create student member
        $student = User::factory()->member()->student()->create();
        Contribution::factory()
            ->forUser($student)
            ->currentMonth()
            ->create(['expected_amount' => 100000]);

        $this->actingAs($student)
            ->get(route('contributions.my'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('contributions.0.expected_amount', 100000)
            );
    });
});
