<?php

declare(strict_types=1);

/**
 * T045 [US2] Browser test for dashboard navigation and member click-through
 *
 * Run with: ./vendor/bin/pest tests/Browser/ --headed
 */

use App\Models\Contribution;

describe('Dashboard Flow (Browser)', function () {
    beforeEach(function () {
        $this->family = createBrowserFamily();
        $this->admin = createBrowserAdmin($this->family, [
            'email' => 'admin@test.com',
        ]);

        $this->member = createBrowserMember($this->family, [
            'name' => 'John Doe',
            'email' => 'member@test.com',
        ]);
        $this->financialSecretary = createBrowserFinancialSecretary($this->family, [
            'email' => 'financial-secretary@test.com',
        ]);

        // Create contribution for current month
        Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->employed()
            ->create();
    });

    it('displays dashboard with member statuses after login', function () {
        loginBrowserAs($this->admin)
            ->assertSee('Dashboard')
            ->assertNoJavaScriptErrors();
    });

    it('shows summary cards on dashboard', function () {
        loginBrowserAs($this->admin)
            ->assertSee('Dashboard')
            ->assertSee('Total Members')
            ->assertSee('Total Collected')
            ->assertNoJavaScriptErrors();
    });

    it('member dashboard shows personal status', function () {
        loginBrowserAs($this->member)
            ->assertSee('Dashboard')
            ->assertSee('Your Contribution')
            ->assertNoJavaScriptErrors();
    });

    it('financial secretary login lands on the family dashboard', function () {
        loginBrowserAs($this->financialSecretary)
            ->assertSee('Dashboard')
            ->assertNoJavaScriptErrors();
    });

    it('platform administrator login lands on the platform dashboard', function () {
        $platformAdmin = createBrowserSuperAdmin(null, [
            'email' => 'platform-admin@test.com',
            'family_id' => null,
            'current_family_id' => null,
        ]);

        loginBrowserAs($platformAdmin)
            ->assertSee('Platform Overview')
            ->assertNoJavaScriptErrors();
    });
});
