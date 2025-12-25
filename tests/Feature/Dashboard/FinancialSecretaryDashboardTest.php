<?php

use App\Models\Contribution;
use App\Models\Payment;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * T042 [US2] Feature test for Financial Secretary dashboard
 *
 * Financial Secretary should see the same view as Super Admin
 */
describe('Financial Secretary Dashboard', function () {
    beforeEach(function () {
        $this->financialSecretary = User::factory()->financialSecretary()->create();

        // Create some members with contributions
        $this->employedMember = User::factory()->member()->employed()->create();
        $this->studentMember = User::factory()->member()->student()->create();

        // Create contributions for current month
        Contribution::factory()
            ->forUser($this->employedMember)
            ->currentMonth()
            ->employed()
            ->create();

        Contribution::factory()
            ->forUser($this->studentMember)
            ->currentMonth()
            ->student()
            ->create();
    });

    it('displays dashboard with summary for financial secretary', function () {
        $this->actingAs($this->financialSecretary)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->has('summary')
            );
    });

    it('displays all members contribution statuses for financial secretary', function () {
        $this->actingAs($this->financialSecretary)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('member_statuses', 2) // Financial Secretary sees all members
            );
    });

    it('displays recent payments for financial secretary', function () {
        // Record a payment first
        $contribution = Contribution::forUser($this->employedMember->id)->currentMonth()->first();
        Payment::factory()
            ->forContribution($contribution)
            ->recordedBy($this->financialSecretary)
            ->create(['amount' => 400000]);

        $this->actingAs($this->financialSecretary)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('recent_payments', 1)
            );
    });

    it('financial secretary can see payment recording links', function () {
        $this->actingAs($this->financialSecretary)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can_record_payments', true)
            );
    });
});
