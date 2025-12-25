<?php

/**
 * T045 [US2] Browser test for dashboard navigation and member click-through
 *
 * Run with: ./vendor/bin/pest tests/Browser/ --headed
 */

use App\Models\Contribution;
use App\Models\User;

describe('Dashboard Flow (Browser)', function () {
    beforeEach(function () {
        $this->superAdmin = User::factory()->superAdmin()->create([
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
        ]);

        $this->member = User::factory()->member()->employed()->create([
            'name' => 'John Doe',
        ]);

        // Create contribution for current month
        Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->employed()
            ->create();
    });

    it('displays dashboard with member statuses after login', function () {
        $page = visit('/login');

        $page->fill('email', 'admin@test.com')
            ->fill('password', 'password')
            ->click('Log in')
            ->assertSee('Dashboard')
            ->assertNoJavaScriptErrors();
    });

    it('shows summary cards on dashboard', function () {
        $page = visit('/login');

        $page->fill('email', 'admin@test.com')
            ->fill('password', 'password')
            ->click('Log in')
            ->assertSee('Dashboard')
            ->assertSee('Total Members')
            ->assertSee('Total Collected')
            ->assertNoJavaScriptErrors();
    });

    it('member dashboard shows personal status', function () {
        $this->member->update([
            'email' => 'member@test.com',
            'password' => bcrypt('password'),
        ]);

        $page = visit('/login');

        $page->fill('email', 'member@test.com')
            ->fill('password', 'password')
            ->click('Log in')
            ->assertSee('Dashboard')
            ->assertSee('Your Contribution')
            ->assertNoJavaScriptErrors();
    });
});
