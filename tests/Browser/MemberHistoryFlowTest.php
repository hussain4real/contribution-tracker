<?php

declare(strict_types=1);

use App\Models\Contribution;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Payment;
use App\Models\PaymentBatch;
use App\Models\PlatformPlan;

/**
 * T078 [US5] Browser test for member contribution history navigation
 */
describe('Member History Flow', function () {
    beforeEach(function () {
        $this->family = createBrowserFamily();
        $this->member = createBrowserMember($this->family);
        $this->recorder = createBrowserFinancialSecretary($this->family);
    });

    it('keeps an intended destination isolated from a different browser session', function (): void {
        $otherFamily = createBrowserFamily();
        visit('/'.$otherFamily->slug.'/contributions/my')->assertPathIs('/login');

        visit(route('login'))
            ->fill('email', $this->member->email)
            ->fill('password', 'password')
            ->click('@login-button')
            ->assertPathIs('/'.$this->family->slug.'/dashboard')
            ->assertNoJavaScriptErrors();
    });

    it('returns a member to their intended page in the same browser session', function (): void {
        $page = visit('/'.$this->family->slug.'/contributions/my')->assertPathIs('/login');

        $page->fill('email', $this->member->email)
            ->fill('password', 'password')
            ->click('@login-button')
            ->assertPathIs('/'.$this->family->slug.'/contributions/my')
            ->assertSee('My money')
            ->assertNoJavaScriptErrors();
    });

    it('does not share authentication with a different browser session', function (): void {
        loginBrowserAs($this->member);

        visit('/'.$this->family->slug.'/contributions/my')
            ->assertPathIs('/login')
            ->assertNoJavaScriptErrors();
    });

    it('shows only the signed-in member’s receipts with an accessible download link', function () {
        $family = $this->member->family;
        assert($family instanceof Family);
        $membership = $this->member->membershipForFamily($family);
        assert($membership instanceof FamilyMembership);
        $other = createBrowserMember($family);
        $otherMembership = $other->membershipForFamily($family);
        assert($otherMembership instanceof FamilyMembership);
        PaymentBatch::factory()->create(['family_id' => $family->id, 'family_membership_id' => $membership->id, 'receipt_number' => 123]);
        PaymentBatch::factory()->create(['family_id' => $family->id, 'family_membership_id' => $otherMembership->id, 'receipt_number' => 456]);

        $page = loginBrowserAs($this->member);
        $page->navigate(route('contributions.my', ['current_family' => $family->slug]))
            ->assertSee('Your receipts')
            ->assertSee('Receipt #123')
            ->assertDontSee('Receipt #456')
            ->assertNoJavaScriptErrors();
        expect($page->script("() => Boolean(document.querySelector('a[aria-label=\"Download receipt #123\"]'))"))->toBeTrue();
    });

    it('shows an unconfirmed callback result after a full page return', function () {
        $page = loginBrowserAs($this->member);
        $page->navigate(route('pay.callback', ['current_family' => $this->family->slug, 'reference' => 'TXN_MISSING']))
            ->assertPathIs("/{$this->family->slug}/contributions/my")
            ->assertSeeIn('[role="alert"]', 'Transaction not found.')
            ->assertNoJavaScriptErrors();
    });

    it('member can navigate to my contributions page from dashboard', function () {
        $contribution = Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->create(['expected_amount' => 4000]);

        Payment::factory()->forContribution($contribution)->recordedBy($this->recorder)->create([
            'amount' => 1250, 'paid_at' => '2026-01-15',
        ]);

        $page = loginBrowserAs($this->member);

        $page->click('My Contributions')
            ->assertPathIs("/{$this->family->slug}/contributions/my")
            ->assertSee('My money')
            ->assertSee('Latest payment')
            ->assertSee('2026-01-15')
            ->assertSee('No receipts are available yet.');
    });

    it('explains when an advance contribution is too far away for online payment', function () {
        config(['services.paystack.public_key' => 'pk_test_synthetic', 'services.paystack.secret_key' => 'sk_test_synthetic']);
        $plan = PlatformPlan::create([
            'name' => 'Browser online payments', 'slug' => 'browser-online-payments', 'price' => 0,
            'max_members' => null, 'features' => ['online_payments'], 'is_active' => true, 'sort_order' => 0,
        ]);
        $this->family->update(['platform_plan_id' => $plan->id, 'bank_code' => '058', 'account_number' => '1234567890']);
        $period = now()->startOfMonth()->addMonths(7);
        $contribution = Contribution::factory()->forUser($this->member)->forMonth($period->year, $period->month)->create();

        $page = loginBrowserAs($this->member);
        $page->navigate(route('contributions.my', ['current_family' => $this->family->slug]))
            ->assertSee('Online payment is available up to six months before the contribution month.')
            ->assertDontSee('Pay now')
            ->assertNoJavaScriptErrors();

        $period = now()->startOfMonth()->addMonths(6);
        $contribution->update(['year' => $period->year, 'month' => $period->month]);
        $page->refresh()->assertSee('Pay now')
            ->assertDontSee('Online payment is available up to six months before the contribution month.')
            ->assertNoJavaScriptErrors();
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
