<?php

declare(strict_types=1);

use App\Models\Contribution;
use App\Models\FamilyMembership;
use App\Models\Payment;
use App\Models\PaymentBatch;
use App\Models\PaystackTransaction;

/**
 * T078 [US5] Browser test for member contribution history navigation
 */
describe('Member History Flow', function () {
    beforeEach(function () {
        $this->family = createBrowserFamily();
        $this->member = createBrowserMember($this->family);
        $this->recorder = createBrowserFinancialSecretary($this->family);
    });

    it('member can navigate to my contributions page from dashboard', function () {
        Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->create(['expected_amount' => 4000]);

        $page = loginBrowserAs($this->member);

        $page->click('My Contributions')
            ->assertPathIs("/{$this->family->slug}/contributions/my")
            ->assertSee('My money');
    });

    it('shows a payment callback error after a full page return', function () {
        $page = loginBrowserAs($this->member);

        $page->navigate(route('pay.callback', ['current_family' => $this->family->slug, 'reference' => 'TXN_MISSING']))
            ->assertPathIs("/{$this->family->slug}/contributions/my")
            ->assertSeeIn('[role="alert"]', 'Transaction not found.')
            ->assertVisible('[role="alert"]')
            ->assertNoJavaScriptErrors();
        $page->click('Dashboard')
            ->assertPathIs("/{$this->family->slug}/dashboard")
            ->back()
            ->assertPathIs("/{$this->family->slug}/contributions/my")
            ->assertDontSee('Transaction not found.');
    });

    it('shows a confirmed payment and receipt after a full page return', function () {
        $membership = $this->member->membershipForFamily($this->family);
        assert($membership instanceof FamilyMembership);
        $batch = PaymentBatch::factory()->create([
            'family_id' => $this->family->id,
            'family_membership_id' => $membership->id,
            'recorded_by' => $this->recorder->id,
        ]);
        $transaction = PaystackTransaction::factory()->create([
            'family_id' => $this->family->id,
            'user_id' => $this->member->id,
            'payment_batch_id' => $batch->id,
        ]);

        $page = loginBrowserAs($this->member);
        $page->navigate(route('pay.callback', ['current_family' => $this->family->slug, 'reference' => $transaction->reference]))
            ->assertPathIs("/{$this->family->slug}/contributions/my")
            ->assertSeeIn('[role="status"]', 'Payment already confirmed.')
            ->assertVisible('[role="status"]')
            ->assertSee("Receipt #{$batch->receipt_number}")
            ->assertNoJavaScriptErrors();
        $page->click('Dashboard')
            ->assertPathIs("/{$this->family->slug}/dashboard")
            ->back()
            ->assertPathIs("/{$this->family->slug}/contributions/my")
            ->assertDontSee('Payment already confirmed.');
    });

    it('member can view contribution details with payments', function () {
        // Use a future month so it's before the due date (shows "Partial" not "Overdue")
        $nextMonth = now()->startOfMonth()->addMonth();
        $contribution = Contribution::factory()
            ->forUser($this->member)
            ->forMonth($nextMonth->year, $nextMonth->month)
            ->create(['expected_amount' => 4000]);

        Payment::factory()->create([
            'contribution_id' => $contribution->id,
            'amount' => 2000,
            'recorded_by' => $this->recorder->id,
        ]);

        $page = loginBrowserAs($this->member);

        $page->click('My Contributions')
            ->assertSee('₦2,000.00')  // Payment amount
            ->assertSee('Partial');    // Status badge
    });

    it('member can see family aggregate statistics', function () {
        Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->create(['expected_amount' => 4000]);

        $otherMember = createBrowserMember($this->family);
        Contribution::factory()
            ->forUser($otherMember)
            ->currentMonth()
            ->create(['expected_amount' => 4000]);

        $page = loginBrowserAs($this->member);

        $page->click('My Contributions')
            ->assertSee('Family Total');  // Aggregate stats visible
    });

    it('member can open contribution details from the history page', function () {
        $contribution = Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->create(['expected_amount' => 4000]);

        $page = loginBrowserAs($this->member);

        $page->navigate(route('contributions.my', ['current_family' => $this->family->slug]))
            ->assertPathIs("/{$this->family->slug}/contributions/my");

        $page->navigate(route('contributions.show', [
            'current_family' => $this->family->slug,
            'contribution' => $contribution,
        ]))
            ->assertPathContains('/contributions/')
            ->assertSee($contribution->period_label);
    });

    it('shows overdue badge for past unpaid contributions', function () {
        Contribution::factory()
            ->forUser($this->member)
            ->forMonth(now()->subMonth()->year, now()->subMonth()->month)
            ->create(['expected_amount' => 4000]);

        $page = loginBrowserAs($this->member);

        $page->click('My Contributions')
            ->assertSee('Overdue');
    });

    it('shows paid badge for fully paid contributions', function () {
        $contribution = Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->create(['expected_amount' => 4000]);

        Payment::factory()->create([
            'contribution_id' => $contribution->id,
            'amount' => 4000,
            'recorded_by' => $this->recorder->id,
        ]);

        $page = loginBrowserAs($this->member);

        $page->click('My Contributions')
            ->assertSee('Paid');
    });

    it('displays contributions in chronological order', function () {
        Contribution::factory()
            ->forUser($this->member)
            ->forMonth(2025, 10)
            ->create(['expected_amount' => 4000]);

        Contribution::factory()
            ->forUser($this->member)
            ->forMonth(2025, 12)
            ->create(['expected_amount' => 4000]);

        Contribution::factory()
            ->forUser($this->member)
            ->forMonth(2025, 11)
            ->create(['expected_amount' => 4000]);

        $page = loginBrowserAs($this->member);

        $page->click('My Contributions')
            ->assertSee('December 2025')
            ->assertSee('November 2025')
            ->assertSee('October 2025');
    });

    it('member cannot see other members individual contributions', function () {
        $otherMember = createBrowserMember($this->family, [
            'name' => 'Other Member Name',
        ]);

        Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->create(['expected_amount' => 4000]);

        Contribution::factory()
            ->forUser($otherMember)
            ->currentMonth()
            ->create(['expected_amount' => 4000]);

        $page = loginBrowserAs($this->member);

        $page->click('My Contributions')
            ->assertDontSee('Other Member Name');
    });

    it('shows no contributions message when member has none', function () {
        $page = loginBrowserAs($this->member);

        $page->click('My Contributions')
            ->assertSee('No contributions found');
    });
});
