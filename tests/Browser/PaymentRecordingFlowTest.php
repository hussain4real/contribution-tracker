<?php

declare(strict_types=1);

/**
 * Browser test for Payment Recording Flow
 *
 * Run with: ./vendor/bin/pest tests/Browser/ --headed
 */

use App\Models\Contribution;
use App\Models\Payment;
use App\Models\PaymentBatch;

describe('Payment Recording Flow (Browser)', function () {
    beforeEach(function () {
        $this->family = createBrowserFamily();
        $this->financialSecretary = createBrowserFinancialSecretary($this->family, [
            'email' => 'fs@test.com',
        ]);

        $this->member = createBrowserMember($this->family, [
            'name' => 'John Doe',
        ]);

        $nextMonth = now()->startOfMonth()->addMonth();
        $this->contribution = Contribution::factory()
            ->forUser($this->member)
            ->forMonth($nextMonth->year, $nextMonth->month)
            ->employed()
            ->create();
    });

    it('allows financial secretary to record a payment through the UI', function () {
        $page = loginBrowserAs($this->financialSecretary);

        $page->navigate(route('payments.create', [
            'current_family' => $this->family->slug,
            'member' => $this->member,
        ]))
            ->assertSee('Record Payment')
            ->assertSee($this->member->name)
            ->assertSee('1 Month');

        fillBrowserFieldWithoutChange($page, '[name="amount"]', '4000');
        fillBrowserFieldWithoutChange($page, '[name="paid_at"]', now()->format('Y-m-d'));

        expect($page->script('() => document.querySelector("form")?.getAttribute("action")'))
            ->toBe("/{$this->family->slug}/payments");
        expect($page->script('() => document.querySelector("form")?.getAttribute("method")'))
            ->toBe('post');

        $page->click('Review payment')
            ->assertSee('Record Payment')
            ->assertSee($this->contribution->period_label.':');
        expect($page->script('() => document.activeElement?.id'))->toBe('review-heading');
        $page->click('button[type="submit"]')
            ->assertSee('Receipt #1: ₦4,000.00 recorded for John Doe.');

        expect($this->contribution->refresh()->isPaid())->toBeTrue();

        $page
            ->assertSee('Dashboard')
            ->assertSee('Receipt #1: ₦4,000.00 recorded for John Doe.')
            ->assertNoJavaScriptErrors();

    });

    it('shows pending contributions on payment form', function () {
        // Create a partial payment
        $this->contribution->payments()->create([
            'amount' => 2000,
            'paid_at' => now(),
            'recorded_by' => $this->financialSecretary->id,
        ]);

        $page = loginBrowserAs($this->financialSecretary);

        $page->navigate(route('payments.create', [
            'current_family' => $this->family->slug,
            'member' => $this->member,
        ]))
            ->assertSee('Pending Contributions')
            ->assertSee('remaining');
    });

    it('retrieves a recorded receipt when the payment form is retried after review expiry', function () {
        $page = loginBrowserAs($this->financialSecretary);
        $page->navigate(route('payments.create', [
            'current_family' => $this->family->slug,
            'member' => $this->member,
        ]));
        $paidAt = now()->format('Y-m-d');
        fillBrowserFieldWithoutChange($page, '[name="amount"]', '4000');
        fillBrowserFieldWithoutChange($page, '[name="paid_at"]', $paidAt);
        $page->click('Review payment')->assertSee($this->contribution->period_label.':');
        $token = $page->script('() => document.querySelector("[name=review_token]")?.value');
        $key = $page->script('() => document.querySelector("[name=idempotency_key]")?.value');
        expect($token)->toBeString()->and($key)->toBeString();

        $this->actingAs($this->financialSecretary)->post(route('payments.store', ['current_family' => $this->family->slug]), [
            'member_id' => $this->member->id, 'amount' => 4000, 'paid_at' => $paidAt,
            'method' => 'cash', 'idempotency_key' => $key, 'review_token' => $token,
        ])->assertSessionHasNoErrors();
        $receipt = PaymentBatch::sole();

        $this->travel(16)->minutes();
        $page->click('button[type="submit"]')
            ->assertSee('Receipt #'.$receipt->receipt_number.': ₦4,000.00 recorded for John Doe.')
            ->assertNoJavaScriptErrors();
        expect(PaymentBatch::count())->toBe(1)->and(Payment::count())->toBe(1);
    });

    it('quick amount buttons work correctly', function () {
        $page = loginBrowserAs($this->financialSecretary);

        $page->navigate(route('payments.create', [
            'current_family' => $this->family->slug,
            'member' => $this->member,
        ]))
            ->assertSee('1 Month')
            ->assertSee('2 Months')
            ->assertSee('3 Months')
            ->assertSee('6 Months')
            ->assertNoJavaScriptErrors();
    });
});
