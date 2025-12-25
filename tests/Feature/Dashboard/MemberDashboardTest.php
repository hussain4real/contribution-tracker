<?php

use App\Models\Contribution;
use App\Models\Payment;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * T043 [US2] Feature test for Member dashboard
 *
 * FR-015: Members CAN see family aggregate balance
 * FR-016: Members CANNOT see other individuals' contribution details
 */
describe('Member Dashboard', function () {
    beforeEach(function () {
        // Create regular member
        $this->member = User::factory()->member()->employed()->create();

        // Create another member (member should NOT see their details)
        $this->otherMember = User::factory()->member()->student()->create();

        // Create a financial secretary for recording payments
        $this->financialSecretary = User::factory()->financialSecretary()->create();

        // Create contributions for both members
        $this->memberContribution = Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->employed()
            ->create();

        $this->otherContribution = Contribution::factory()
            ->forUser($this->otherMember)
            ->currentMonth()
            ->student()
            ->create();

        // Record a payment for the logged-in member
        Payment::factory()
            ->forContribution($this->memberContribution)
            ->recordedBy($this->financialSecretary)
            ->create(['amount' => 200000]); // Partial payment
    });

    it('displays family aggregate stats for members (FR-015)', function () {
        $this->actingAs($this->member)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->has('family_aggregate', fn (Assert $agg) => $agg
                    ->has('total_expected')
                    ->has('total_collected')
                    ->has('total_outstanding')
                    ->has('collection_rate')
                    ->etc()
                )
            );
    });

    it('displays own contribution status for members', function () {
        $this->actingAs($this->member)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('personal', fn (Assert $personal) => $personal
                    ->has('current_month_status')
                    ->has('current_month_balance')
                    ->has('expected_amount')
                    ->has('total_paid')
                    ->etc()
                )
            );
    });

    it('does not show other members statuses (FR-016)', function () {
        $this->actingAs($this->member)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->missing('member_statuses') // Members cannot see individual statuses
            );
    });

    it('does not show recent payments list (FR-016)', function () {
        $this->actingAs($this->member)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->missing('recent_payments') // Members cannot see payment list
            );
    });

    it('calculates family aggregate correctly', function () {
        // Total expected: 400000 (employed) + 100000 (student) = 500000
        // Total collected: 200000 (partial payment to member)

        $this->actingAs($this->member)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('family_aggregate.total_expected', 500000)
                ->where('family_aggregate.total_collected', 200000)
                ->where('family_aggregate.total_outstanding', 300000)
            );
    });

    it('shows correct personal status', function () {
        $this->actingAs($this->member)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('personal.expected_amount', 400000)
                ->where('personal.total_paid', 200000)
                ->where('personal.current_month_balance', 200000)
                ->where('personal.current_month_status', 'partial')
            );
    });

    it('member cannot see payment recording links', function () {
        $this->actingAs($this->member)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can_record_payments', false)
            );
    });
});
