<?php

/**
 * Browser test for Payment Recording Flow
 *
 * Run with: ./vendor/bin/pest tests/Browser/ --headed
 */

use App\Models\Contribution;
use App\Models\User;

describe('Payment Recording Flow (Browser)', function () {
    beforeEach(function () {
        $this->financialSecretary = User::factory()->financialSecretary()->create([
            'email' => 'fs@test.com',
            'password' => bcrypt('password'),
        ]);

        $this->member = User::factory()->member()->employed()->create([
            'name' => 'John Doe',
        ]);

        // Create a contribution for the current month
        $this->contribution = Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->employed()
            ->create();
    });

    it('allows financial secretary to record a payment through the UI', function () {
        $page = visit('/login');

        // Login as Financial Secretary
        $page->fill('email', 'fs@test.com')
            ->fill('password', 'password')
            ->click('Log in')
            ->assertSee('Dashboard');

        // Navigate to member's payment form
        $page->navigate("/members/{$this->member->id}/payments/create")
            ->assertSee('Record Payment')
            ->assertSee($this->member->name);

        // Fill payment form
        $page->fill('#amount', '400000')
            ->fill('#paid_at', now()->format('Y-m-d'))
            ->click('Record Payment');

        // Verify success message
        $page->assertSee('Payment of ₦4,000.00 recorded');

        // Verify contribution is now paid
        expect($this->contribution->fresh()->isPaid())->toBeTrue();
    });

    it('shows pending contributions on payment form', function () {
        // Create a partial payment
        $this->contribution->payments()->create([
            'amount' => 200000,
            'paid_at' => now(),
            'recorded_by' => $this->financialSecretary->id,
        ]);

        $page = visit('/login');

        $page->fill('email', 'fs@test.com')
            ->fill('password', 'password')
            ->click('Log in');

        $page->navigate("/members/{$this->member->id}/payments/create")
            ->assertSee('Pending Contributions')
            ->assertSee('remaining')
            ->assertSee('Partial');
    });

    it('quick amount buttons work correctly', function () {
        $page = visit('/login');

        $page->fill('email', 'fs@test.com')
            ->fill('password', 'password')
            ->click('Log in');

        $page->navigate("/members/{$this->member->id}/payments/create")
            ->click('1 Month')
            ->assertValue('#amount', '400000');
    });
});
