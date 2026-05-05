<?php

use App\Models\User;
use Illuminate\Support\Str;

describe('Mobile app shell', function () {
    beforeEach(function () {
        $this->admin = User::factory()->withoutTwoFactor()->admin()->create([
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
        ]);
    });

    it('shows role-aware mobile tabs and the more sheet', function () {
        $page = visit('/login')->on()->iPhone15Pro();

        $page->fill('email', 'admin@test.com')
            ->fill('password', 'password')
            ->click('Log in')
            ->assertSee('Dashboard')
            ->assertSee('Members')
            ->assertSee('My Contributions')
            ->assertSee('Payments')
            ->assertSee('More')
            ->click('More')
            ->assertSee('Notifications')
            ->assertSee('Family Admin')
            ->assertSee('Family Settings')
            ->assertNoJavaScriptErrors();
    });

    it('shows notification badges in the mobile more menu', function () {
        $this->admin->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\ContributionReminderNotification',
            'data' => [
                'contribution_id' => 1,
                'family_name' => 'Test Family',
                'period_label' => 'May 2026',
                'amount_owed' => 4000,
                'due_date' => '2026-05-25',
                'type' => 'reminder',
            ],
        ]);

        $page = visit('/login')->on()->iPhone15Pro();

        $page->fill('email', 'admin@test.com')
            ->fill('password', 'password')
            ->click('Log in')
            ->click('More')
            ->assertSee('Notifications')
            ->assertSee('1')
            ->assertNoJavaScriptErrors();
    });

    it('keeps role-aware desktop navigation available', function () {
        $page = visit('/login');

        $page->fill('email', 'admin@test.com')
            ->fill('password', 'password')
            ->click('Log in')
            ->assertSee('Dashboard')
            ->assertSee('Members')
            ->assertSee('Payments')
            ->assertSee('Family Admin')
            ->assertSee('Family Settings')
            ->assertNoJavaScriptErrors();
    });
});
