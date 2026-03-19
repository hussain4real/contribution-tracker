<?php

use App\Models\Contribution;
use App\Models\Family;
use App\Models\Payment;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * T041 [US2] Feature test for Admin dashboard
 *
 * Admin should see:
 * - Summary statistics (total members, total expected, total collected, overdue)
 * - All members' contribution statuses
 * - Recent payments
 */
describe('Admin Dashboard', function () {
    beforeEach(function () {
        $family = Family::factory()->create();

        $this->admin = User::factory()->admin()->create(['family_id' => $family->id]);

        // Create some members with contributions
        $this->employedMember = User::factory()->member()->employed()->create(['family_id' => $family->id]);
        $this->studentMember = User::factory()->member()->student()->create(['family_id' => $family->id]);

        // Create contributions for current month
        $this->employedContribution = Contribution::factory()
            ->forUser($this->employedMember)
            ->currentMonth()
            ->employed()
            ->create();

        $this->studentContribution = Contribution::factory()
            ->forUser($this->studentMember)
            ->currentMonth()
            ->student()
            ->create();

        // Record a full payment for employed member
        Payment::factory()
            ->forContribution($this->employedContribution)
            ->recordedBy($this->admin)
            ->create(['amount' => 4000]);

        // Student has no payment (unpaid)
    });

    it('displays dashboard with summary statistics', function () {
        $this->actingAs($this->admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->has('summary', fn (Assert $summary) => $summary
                    ->has('total_members')
                    ->has('total_expected')
                    ->has('total_collected')
                    ->has('total_outstanding')
                    ->has('overdue_count')
                    ->has('collection_rate')
                    ->etc()
                )
            );
    });

    it('displays all members contribution statuses for admin', function () {
        $this->actingAs($this->admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('member_statuses', 2) // Both members visible
                ->has('member_statuses.0', fn (Assert $status) => $status
                    ->has('id')
                    ->has('name')
                    ->has('category')
                    ->has('current_month_status')
                    ->has('current_month_balance')
                    ->etc()
                )
            );
    });

    it('displays recent payments', function () {
        $this->actingAs($this->admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('recent_payments', 1) // One payment was recorded
                ->has('recent_payments.0', fn (Assert $payment) => $payment
                    ->has('id')
                    ->has('amount')
                    ->has('paid_at')
                    ->has('member_name')
                    ->etc()
                )
            );
    });

    it('calculates summary statistics correctly', function () {
        $this->actingAs($this->admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total_members', 2)
                ->where('summary.total_expected', 5000) // 4000 + 1000
                ->where('summary.total_collected', 4000) // Only employed paid
                ->where('summary.total_outstanding', 1000) // Student outstanding
            );
    });
});
