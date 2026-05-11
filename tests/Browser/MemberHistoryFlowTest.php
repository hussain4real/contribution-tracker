<?php

use App\Models\Contribution;
use App\Models\Payment;

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
            ->assertPathIs('/contributions/my')
            ->assertSee('My Contribution History');
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

    it('member can click on contribution to view details', function () {
        $contribution = Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->create(['expected_amount' => 4000]);

        $page = loginBrowserAs($this->member);

        $page->click('My Contributions')
            ->assertPathIs('/contributions/my');

        $page->click($contribution->period_label)
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
