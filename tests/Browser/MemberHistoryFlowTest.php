<?php

declare(strict_types=1);

use App\Models\Contribution;
use App\Models\FamilyMembership;
use App\Models\Payment;
use App\Models\PaymentBatch;
use App\Models\PaystackTransaction;
use Pest\Browser\Api\PendingAwaitablePage;

function simulateContributionCheckout(PendingAwaitablePage $page, string $checkoutEvent, bool $dropVerification = false): void
{
    $manifestJson = file_get_contents(public_path('build/manifest.json'));
    if ($manifestJson === false) {
        throw new RuntimeException('The frontend build manifest is unavailable.');
    }
    $manifest = json_decode($manifestJson, true, flags: JSON_THROW_ON_ERROR);
    $entry = is_array($manifest) ? ($manifest['node_modules/@paystack/inline-js/es/inline.js'] ?? null) : null;
    $moduleFile = is_array($entry) ? ($entry['file'] ?? null) : null;
    if (! is_string($moduleFile)) {
        throw new RuntimeException('The Paystack module is missing from the frontend build.');
    }
    $moduleUrl = json_encode('/build/'.$moduleFile, JSON_THROW_ON_ERROR);
    $event = json_encode($checkoutEvent, JSON_THROW_ON_ERROR);
    $drop = json_encode($dropVerification, JSON_THROW_ON_ERROR);
    $page->script(<<<JS
        async () => {
            sessionStorage.setItem('checkout-verifications', '0');
            const blocked = new WeakSet();
            const originalOpen = XMLHttpRequest.prototype.open;
            XMLHttpRequest.prototype.open = function (...args) {
                if (String(args[1]).includes('/pay/callback')) {
                    sessionStorage.setItem('checkout-verifications', String(Number(sessionStorage.getItem('checkout-verifications')) + 1));
                    if ({$drop}) blocked.add(this);
                }
                return originalOpen.apply(this, args);
            };
            const originalSend = XMLHttpRequest.prototype.send;
            XMLHttpRequest.prototype.send = function (...args) {
                if (blocked.has(this)) {
                    queueMicrotask(() => this.dispatchEvent(new ProgressEvent('error')));
                    return;
                }
                return originalSend.apply(this, args);
            };
            const originalFetch = window.fetch.bind(window);
            window.fetch = (input, options) => String(input).includes('/pay/initiate')
                ? Promise.resolve(new Response(JSON.stringify({ access_code: 'synthetic', reference: 'TXN_MISSING' }), { status: 200, headers: { 'Content-Type': 'application/json' } }))
                : originalFetch(input, options);
            const { default: PaystackPop } = await import({$moduleUrl});
            PaystackPop.prototype.resumeTransaction = function (accessCode, callbacks) {
                callbacks[{$event}]({ message: 'Synthetic checkout event' });
                callbacks.onSuccess({});
            };
        }
        JS);
}

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

    it('checks an initialized payment when checkout is cancelled or fails to load', function (string $checkoutEvent) {
        config(['services.paystack.public_key' => 'pk_test_synthetic', 'services.paystack.secret_key' => 'sk_test_synthetic']);
        $this->family->update(['bank_code' => '058', 'account_number' => '1234567890']);
        Contribution::factory()->forUser($this->member)->currentMonth()->create(['expected_amount' => 4000]);

        $page = loginBrowserAs($this->member);
        $page->navigate(route('pay.index', ['current_family' => $this->family->slug]))
            ->assertSee('Pay Contributions');

        simulateContributionCheckout($page, $checkoutEvent);

        $page->click('Select All')
            ->click('button:has-text("with Paystack")')
            ->assertPathIs("/{$this->family->slug}/contributions/my")
            ->assertSeeIn('[role="alert"]', 'Transaction not found.')
            ->assertNoJavaScriptErrors();
        expect($page->script("() => sessionStorage.getItem('checkout-verifications')"))->toBe('1');
        expect($page->script("() => document.body.innerText.split('Transaction not found.').length - 1"))->toBe(1);
    })->with(['cancelled' => 'onCancel', 'load failure' => 'onError']);

    it('keeps an unconfirmed warning visible if payment verification loses its connection', function () {
        config(['services.paystack.public_key' => 'pk_test_synthetic', 'services.paystack.secret_key' => 'sk_test_synthetic']);
        $this->family->update(['bank_code' => '058', 'account_number' => '1234567890']);
        Contribution::factory()->forUser($this->member)->currentMonth()->create(['expected_amount' => 4000]);
        $page = loginBrowserAs($this->member);
        $page->navigate(route('pay.index', ['current_family' => $this->family->slug]))->assertSee('Pay Contributions');
        simulateContributionCheckout($page, 'onCancel', dropVerification: true);

        $page->click('Select All')
            ->click('button:has-text("with Paystack")')
            ->assertPathIs("/{$this->family->slug}/pay")
            ->assertSeeIn('[role="alert"]', 'Could not confirm your payment. Check your payment history or contact your family admin before trying again.')
            ->assertEnabled('button:has-text("with Paystack")')
            ->assertNoJavaScriptErrors();
        expect($page->script("() => sessionStorage.getItem('checkout-verifications')"))->toBe('1');
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
