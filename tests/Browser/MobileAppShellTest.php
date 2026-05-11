<?php

use App\Features\AiAssistant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

describe('Mobile app shell', function () {
    beforeEach(function () {
        $this->family = createBrowserFamily();
        $this->admin = createBrowserAdmin($this->family, [
            'name' => 'Admin User',
            'email' => 'admin@test.com',
        ]);

        Feature::for($this->admin)->activate(AiAssistant::class);
        Feature::flushCache();
        Cache::put('paystack_banks', [], now()->addDay());
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
            ->click('Payments')
            ->assertAttribute(
                'nav[aria-label="Mobile navigation"] a[href$="/payments"]',
                'aria-current',
                'page',
            )
            ->click('More')
            ->assertSee('Admin User')
            ->assertSee('admin@test.com')
            ->assertSee('Settings')
            ->assertSee('Log out')
            ->assertSee('Notifications')
            ->assertSee('Family Admin')
            ->assertSee('Family Settings')
            ->assertSee('Legal')
            ->assertSee('Privacy Policy')
            ->assertSee('Terms of Service')
            ->assertNoJavaScriptErrors();
    });

    it('shows a draggable floating AI assistant entry point', function () {
        $page = visit('/login')->on()->iPhone15Pro();

        $page->fill('email', 'admin@test.com')
            ->fill('password', 'password')
            ->click('Log in')
            ->assertAttribute(
                'button[aria-label="Open AI assistant"]',
                'type',
                'button',
            )
            ->assertScript(<<<'JS'
                async () => {
                    const button = document.querySelector('button[aria-label="Open AI assistant"]');

                    if (!button) {
                        return false;
                    }

                    const before = button.getBoundingClientRect();
                    const startX = before.left + 30;
                    const startY = before.top + 30;
                    const endX = Math.max(40, startX - 80);
                    const endY = Math.max(40, startY - 70);

                    button.dispatchEvent(new PointerEvent('pointerdown', {
                        pointerId: 7,
                        pointerType: 'touch',
                        clientX: startX,
                        clientY: startY,
                        button: 0,
                        bubbles: true,
                    }));

                    window.dispatchEvent(new PointerEvent('pointermove', {
                        pointerId: 7,
                        pointerType: 'touch',
                        clientX: endX,
                        clientY: endY,
                        button: -1,
                        buttons: 1,
                        bubbles: true,
                        cancelable: true,
                    }));

                    window.dispatchEvent(new PointerEvent('pointerup', {
                        pointerId: 7,
                        pointerType: 'touch',
                        clientX: endX,
                        clientY: endY,
                        button: 0,
                        bubbles: true,
                    }));

                    await new Promise((resolve) => requestAnimationFrame(resolve));

                    const after = button.getBoundingClientRect();
                    const stored = localStorage.getItem('familyfunds_floating_ai_position');

                    return stored !== null && Math.abs(after.left - before.left) > 20;
                }
            JS)
            ->click('button[aria-label="Open AI assistant"]')
            ->assertPathIs('/ai')
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

    it('renders the dedicated notifications page on mobile', function () {
        $this->admin->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\ContributionReminderNotification',
            'data' => [
                'contribution_id' => 1,
                'family_name' => 'Test Family',
                'period_label' => 'May 2026',
                'amount_owed' => 4000,
                'due_date' => '2026-05-25',
                'type' => 'follow_up',
            ],
        ]);

        $page = visit('/login')->on()->iPhone15Pro();

        $page->fill('email', 'admin@test.com')
            ->fill('password', 'password')
            ->click('Log in')
            ->navigate('/notifications')
            ->assertSee('Notification Center')
            ->assertSee('Timeline')
            ->assertSee('May 2026')
            ->assertSee('Due today')
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

    it('only marks the matching sibling navigation item active', function () {
        $superAdmin = createBrowserSuperAdmin($this->family, [
            'email' => 'super@test.com',
        ]);

        Feature::for($superAdmin)->activate(AiAssistant::class);
        Feature::flushCache();

        $page = visit('/login');

        $page->fill('email', 'super@test.com')
            ->fill('password', 'password')
            ->click('Log in')
            ->navigate('/family/invitations')
            ->assertScript(<<<'JS'
                () => {
                    const familySettings = document.querySelector('a[href$="/family/settings"]');
                    const invitations = document.querySelector('a[href$="/family/invitations"]');

                    return familySettings?.dataset.active !== 'true'
                        && invitations?.dataset.active === 'true';
                }
            JS)
            ->navigate('/platform/users')
            ->assertScript(<<<'JS'
                () => {
                    const platformDashboard = document.querySelector('a[href$="/platform"]');
                    const platformUsers = document.querySelector('a[href$="/platform/users"]');

                    return platformDashboard?.dataset.active !== 'true'
                        && platformUsers?.dataset.active === 'true';
                }
            JS)
            ->assertNoJavaScriptErrors();
    });
});
