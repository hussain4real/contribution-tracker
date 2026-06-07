<?php

declare(strict_types=1);

use App\Models\Contribution;
use App\Models\Expense;
use App\Models\FamilyCategory;
use App\Models\FamilyInvitation;
use App\Models\FundAdjustment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

describe('Financial and family administration flows (Browser)', function () {
    beforeEach(function () {
        $this->family = createBrowserFamily([
            'name' => 'Browser Operations Family',
            'account_name' => 'Browser Operations Family',
            'account_number' => '0123456789',
        ]);
        $this->admin = createBrowserAdmin($this->family, [
            'email' => 'operations-admin@example.com',
        ]);
        $this->financialSecretary = createBrowserFinancialSecretary($this->family, [
            'email' => 'operations-secretary@example.com',
        ]);

        Cache::put('paystack_banks', [], now()->addDay());
    });

    it('records an expense through the UI', function () {
        $page = loginBrowserAs($this->financialSecretary);

        $page->navigate(route('expenses.create'))
            ->assertSee('Record Expense');

        fillBrowserFieldWithoutChange($page, '[name="amount"]', '1250');
        fillBrowserFieldWithoutChange($page, 'input[name="description"]', 'Browser workflow transport');
        fillBrowserFieldWithoutChange($page, '[name="spent_at"]', now()->format('Y-m-d'));

        $page->click('button[type="submit"]')
            ->assertSee('Expenses')
            ->assertSee('Browser workflow transport')
            ->assertNoJavaScriptErrors();

        expect(Expense::where('family_id', $this->family->id)
            ->where('recorded_by', $this->financialSecretary->id)
            ->where('description', 'Browser workflow transport')
            ->exists())->toBeTrue();
    });

    it('lets members select pending contributions before the Paystack handoff', function () {
        config(['services.paystack.public_key' => 'pk_test_browser']);

        $this->family->update([
            'bank_code' => '999',
            'account_number' => '0123456789',
        ]);

        $member = createBrowserMember($this->family, [
            'email' => 'paying-member@example.com',
        ]);
        $contribution = Contribution::factory()
            ->forUser($member)
            ->currentMonth()
            ->create(['expected_amount' => 4000]);

        $page = loginBrowserAs($member);

        $page->navigate(route('pay.index'))
            ->assertSee('Pay Contributions')
            ->assertSee($contribution->period_label)
            ->click('Select All')
            ->assertSee('Pay ₦4,000.00 with Paystack')
            ->assertNoJavaScriptErrors();
    });

    it('records a fund adjustment through the UI', function () {
        $page = loginBrowserAs($this->admin);

        $page->navigate(route('fund-adjustments.index'))
            ->assertSee('Fund Adjustments')
            ->click('Record Adjustment')
            ->assertSee('Record Fund Adjustment');

        fillBrowserFieldWithoutChange($page, '[name="amount"]', '25000');
        fillBrowserFieldWithoutChange($page, '[name="recorded_at"]', now()->format('Y-m-d'));
        fillBrowserFieldWithoutChange($page, 'input[name="description"]', 'Browser workflow opening balance');

        $page->click('form button[type="submit"]')
            ->assertSee('Browser workflow opening balance')
            ->assertNoJavaScriptErrors();

        expect(FundAdjustment::where('family_id', $this->family->id)
            ->where('recorded_by', $this->admin->id)
            ->where('description', 'Browser workflow opening balance')
            ->exists())->toBeTrue();
    });

    it('sends a family invitation through the UI', function () {
        Mail::fake();

        $page = loginBrowserAs($this->admin);

        $page->navigate(route('family.invitations'))
            ->assertSee('Invitations')
            ->click('Invite Member')
            ->fill('email', 'browser-invite@example.com')
            ->select('role', 'financial_secretary')
            ->click('Send Invitation')
            ->assertSee('browser-invite@example.com')
            ->assertSee('Pending')
            ->assertNoJavaScriptErrors();

        expect(FamilyInvitation::where('family_id', $this->family->id)
            ->where('email', 'browser-invite@example.com')
            ->exists())->toBeTrue();
    });

    it('updates family settings and adds a contribution category through the UI', function () {
        $page = loginBrowserAs($this->admin);

        $page->navigate(route('family.settings'))
            ->assertSee('Family Settings')
            ->fill('name', 'Browser Updated Family')
            ->fill('currency', '₦')
            ->fill('due_day', '30')
            ->fill('account_name', 'Browser Account')
            ->fill('account_number', '1234567890')
            ->click('Save Changes')
            ->assertSee('Saved.')
            ->click('Add Category')
            ->fill('new-name', 'Browser Senior')
            ->fill('new-amount', '7500')
            ->click('Add')
            ->assertSee('Browser Senior')
            ->assertNoJavaScriptErrors();

        $this->family->refresh();

        expect($this->family->name)->toBe('Browser Updated Family')
            ->and($this->family->due_day)->toBe(30)
            ->and(FamilyCategory::where('family_id', $this->family->id)
                ->where('name', 'Browser Senior')
                ->where('monthly_amount', 7500)
                ->exists())->toBeTrue();
    });
});
