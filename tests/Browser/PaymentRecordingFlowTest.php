<?php

declare(strict_types=1);

/**
 * Browser test for Payment Recording Flow
 *
 * Run with: ./vendor/bin/pest tests/Browser/ --headed
 */

use App\Jobs\SendPaymentReceipt;
use App\Models\Contribution;
use App\Models\Payment;
use App\Models\PaymentBatch;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

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
        expect($page->script('() => Object.keys(sessionStorage).filter((key) => key.startsWith("familyfund:payment:")).length'))->toBe(0);

        $page
            ->assertSee('Dashboard')
            ->assertSee('Receipt #1: ₦4,000.00 recorded for John Doe.')
            ->assertNoJavaScriptErrors();

    });

    it('does not submit a payment when it cannot save the retry key', function (): void {
        $page = loginBrowserAs($this->financialSecretary);
        $page->navigate(route('payments.create', [
            'current_family' => $this->family->slug,
            'member' => $this->member,
        ]));
        fillBrowserFieldWithoutChange($page, '[name="amount"]', '4000');
        fillBrowserFieldWithoutChange($page, '[name="paid_at"]', now()->format('Y-m-d'));
        $page->click('Review payment')->assertSee($this->contribution->period_label.':');
        $page->script('() => { Storage.prototype.setItem = () => { throw new Error("Synthetic storage failure"); }; }');
        $page->click('button[type="submit"]')
            ->assertSee('Payment review requires session storage to protect against duplicate recording.');
        expect(PaymentBatch::count())->toBe(0)->and(Payment::count())->toBe(0);
    });

    it('requires a fresh review when the amount changes during a slow review', function (): void {
        $page = loginBrowserAs($this->financialSecretary);
        $page->resize(390, 844)->navigate(route('payments.create', [
            'current_family' => $this->family->slug,
            'member' => $this->member,
        ]));
        fillBrowserFieldWithoutChange($page, '[name="amount"]', '4000');
        fillBrowserFieldWithoutChange($page, '[name="paid_at"]', now()->format('Y-m-d'));

        $reviewPath = json_encode(route('payments.review', ['current_family' => $this->family->slug], false), JSON_THROW_ON_ERROR);
        $page->script(<<<JS
            () => {
                const originalOpen = XMLHttpRequest.prototype.open;
                const originalSend = XMLHttpRequest.prototype.send;
                const delayed = new WeakSet();
                let first = true;
                XMLHttpRequest.prototype.open = function (method, url, ...rest) {
                    if (first && method === 'POST' && new URL(url, window.location.origin).pathname === {$reviewPath}) {
                        first = false;
                        delayed.add(this);
                    }
                    return originalOpen.call(this, method, url, ...rest);
                };
                XMLHttpRequest.prototype.send = function (...args) {
                    if (delayed.has(this)) {
                        window.releasePaymentReview = () => originalSend.apply(this, args);
                        return;
                    }
                    return originalSend.apply(this, args);
                };
            }
        JS);

        $page->click('Review payment')
            ->assertButtonDisabled('Preparing review...');
        fillBrowserFieldWithoutChange($page, '[name="amount"]', '2000');
        $page->script('() => window.releasePaymentReview()');
        $page->assertButtonEnabled('Review payment')
            ->assertNotPresent('[name="review_token"]')
            ->click('Review payment')
            ->assertSee($this->contribution->period_label.': ₦2,000.00')
            ->assertNoJavaScriptErrors();

        expect(PaymentBatch::count())->toBe(0)->and(Payment::count())->toBe(0);
    });

    it('keeps a recorded payment safe to retry after an interrupted response', function (string $failure): void {
        $queue = Queue::fake();
        Route::post('/synthetic-payment-session-error', fn () => response('Synthetic session error', 500));
        $page = loginBrowserAs($this->financialSecretary);
        $page->navigate(route('payments.create', [
            'current_family' => $this->family->slug,
            'member' => $this->member,
        ]));
        fillBrowserFieldWithoutChange($page, '[name="amount"]', '4000');
        fillBrowserFieldWithoutChange($page, '[name="paid_at"]', now()->format('Y-m-d'));
        $page->click('Review payment')->assertSee($this->contribution->period_label.':');
        $token = $page->script('() => document.querySelector("[name=review_token]")?.value');
        $key = $page->script('() => document.querySelector("[name=idempotency_key]")?.value');
        expect($token)->toBeString()->and($key)->toBeString();
        $this->withoutMiddleware(ResetBrowserSessionAttributes::class);
        $this->actingAs($this->financialSecretary)->post(route('payments.store', ['current_family' => $this->family->slug]), [
            'member_id' => $this->member->id, 'amount' => 4000, 'paid_at' => now()->format('Y-m-d'),
            'method' => 'cash', 'idempotency_key' => $key, 'review_token' => $token,
        ])->assertSessionHasNoErrors();
        $this->withMiddleware(ResetBrowserSessionAttributes::class);
        $receipt = PaymentBatch::sole();
        $queuedCount = $queue->pushed(SendPaymentReceipt::class)->count();
        expect($queuedCount)->toBeGreaterThan(0);
        $paymentPath = json_encode(route('payments.store', ['current_family' => $this->family->slug], false), JSON_THROW_ON_ERROR);
        $failureMode = json_encode($failure, JSON_THROW_ON_ERROR);
        $page->script(<<<JS
            () => {
                const originalOpen = XMLHttpRequest.prototype.open;
                const originalSend = XMLHttpRequest.prototype.send;
                const interrupted = new WeakSet();
                let first = true;
                XMLHttpRequest.prototype.open = function (method, url, ...rest) {
                    if (first && method === 'POST' && new URL(url, window.location.origin).pathname === {$paymentPath}) {
                        first = false;
                        if ({$failureMode} === 'server') {
                            url = '/synthetic-payment-session-error';
                        } else {
                            interrupted.add(this);
                        }
                    }
                    return originalOpen.call(this, method, url, ...rest);
                };
                XMLHttpRequest.prototype.send = function (...args) {
                    if (interrupted.has(this)) {
                        queueMicrotask(() => this.dispatchEvent(new ProgressEvent('error')));
                        return;
                    }
                    return originalSend.apply(this, args);
                };
            }
        JS);

        $page->click('button[type="submit"]')
            ->assertSee("We couldn't confirm whether this payment was recorded.");
        $page->click('button[type="submit"]')
            ->assertSee('Receipt #'.$receipt->receipt_number.': ₦4,000.00 recorded for John Doe.')
            ->assertNoJavaScriptErrors();
        expect(PaymentBatch::count())->toBe(1)->and(Payment::count())->toBe(1)
            ->and($queue->pushed(SendPaymentReceipt::class)->count())->toBe($queuedCount);
    })->with(['server error' => 'server', 'network interruption' => 'network']);

    it('preserves an unconfirmed payment request across reload and requires confirmation before starting another', function (): void {
        $queue = Queue::fake();
        Route::post('/synthetic-payment-session-error', fn () => response('Synthetic session error', 500));
        $page = loginBrowserAs($this->financialSecretary);
        $page->navigate(route('payments.create', [
            'current_family' => $this->family->slug,
            'member' => $this->member,
        ]));
        fillBrowserFieldWithoutChange($page, '[name="amount"]', '4000');
        fillBrowserFieldWithoutChange($page, '[name="paid_at"]', now()->format('Y-m-d'));
        $page->click('Review payment')->assertSee($this->contribution->period_label.':');
        $token = $page->script('() => document.querySelector("[name=review_token]")?.value');
        $key = $page->script('() => document.querySelector("[name=idempotency_key]")?.value');
        expect($token)->toBeString()->and($key)->toBeString();
        $this->withoutMiddleware(ResetBrowserSessionAttributes::class);
        $this->actingAs($this->financialSecretary)->post(route('payments.store', ['current_family' => $this->family->slug]), [
            'member_id' => $this->member->id, 'amount' => 4000, 'paid_at' => now()->format('Y-m-d'),
            'method' => 'cash', 'idempotency_key' => $key, 'review_token' => $token,
        ])->assertSessionHasNoErrors();
        $this->withMiddleware(ResetBrowserSessionAttributes::class);
        $receipt = PaymentBatch::sole();
        $queuedCount = $queue->pushed(SendPaymentReceipt::class)->count();
        expect($queuedCount)->toBeGreaterThan(0);
        $paymentPath = json_encode(route('payments.store', ['current_family' => $this->family->slug], false), JSON_THROW_ON_ERROR);
        $failureMode = json_encode('network', JSON_THROW_ON_ERROR);
        $page->script(<<<JS
            () => {
                const originalOpen = XMLHttpRequest.prototype.open;
                const originalSend = XMLHttpRequest.prototype.send;
                const interrupted = new WeakSet();
                let first = true;
                XMLHttpRequest.prototype.open = function (method, url, ...rest) {
                    if (first && method === 'POST' && new URL(url, window.location.origin).pathname === {$paymentPath}) {
                        first = false;
                        if ({$failureMode} === 'server') {
                            url = '/synthetic-payment-session-error';
                        } else {
                            interrupted.add(this);
                        }
                    }
                    return originalOpen.call(this, method, url, ...rest);
                };
                XMLHttpRequest.prototype.send = function (...args) {
                    if (interrupted.has(this)) {
                        queueMicrotask(() => this.dispatchEvent(new ProgressEvent('error')));
                        return;
                    }
                    return originalSend.apply(this, args);
                };
            }
        JS);

        $page->click('button[type="submit"]')
            ->assertSee("We couldn't confirm whether this payment was recorded.")
            ->navigate(route('payments.create', ['current_family' => $this->family->slug, 'member' => $this->member]))
            ->assertSee("We couldn't confirm whether this payment was recorded.");
        expect($page->script('() => document.querySelector("[name=idempotency_key]")?.value'))->toBe($key);
        fillBrowserFieldWithoutChange($page, '[name="amount"]', '4000');
        fillBrowserFieldWithoutChange($page, '[name="paid_at"]', now()->format('Y-m-d'));
        $page->click('Review payment')->click('button[type="submit"]')
            ->assertSee('This payment request key was already used for different details.')
            ->assertSee("We couldn't confirm whether this payment was recorded.");
        expect(PaymentBatch::count())->toBe(1)->and(Payment::count())->toBe(1)
            ->and($queue->pushed(SendPaymentReceipt::class)->count())->toBe($queuedCount);
        $page->script('() => { window.confirm = () => false; }');
        $page->click('Start a new payment');
        expect($page->script('() => document.querySelector("[name=idempotency_key]")?.value'))->toBe($key);
        $page->script('() => { window.confirm = () => true; }');
        $page->click('Start a new payment')->assertDontSee("We couldn't confirm whether this payment was recorded.")
            ->assertNoJavaScriptErrors();
        expect($page->script('() => document.querySelector("[name=idempotency_key]")?.value'))->not->toBe($key);
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
