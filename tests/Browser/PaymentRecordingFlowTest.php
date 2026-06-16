<?php

declare(strict_types=1);

/**
 * Browser test for Payment Recording Flow
 *
 * Run with: ./vendor/bin/pest tests/Browser/ --headed
 */

use App\Models\Contribution;

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

        $page->script('() => document.querySelector("button[type=submit]")?.click()');

        $page->wait(0.5);

        expect($this->contribution->refresh()->isPaid())->toBeTrue();

        $page
            ->assertSee('Dashboard')
            ->assertSee('Payment of ₦4,000.00 recorded for John Doe.')
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
