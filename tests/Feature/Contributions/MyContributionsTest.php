<?php

use App\Models\Contribution;
use App\Models\Payment;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * T076 [US5] Feature test for member viewing own contribution history
 */
describe('My Contributions', function () {
    beforeEach(function () {
        $this->member = User::factory()->member()->employed()->create();
    });

    it('displays my contributions page for authenticated member', function () {
        // Create some contributions for this member
        Contribution::factory()
            ->forUser($this->member)
            ->forMonth(now()->year, now()->month)
            ->create();

        $this->actingAs($this->member)
            ->get(route('contributions.my'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Contributions/My')
                ->has('contributions')
            );
    });

    it('shows member contributions with correct structure', function () {
        $contribution = Contribution::factory()
            ->forUser($this->member)
            ->forMonth(now()->year, now()->month)
            ->create([
                'expected_amount' => 400000,
            ]);

        $this->actingAs($this->member)
            ->get(route('contributions.my'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('contributions', 1)
                ->has('contributions.0', fn (Assert $contribution) => $contribution
                    ->has('id')
                    ->has('year')
                    ->has('month')
                    ->has('expected_amount')
                    ->has('total_paid')
                    ->has('balance')
                    ->has('status')
                    ->has('period_label')
                    ->has('due_date')
                    ->etc()
                )
            );
    });

    it('includes family aggregate statistics (FR-015)', function () {
        // Create contributions for multiple members
        $member2 = User::factory()->member()->employed()->create();

        Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->create(['expected_amount' => 400000]);

        Contribution::factory()
            ->forUser($member2)
            ->currentMonth()
            ->create(['expected_amount' => 400000]);

        $this->actingAs($this->member)
            ->get(route('contributions.my'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('family_aggregate', fn (Assert $aggregate) => $aggregate
                    ->has('total_expected')
                    ->has('total_collected')
                    ->has('total_outstanding')
                    ->has('collection_rate')
                    ->etc()
                )
            );
    });

    it('does not show other members individual details (FR-016)', function () {
        $otherMember = User::factory()->member()->employed()->create();

        Contribution::factory()
            ->forUser($otherMember)
            ->currentMonth()
            ->create();

        $this->actingAs($this->member)
            ->get(route('contributions.my'))
            ->assertInertia(fn (Assert $page) => $page
                ->missing('other_members')
                ->missing('all_contributions')
            );
    });

    it('shows contributions ordered by date descending', function () {
        // Create contributions for multiple months
        Contribution::factory()
            ->forUser($this->member)
            ->forMonth(2025, 10)
            ->create();

        Contribution::factory()
            ->forUser($this->member)
            ->forMonth(2025, 12)
            ->create();

        Contribution::factory()
            ->forUser($this->member)
            ->forMonth(2025, 11)
            ->create();

        $this->actingAs($this->member)
            ->get(route('contributions.my'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('contributions', 3)
                ->where('contributions.0.month', 12)
                ->where('contributions.1.month', 11)
                ->where('contributions.2.month', 10)
            );
    });

    it('includes payment history for each contribution', function () {
        $contribution = Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->create(['expected_amount' => 400000]);

        $recorder = User::factory()->financialSecretary()->create();

        Payment::factory()->create([
            'contribution_id' => $contribution->id,
            'amount' => 200000,
            'recorded_by' => $recorder->id,
        ]);

        $this->actingAs($this->member)
            ->get(route('contributions.my'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('contributions.0.payments', 1)
                ->has('contributions.0.payments.0', fn (Assert $payment) => $payment
                    ->has('id')
                    ->has('amount')
                    ->has('paid_at')
                    ->etc()
                )
            );
    });

    it('requires authentication', function () {
        $this->get(route('contributions.my'))
            ->assertRedirect();
    });

    it('is accessible to all authenticated users regardless of role', function () {
        $superAdmin = User::factory()->superAdmin()->create();
        $financialSecretary = User::factory()->financialSecretary()->create();

        $this->actingAs($superAdmin)
            ->get(route('contributions.my'))
            ->assertOk();

        $this->actingAs($financialSecretary)
            ->get(route('contributions.my'))
            ->assertOk();
    });
});
