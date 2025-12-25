<?php

use App\Models\Contribution;
use App\Models\Payment;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * T078 [US5] Browser test for member contribution history navigation
 */
describe('Member History Flow', function () {
    beforeEach(function () {
        $this->member = User::factory()->member()->employed()->create();
        $this->recorder = User::factory()->financialSecretary()->create();
    });

    it('member can navigate to my contributions page from dashboard', function () {
        $contribution = Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->create(['expected_amount' => 400000]);

        $page = visit(route('login'));

        $page->fill('email', $this->member->email)
            ->fill('password', 'password')
            ->click('Log in')
            ->assertPathIs('/dashboard');

        // Navigate to my contributions
        $page->click('My Contributions')
            ->assertPathIs('/contributions/my')
            ->assertSee('My Contribution History');
    });

    it('member can view contribution details with payments', function () {
        $contribution = Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->create(['expected_amount' => 400000]);

        Payment::factory()->create([
            'contribution_id' => $contribution->id,
            'amount' => 200000,
            'recorded_by' => $this->recorder->id,
        ]);

        $page = visit(route('login'));

        $page->fill('email', $this->member->email)
            ->fill('password', 'password')
            ->click('Log in');

        // Navigate to my contributions
        $page->click('My Contributions')
            ->assertSee('₦2,000.00')  // Payment amount
            ->assertSee('Partial');    // Status badge
    });

    it('member can see family aggregate statistics', function () {
        // Create contributions for multiple members
        Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->create(['expected_amount' => 400000]);

        $otherMember = User::factory()->member()->employed()->create();
        Contribution::factory()
            ->forUser($otherMember)
            ->currentMonth()
            ->create(['expected_amount' => 400000]);

        $page = visit(route('login'));

        $page->fill('email', $this->member->email)
            ->fill('password', 'password')
            ->click('Log in')
            ->click('My Contributions')
            ->assertSee('Family Total');  // Aggregate stats visible
    });

    it('member can click on contribution to view details', function () {
        $contribution = Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->create(['expected_amount' => 400000]);

        $page = visit(route('login'));

        $page->fill('email', $this->member->email)
            ->fill('password', 'password')
            ->click('Log in')
            ->click('My Contributions');

        // Click on contribution to view details
        $page->click($contribution->period_label)
            ->assertPathIs("/contributions/{$contribution->id}")
            ->assertSee('Contribution Details');
    });

    it('shows overdue badge for past unpaid contributions', function () {
        // Create an overdue contribution
        Contribution::factory()
            ->forUser($this->member)
            ->forMonth(now()->subMonth()->year, now()->subMonth()->month)
            ->create(['expected_amount' => 400000]);

        $page = visit(route('login'));

        $page->fill('email', $this->member->email)
            ->fill('password', 'password')
            ->click('Log in')
            ->click('My Contributions')
            ->assertSee('Overdue');
    });

    it('shows paid badge for fully paid contributions', function () {
        $contribution = Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->create(['expected_amount' => 400000]);

        Payment::factory()->create([
            'contribution_id' => $contribution->id,
            'amount' => 400000,
            'recorded_by' => $this->recorder->id,
        ]);

        $page = visit(route('login'));

        $page->fill('email', $this->member->email)
            ->fill('password', 'password')
            ->click('Log in')
            ->click('My Contributions')
            ->assertSee('Paid');
    });

    it('displays contributions in chronological order', function () {
        // Create multiple contributions
        Contribution::factory()
            ->forUser($this->member)
            ->forMonth(2025, 10)
            ->create(['expected_amount' => 400000]);

        Contribution::factory()
            ->forUser($this->member)
            ->forMonth(2025, 12)
            ->create(['expected_amount' => 400000]);

        Contribution::factory()
            ->forUser($this->member)
            ->forMonth(2025, 11)
            ->create(['expected_amount' => 400000]);

        $page = visit(route('login'));

        $page->fill('email', $this->member->email)
            ->fill('password', 'password')
            ->click('Log in')
            ->click('My Contributions')
            ->assertSeeInOrder(['December 2025', 'November 2025', 'October 2025']);
    });

    it('member cannot see other members individual contributions', function () {
        $otherMember = User::factory()->member()->employed()->create([
            'name' => 'Other Member Name',
        ]);

        Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->create(['expected_amount' => 400000]);

        Contribution::factory()
            ->forUser($otherMember)
            ->currentMonth()
            ->create(['expected_amount' => 400000]);

        $page = visit(route('login'));

        $page->fill('email', $this->member->email)
            ->fill('password', 'password')
            ->click('Log in')
            ->click('My Contributions')
            ->assertDontSee('Other Member Name');
    });

    it('shows no contributions message when member has none', function () {
        $page = visit(route('login'));

        $page->fill('email', $this->member->email)
            ->fill('password', 'password')
            ->click('Log in')
            ->click('My Contributions')
            ->assertSee('No contributions found');
    });
});
