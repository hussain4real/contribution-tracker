<?php

use App\Models\Contribution;
use App\Models\Payment;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * T041 [US2] Feature test for Super Admin dashboard
 *
 * Super Admin should see:
 * - Summary statistics (total members, total expected, total collected, overdue)
 * - All members' contribution statuses
 * - Recent payments
 */
describe('Super Admin Dashboard', function () {
    beforeEach(function () {
        $this->superAdmin = User::factory()->superAdmin()->create();

        // Create some members with contributions
        $this->employedMember = User::factory()->member()->employed()->create();
        $this->studentMember = User::factory()->member()->student()->create();

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
            ->recordedBy($this->superAdmin)
            ->create(['amount' => 400000]);

        // Student has no payment (unpaid)
    });

    it('displays dashboard with summary statistics', function () {
        $this->actingAs($this->superAdmin)
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
        $this->actingAs($this->superAdmin)
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
        $this->actingAs($this->superAdmin)
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
        $this->actingAs($this->superAdmin)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total_members', 2)
                ->where('summary.total_expected', 500000) // 400000 + 100000
                ->where('summary.total_collected', 400000) // Only employed paid
                ->where('summary.total_outstanding', 100000) // Student outstanding
            );
    });
});
