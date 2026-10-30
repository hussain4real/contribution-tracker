<?php

declare(strict_types=1);

use App\Models\Contribution;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\FinancialReversal;
use App\Models\Payment;
use App\Models\PaymentBatch;
use App\Models\PlatformPlan;
use App\Models\User;
use App\Services\PaymentAllocationService;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

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

    it('shows no next contribution when the member has nothing outstanding', function () {
        $this->actingAs($this->member)->get(route('contributions.my'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('next_contribution', null)
            ->where('personal_stats.total_outstanding', 0));
    });

    it('shows member contributions with correct structure', function () {
        $contribution = Contribution::factory()
            ->forUser($this->member)
            ->forMonth(now()->year, now()->month)
            ->create([
                'expected_amount' => 4000,
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
            ->create(['expected_amount' => 4000]);

        Contribution::factory()
            ->forUser($member2)
            ->currentMonth()
            ->create(['expected_amount' => 4000]);

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

    it('includes personal contribution stats', function () {
        // Create contributions for the member
        Contribution::factory()
            ->forUser($this->member)
            ->forMonth(2025, 10)
            ->create(['expected_amount' => 4000]);

        Contribution::factory()
            ->forUser($this->member)
            ->forMonth(2025, 11)
            ->create(['expected_amount' => 4000]);

        $this->actingAs($this->member)
            ->get(route('contributions.my'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('personal_stats', fn (Assert $stats) => $stats
                    ->where('total_expected', 8000)
                    ->where('total_paid', 0)
                    ->where('total_outstanding', 8000)
                    ->where('payment_rate', 0)
                    ->where('contribution_count', 2)
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
            ->create(['expected_amount' => 4000]);

        $recorder = User::factory()->financialSecretary()->create();

        Payment::factory()->create([
            'contribution_id' => $contribution->id,
            'amount' => 2000,
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

    it('shows the oldest outstanding period and only the member’s effective receipts', function () {
        Queue::fake();
        $family = $this->member->family;
        assert($family instanceof Family);
        $recorder = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
        $other = User::factory()->member()->employed()->create(['family_id' => $family->id]);
        $otherMembership = $other->membershipForFamily($family);
        assert($otherMembership instanceof FamilyMembership);
        Contribution::factory()->forUser($this->member)->forMonth(2025, 10)->create(['expected_amount' => 4000]);
        Contribution::factory()->forUser($this->member)->forMonth(2025, 11)->create(['expected_amount' => 4000]);
        $receipt = app(PaymentAllocationService::class)->createBatch(
            member: $this->member, amount: 1500, paidAt: now(), recordedBy: $recorder, family: $family,
        );
        PaymentBatch::factory()->create([
            'family_id' => $family->id,
            'family_membership_id' => $otherMembership->id,
            'receipt_number' => 2,
        ]);

        $this->actingAs($this->member)->get(route('contributions.my'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('personal_stats.total_outstanding', 6500)
            ->where('next_contribution.period_label', 'October 2025')
            ->where('next_contribution.balance', 2500)
            ->has('receipts.data', 1)
            ->where('receipts.data.0.id', $receipt->id)
            ->where('receipts.data.0.amount', 1500)
            ->where('receipts.data.0.periods.0', 'October 2025')
            );
    });

    it('marks reversed receipts and restores the outstanding balance', function () {
        Queue::fake();
        $family = $this->member->family;
        assert($family instanceof Family);
        $recorder = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
        Contribution::factory()->forUser($this->member)->forMonth(2025, 10)->create(['expected_amount' => 4000]);
        $receipt = app(PaymentAllocationService::class)->createBatch(
            member: $this->member, amount: 1500, paidAt: now(), recordedBy: $recorder, family: $family,
        );
        FinancialReversal::factory()->create([
            'family_id' => $family->id,
            'reversible_type' => PaymentBatch::MORPH_TYPE,
            'reversible_id' => $receipt->id,
            'reversed_by' => $recorder->id,
        ]);

        $this->actingAs($this->member)->get(route('contributions.my'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('personal_stats.total_outstanding', 4000)
            ->where('next_contribution.balance', 4000)
            ->has('receipts.data', 1)
            ->where('receipts.data.0.id', $receipt->id)
            ->where('receipts.data.0.is_reversed', true)
            );
    });

    it('lets the member reach older receipts without exposing another member’s receipts', function () {
        $family = $this->member->family;
        assert($family instanceof Family);
        $membership = $this->member->membershipForFamily($family);
        assert($membership instanceof FamilyMembership);
        for ($number = 1; $number <= 21; $number++) {
            PaymentBatch::factory()->create([
                'family_id' => $family->id,
                'family_membership_id' => $membership->id,
                'recorded_by' => $this->member->id,
                'receipt_number' => $number,
            ]);
        }

        $this->actingAs($this->member)->get(route('contributions.my', ['receipts_page' => 2]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('receipts.current_page', 2)
            ->where('receipts.last_page', 2)
            ->has('receipts.data', 1)
            ->where('receipts.data.0.receipt_number', 1)
            );
    });

    it('only offers online payment when the plan and payment setup allow it', function () {
        config(['services.paystack.public_key' => 'public-test-key']);
        $family = $this->member->family;
        assert($family instanceof Family);
        $plan = PlatformPlan::create([
            'name' => 'Test', 'slug' => 'test', 'price' => 0, 'max_members' => null,
            'features' => ['online_payments'], 'is_active' => true, 'sort_order' => 0,
        ]);
        $family->update(['platform_plan_id' => $plan->id, 'bank_code' => '123', 'account_number' => '1234567890']);

        $this->actingAs($this->member)->get(route('contributions.my'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('online_payments_available', true));

        $plan->update(['features' => []]);
        $member = $this->member->fresh();
        assert($member instanceof User);
        $this->actingAs($member)->get(route('contributions.my'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('online_payments_available', false));
    });

    it('requires authentication', function () {
        $this->get(route('contributions.my'))
            ->assertRedirect();
    });

    it('is accessible to all authenticated users regardless of role', function () {
        $admin = User::factory()->admin()->create();
        $financialSecretary = User::factory()->financialSecretary()->create();

        $this->actingAs($admin)
            ->get(route('contributions.my'))
            ->assertOk();

        $this->actingAs($financialSecretary)
            ->get(route('contributions.my'))
            ->assertOk();
    });
});
